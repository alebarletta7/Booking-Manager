<?php
/**
 * Temporary holds: while a visitor fills in the public form, the rooms chosen are kept
 * out of sale for a few minutes (property setting hold_minutes). Nothing has to clean
 * them up for availability: an expired hold simply stops counting (Ledger::usage). A
 * daily task marks old holds as expired to keep the table small.
 *
 * The visitor gets a random token; only its SHA-256 is stored.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Availability;

use BookingManager\Core\Hooks;
use BookingManager\Database\Schema;
use BookingManager\Database\Transaction;

defined( 'ABSPATH' ) || exit;

class HoldService {

	public const ACTIVE    = 'active';
	public const CONVERTED = 'converted';
	public const RELEASED  = 'released';
	public const EXPIRED   = 'expired';

	public function __construct( private Ledger $ledger ) {}

	/**
	 * Holds $quantity rooms of a type, if free.
	 *
	 * @return array{token: string, expires_at: string, id: int}|\WP_Error
	 */
	public function create( int $property_id, int $type_id, string $arrival, string $departure, int $quantity, int $minutes ): array|\WP_Error {
		return $this->create_set( $property_id, array( $type_id => $quantity ), $arrival, $departure, $minutes );
	}

	/**
	 * Holds rooms of one or more types under a single token (a group in two doubles, or
	 * a triple and a double): all of them or none. One row per type, same token.
	 *
	 * @param array<int, int> $quantities room type id => rooms
	 * @return array{token: string, expires_at: string, id: int, ids: int[]}|\WP_Error
	 */
	public function create_set( int $property_id, array $quantities, string $arrival, string $departure, int $minutes ): array|\WP_Error {
		$quantities = array_filter( array_map( 'intval', $quantities ), static fn( int $q ) => $q > 0 );
		ksort( $quantities );
		if ( ! $quantities ) {
			return new \WP_Error( 'bkng_unavailable', __( 'Le camere scelte non sono più disponibili per queste date.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$hold = Transaction::run_retrying(
			function () use ( $property_id, $quantities, $arrival, $departure, $minutes ) {
				global $wpdb;
				$this->ledger->lock_types( array_keys( $quantities ) );
				foreach ( $quantities as $type_id => $quantity ) {
					if ( $this->ledger->free( (int) $type_id, $arrival, $departure ) < $quantity ) {
						return new \WP_Error( 'bkng_unavailable', __( 'Le camere scelte non sono più disponibili per queste date.', 'booking-manager' ), array( 'status' => 409 ) );
					}
				}
				$token   = bin2hex( random_bytes( 16 ) );
				$expires = gmdate( 'Y-m-d H:i:s', time() + max( 1, $minutes ) * MINUTE_IN_SECONDS );
				$ids     = array();
				foreach ( $quantities as $type_id => $quantity ) {
					$wpdb->insert(
						Schema::table( 'holds' ),
						array(
							'uuid'         => wp_generate_uuid4(),
							'token_hash'   => self::hash( $token ),
							'property_id'  => $property_id,
							'room_type_id' => (int) $type_id,
							'arrival'      => $arrival,
							'departure'    => $departure,
							'quantity'     => $quantity,
							'status'       => self::ACTIVE,
							'expires_at'   => $expires,
							'created_at'   => gmdate( 'Y-m-d H:i:s' ),
						)
					);
					$ids[] = (int) $wpdb->insert_id;
				}
				return array(
					'token'      => $token,
					'expires_at' => $expires,
					'id'         => $ids[0],
					'ids'        => $ids,
				);
			}
		);
		if ( is_array( $hold ) ) {
			// Channels see the rooms on hold as sold while the visitor completes the form.
			Hooks::availability_changed( $property_id, array_keys( $quantities ), $arrival, $departure );
		}
		return $hold;
	}

	/**
	 * An active, unexpired hold: its first row, with ids and room_type_ids of all the
	 * rows under the same token.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_active( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$table = Schema::table( 'holds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s AND status = %s AND expires_at > %s ORDER BY id", self::hash( $token ), self::ACTIVE, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		if ( ! $rows ) {
			return null;
		}
		$row = $rows[0];
		foreach ( array( 'id', 'property_id', 'room_type_id', 'quantity' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$row['ids']           = array_map( static fn( array $r ) => (int) $r['id'], $rows );
		$row['room_type_ids'] = array_map( static fn( array $r ) => (int) $r['room_type_id'], $rows );
		return $row;
	}

	/** @param int|int[] $hold_ids */
	public function convert( int|array $hold_ids, int $booking_id ): void {
		global $wpdb;
		foreach ( (array) $hold_ids as $hold_id ) {
			$wpdb->update(
				Schema::table( 'holds' ),
				array(
					'status'     => self::CONVERTED,
					'booking_id' => $booking_id,
				),
				array( 'id' => (int) $hold_id )
			);
		}
	}

	public function release( string $token ): void {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return;
		}
		$hold = $this->find_active( $token );
		$done = $wpdb->update(
			Schema::table( 'holds' ),
			array( 'status' => self::RELEASED ),
			array(
				'token_hash' => self::hash( $token ),
				'status'     => self::ACTIVE,
			)
		);
		if ( $hold && $done ) {
			Hooks::availability_changed( $hold['property_id'], $hold['room_type_ids'], (string) $hold['arrival'], (string) $hold['departure'] );
		}
	}

	/**
	 * Marks the holds that ran out since the last call and returns them, so channels get
	 * the rooms back at once (the daily expire() only keeps the table small).
	 *
	 * @return array<int, array{property_id: int, room_type_id: int, arrival: string, departure: string}>
	 */
	public function expire_due(): array {
		global $wpdb;
		$table = Schema::table( 'holds' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, property_id, room_type_id, arrival, departure FROM {$table} WHERE status = %s AND expires_at <= %s ORDER BY id LIMIT 500", self::ACTIVE, $now ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			if ( $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id = %d AND status = %s", self::EXPIRED, (int) $row['id'], self::ACTIVE ) ) ) {
				$out[] = array(
					'property_id'  => (int) $row['property_id'],
					'room_type_id' => (int) $row['room_type_id'],
					'arrival'      => (string) $row['arrival'],
					'departure'    => (string) $row['departure'],
				);
			}
		}
		return $out;
	}

	/** Daily: marks expired holds (they already stopped counting). */
	public function expire(): int {
		global $wpdb;
		$table = Schema::table( 'holds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE status = %s AND expires_at <= %s", self::EXPIRED, self::ACTIVE, gmdate( 'Y-m-d H:i:s' ) ) );
	}

	public static function hash( string $token ): string {
		return hash( 'sha256', 'bkng-hold|' . $token );
	}
}
