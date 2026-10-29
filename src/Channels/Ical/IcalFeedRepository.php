<?php
/**
 * Portal calendars imported for the rooms (bkng_ical_feeds) and the room closures they
 * wrote (unit_blocks with feed_id).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Ical;

use BookingManager\Core\SecretBox;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The address of a portal calendar carries the listing's private key: it is stored
 * encrypted (SecretBox), compared through a keyed hash and shown only as a hint.
 */
class IcalFeedRepository {

	public function __construct( private SecretBox $box ) {}

	/** Keyed hash of an address: finds the same calendar twice without storing it in clear. */
	public static function hash( string $url ): string {
		return hash_hmac( 'sha256', $url, 'bkng-ical-url|' . wp_salt( 'auth' ) );
	}

	/** The address of a calendar, null when it cannot be decrypted (keys of the site changed). */
	public function url( array $feed ): ?string {
		$stored = (string) ( $feed['url'] ?? '' );
		if ( ! str_starts_with( $stored, 'rm1:' ) ) {
			return '' !== $stored ? $stored : null;
		}
		$data = $this->box->open( $stored );
		return is_array( $data ) && isset( $data['url'] ) && is_string( $data['url'] ) ? $data['url'] : null;
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			Schema::table( 'ical_feeds' ),
			array(
				'property_id' => (int) $data['property_id'],
				'unit_id'     => (int) $data['unit_id'],
				'channel'     => (string) $data['channel'],
				'url'         => $this->box->seal( array( 'url' => (string) $data['url'] ) ),
				'url_hash'    => self::hash( (string) $data['url'] ),
				'url_hint'    => IcalCalendar::hint( (string) $data['url'] ),
				'status'      => 'active',
				'next_sync_at' => $now,
				'created_by'  => $data['created_by'] ?? null,
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$t = Schema::table( 'ical_feeds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND status = 'active'", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function for_property( int $property_id ): array {
		global $wpdb;
		$t = Schema::table( 'ical_feeds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE property_id = %d AND status = 'active' ORDER BY unit_id, id", $property_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** @return int[] every active feed, least recently synchronised first */
	public function due_ids( int $limit ): array {
		global $wpdb;
		$t = Schema::table( 'ical_feeds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'active' AND (next_sync_at IS NULL OR next_sync_at <= %s) ORDER BY next_sync_at IS NOT NULL, next_sync_at, id LIMIT %d", gmdate( 'Y-m-d H:i:s' ), $limit ) ) );
	}

	/** @return int[] active calendars of a room */
	public function for_unit( int $unit_id ): array {
		global $wpdb;
		$t = Schema::table( 'ical_feeds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE unit_id = %d AND status = 'active' ORDER BY id", $unit_id ) ) );
	}

	/**
	 * Takes a calendar for one reading: false when another reading (cron or «Sincronizza»)
	 * holds it. The lock expires by itself if that reading died half way.
	 */
	public function claim( int $id, int $seconds ): bool {
		global $wpdb;
		$t   = Schema::table( 'ical_feeds' );
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET locked_until = %s WHERE id = %d AND status = 'active' AND (locked_until IS NULL OR locked_until < %s)", gmdate( 'Y-m-d H:i:s', time() + $seconds ), $id, $now ) );
	}

	public function unlock( int $id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'ical_feeds' ), array( 'locked_until' => null ), array( 'id' => $id ) );
	}

	/** @param array<string, mixed> $data */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'ical_feeds' ), $data + array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );
	}

	/** @return array<int, array<string, mixed>> active closures written by a feed */
	public function blocks( int $feed_id ): array {
		global $wpdb;
		$t = Schema::table( 'unit_blocks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE feed_id = %d AND status = 'active' ORDER BY date_from, id", $feed_id ), ARRAY_A );
	}

	/** @return array<int, array<string, mixed>> active closures of a room written by any portal calendar */
	public function unit_blocks( int $unit_id ): array {
		global $wpdb;
		$t = Schema::table( 'unit_blocks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE unit_id = %d AND feed_id IS NOT NULL AND status = 'active' ORDER BY date_from, id", $unit_id ), ARRAY_A );
	}

	/**
	 * What occupies each room of a property from $from on, by source: direct bookings (the
	 * booking's own source), manual closures and each portal.
	 *
	 * @return array<int, array<int, array{source: string, from: string, to: string}>> unit id => periods
	 */
	public function occupancy( int $property_id, string $from ): array {
		global $wpdb;
		$rooms    = Schema::table( 'booking_rooms' );
		$bookings = Schema::table( 'bookings' );
		$blocks   = Schema::table( 'unit_blocks' );
		$out      = array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$stays = (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.unit_id, r.arrival AS date_from, r.departure AS date_to, b.source FROM {$rooms} r JOIN {$bookings} b ON b.id = r.booking_id WHERE r.property_id = %d AND r.occupies = 1 AND r.unit_id IS NOT NULL AND r.departure > %s ORDER BY r.arrival", $property_id, $from ), ARRAY_A );
		$closed = (array) $wpdb->get_results( $wpdb->prepare( "SELECT unit_id, date_from, date_to, source FROM {$blocks} WHERE property_id = %d AND status = 'active' AND date_to > %s ORDER BY date_from", $property_id, $from ), ARRAY_A );
		// phpcs:enable
		foreach ( $stays as $row ) {
			$out[ (int) $row['unit_id'] ][] = array(
				'source' => 'import' === (string) $row['source'] ? 'channel' : 'direct',
				'from'   => (string) $row['date_from'],
				'to'     => (string) $row['date_to'],
			);
		}
		foreach ( $closed as $row ) {
			$out[ (int) $row['unit_id'] ][] = array(
				'source' => (string) $row['source'],
				'from'   => (string) $row['date_from'],
				'to'     => (string) $row['date_to'],
			);
		}
		foreach ( $out as &$list ) {
			usort( $list, static fn( $a, $b ) => strcmp( $a['from'], $b['from'] ) );
		}
		return $out;
	}

	/** Counts of active closures per feed of a property. @return array<int, int> */
	public function block_counts( int $property_id ): array {
		global $wpdb;
		$t = Schema::table( 'unit_blocks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT feed_id, COUNT(*) AS n FROM {$t} WHERE property_id = %d AND feed_id IS NOT NULL AND status = 'active' GROUP BY feed_id", $property_id ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['feed_id'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * A closure of a feed. Removed for good when the portal drops it (no row left behind
	 * at every synchronisation).
	 *
	 * @param array<string, mixed> $data
	 */
	public function create_block( array $data ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			Schema::table( 'unit_blocks' ),
			array(
				'property_id'  => (int) $data['property_id'],
				'unit_id'      => (int) $data['unit_id'],
				'room_type_id' => (int) $data['room_type_id'],
				'date_from'    => (string) $data['date_from'],
				'date_to'      => (string) $data['date_to'],
				'kind'         => 'channel',
				'source'       => (string) $data['source'],
				'reason'       => mb_substr( (string) $data['reason'], 0, 190 ),
				'feed_id'      => (int) $data['feed_id'],
				'external_uid' => mb_substr( (string) $data['external_uid'], 0, 190 ),
				'status'       => 'active',
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public function delete_block( int $block_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'unit_blocks' ), array( 'id' => $block_id ), array( '%d' ) );
	}

	/**
	 * Nights of a room already taken in [from, to), and by what: a stay, a closure, or a
	 * closure of the given feed.
	 *
	 * @return array<string, int|null> night => block id (null for a stay)
	 */
	public function taken_nights( int $unit_id, string $from, string $to ): array {
		global $wpdb;
		$t = Schema::table( 'unit_nights' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT night, block_id FROM {$t} WHERE unit_id = %d AND night >= %s AND night < %s", $unit_id, $from, $to ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['night'] ] = null === $row['block_id'] ? null : (int) $row['block_id'];
		}
		return $out;
	}

	/**
	 * Taken nights of several rooms in [from, to).
	 *
	 * @param int[] $unit_ids
	 * @return array<int, array<string, true>> unit id => night => true
	 */
	public function nights_of_units( array $unit_ids, string $from, string $to ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $unit_ids ) ) );
		$out = array_fill_keys( $ids, array() );
		if ( ! $ids ) {
			return $out;
		}
		$t = Schema::table( 'unit_nights' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name and integer list.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT unit_id, night FROM {$t} WHERE unit_id IN (" . implode( ',', $ids ) . ') AND night >= %s AND night < %s', $from, $to ), ARRAY_A );
		foreach ( $rows as $row ) {
			$out[ (int) $row['unit_id'] ][ (string) $row['night'] ] = true;
		}
		return $out;
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'ical_feeds' ), array( 'id' => $id ), array( '%d' ) );
	}

	/** Active calendars of a room with the same address. */
	public function url_taken( int $unit_id, string $url ): bool {
		global $wpdb;
		$t = Schema::table( 'ical_feeds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE unit_id = %d AND status = 'active' AND url_hash = %s LIMIT 1", $unit_id, self::hash( $url ) ) );
	}

	public function count_for_unit( int $unit_id ): int {
		global $wpdb;
		$t = Schema::table( 'ical_feeds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE unit_id = %d AND status = 'active'", $unit_id ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'property_id', 'unit_id', 'events', 'failures', 'empty_reads' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		return $row;
	}
}
