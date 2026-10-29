<?php
/**
 * The figures of the dashboard for one property and day: arrivals, departures, guests in
 * house, bookings waiting, rooms occupied and free tonight, occupancy, future bookings,
 * balances to collect, rooms by operational status.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Reports;

use BookingManager\Bookings\BookingStatus;
use BookingManager\Core\Clock;
use BookingManager\Core\Dates;
use BookingManager\Database\Schema;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class DashboardService {

	public function __construct( private PropertyRepository $properties, private Clock $clock ) {}

	/**
	 * @return array<string, mixed>|null null when the property does not exist
	 */
	public function summary( int $property_id, ?string $date = null ): ?array {
		global $wpdb;
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return null;
		}
		$today    = $date ?? $this->clock->today_in( (string) $property['timezone'] );
		$rooms    = Schema::table( 'booking_rooms' );
		$bookings = Schema::table( 'bookings' );
		$units    = Schema::table( 'units' );
		$blocks   = Schema::table( 'unit_blocks' );
		$occupy   = "'" . implode( "','", array( BookingStatus::PENDING, BookingStatus::OPTION, BookingStatus::CONFIRMED, BookingStatus::IN_HOUSE ) ) . "'";
		$in30     = Dates::add_days( $today, 30 );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names and constant status list.
		$total_units = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$units} WHERE property_id = %d AND status = 'active'", $property_id ) );
		$arrivals    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rooms} WHERE property_id = %d AND occupies = 1 AND arrival = %s", $property_id, $today ) );
		$arrived     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rooms} WHERE property_id = %d AND occupies = 1 AND arrival = %s AND stay_status <> 'expected'", $property_id, $today ) );
		$departures  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rooms} WHERE property_id = %d AND departure = %s AND stay_status IN ('in_house','departed','expected') AND (occupies = 1 OR stay_status = 'departed')", $property_id, $today ) );
		$departed    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rooms} WHERE property_id = %d AND departure = %s AND stay_status = 'departed'", $property_id, $today ) );
		$guests_in   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(adults + children), 0) FROM {$rooms} WHERE property_id = %d AND stay_status = 'in_house'", $property_id ) );
		$pending     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bookings} WHERE property_id = %d AND status IN (%s, %s)", $property_id, BookingStatus::PENDING, BookingStatus::OPTION ) );
		$occupied    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rooms} WHERE property_id = %d AND occupies = 1 AND arrival <= %s AND departure > %s", $property_id, $today, $today ) );
		$blocked     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$blocks} b JOIN {$units} u ON u.id = b.unit_id AND u.status = 'active' WHERE b.property_id = %d AND b.status = 'active' AND b.date_from <= %s AND b.date_to > %s", $property_id, $today, $today ) );
		$sold_30     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(DATEDIFF(LEAST(departure, %s), GREATEST(arrival, %s))), 0) FROM {$rooms} WHERE property_id = %d AND occupies = 1 AND arrival < %s AND departure > %s", $in30, $today, $property_id, $in30, $today ) );
		$future      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bookings} WHERE property_id = %d AND status IN ({$occupy}) AND arrival > %s", $property_id, $today ) );
		$balance     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(balance), 0) FROM {$bookings} WHERE property_id = %d AND status IN ({$occupy}) AND balance > 0", $property_id ) );
		$balance_now = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(balance), 0) FROM {$bookings} WHERE property_id = %d AND status IN ({$occupy}) AND balance > 0 AND arrival <= %s", $property_id, Dates::add_days( $today, 7 ) ) );
		$deposits    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bookings} WHERE property_id = %d AND status IN ({$occupy}) AND deposit_due > paid - refunded AND deposit_due_date IS NOT NULL AND deposit_due_date <= %s", $property_id, $today ) );
		$unassigned  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rooms} WHERE property_id = %d AND occupies = 1 AND unit_id IS NULL AND arrival <= %s AND departure > %s", $property_id, Dates::add_days( $today, 7 ), $today ) );
		$ops         = (array) $wpdb->get_results( $wpdb->prepare( "SELECT op_status, COUNT(*) AS n FROM {$units} WHERE property_id = %d AND status = 'active' GROUP BY op_status", $property_id ), ARRAY_A );
		// phpcs:enable

		$sellable = max( 0, $total_units - $blocked );
		$ops_map  = array();
		foreach ( $ops as $row ) {
			$ops_map[ (string) $row['op_status'] ] = (int) $row['n'];
		}
		return array(
			'date'                 => $today,
			'currency'             => (string) $property['currency'],
			'arrivals'             => $arrivals,
			'arrivals_done'        => $arrived,
			'departures'           => $departures,
			'departures_done'      => $departed,
			'guests_in_house'      => $guests_in,
			'pending'              => $pending,
			'rooms_total'          => $total_units,
			'rooms_out_of_service' => $blocked,
			'rooms_occupied'       => $occupied,
			'rooms_free'           => max( 0, $sellable - $occupied ),
			'occupancy_today'      => $sellable > 0 ? round( 100 * $occupied / $sellable, 1 ) : 0.0,
			'occupancy_30_days'    => $total_units > 0 ? round( 100 * $sold_30 / ( $total_units * 30 ), 1 ) : 0.0,
			'future_bookings'      => $future,
			'balance_due'          => $balance,
			'balance_due_7_days'   => $balance_now,
			'deposits_overdue'     => $deposits,
			'unassigned_7_days'    => $unassigned,
			'rooms_by_status'      => $ops_map,
		);
	}
}
