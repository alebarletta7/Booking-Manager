<?php
/**
 * What the calendar and the planning grid show: rooms by type, stays, out-of-service
 * periods and free rooms per type, for a range of nights; and the day view (arrivals,
 * departures, guests in house, bookings waiting for an answer).
 *
 * Read-only: moving a stay in the grid goes through BookingService::move_room().
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Calendar;

use BookingManager\Availability\AvailabilityService;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Database\Schema;
use BookingManager\Inventory\InventoryService;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Inventory\UnitRepository;

defined( 'ABSPATH' ) || exit;

class PlanningService {

	public function __construct(
		private RoomTypeRepository $types,
		private UnitRepository $units,
		private BookingRepository $bookings,
		private AvailabilityService $availability
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function planning( int $property_id, string $from, string $to ): array {
		$stays = array();
		foreach ( $this->bookings->stays_between( $property_id, $from, $to ) as $room ) {
			$stays[] = array(
				'booking_room_id' => $room['id'],
				'booking_id'      => $room['booking_id'],
				'booking_version' => $room['booking_version'],
				'code'            => (string) $room['code'],
				'status'          => (string) $room['status'],
				'payment_status'  => (string) $room['payment_status'],
				'source'          => (string) $room['source'],
				'name'            => trim( $room['first_name'] . ' ' . $room['last_name'] ),
				'room_type_id'    => $room['room_type_id'],
				'unit_id'         => $room['unit_id'],
				'arrival'         => $room['arrival'],
				'departure'       => $room['departure'],
				'adults'          => $room['adults'],
				'children'        => $room['children'],
				'stay_status'     => $room['stay_status'],
				'balance'         => $room['balance'],
				'currency'        => (string) $room['currency'],
			);
		}
		return array(
			'from'       => $from,
			'to'         => $to,
			'room_types' => array_map( array( InventoryService::class, 'type_payload' ), $this->types->for_property( $property_id ) ),
			'units'      => array_map( array( InventoryService::class, 'unit_payload' ), $this->units->for_property( $property_id ) ),
			'stays'      => $stays,
			'blocks'     => $this->units->blocks_between( $property_id, $from, $to ),
			'free'       => $this->availability->grid( $property_id, $from, $to ),
		);
	}

	/**
	 * The day at the reception.
	 *
	 * @return array<string, mixed>
	 */
	public function day( int $property_id, string $date ): array {
		global $wpdb;
		$rooms    = Schema::table( 'booking_rooms' );
		$bookings = Schema::table( 'bookings' );
		$units    = Schema::table( 'units' );
		$select   = "SELECT r.id AS booking_room_id, r.booking_id, r.unit_id, u.code AS unit_code, r.room_type_id, r.arrival, r.departure, r.adults, r.children, r.stay_status, r.checked_in_at, r.checked_out_at, b.code, b.status, b.payment_status, b.first_name, b.last_name, b.arrival_time, b.balance, b.currency, b.version FROM {$rooms} r JOIN {$bookings} b ON b.id = r.booking_id LEFT JOIN {$units} u ON u.id = r.unit_id WHERE r.property_id = %d AND r.occupies = 1";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal table names, values prepared.
		$arrivals   = (array) $wpdb->get_results( $wpdb->prepare( $select . ' AND r.arrival = %s ORDER BY b.arrival_time IS NULL, b.arrival_time, b.last_name', $property_id, $date ), ARRAY_A );
		$departures = (array) $wpdb->get_results( $wpdb->prepare( $select . ' AND r.departure = %s ORDER BY u.code, b.last_name', $property_id, $date ), ARRAY_A );
		$in_house   = (array) $wpdb->get_results( $wpdb->prepare( $select . " AND r.stay_status = 'in_house' ORDER BY u.code, b.last_name", $property_id ), ARRAY_A );
		$pending    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, code, status, first_name, last_name, arrival, departure, total, currency, option_expires_at, created_at, version FROM {$bookings} WHERE property_id = %d AND status IN (%s, %s) ORDER BY created_at LIMIT 100", $property_id, BookingStatus::PENDING, BookingStatus::OPTION ), ARRAY_A );
		// phpcs:enable
		return array(
			'date'       => $date,
			'arrivals'   => array_map( array( self::class, 'clean' ), $arrivals ),
			'departures' => array_map( array( self::class, 'clean' ), $departures ),
			'in_house'   => array_map( array( self::class, 'clean' ), $in_house ),
			'pending'    => array_map( array( self::class, 'clean' ), $pending ),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function clean( array $row ): array {
		foreach ( array( 'booking_room_id', 'booking_id', 'room_type_id', 'adults', 'children', 'balance', 'version', 'id', 'total' ) as $int ) {
			if ( isset( $row[ $int ] ) ) {
				$row[ $int ] = (int) $row[ $int ];
			}
		}
		if ( array_key_exists( 'unit_id', $row ) ) {
			$row['unit_id'] = null !== $row['unit_id'] ? (int) $row['unit_id'] : null;
		}
		$row['name'] = trim( $row['first_name'] . ' ' . $row['last_name'] );
		unset( $row['first_name'], $row['last_name'] );
		return $row;
	}
}
