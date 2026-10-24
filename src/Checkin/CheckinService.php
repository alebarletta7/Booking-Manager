<?php
/**
 * Check-in and check-out at the front desk: reads a QR or a typed booking code, says
 * what it is, and checks the rooms in or out.
 *
 * - The QR is verified on the server (signature, then qr_id in the database): a forged or
 *   regenerated QR is "invalid" without revealing anything.
 * - The booking must belong to the property the operator works at, be confirmed and
 *   arrive by today (the check-in is never recorded before the arrival day).
 * - Check-in and check-out are per room: a family arriving in two rounds, or a room
 *   leaving earlier, is recorded as it happens. The booking becomes «in soggiorno» with the
 *   first room and «conclusa» when the last room leaves.
 * - Rooms change with conditional updates: two phones scanning the same QR produce one
 *   check-in and one "already checked in" with time and operator.
 * - Every attempt, valid or not, is written to bkng_checkins.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Checkin;

use BookingManager\Audit\AuditLogger;
use BookingManager\Bookings\BookingCodes;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingService;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Bookings\StayStatus;
use BookingManager\Core\Clock;
use BookingManager\Core\Dates;
use BookingManager\Core\Money;
use BookingManager\Core\QrCode;
use BookingManager\Core\RateLimiter;
use BookingManager\Housekeeping\OpStatus;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Inventory\UnitRepository;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class CheckinService {

	/** Rooms checked in or out by this request. */
	public const OK = 'ok';
	/** Valid: rooms can be checked in, waiting for the operator's confirmation. */
	public const READY = 'ready';
	/** Every room arriving by today is already in house (check-out possible). */
	public const ALREADY        = 'already';
	public const PENDING        = 'pending';
	public const CANCELLED      = 'cancelled';
	public const NO_SHOW        = 'no_show';
	public const COMPLETED      = 'completed';
	public const TOO_EARLY      = 'too_early';
	public const PAST           = 'past';
	public const NOT_READY      = 'not_ready';
	public const WRONG_PROPERTY = 'wrong_property';
	public const INVALID        = 'invalid';

	public const METHOD_QR     = 'qr';
	public const METHOD_MANUAL = 'manual';
	public const METHOD_LIST   = 'list';

	/** Typed codes per operator per minute: enough for a busy desk, too few to guess codes. */
	private const MANUAL_LIMIT = 30;

	public function __construct(
		private BookingRepository $bookings,
		private BookingService $booking_service,
		private CheckinRepository $log,
		private PropertyRepository $properties,
		private RoomTypeRepository $types,
		private UnitRepository $units,
		private QrToken $qr,
		private AuditLogger $audit,
		private Clock $clock
	) {}

	/**
	 * A scanned QR or a typed code.
	 *
	 * @param array{immediate?: bool, can_view_payments?: bool} $opts immediate: check in at once when valid.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function scan( int $property_id, string $text, int $user_id, array $opts = array() ): array|\WP_Error {
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return self::property_not_found();
		}
		$text    = trim( $text );
		$is_qr   = QrToken::looks_like( $text );
		$method  = $is_qr ? self::METHOD_QR : self::METHOD_MANUAL;
		$booking = null;

		if ( $is_qr ) {
			$qr_id   = $this->qr->verify( $text );
			$booking = null === $qr_id ? null : $this->bookings->find_by_qr( $qr_id );
		} else {
			if ( ! RateLimiter::hit( 'checkin_manual', (string) $user_id, self::MANUAL_LIMIT, MINUTE_IN_SECONDS ) ) {
				return new \WP_Error( 'bkng_rate_limited', __( 'Troppi codici inseriti in poco tempo. Attendi un minuto.', 'booking-manager' ), array( 'status' => 429 ) );
			}
			$code    = BookingCodes::clean( $text );
			$booking = BookingCodes::looks_like_code( $code ) ? $this->bookings->find_by_code( $code ) : null;
		}

		if ( ! $booking ) {
			$this->log->log( $property_id, null, null, CheckinRepository::KIND_SCAN, $user_id, $method, self::INVALID );
			return $this->answer( self::INVALID, $property, null, $opts );
		}
		$result = $this->evaluate( $booking, $property );
		if ( self::READY === $result && ! empty( $opts['immediate'] ) ) {
			return $this->check_in( $property_id, (int) $booking['id'], $user_id, $opts + array( 'method' => $method ) );
		}
		$this->log->log( $property_id, (int) $booking['id'], null, CheckinRepository::KIND_SCAN, $user_id, $method, $result );
		return $this->answer( $result, $property, $booking, $opts );
	}

	/**
	 * The operator checks in the rooms of a booking found by scan() or picked from the list
	 * of the day: all the rooms arriving by today, or only $opts['room_ids'].
	 *
	 * @param array{method?: string, room_ids?: int[], ignore_dirty?: bool, version?: int, can_view_payments?: bool} $opts
	 *        ignore_dirty: give a room not cleaned yet (the caller checks the permission).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function check_in( int $property_id, int $booking_id, int $user_id, array $opts = array() ): array|\WP_Error {
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return self::property_not_found();
		}
		$method  = self::method( $opts['method'] ?? '' );
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking || (int) $booking['property_id'] !== $property_id ) {
			// Another property's booking is "not found" here as well.
			$this->log->log( $property_id, null, null, CheckinRepository::KIND_IN, $user_id, $method, self::INVALID );
			return $this->answer( self::INVALID, $property, null, $opts );
		}
		$result = $this->evaluate( $booking, $property );
		if ( self::READY !== $result ) {
			$this->log->log( $property_id, $booking_id, null, CheckinRepository::KIND_IN, $user_id, $method, $result );
			return $this->answer( $result, $property, $booking, $opts );
		}

		$before   = $this->stay_map( $booking_id );
		$room_ids = array_map( 'intval', (array) ( $opts['room_ids'] ?? array() ) );
		$common   = array(
			'user_id'      => $user_id,
			'ignore_dirty' => ! empty( $opts['ignore_dirty'] ),
		);
		$done     = BookingStatus::CONFIRMED === $booking['status']
			? $this->booking_service->set_status( $booking_id, (int) ( $opts['version'] ?? $booking['version'] ), BookingStatus::IN_HOUSE, $common + array( 'room_ids' => $room_ids ) )
			: $this->booking_service->stay_rooms( $booking_id, $room_ids, StayStatus::IN_HOUSE, $common );

		$fresh = (array) $this->bookings->find( $booking_id );
		if ( $done instanceof \WP_Error ) {
			if ( 'bkng_room_not_ready' === $done->get_error_code() ) {
				$this->log->log( $property_id, $booking_id, null, CheckinRepository::KIND_IN, $user_id, $method, self::NOT_READY );
				return $done;
			}
			// Another phone was faster, or the booking changed since it was read.
			$outcome = $this->evaluate( $fresh, $property );
			if ( self::READY === $outcome ) {
				return $done;
			}
			$this->log->log( $property_id, $booking_id, null, CheckinRepository::KIND_IN, $user_id, $method, $outcome );
			return $this->answer( $outcome, $property, $fresh, $opts );
		}
		$today = $this->clock->today_in( (string) $property['timezone'] );
		foreach ( $this->bookings->rooms( $booking_id, true ) as $room ) {
			if ( StayStatus::EXPECTED === ( $before[ (int) $room['id'] ] ?? '' ) && StayStatus::IN_HOUSE === $room['stay_status'] ) {
				$this->log->log( $property_id, $booking_id, (int) $room['id'], CheckinRepository::KIND_IN, $user_id, $method, self::OK, $room['arrival'] < $today ? 'late' : 'on_time' );
			}
		}
		return $this->answer( self::OK, $property, $fresh, $opts, StayStatus::IN_HOUSE );
	}

	/**
	 * Check-out of the rooms in house (all, or only $opts['room_ids']). The last room
	 * leaving completes the booking. The balance still due is shown, never blocking: the
	 * desk decides.
	 *
	 * @param array{method?: string, room_ids?: int[], version?: int, can_view_payments?: bool} $opts
	 * @return array<string, mixed>|\WP_Error
	 */
	public function check_out( int $property_id, int $booking_id, int $user_id, array $opts = array() ): array|\WP_Error {
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return self::property_not_found();
		}
		$method  = self::method( $opts['method'] ?? '' );
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking || (int) $booking['property_id'] !== $property_id ) {
			$this->log->log( $property_id, null, null, CheckinRepository::KIND_OUT, $user_id, $method, self::INVALID );
			return $this->answer( self::INVALID, $property, null, $opts );
		}
		if ( BookingStatus::IN_HOUSE !== $booking['status'] ) {
			$result = BookingStatus::COMPLETED === $booking['status'] ? self::COMPLETED : $this->evaluate( $booking, $property );
			$this->log->log( $property_id, $booking_id, null, CheckinRepository::KIND_OUT, $user_id, $method, $result );
			return $this->answer( $result, $property, $booking, $opts );
		}
		$before = $this->stay_map( $booking_id );
		$done   = $this->booking_service->stay_rooms(
			$booking_id,
			array_map( 'intval', (array) ( $opts['room_ids'] ?? array() ) ),
			StayStatus::DEPARTED,
			array(
				'user_id' => $user_id,
				'version' => (int) ( $opts['version'] ?? $booking['version'] ),
			)
		);
		$fresh = (array) $this->bookings->find( $booking_id );
		if ( $done instanceof \WP_Error ) {
			return $done;
		}
		$today = $this->clock->today_in( (string) $property['timezone'] );
		foreach ( $this->bookings->rooms( $booking_id, true ) as $room ) {
			if ( StayStatus::IN_HOUSE === ( $before[ (int) $room['id'] ] ?? '' ) && StayStatus::DEPARTED === $room['stay_status'] ) {
				$timing = $today < $room['departure'] ? 'early' : ( $today > $room['departure'] ? 'late' : 'on_time' );
				$this->log->log( $property_id, $booking_id, (int) $room['id'], CheckinRepository::KIND_OUT, $user_id, $method, self::OK, $timing );
			}
		}
		return $this->answer( self::OK, $property, $fresh, $opts, StayStatus::DEPARTED );
	}

	/**
	 * The front desk screen: arrivals, guests in house and departures of today, latest
	 * attempts.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function overview( int $property_id ): array|\WP_Error {
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return self::property_not_found();
		}
		$tz       = (string) $property['timezone'];
		$today    = $this->clock->today_in( $tz );
		$arrivals = array();
		$in_house = array();
		$leaving  = array();
		$units    = array();
		foreach ( $this->units->for_property( $property_id, true ) as $u ) {
			$units[ (int) $u['id'] ] = $u;
		}
		$row = static function ( array $s ) use ( $units ): array {
			$unit = $s['unit_id'] ? ( $units[ (int) $s['unit_id'] ] ?? null ) : null;
			return array(
				'booking_id'      => (int) $s['booking_id'],
				'booking_room_id' => (int) $s['id'],
				'code'            => (string) $s['code'],
				'name'            => trim( $s['first_name'] . ' ' . $s['last_name'] ),
				'status'          => (string) $s['status'],
				'stay_status'     => (string) $s['stay_status'],
				'arrival'         => (string) $s['arrival'],
				'departure'       => (string) $s['departure'],
				'guests'          => (int) $s['adults'] + (int) $s['children'],
				'unit'            => $unit ? array(
					'id'        => (int) $unit['id'],
					'code'      => (string) $unit['code'],
					'op_status' => (string) $unit['op_status'],
					'op_label'  => OpStatus::label( (string) $unit['op_status'] ),
				) : null,
				'version'         => (int) $s['booking_version'],
			);
		};
		foreach ( $this->bookings->stays_between( $property_id, $today, Dates::add_days( $today, 1 ) ) as $s ) {
			if ( in_array( $s['status'], array( BookingStatus::PENDING, BookingStatus::OPTION ), true ) ) {
				continue;
			}
			if ( StayStatus::IN_HOUSE === $s['stay_status'] ) {
				$in_house[] = $row( $s );
			} elseif ( StayStatus::EXPECTED === $s['stay_status'] && $s['arrival'] <= $today && BookingStatus::NO_SHOW !== $s['status'] ) {
				$arrivals[] = $row( $s );
			}
		}
		foreach ( $this->bookings->stays_between( $property_id, Dates::add_days( $today, -1 ), $today, true ) as $s ) {
			if ( $s['departure'] === $today && in_array( $s['stay_status'], array( StayStatus::IN_HOUSE, StayStatus::DEPARTED ), true ) ) {
				$leaving[] = $row( $s );
			}
		}
		foreach ( $in_house as $s ) {
			if ( $s['departure'] <= $today ) {
				$leaving[] = $s;
			}
		}
		$in_house = array_values( array_filter( $in_house, static fn( array $s ) => $s['departure'] > $today ) );

		$since  = Dates::local_midnight_utc( $today, $tz );
		$names  = array();
		$recent = array_map(
			function ( array $r ) use ( &$names, $tz ) {
				$uid = (int) $r['user_id'];
				if ( $uid && ! array_key_exists( $uid, $names ) ) {
					$names[ $uid ] = self::user_name( $uid );
				}
				return array(
					'id'         => (int) $r['id'],
					'kind'       => (string) $r['kind'],
					'result'     => (string) $r['result'],
					'label'      => self::label( (string) $r['result'] ),
					'method'     => (string) $r['method'],
					'timing'     => $r['timing'],
					'at'         => Clock::iso( (string) $r['created_at'] ),
					'local_time' => self::local_time( (string) $r['created_at'], $tz ),
					'operator'   => $uid ? $names[ $uid ] : null,
					'booking'    => empty( $r['code'] ) ? null : array(
						'id'   => (int) $r['booking_id'],
						'code' => (string) $r['code'],
						'name' => trim( $r['first_name'] . ' ' . $r['last_name'] ),
					),
				);
			},
			$this->log->recent( $property_id, $since, 30 )
		);
		return array(
			'date'       => $today,
			'counts'     => array(
				'arrivals_expected'   => count( $arrivals ),
				'in_house'            => count( $in_house ),
				'departures_expected' => count( array_filter( $leaving, static fn( array $s ) => StayStatus::IN_HOUSE === $s['stay_status'] ) ),
				'departures_done'     => count( array_filter( $leaving, static fn( array $s ) => StayStatus::DEPARTED === $s['stay_status'] ) ),
			),
			'arrivals'   => $arrivals,
			'in_house'   => $in_house,
			'departures' => $leaving,
			'recent'     => $recent,
		);
	}

	/**
	 * The QR of a booking (payload and SVG), for the desk or a reprint.
	 *
	 * @param array<string, mixed> $booking
	 * @return array<string, mixed>|\WP_Error
	 */
	public function qr_for( array $booking ): array|\WP_Error {
		if ( empty( $booking['qr_id'] ) || ! in_array( $booking['status'], array( BookingStatus::CONFIRMED, BookingStatus::IN_HOUSE ), true ) ) {
			return new \WP_Error( 'bkng_no_qr', __( 'Il QR esiste solo per le prenotazioni confermate.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$payload = $this->qr->payload( (string) $booking['qr_id'] );
		return array(
			'qr'         => $payload,
			'svg'        => QrCode::svg( $payload, (string) $booking['code'] ),
			'qr_version' => (int) $booking['qr_version'],
		);
	}

	/**
	 * New QR for a booking: the old one stops working at once (lost or forwarded e-mail).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function regenerate_qr( int $booking_id, int $user_id ): array|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return BookingService::not_found();
		}
		if ( ! in_array( $booking['status'], array( BookingStatus::CONFIRMED, BookingStatus::IN_HOUSE ), true ) ) {
			return new \WP_Error( 'bkng_no_qr', __( 'Il QR esiste solo per le prenotazioni confermate.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$ok = $this->bookings->update(
			$booking_id,
			(int) $booking['version'],
			array(
				'qr_id'      => BookingCodes::qr_id(),
				'qr_version' => (int) $booking['qr_version'] + 1,
				'updated_by' => $user_id ? $user_id : null,
			)
		);
		if ( ! $ok ) {
			return BookingService::conflict();
		}
		$this->audit->log(
			array(
				'action'      => 'booking.qr_regenerated',
				'entity_type' => 'booking',
				'entity_id'   => $booking_id,
				'property_id' => (int) $booking['property_id'],
				'new'         => array( 'qr_version' => (int) $booking['qr_version'] + 1 ),
			)
		);
		return $this->qr_for( (array) $this->bookings->find( $booking_id ) );
	}

	/**
	 * What a booking found at a property means for the desk (pure apart from the clock).
	 *
	 * @param array<string, mixed> $booking
	 * @param array<string, mixed> $property
	 */
	public function evaluate( array $booking, array $property ): string {
		if ( (int) $booking['property_id'] !== (int) $property['id'] ) {
			return self::WRONG_PROPERTY;
		}
		switch ( (string) $booking['status'] ) {
			case BookingStatus::PENDING:
			case BookingStatus::OPTION:
				return self::PENDING;
			case BookingStatus::CANCELLED:
				return self::CANCELLED;
			case BookingStatus::NO_SHOW:
				return self::NO_SHOW;
			case BookingStatus::COMPLETED:
				return self::COMPLETED;
		}
		$today = $this->clock->today_in( (string) $property['timezone'] );
		if ( BookingStatus::IN_HOUSE === $booking['status'] ) {
			foreach ( $this->bookings->rooms( (int) $booking['id'], true ) as $room ) {
				if ( StayStatus::EXPECTED === $room['stay_status'] && $room['arrival'] <= $today && $room['departure'] > $today ) {
					return self::READY;
				}
			}
			return self::ALREADY;
		}
		if ( (string) $booking['arrival'] > $today ) {
			return self::TOO_EARLY;
		}
		if ( (string) $booking['departure'] <= $today ) {
			return self::PAST;
		}
		return self::READY;
	}

	/** Italian label of a result, for the screen and the log. */
	public static function label( string $result ): string {
		return match ( $result ) {
			self::OK             => __( 'Fatto', 'booking-manager' ),
			self::READY          => __( 'Prenotazione valida', 'booking-manager' ),
			self::ALREADY        => __( 'Già in soggiorno', 'booking-manager' ),
			self::PENDING        => __( 'Da confermare', 'booking-manager' ),
			self::CANCELLED      => __( 'Prenotazione annullata', 'booking-manager' ),
			self::NO_SHOW        => __( 'Segnata come no-show', 'booking-manager' ),
			self::COMPLETED      => __( 'Soggiorno concluso', 'booking-manager' ),
			self::TOO_EARLY      => __( 'Arrivo in un altro giorno', 'booking-manager' ),
			self::PAST           => __( 'Soggiorno già passato', 'booking-manager' ),
			self::NOT_READY      => __( 'Camera non pronta', 'booking-manager' ),
			self::WRONG_PROPERTY => __( 'Prenotazione di un\'altra struttura', 'booking-manager' ),
			default              => __( 'Codice non valido', 'booking-manager' ),
		};
	}

	/* ------------------------------------------------------------------ Internals */

	/** @return array<int, string> booking_room_id => stay_status */
	private function stay_map( int $booking_id ): array {
		$out = array();
		foreach ( $this->bookings->rooms( $booking_id, true ) as $room ) {
			$out[ (int) $room['id'] ] = (string) $room['stay_status'];
		}
		return $out;
	}

	/**
	 * @param array<string, mixed>      $property
	 * @param array<string, mixed>|null $booking
	 * @param array<string, mixed>      $opts
	 * @param string|null               $done which move an OK refers to (in_house or departed)
	 * @return array<string, mixed>
	 */
	private function answer( string $result, array $property, ?array $booking, array $opts, ?string $done = null ): array {
		$tz  = (string) $property['timezone'];
		$out = array(
			'result'       => $result,
			'label'        => self::label( $result ),
			'message'      => $this->message( $result, $booking, $tz, $done ),
			'can_checkin'  => self::READY === $result,
			'can_checkout' => false,
			'booking'      => null,
		);
		// Another property's booking: nothing about it is shown here.
		if ( ! $booking || self::WRONG_PROPERTY === $result || self::INVALID === $result ) {
			return $out;
		}
		$rooms = $this->bookings->rooms( (int) $booking['id'], true );
		$types = $this->types->find_many( array_column( $rooms, 'room_type_id' ) );
		$list  = array();
		foreach ( $rooms as $room ) {
			$unit   = $room['unit_id'] ? $this->units->find( (int) $room['unit_id'] ) : null;
			$list[] = array(
				'id'             => (int) $room['id'],
				'room_type'      => isset( $types[ $room['room_type_id'] ] ) ? (string) $types[ $room['room_type_id'] ]['name'] : '',
				'unit'           => $unit ? array(
					'id'        => (int) $unit['id'],
					'code'      => (string) $unit['code'],
					'name'      => (string) $unit['name'],
					'op_status' => (string) $unit['op_status'],
					'op_label'  => OpStatus::label( (string) $unit['op_status'] ),
				) : null,
				'arrival'        => (string) $room['arrival'],
				'departure'      => (string) $room['departure'],
				'adults'         => (int) $room['adults'],
				'children'       => (int) $room['children'],
				'stay_status'    => (string) $room['stay_status'],
				'stay_label'     => StayStatus::label( (string) $room['stay_status'] ),
				'checked_in_at'  => Clock::iso( $room['checked_in_at'] ),
				'checked_in_by'  => empty( $room['checked_in_by'] ) ? null : self::user_name( (int) $room['checked_in_by'] ),
				'checked_out_at' => Clock::iso( $room['checked_out_at'] ),
			);
			if ( StayStatus::IN_HOUSE === $room['stay_status'] ) {
				$out['can_checkout'] = true;
			}
		}
		$out['booking'] = array(
			'id'             => (int) $booking['id'],
			'code'           => (string) $booking['code'],
			'name'           => trim( $booking['first_name'] . ' ' . $booking['last_name'] ),
			'arrival'        => (string) $booking['arrival'],
			'departure'      => (string) $booking['departure'],
			'nights'         => (int) $booking['nights'],
			'adults'         => (int) $booking['adults'],
			'children'       => (int) $booking['children'],
			'status'         => (string) $booking['status'],
			'status_label'   => BookingStatus::label( (string) $booking['status'] ),
			'arrival_time'   => $booking['arrival_time'],
			'customer_notes' => $booking['customer_notes'],
			'internal_notes' => $booking['internal_notes'],
			'rooms'          => $list,
			'version'        => (int) $booking['version'],
		);
		if ( ! empty( $opts['can_view_payments'] ) ) {
			$out['booking']['balance']         = (int) $booking['balance'];
			$out['booking']['balance_label']   = Money::format( (int) $booking['balance'], (string) $booking['currency'] );
			$out['booking']['payment_status']  = (string) $booking['payment_status'];
		}
		return $out;
	}

	/** @param array<string, mixed>|null $booking */
	private function message( string $result, ?array $booking, string $tz, ?string $done ): string {
		if ( self::OK === $result ) {
			if ( StayStatus::DEPARTED === $done ) {
				return $booking && BookingStatus::COMPLETED === $booking['status']
					? __( 'Check-out registrato: il soggiorno è concluso.', 'booking-manager' )
					: __( 'Check-out registrato per le camere indicate.', 'booking-manager' );
			}
			return __( 'Check-in registrato: buon soggiorno!', 'booking-manager' );
		}
		if ( self::ALREADY === $result && $booking ) {
			foreach ( $this->bookings->rooms( (int) $booking['id'], true ) as $room ) {
				if ( ! empty( $room['checked_in_at'] ) ) {
					$who = empty( $room['checked_in_by'] ) ? null : self::user_name( (int) $room['checked_in_by'] );
					return $who
						/* translators: 1: time, 2: operator name. */
						? sprintf( __( 'Check-in già registrato alle %1$s da %2$s.', 'booking-manager' ), self::local_time( (string) $room['checked_in_at'], $tz ), $who )
						/* translators: %s: time. */
						: sprintf( __( 'Check-in già registrato alle %s.', 'booking-manager' ), self::local_time( (string) $room['checked_in_at'], $tz ) );
				}
			}
		}
		if ( self::TOO_EARLY === $result && $booking ) {
			/* translators: %s: arrival date. */
			return sprintf( __( 'L\'arrivo è previsto il %s: il check-in si registra dal giorno di arrivo.', 'booking-manager' ), self::date_label( (string) $booking['arrival'] ) );
		}
		if ( self::PAST === $result && $booking ) {
			/* translators: %s: departure date. */
			return sprintf( __( 'Il soggiorno è terminato il %s senza check-in: valuta il no-show.', 'booking-manager' ), self::date_label( (string) $booking['departure'] ) );
		}
		return match ( $result ) {
			self::READY          => __( 'Controlla nome, ospiti e camere, poi conferma il check-in.', 'booking-manager' ),
			self::PENDING        => __( 'La prenotazione non è ancora confermata: confermala prima del check-in.', 'booking-manager' ),
			self::CANCELLED      => __( 'La prenotazione è stata annullata.', 'booking-manager' ),
			self::NO_SHOW        => __( 'La prenotazione è segnata come no-show. Un responsabile può ripristinarla.', 'booking-manager' ),
			self::COMPLETED      => __( 'Il soggiorno è già concluso.', 'booking-manager' ),
			self::ALREADY        => __( 'Gli ospiti sono già in soggiorno.', 'booking-manager' ),
			self::WRONG_PROPERTY => __( 'Questo codice appartiene a un\'altra struttura.', 'booking-manager' ),
			default              => __( 'QR o codice non riconosciuto. Il QR potrebbe essere stato rigenerato: cerca l\'ospite per nome.', 'booking-manager' ),
		};
	}

	private static function method( string $method ): string {
		return in_array( $method, array( self::METHOD_QR, self::METHOD_MANUAL, self::METHOD_LIST ), true ) ? $method : self::METHOD_LIST;
	}

	private static function date_label( string $date ): string {
		return wp_date( 'j F Y', strtotime( $date . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
	}

	public static function local_time( string $utc, string $tz ): string {
		try {
			return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( $tz ) )->format( 'H:i' );
		} catch ( \Exception $e ) {
			return substr( $utc, 11, 5 );
		}
	}

	private static function user_name( int $user_id ): ?string {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : null;
	}

	private static function property_not_found(): \WP_Error {
		return new \WP_Error( 'bkng_property_not_found', __( 'Struttura non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
	}
}
