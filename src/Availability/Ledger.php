<?php
/**
 * The inventory ledger: how many rooms of a type are free on each night, and which
 * rooms a stay occupies.
 *
 * Free rooms of a type on a night = active units of the type
 *   − stays of the type that occupy that night (assigned to a room or not)
 *   − out-of-service blocks of units of the type
 *   − valid holds (temporary blocks of the public form, not expired).
 *
 * Writers must call lock_types() inside a transaction first: the room type rows are
 * locked FOR UPDATE in id order, so two requests never count the same free room and
 * never deadlock against each other. unit_nights (PRIMARY KEY unit_id, night) is the
 * second guard: the database refuses a second stay or block in the same room and night,
 * whatever the code above it does. The departure day is never an occupied night.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Availability;

use BookingManager\Core\Dates;
use BookingManager\Database\LockFailed;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class Ledger {

	/**
	 * Locks the room types (SELECT … FOR UPDATE, in id order). Inside a transaction only.
	 *
	 * @param int[] $type_ids
	 */
	public function lock_types( array $type_ids ): void {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $type_ids ) ) ) );
		if ( ! $ids ) {
			return;
		}
		sort( $ids );
		$table = Schema::table( 'room_types' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name and integer list.
		$wpdb->query( "SELECT id FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ') ORDER BY id FOR UPDATE' );
		LockFailed::check();
	}

	/** Active units of a type. */
	public function capacity( int $type_id ): int {
		global $wpdb;
		$table = Schema::table( 'units' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE room_type_id = %d AND status = 'active'", $type_id ) );
	}

	/**
	 * Rooms of a type used on each night of [from, to).
	 *
	 * @param int[] $exclude_rooms booking_rooms ids not to count (the stay being changed)
	 * @return array<string, array{booked: int, blocked: int, held: int}> night => usage
	 */
	public function usage( int $type_id, string $from, string $to, array $exclude_rooms = array(), int $exclude_hold = 0 ): array {
		global $wpdb;
		$usage = array();
		foreach ( Dates::nights( $from, $to ) as $night ) {
			$usage[ $night ] = array(
				'booked'  => 0,
				'blocked' => 0,
				'held'    => 0,
			);
		}
		if ( ! $usage ) {
			return $usage;
		}

		$rooms   = Schema::table( 'booking_rooms' );
		$exclude = $exclude_rooms ? ' AND id NOT IN (' . implode( ',', array_map( 'intval', $exclude_rooms ) ) . ')' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name and integer list.
		$stays = (array) $wpdb->get_results( $wpdb->prepare( "SELECT arrival, departure FROM {$rooms} WHERE room_type_id = %d AND occupies = 1 AND arrival < %s AND departure > %s{$exclude}", $type_id, $to, $from ), ARRAY_A );
		self::add( $usage, $stays, 'arrival', 'departure', 'booked' );

		$blocks = Schema::table( 'unit_blocks' );
		$units  = Schema::table( 'units' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT b.date_from, b.date_to FROM {$blocks} b JOIN {$units} u ON u.id = b.unit_id AND u.status = 'active' WHERE b.room_type_id = %d AND b.status = 'active' AND b.date_from < %s AND b.date_to > %s", $type_id, $to, $from ), ARRAY_A );
		self::add( $usage, $rows, 'date_from', 'date_to', 'blocked' );

		$holds = Schema::table( 'holds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT arrival, departure, quantity FROM {$holds} WHERE room_type_id = %d AND status = 'active' AND expires_at > %s AND arrival < %s AND departure > %s AND id <> %d", $type_id, gmdate( 'Y-m-d H:i:s' ), $to, $from, $exclude_hold ), ARRAY_A );
		self::add( $usage, $rows, 'arrival', 'departure', 'held', 'quantity' );

		return $usage;
	}

	/**
	 * Free rooms of a type on each night of [from, to).
	 *
	 * @param int[] $exclude_rooms
	 * @return array<string, int> night => free rooms (never below 0)
	 */
	public function free_by_night( int $type_id, string $from, string $to, array $exclude_rooms = array(), int $exclude_hold = 0 ): array {
		$capacity = $this->capacity( $type_id );
		$out      = array();
		foreach ( $this->usage( $type_id, $from, $to, $exclude_rooms, $exclude_hold ) as $night => $u ) {
			$out[ $night ] = max( 0, $capacity - $u['booked'] - $u['blocked'] - $u['held'] );
		}
		return $out;
	}

	/** Rooms of a type free on every night of [from, to). */
	public function free( int $type_id, string $from, string $to, array $exclude_rooms = array(), int $exclude_hold = 0 ): int {
		$nights = $this->free_by_night( $type_id, $from, $to, $exclude_rooms, $exclude_hold );
		return $nights ? min( $nights ) : 0;
	}

	/**
	 * Units of a type with no stay or block on any night of [from, to).
	 *
	 * @return int[] unit ids in display order
	 */
	public function free_units( int $type_id, string $from, string $to ): array {
		global $wpdb;
		$units  = Schema::table( 'units' );
		$nights = Schema::table( 'unit_nights' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT u.id FROM {$units} u WHERE u.room_type_id = %d AND u.status = 'active' AND NOT EXISTS (SELECT 1 FROM {$nights} n WHERE n.unit_id = u.id AND n.night >= %s AND n.night < %s) ORDER BY u.sort_order, u.code, u.id", $type_id, $from, $to ) ) );
	}

	/**
	 * Writes the nights of a room. False when one of them is already taken (the database
	 * refused the whole insert: nothing was written).
	 */
	public function occupy( int $unit_id, int $property_id, string $from, string $to, ?int $booking_room_id, ?int $block_id = null ): bool {
		global $wpdb;
		$nights = Dates::nights( $from, $to );
		if ( ! $nights ) {
			return false;
		}
		$values = array();
		foreach ( $nights as $night ) {
			// Every part is an integer or a date built by Dates::nights(): nothing to escape.
			$values[] = sprintf( "(%d, '%s', %d, %s, %s)", $unit_id, $night, $property_id, null === $booking_room_id ? 'NULL' : (string) (int) $booking_room_id, null === $block_id ? 'NULL' : (string) (int) $block_id );
		}
		$table    = Schema::table( 'unit_nights' );
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integers and validated dates only.
		$ok = $wpdb->query( "INSERT INTO {$table} (unit_id, night, property_id, booking_room_id, block_id) VALUES " . implode( ',', $values ) );
		$wpdb->suppress_errors( $suppress );
		if ( false === $ok ) {
			LockFailed::check();
			return false;
		}
		return true;
	}

	/** Frees the nights of a stay. */
	public function release_room( int $booking_room_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'unit_nights' ), array( 'booking_room_id' => $booking_room_id ), array( '%d' ) );
	}

	/** Frees the nights of a block. */
	public function release_block( int $block_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'unit_nights' ), array( 'block_id' => $block_id ), array( '%d' ) );
	}

	/**
	 * Adds rows (date ranges) to the usage of each night they cover.
	 *
	 * @param array<string, array{booked: int, blocked: int, held: int}> $usage
	 * @param array<int, array<string, mixed>>                            $rows
	 */
	private static function add( array &$usage, array $rows, string $from_key, string $to_key, string $bucket, ?string $quantity_key = null ): void {
		foreach ( $rows as $row ) {
			$qty = null === $quantity_key ? 1 : (int) $row[ $quantity_key ];
			foreach ( Dates::nights( (string) $row[ $from_key ], (string) $row[ $to_key ] ) as $night ) {
				if ( isset( $usage[ $night ] ) ) {
					$usage[ $night ][ $bucket ] += $qty;
				}
			}
		}
	}
}
