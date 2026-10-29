<?php
/**
 * What each portal calendar said at its last good reading (bkng_ical_events), and which
 * nights each room's link told the portals were closed (bkng_ical_exports).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Ical;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class IcalEventRepository {

	/**
	 * The calendar's events become exactly these: new ones added, changed dates updated,
	 * the ones gone from the portal removed.
	 *
	 * @param array<string, mixed>                                                     $feed
	 * @param array<int, array{uid: string, from: string, to: string, summary: string}> $events
	 */
	public function replace( array $feed, array $events ): void {
		global $wpdb;
		$t    = Schema::table( 'ical_events' );
		$now  = gmdate( 'Y-m-d H:i:s' );
		$uids = array();
		foreach ( $events as $e ) {
			$uids[] = $e['uid'];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (feed_id, unit_id, property_id, uid, date_from, date_to, reservation, first_seen_at, last_seen_at) VALUES (%d, %d, %d, %s, %s, %s, %d, %s, %s) ON DUPLICATE KEY UPDATE date_from = VALUES(date_from), date_to = VALUES(date_to), reservation = VALUES(reservation), last_seen_at = VALUES(last_seen_at)", (int) $feed['id'], (int) $feed['unit_id'], (int) $feed['property_id'], $e['uid'], $e['from'], $e['to'], IcalCalendar::is_reservation( $e['summary'] ) ? 1 : 0, $now, $now ) );
		}
		if ( $uids ) {
			$marks = implode( ',', array_fill( 0, count( $uids ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- internal table name, placeholders built above.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE feed_id = %d AND uid NOT IN ({$marks})", array_merge( array( (int) $feed['id'] ), $uids ) ) );
		} else {
			$this->delete_for_feed( (int) $feed['id'] );
		}
	}

	public function delete_for_feed( int $feed_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'ical_events' ), array( 'feed_id' => $feed_id ), array( '%d' ) );
	}

	/**
	 * Events of some calendars still to come (ending after $today).
	 *
	 * @param int[] $feed_ids
	 * @return array<int, array{feed_id: int, uid: string, from: string, to: string, reservation: bool}>
	 */
	public function future( array $feed_ids, string $today ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $feed_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$t = Schema::table( 'ical_events' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name and integer list.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT feed_id, uid, date_from, date_to, reservation FROM {$t} WHERE feed_id IN (" . implode( ',', $ids ) . ') AND date_to > %s ORDER BY feed_id, date_from, id', $today ), ARRAY_A );
		return array_map(
			static fn( $r ) => array(
				'feed_id'     => (int) $r['feed_id'],
				'uid'         => (string) $r['uid'],
				'from'        => (string) $r['date_from'],
				'to'          => (string) $r['date_to'],
				'reservation' => (bool) (int) $r['reservation'],
			),
			$rows
		);
	}

	public function count_future( int $feed_id, string $today ): int {
		global $wpdb;
		$t = Schema::table( 'ical_events' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE feed_id = %d AND date_to > %s", $feed_id, $today ) );
	}

	/* ------------------------------------------------------------------ Exports */

	/**
	 * Records the nights a portal was just told are closed.
	 *
	 * @param string[] $nights
	 */
	public function exported( int $unit_id, array $nights, int $now ): void {
		global $wpdb;
		if ( ! $nights ) {
			return;
		}
		$t    = Schema::table( 'ical_exports' );
		$at   = gmdate( 'Y-m-d H:i:s', $now );
		$rows = array();
		foreach ( $nights as $night ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $night ) ) {
				// Integers and validated dates only: nothing to escape.
				$rows[] = sprintf( "(%d, '%s', '%s')", $unit_id, $night, $at );
			}
		}
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integers and validated dates only.
			$wpdb->query( "INSERT INTO {$t} (unit_id, night, exported_at) VALUES " . implode( ',', $chunk ) . ' ON DUPLICATE KEY UPDATE exported_at = VALUES(exported_at)' );
		}
	}

	/** @return array<string, true> nights of a room told closed to a portal since $since */
	public function exported_since( int $unit_id, int $since ): array {
		global $wpdb;
		$t = Schema::table( 'ical_exports' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$nights = (array) $wpdb->get_col( $wpdb->prepare( "SELECT night FROM {$t} WHERE unit_id = %d AND exported_at >= %s", $unit_id, gmdate( 'Y-m-d H:i:s', $since ) ) );
		return array_fill_keys( array_map( 'strval', $nights ), true );
	}

	/** Last time a portal read a room's link (null: never). */
	public function last_export( int $unit_id ): ?string {
		global $wpdb;
		$t = Schema::table( 'ical_exports' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$at = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(exported_at) FROM {$t} WHERE unit_id = %d", $unit_id ) );
		return $at ? (string) $at : null;
	}

	public function purge_exports( int $before ): void {
		global $wpdb;
		$t = Schema::table( 'ical_exports' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE exported_at < %s LIMIT 20000", gmdate( 'Y-m-d H:i:s', $before ) ) );
	}
}
