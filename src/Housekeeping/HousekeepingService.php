<?php
/**
 * Housekeeping: the rooms board of the day and the change of a room's status.
 *
 * - The board shows no guest names and no amounts: only the room, its status and what
 *   happens today (arrival, departure, guests staying), which is what cleaning needs.
 * - A change is accepted only when the room is still in the status the operator saw
 *   (two phones on the same room: the second gets a conflict with the current status).
 * - When the property asks so, a room checked out becomes "da pulire" by itself.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Housekeeping;

use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Bookings\StayStatus;
use BookingManager\Core\Clock;
use BookingManager\Core\Dates;
use BookingManager\Core\Hooks;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Inventory\UnitRepository;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class HousekeepingService {

	public function __construct(
		private UnitRepository $units,
		private RoomTypeRepository $types,
		private BookingRepository $bookings,
		private PropertyRepository $properties,
		private Clock $clock
	) {}

	/**
	 * Rooms of a property with their status and today's movements.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function board( int $property_id ): array|\WP_Error {
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return new \WP_Error( 'bkng_property_not_found', __( 'Struttura non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$today    = $this->clock->today_in( (string) $property['timezone'] );
		$tomorrow = Dates::add_days( $today, 1 );
		$types    = array();
		foreach ( $this->types->for_property( $property_id ) as $type ) {
			$types[ (int) $type['id'] ] = (string) $type['name'];
		}
		// Stays touching today: arriving, staying, leaving.
		$by_unit = array();
		foreach ( $this->bookings->stays_between( $property_id, $today, $tomorrow ) as $stay ) {
			if ( $stay['unit_id'] ) {
				$by_unit[ (int) $stay['unit_id'] ][] = $stay;
			}
		}
		// Departures of today, also the ones already completed (rooms no longer occupied).
		foreach ( $this->bookings->stays_between( $property_id, Dates::add_days( $today, -1 ), $today, true ) as $stay ) {
			$gone = StayStatus::REMOVED === $stay['stay_status'] || in_array( $stay['status'], array( BookingStatus::CANCELLED, BookingStatus::NO_SHOW ), true );
			if ( $stay['unit_id'] && $stay['departure'] === $today && ! $gone ) {
				$by_unit[ (int) $stay['unit_id'] ][] = $stay;
			}
		}

		$rooms  = array();
		$counts = array_fill_keys( array_keys( OpStatus::labels() ), 0 );
		foreach ( $this->units->for_property( $property_id ) as $unit ) {
			$day = array(
				'arrival'   => false,
				'departure' => false,
				'staying'   => false,
				'in_house'  => false,
				'departed'  => false,
				'guests'    => 0,
			);
			foreach ( $by_unit[ (int) $unit['id'] ] ?? array() as $stay ) {
				if ( $stay['arrival'] === $today ) {
					$day['arrival'] = true;
					$day['guests']  = (int) $stay['adults'] + (int) $stay['children'];
				} elseif ( $stay['departure'] === $today ) {
					$day['departure'] = true;
					$day['departed']  = StayStatus::DEPARTED === $stay['stay_status'];
				} else {
					$day['staying'] = true;
					$day['guests']  = (int) $stay['adults'] + (int) $stay['children'];
				}
				if ( StayStatus::IN_HOUSE === $stay['stay_status'] ) {
					$day['in_house'] = true;
				}
			}
			$status = (string) $unit['op_status'];
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
			$rooms[] = array(
				'id'           => (int) $unit['id'],
				'code'         => (string) $unit['code'],
				'name'         => (string) $unit['name'],
				'floor'        => $unit['floor'],
				'room_type'    => $types[ (int) $unit['room_type_id'] ] ?? '',
				'op_status'    => $status,
				'op_label'     => OpStatus::label( $status ),
				'op_note'      => $unit['op_note'],
				'op_updated_at' => Clock::iso( $unit['op_updated_at'] ),
				'today'        => $day,
				'version'      => (int) $unit['version'],
			);
		}
		return array(
			'date'     => $today,
			'enabled'  => ! empty( $property['settings']['housekeeping_enabled'] ),
			'statuses' => OpStatus::labels(),
			'counts'   => $counts,
			'rooms'    => $rooms,
		);
	}

	/**
	 * Changes a room's status. $from is the status the operator saw.
	 *
	 * @return array<string, mixed>|\WP_Error the room
	 */
	public function set_status( int $unit_id, string $from, string $to, ?string $note, int $user_id ): array|\WP_Error {
		$unit = $this->units->find( $unit_id );
		if ( ! $unit || 'active' !== $unit['status'] ) {
			return new \WP_Error( 'bkng_unit_not_found', __( 'Camera non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		if ( ! OpStatus::exists( $to ) ) {
			return new \WP_Error( 'bkng_invalid_op_status', __( 'Stato della camera non valido.', 'booking-manager' ), array( 'status' => 400 ) );
		}
		$note = null === $note ? null : mb_substr( trim( sanitize_text_field( $note ) ), 0, 255 );
		$note = '' === $note ? null : $note;
		if ( $from === $to && $note === $unit['op_note'] ) {
			return $this->present( $unit );
		}
		if ( ! $this->units->set_op_status( $unit_id, $from, $to, $note, $user_id ) ) {
			$current = (string) $unit['op_status'];
			return new \WP_Error(
				'bkng_conflict',
				/* translators: %s: current status of the room. */
				sprintf( __( 'Lo stato della camera è cambiato nel frattempo: ora è «%s».', 'booking-manager' ), OpStatus::label( $current ) ),
				array(
					'status'  => 409,
					'current' => $current,
				)
			);
		}
		Hooks::dispatch(
			Hooks::UNIT_STATUS_CHANGED,
			$unit_id,
			array(
				'property_id' => (int) $unit['property_id'],
				'from'        => $from,
				'to'          => $to,
				'user_id'     => $user_id,
			)
		);
		return $this->present( (array) $this->units->find( $unit_id ) );
	}

	/**
	 * A room checked out becomes "da pulire" (property setting), whatever its status was.
	 *
	 * @param array<string, mixed> $context hook context: unit_id, property_id, user_id
	 */
	public function on_checkout( int $booking_room_id, array $context ): void {
		$unit_id = (int) ( $context['unit_id'] ?? 0 );
		if ( $unit_id <= 0 ) {
			return;
		}
		$property = $this->properties->find( (int) ( $context['property_id'] ?? 0 ) );
		if ( ! $property || empty( $property['settings']['housekeeping_enabled'] ) || empty( $property['settings']['dirty_on_checkout'] ) ) {
			return;
		}
		$unit = $this->units->find( $unit_id );
		if ( ! $unit || OpStatus::DIRTY === $unit['op_status'] ) {
			return;
		}
		$this->set_status( $unit_id, (string) $unit['op_status'], OpStatus::DIRTY, __( 'Check-out', 'booking-manager' ), (int) ( $context['user_id'] ?? 0 ) );
	}

	/**
	 * Latest changes of a room, with the operator's name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function history( int $unit_id ): array {
		$names = array();
		return array_map(
			static function ( array $r ) use ( &$names ): array {
				$uid = (int) $r['user_id'];
				if ( $uid && ! array_key_exists( $uid, $names ) ) {
					$user          = get_userdata( $uid );
					$names[ $uid ] = $user ? $user->display_name : null;
				}
				return array(
					'from'     => (string) $r['from_status'],
					'to'       => (string) $r['to_status'],
					'label'    => OpStatus::label( (string) $r['to_status'] ),
					'note'     => $r['note'],
					'operator' => $uid ? $names[ $uid ] : null,
					'at'       => Clock::iso( (string) $r['created_at'] ),
				);
			},
			$this->units->status_log( $unit_id )
		);
	}

	/**
	 * @param array<string, mixed> $unit
	 * @return array<string, mixed>
	 */
	private function present( array $unit ): array {
		return array(
			'id'            => (int) $unit['id'],
			'code'          => (string) $unit['code'],
			'op_status'     => (string) $unit['op_status'],
			'op_label'      => OpStatus::label( (string) $unit['op_status'] ),
			'op_note'       => $unit['op_note'],
			'op_updated_at' => Clock::iso( $unit['op_updated_at'] ),
			'version'       => (int) $unit['version'],
		);
	}
}
