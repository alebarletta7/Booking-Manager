<?php
/**
 * Register of check-in and check-out attempts: every scan or typed code, valid or not,
 * and every room checked in or out, with its result.
 *
 * It stores no personal data, only ids, the operator and the outcome, so it can be kept
 * longer than the guests' data.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Checkin;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class CheckinRepository {

	public const KIND_SCAN = 'scan';
	public const KIND_IN   = 'in';
	public const KIND_OUT  = 'out';

	public function log( ?int $property_id, ?int $booking_id, ?int $booking_room_id, string $kind, int $user_id, string $method, string $result, ?string $timing = null ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			Schema::table( 'checkins' ),
			array(
				'property_id'     => $property_id ? $property_id : null,
				'booking_id'      => $booking_id ? $booking_id : null,
				'booking_room_id' => $booking_room_id ? $booking_room_id : null,
				'kind'            => substr( $kind, 0, 10 ),
				'method'          => substr( $method, 0, 10 ),
				'result'          => substr( $result, 0, 20 ),
				'timing'          => null === $timing ? null : substr( $timing, 0, 10 ),
				'user_id'         => $user_id ? $user_id : null,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Latest attempts at a property since a UTC moment, with the booking's code and name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $property_id, string $since_utc, int $limit = 30 ): array {
		global $wpdb;
		$c = Schema::table( 'checkins' );
		$b = Schema::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.*, b.code, b.first_name, b.last_name FROM {$c} c LEFT JOIN {$b} b ON b.id = c.booking_id WHERE c.property_id = %d AND c.created_at >= %s ORDER BY c.id DESC LIMIT %d", $property_id, $since_utc, max( 1, min( 200, $limit ) ) ), ARRAY_A );
	}

	/** @return array<int, array<string, mixed>> */
	public function for_booking( int $booking_id ): array {
		global $wpdb;
		$c = Schema::table( 'checkins' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$c} WHERE booking_id = %d ORDER BY id DESC LIMIT 100", $booking_id ), ARRAY_A );
	}

	/** Deletes attempts older than $months months (0 = keep everything). */
	public function purge( int $months, int $now ): int {
		global $wpdb;
		if ( $months <= 0 ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months', $now ) );
		$c      = Schema::table( 'checkins' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$c} WHERE created_at < %s LIMIT 5000", $cutoff ) );
	}
}
