<?php
/**
 * Portal calendars imported for the rooms (bkng_ical_feeds) and the room closures they
 * wrote (unit_blocks with feed_id).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Ical;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class IcalFeedRepository {

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
				'url'         => (string) $data['url'],
				'status'      => 'active',
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
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'active' ORDER BY last_sync_at IS NOT NULL, last_sync_at, id LIMIT %d", $limit ) ) );
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
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE unit_id = %d AND status = 'active' AND url = %s LIMIT 1", $unit_id, $url ) );
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
		foreach ( array( 'id', 'property_id', 'unit_id', 'events' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		return $row;
	}
}
