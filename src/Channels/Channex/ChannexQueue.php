<?php
/**
 * The queue of what must be sent to Channex (bkng_cx_dirty): one row per kind, room
 * type, plan and night, whatever the number of changes (coalescing).
 *
 * A change marks the row, or bumps its dirty_seq when it is already there. The worker
 * takes a snapshot, reads the CURRENT values from the plugin, sends them and deletes
 * only the rows whose dirty_seq did not move in the meantime: a night changed again
 * while the call was running stays queued and goes with the next call.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class ChannexQueue {

	public const AVAILABILITY = 'availability';
	public const RESTRICTIONS = 'restrictions';

	/**
	 * Marks nights to send again.
	 *
	 * @param string[] $dates Y-m-d
	 */
	public function mark( int $property_id, string $kind, int $room_type_id, int $rate_plan_id, array $dates ): void {
		global $wpdb;
		$dates = array_values( array_unique( array_filter( $dates, static fn( $d ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) ) ) );
		if ( ! $dates || ! in_array( $kind, array( self::AVAILABILITY, self::RESTRICTIONS ), true ) ) {
			return;
		}
		$t   = Schema::table( 'cx_dirty' );
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( array_chunk( $dates, 500 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $date ) {
				// Integers, a fixed kind and dates checked above: nothing to escape.
				$values[] = sprintf( "('%s', %d, %d, '%s', %d, 1, '%s', '%s')", $kind, $room_type_id, $rate_plan_id, $date, $property_id, $now, $now );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- values built above from validated data.
			$wpdb->query( "INSERT INTO {$t} (kind, room_type_id, rate_plan_id, stay_date, property_id, dirty_seq, created_at, updated_at) VALUES " . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE dirty_seq = dirty_seq + 1, updated_at = VALUES(updated_at)' );
		}
	}

	/**
	 * The rows of a property and kind, oldest night first.
	 *
	 * @return array<int, array{room_type_id: int, rate_plan_id: int, stay_date: string, dirty_seq: int}>
	 */
	public function snapshot( int $property_id, string $kind, int $limit = 20000 ): array {
		global $wpdb;
		$t = Schema::table( 'cx_dirty' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT room_type_id, rate_plan_id, stay_date, dirty_seq FROM {$t} WHERE property_id = %d AND kind = %s ORDER BY room_type_id, rate_plan_id, stay_date LIMIT %d", $property_id, $kind, $limit ), ARRAY_A );
		return array_map(
			static fn( array $r ) => array(
				'room_type_id' => (int) $r['room_type_id'],
				'rate_plan_id' => (int) $r['rate_plan_id'],
				'stay_date'    => (string) $r['stay_date'],
				'dirty_seq'    => (int) $r['dirty_seq'],
			),
			$rows
		);
	}

	/**
	 * Deletes the rows sent, unless they changed after the snapshot.
	 *
	 * @param array<int, array{room_type_id: int, rate_plan_id: int, stay_date: string, dirty_seq: int}> $rows
	 */
	public function remove_sent( string $kind, array $rows ): void {
		global $wpdb;
		$t = Schema::table( 'cx_dirty' );
		foreach ( array_chunk( $rows, 300 ) as $chunk ) {
			$tuples = array();
			foreach ( $chunk as $r ) {
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $r['stay_date'] ) ) {
					continue;
				}
				$tuples[] = sprintf( "('%s', %d, %d, '%s', %d)", $kind === self::AVAILABILITY ? self::AVAILABILITY : self::RESTRICTIONS, $r['room_type_id'], $r['rate_plan_id'], $r['stay_date'], $r['dirty_seq'] );
			}
			if ( $tuples ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integers, fixed kind and checked dates.
				$wpdb->query( "DELETE FROM {$t} WHERE (kind, room_type_id, rate_plan_id, stay_date, dirty_seq) IN (" . implode( ',', $tuples ) . ')' );
			}
		}
	}

	/** Nights already gone (Channex refuses them) and rows of types or plans no longer linked. */
	public function drop( int $property_id, string $kind, string $before_date ): void {
		global $wpdb;
		$t = Schema::table( 'cx_dirty' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE property_id = %d AND kind = %s AND stay_date < %s", $property_id, $kind, $before_date ) );
	}

	/** Every row of a property (mapping removed). */
	public function clear( int $property_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'cx_dirty' ), array( 'property_id' => $property_id ), array( '%d' ) );
	}

	/** @return array{availability: int, restrictions: int} rows waiting */
	public function counts( int $property_id ): array {
		global $wpdb;
		$t    = Schema::table( 'cx_dirty' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT kind, COUNT(*) AS n FROM {$t} WHERE property_id = %d GROUP BY kind", $property_id ), ARRAY_A );
		$out  = array(
			self::AVAILABILITY => 0,
			self::RESTRICTIONS => 0,
		);
		foreach ( $rows as $row ) {
			$out[ (string) $row['kind'] ] = (int) $row['n'];
		}
		return $out;
	}
}
