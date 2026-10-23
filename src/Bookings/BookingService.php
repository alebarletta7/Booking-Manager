<?php
/**
 * Bookings: creation (staff, public form, import), changes of dates and rooms, guests,
 * status changes, room assignment and moves in the planning.
 *
 * Every write that touches availability runs in one InnoDB transaction:
 * 1. the booking row (when it exists) and then the room types are locked FOR UPDATE;
 * 2. free rooms are counted (Ledger) and the stay rules checked;
 * 3. the stays are written and their rooms' nights inserted in unit_nights, where the
 *    primary key refuses a second stay in the same room and night.
 * Any failure throws Abort: nothing is half-written. Hooks and notifications run only
 * after the commit, and a failing listener never undoes a booking.
 *
 * The commercial status (BookingStatus), the payment status (Payments) and the stay of
 * each room (check-in/out) are kept apart.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Bookings;

use BookingManager\Audit\AuditLogger;
use BookingManager\Availability\HoldService;
use BookingManager\Availability\Ledger;
use BookingManager\Core\Clock;
use BookingManager\Core\Dates;
use BookingManager\Core\Hooks;
use BookingManager\Core\Validation;
use BookingManager\Database\Abort;
use BookingManager\Guests\GuestRepository;
use BookingManager\Guests\GuestService;
use BookingManager\Licensing\LicenseGate;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Properties\PropertyService;

defined( 'ABSPATH' ) || exit;

class BookingService {

	public const SOURCES = array( 'manual', 'public_form', 'api', 'import' );

	public function __construct(
		private BookingRepository $repo,
		private PropertyRepository $properties,
		private StayBuilder $stays,
		private Ledger $ledger,
		private HoldService $holds,
		private GuestService $guests,
		private GuestRepository $guest_repo,
		private AuditLogger $audit,
		private Clock $clock,
		private LicenseGate $license
	) {}

	/**
	 * Creates a booking.
	 *
	 * @param array<string, mixed> $input property_id, rooms[], guest{}, guests[], status, extras[], customer_notes, internal_notes, arrival_time, idempotency_key, hold_token, override_restrictions, external_source, external_ref, privacy_accepted, marketing_consent
	 * @param array{channel?: string, user_id?: int, can_override?: bool, can_price?: bool} $ctx channel: staff | online | import
	 * @return array<string, mixed>|\WP_Error the booking payload
	 */
	public function create( array $input, array $ctx ): array|\WP_Error {
		$channel = (string) ( $ctx['channel'] ?? 'staff' );
		$online  = 'online' === $channel;
		$user_id = (int) ( $ctx['user_id'] ?? 0 );

		if ( ! $this->license->allows( $online ? LicenseGate::OP_BOOKING_ONLINE : LicenseGate::OP_BOOKING_STAFF ) ) {
			return new \WP_Error( 'bkng_license_required', $online ? __( 'Le prenotazioni online non sono disponibili in questo momento. Contatta la struttura.', 'booking-manager' ) : __( 'La licenza non permette di registrare nuove prenotazioni.', 'booking-manager' ), array( 'status' => 403 ) );
		}

		$property = $this->properties->find( (int) ( $input['property_id'] ?? 0 ) );
		if ( ! $property || 'active' !== $property['status'] ) {
			return PropertyService::not_found();
		}

		$key = isset( $input['idempotency_key'] ) ? substr( preg_replace( '/[^A-Za-z0-9_\-:.]/', '', (string) $input['idempotency_key'] ), 0, 64 ) : '';
		if ( '' !== $key ) {
			$existing = $this->repo->find_by_idempotency( $key );
			if ( $existing ) {
				// The same request sent twice (double click, network retry): same booking.
				return (int) $existing['property_id'] === (int) $property['id'] ? $this->payload( $existing ) : new \WP_Error( 'bkng_idempotency_conflict', __( 'Richiesta duplicata non valida.', 'booking-manager' ), array( 'status' => 409 ) );
			}
		}

		$status = (string) ( $input['status'] ?? '' );
		if ( $online ) {
			$status = 'direct' === $property['booking_mode'] ? BookingStatus::CONFIRMED : BookingStatus::PENDING;
		} elseif ( '' === $status ) {
			$status = BookingStatus::CONFIRMED;
		}
		if ( ! BookingStatus::initial( $status ) ) {
			return Validation::error( array( 'status' => __( 'Stato iniziale non valido.', 'booking-manager' ) ) );
		}

		$source = $online ? 'public_form' : ( 'import' === $channel ? 'import' : (string) ( $input['source'] ?? 'manual' ) );
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			$source = 'manual';
		}

		$guest = $this->guests->validate( is_array( $input['guest'] ?? null ) ? $input['guest'] : array(), $online );
		if ( $guest instanceof \WP_Error ) {
			return $guest;
		}
		if ( $online && empty( $input['privacy_accepted'] ) ) {
			return Validation::error( array( 'privacy_accepted' => __( 'Per prenotare devi accettare l\'informativa sulla privacy.', 'booking-manager' ) ) );
		}

		$lines = $this->stays->normalize(
			$property,
			self::rooms_input( $input ),
			array(
				'online'             => $online,
				'allow_manual_price' => ! $online && ! empty( $ctx['can_price'] ),
			)
		);
		if ( $lines instanceof \WP_Error ) {
			return $lines;
		}

		$override = ! $online && ! empty( $input['override_restrictions'] ) && ! empty( $ctx['can_override'] );
		if ( ! $override ) {
			$rule_errors = $this->stays->rule_errors( $property, $lines, $online );
			if ( $rule_errors ) {
				return Validation::error( $rule_errors );
			}
		}

		$extras  = array_values( array_filter( array_map( 'intval', (array) ( $input['extras'] ?? array() ) ) ) );
		$hold    = null;
		$hold_id = 0;
		if ( ! empty( $input['hold_token'] ) ) {
			$hold = $this->holds->find_active( (string) $input['hold_token'] );
			if ( $hold && (int) $hold['property_id'] === (int) $property['id'] ) {
				$hold_id = (int) $hold['id'];
			}
		}

		$option_hours = (int) $property['settings']['option_hours'];
		$result       = Abort::run(
			function () use ( $property, $lines, $guest, $input, $status, $source, $extras, $key, $hold_id, $user_id, $online, $option_hours, $channel ) {
				$this->ledger->lock_types( array_column( $lines, 'room_type_id' ) );
				$free = $this->stays->check_free( $lines, array(), $hold_id );
				if ( $free instanceof \WP_Error ) {
					throw new Abort( $free );
				}
				$priced   = $this->stays->price( $property, $lines, $extras );
				$guest_id = $this->guests->find_or_create( $guest );
				$first    = $lines[0];
				$arrival  = min( array_column( $lines, 'arrival' ) );
				$depart   = max( array_column( $lines, 'departure' ) );
				$row      = array(
					'uuid'                => wp_generate_uuid4(),
					'code'                => $this->unique_code(),
					'property_id'         => (int) $property['id'],
					'status'              => $status,
					'payment_status'      => 'unpaid',
					'source'              => $source,
					'guest_id'            => $guest_id,
					'first_name'          => $guest['first_name'],
					'last_name'           => $guest['last_name'],
					'email'               => $guest['email'],
					'phone'               => $guest['phone'],
					'language'            => $guest['language'] ?? null,
					'arrival'             => $arrival,
					'departure'           => $depart,
					'nights'              => Dates::count( $arrival, $depart ),
					'adults'              => array_sum( array_column( $lines, 'adults' ) ),
					'children'            => array_sum( array_column( $lines, 'children' ) ),
					'currency'            => (string) $property['currency'],
					'total'               => $priced['total'],
					'taxes_total'         => $priced['taxes'],
					'deposit_due'         => $priced['deposit'],
					'deposit_due_date'    => $priced['deposit_due_date'],
					'paid'                => 0,
					'refunded'            => 0,
					'balance'             => $priced['total'],
					'price_snapshot'      => $priced['price_snapshot'],
					'terms_snapshot'      => $priced['terms_snapshot'],
					'extras'              => $extras,
					'customer_notes'      => self::text( $input['customer_notes'] ?? null, 2000 ),
					'internal_notes'      => $online ? null : self::text( $input['internal_notes'] ?? null, 4000 ),
					'arrival_time'        => self::arrival_time( $input['arrival_time'] ?? null ),
					'option_expires_at'   => BookingStatus::OPTION === $status ? gmdate( 'Y-m-d H:i:s', time() + $option_hours * HOUR_IN_SECONDS ) : null,
					'external_source'     => 'import' === $channel ? self::text( $input['external_source'] ?? null, 60 ) : null,
					'external_ref'        => 'import' === $channel ? self::text( $input['external_ref'] ?? null, 190 ) : null,
					'idempotency_key'     => '' !== $key ? $key : null,
					'qr_id'               => BookingCodes::qr_id(),
					'qr_version'          => 1,
					'privacy_accepted_at' => ! empty( $input['privacy_accepted'] ) ? gmdate( 'Y-m-d H:i:s' ) : null,
					'confirmed_at'        => BookingStatus::CONFIRMED === $status ? gmdate( 'Y-m-d H:i:s' ) : null,
					'confirmed_by'        => BookingStatus::CONFIRMED === $status && $user_id ? $user_id : null,
					'created_by'          => $user_id ? $user_id : null,
				);
				$id = $this->repo->insert( $row );
				if ( $id <= 0 ) {
					throw new Abort( new \WP_Error( 'bkng_db_error', __( 'Non è stato possibile salvare la prenotazione.', 'booking-manager' ), array( 'status' => 500 ) ) );
				}
				$written = $this->write_rooms( $id, (int) $property['id'], $priced['lines'], (bool) $property['settings']['auto_assign_unit'] );
				$this->repo->replace_guests( $id, self::guest_list( $input, $guest, $guest_id, $written ) );
				if ( $hold_id ) {
					$this->holds->convert( $hold_id, $id );
				}
				if ( ! empty( $input['privacy_accepted'] ) ) {
					$this->guest_repo->add_consent(
						array(
							'guest_id'    => $guest_id,
							'property_id' => (int) $property['id'],
							'purpose'     => 'privacy',
							'granted'     => true,
							'source'      => $online ? 'online' : 'staff',
							'booking_id'  => $id,
						)
					);
				}
				if ( array_key_exists( 'marketing_consent', $input ) && ( $online ? ! empty( $property['settings']['marketing_consent'] ) : true ) ) {
					$this->guest_repo->add_consent(
						array(
							'guest_id'    => $guest_id,
							'property_id' => (int) $property['id'],
							'purpose'     => 'marketing',
							'granted'     => ! empty( $input['marketing_consent'] ),
							'source'      => $online ? 'online' : 'staff',
							'booking_id'  => $id,
						)
					);
				}
				return $id;
			}
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$booking = (array) $this->repo->find( (int) $result );
		$this->guest_repo->refresh_stats( (int) $booking['guest_id'] );
		$this->audit->log(
			array(
				'action'      => 'booking.created',
				'entity_type' => 'booking',
				'entity_id'   => (int) $booking['id'],
				'property_id' => (int) $property['id'],
				'actor_type'  => $online ? 'guest' : null,
				'new'         => array(
					'code'      => $booking['code'],
					'status'    => $booking['status'],
					'source'    => $booking['source'],
					'arrival'   => $booking['arrival'],
					'departure' => $booking['departure'],
					'rooms'     => count( $lines ),
					'total'     => $booking['total'],
					'override'  => $override,
				),
			)
		);
		Hooks::dispatch( Hooks::BOOKING_CREATED, (int) $booking['id'], $this->context( $booking ) );
		if ( BookingStatus::CONFIRMED === $booking['status'] ) {
			Hooks::dispatch( Hooks::BOOKING_CONFIRMED, (int) $booking['id'], $this->context( $booking ) );
		}
		return $this->payload( $booking );
	}

	/**
	 * Changes dates, rooms and guests per room of a booking. The rooms sent replace the
	 * current ones (a line with "id" keeps its check-in data); the price is computed again
	 * unless keep_price is set (then the total stays as it was).
	 *
	 * @param array<string, mixed> $input version, rooms[], extras[], keep_price, override_restrictions
	 * @param array{user_id?: int, can_override?: bool, can_price?: bool} $ctx
	 * @return array<string, mixed>|\WP_Error
	 */
	public function change_stay( int $id, array $input, array $ctx ): array|\WP_Error {
		$booking = $this->repo->find( $id );
		if ( ! $booking ) {
			return self::not_found();
		}
		if ( ! BookingStatus::editable( $booking['status'] ) ) {
			return new \WP_Error( 'bkng_not_editable', __( 'Questa prenotazione non si può più modificare.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$version = (int) ( $input['version'] ?? 0 );
		if ( $version !== (int) $booking['version'] ) {
			return self::conflict();
		}
		$property = (array) $this->properties->find( (int) $booking['property_id'] );
		$lines    = $this->stays->normalize( $property, self::rooms_input( $input ), array( 'allow_manual_price' => ! empty( $ctx['can_price'] ) ) );
		if ( $lines instanceof \WP_Error ) {
			return $lines;
		}
		$current = $this->repo->rooms( $id, true );
		$by_id   = array();
		foreach ( $current as $room ) {
			$by_id[ $room['id'] ] = $room;
		}
		foreach ( $lines as $line ) {
			if ( $line['id'] && ! isset( $by_id[ $line['id'] ] ) ) {
				return Validation::error( array( 'rooms' => __( 'Una delle camere non appartiene a questa prenotazione.', 'booking-manager' ) ) );
			}
		}
		$override = ! empty( $input['override_restrictions'] ) && ! empty( $ctx['can_override'] );
		if ( ! $override ) {
			// Rules are checked only for what changes; a stay already started (arrival in the
			// past, unchanged) can still be extended or shortened by the staff.
			$today   = $this->clock->today_in( (string) $property['timezone'] );
			$changed = array_values(
				array_filter(
					$lines,
					static function ( array $l ) use ( $by_id, $today ): bool {
						if ( ! $l['id'] ) {
							return true;
						}
						$old = $by_id[ $l['id'] ];
						if ( $old['arrival'] === $l['arrival'] && $l['arrival'] < $today ) {
							return false;
						}
						return $old['arrival'] !== $l['arrival'] || $old['departure'] !== $l['departure'] || $old['room_type_id'] !== $l['room_type_id'];
					}
				)
			);
			$rule_errors = $this->stays->rule_errors( $property, $changed, false );
			if ( $rule_errors ) {
				return Validation::error( $rule_errors );
			}
		}
		$extras  = array_key_exists( 'extras', $input ) ? array_values( array_filter( array_map( 'intval', (array) $input['extras'] ) ) ) : array_map( 'intval', (array) $booking['extras'] );
		$keep    = ! empty( $input['keep_price'] );
		$user_id = (int) ( $ctx['user_id'] ?? 0 );

		$result = Abort::run(
			function () use ( $id, $version, $property, $lines, $current, $by_id, $extras, $keep, $user_id ) {
				$locked = $this->repo->lock( $id );
				if ( ! $locked || (int) $locked['version'] !== $version ) {
					throw new Abort( self::conflict() );
				}
				$types = array_merge( array_column( $lines, 'room_type_id' ), array_column( $current, 'room_type_id' ) );
				$this->ledger->lock_types( $types );
				$exclude = array_column( $current, 'id' );
				$free    = $this->stays->check_free( $lines, $exclude );
				if ( $free instanceof \WP_Error ) {
					throw new Abort( $free );
				}
				foreach ( $current as $room ) {
					$this->ledger->release_room( (int) $room['id'] );
				}
				// Rooms of the new list keep their unit when it is still free for their dates.
				foreach ( $lines as $i => $line ) {
					if ( null === $line['unit_id'] && $line['id'] && null !== $by_id[ $line['id'] ]['unit_id'] && $by_id[ $line['id'] ]['room_type_id'] === $line['room_type_id'] ) {
						$lines[ $i ]['unit_id'] = $by_id[ $line['id'] ]['unit_id'];
					}
				}
				$priced = $this->stays->price( $property, $lines, $extras );
				$kept   = array();
				foreach ( $priced['lines'] as $i => $line ) {
					$row = array(
						'room_type_id' => $line['room_type_id'],
						'unit_id'      => null,
						'rate_plan_id' => $line['rate_plan_id'],
						'arrival'      => $line['arrival'],
						'departure'    => $line['departure'],
						'adults'       => $line['adults'],
						'children'     => $line['children'],
						'price_total'  => $line['price_total'],
						'nightly'      => $line['quote']['nightly'],
						'occupies'     => 1,
						'sort_order'   => $i,
					);
					if ( $line['id'] ) {
						$this->repo->update_room( $line['id'], $row );
						$priced['lines'][ $i ]['booking_room_id'] = $line['id'];
						$kept[]                                   = $line['id'];
					} else {
						$priced['lines'][ $i ]['booking_room_id'] = $this->repo->insert_room(
							$row + array(
								'booking_id'  => $id,
								'property_id' => (int) $property['id'],
							)
						);
					}
				}
				foreach ( $current as $room ) {
					if ( ! in_array( $room['id'], $kept, true ) ) {
						$this->repo->update_room(
							(int) $room['id'],
							array(
								'occupies'    => 0,
								'stay_status' => 'removed',
							)
						);
					}
				}
				$occupied = $this->stays->occupy( (int) $property['id'], $priced['lines'], (bool) $property['settings']['auto_assign_unit'] );
				if ( $occupied instanceof \WP_Error ) {
					throw new Abort( $occupied );
				}
				foreach ( $occupied as $line ) {
					$this->repo->update_room( (int) $line['booking_room_id'], array( 'unit_id' => $line['unit_id'] ) );
				}
				$arrival = min( array_column( $lines, 'arrival' ) );
				$depart  = max( array_column( $lines, 'departure' ) );
				$changes = array(
					'arrival'    => $arrival,
					'departure'  => $depart,
					'nights'     => Dates::count( $arrival, $depart ),
					'adults'     => array_sum( array_column( $lines, 'adults' ) ),
					'children'   => array_sum( array_column( $lines, 'children' ) ),
					'extras'     => $extras,
					'updated_by' => $user_id ? $user_id : null,
				);
				if ( ! $keep ) {
					$changes += array(
						'total'            => $priced['total'],
						'taxes_total'      => $priced['taxes'],
						'deposit_due'      => $priced['deposit'],
						'deposit_due_date' => $priced['deposit_due_date'],
						'balance'          => $priced['total'] - (int) $locked['paid'] + (int) $locked['refunded'],
						'price_snapshot'   => $priced['price_snapshot'],
					);
				}
				if ( ! $this->repo->update( $id, $version, $changes ) ) {
					throw new Abort( self::conflict() );
				}
				return true;
			}
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$after = (array) $this->repo->find( $id );
		$this->audit->log(
			array(
				'action'      => 'booking.stay_changed',
				'entity_type' => 'booking',
				'entity_id'   => $id,
				'property_id' => (int) $booking['property_id'],
				'old'         => array_intersect_key( $booking, array_flip( array( 'arrival', 'departure', 'adults', 'children', 'total' ) ) ),
				'new'         => array_intersect_key( $after, array_flip( array( 'arrival', 'departure', 'adults', 'children', 'total' ) ) ) + array( 'override' => $override ),
			)
		);
		Hooks::dispatch( Hooks::BOOKING_UPDATED, $id, $this->context( $after ) + array( 'change' => 'stay' ) );
		return $this->payload( $after );
	}

	/**
	 * Changes the fields that do not touch availability: holder contacts, notes, arrival
	 * time, guest names.
	 *
	 * @param array<string, mixed> $input version, guest{}, guests[], customer_notes, internal_notes, arrival_time
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_details( int $id, array $input, int $user_id ): array|\WP_Error {
		$booking = $this->repo->find( $id );
		if ( ! $booking ) {
			return self::not_found();
		}
		if ( (int) ( $input['version'] ?? 0 ) !== (int) $booking['version'] ) {
			return self::conflict();
		}
		$changes = array();
		if ( isset( $input['guest'] ) && is_array( $input['guest'] ) ) {
			$guest = $this->guests->validate( array_merge( array_intersect_key( $booking, array_flip( array( 'first_name', 'last_name', 'email', 'phone', 'language' ) ) ), $input['guest'] ) );
			if ( $guest instanceof \WP_Error ) {
				return $guest;
			}
			$changes += array_intersect_key( $guest, array_flip( array( 'first_name', 'last_name', 'email', 'phone', 'language' ) ) );
		}
		foreach ( array( 'customer_notes' => 2000, 'internal_notes' => 4000 ) as $field => $max ) {
			if ( array_key_exists( $field, $input ) ) {
				$changes[ $field ] = self::text( $input[ $field ], $max );
			}
		}
		if ( array_key_exists( 'arrival_time', $input ) ) {
			$changes['arrival_time'] = self::arrival_time( $input['arrival_time'] );
		}
		$changes['updated_by'] = $user_id ? $user_id : null;
		if ( ! $this->repo->update( $id, (int) $booking['version'], $changes ) ) {
			return self::conflict();
		}
		if ( isset( $input['guests'] ) && is_array( $input['guests'] ) ) {
			$rooms = array_column( $this->repo->rooms( $id, true ), 'id' );
			$list  = array();
			foreach ( array_slice( $input['guests'], 0, 100 ) as $g ) {
				if ( ! is_array( $g ) ) {
					continue;
				}
				$room   = isset( $g['booking_room_id'] ) ? (int) $g['booking_room_id'] : 0;
				$list[] = array(
					'booking_room_id' => in_array( $room, $rooms, true ) ? $room : null,
					'first_name'      => mb_substr( sanitize_text_field( (string) ( $g['first_name'] ?? '' ) ), 0, 100 ),
					'last_name'       => mb_substr( sanitize_text_field( (string) ( $g['last_name'] ?? '' ) ), 0, 100 ),
					'age_band'        => 'child' === ( $g['age_band'] ?? '' ) ? 'child' : 'adult',
					'is_holder'       => ! empty( $g['is_holder'] ),
					'guest_id'        => ! empty( $g['is_holder'] ) ? $booking['guest_id'] : null,
				);
			}
			$this->repo->replace_guests( $id, $list );
		}
		$this->audit->log(
			array(
				'action'      => 'booking.updated',
				'entity_type' => 'booking',
				'entity_id'   => $id,
				'property_id' => (int) $booking['property_id'],
				'new'         => array( 'fields' => array_keys( array_diff_key( $changes, array( 'updated_by' => 1 ) ) ) ),
			)
		);
		$after = (array) $this->repo->find( $id );
		Hooks::dispatch( Hooks::BOOKING_UPDATED, $id, $this->context( $after ) + array( 'change' => 'details' ) );
		return $this->payload( $after );
	}

	/**
	 * Changes the commercial status. Going back to a status that occupies rooms (no-show
	 * marked by mistake) checks the rooms again.
	 *
	 * @param array{reason?: string, user_id?: int, actor?: string} $opts
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_status( int $id, int $version, string $to, array $opts = array() ): array|\WP_Error {
		$booking = $this->repo->find( $id );
		if ( ! $booking ) {
			return self::not_found();
		}
		if ( $version !== (int) $booking['version'] ) {
			return self::conflict();
		}
		$from = (string) $booking['status'];
		if ( $from === $to ) {
			return $this->payload( $booking );
		}
		if ( ! BookingStatus::can( $from, $to ) ) {
			return new \WP_Error( 'bkng_invalid_transition', __( 'Questo cambio di stato non è possibile.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$property = (array) $this->properties->find( (int) $booking['property_id'] );
		$today    = $this->clock->today_in( (string) $property['timezone'] );
		if ( BookingStatus::NO_SHOW === $to && $booking['arrival'] > $today ) {
			return new \WP_Error( 'bkng_too_early', __( 'Il no-show si può segnare solo dal giorno di arrivo.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		if ( BookingStatus::IN_HOUSE === $to && $booking['arrival'] > $today ) {
			return new \WP_Error( 'bkng_too_early', __( 'Il check-in si può registrare solo dal giorno di arrivo.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$user_id = (int) ( $opts['user_id'] ?? get_current_user_id() );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$changes = array(
			'status'     => $to,
			'updated_by' => $user_id ? $user_id : null,
		);
		switch ( $to ) {
			case BookingStatus::CONFIRMED:
				if ( null === $booking['confirmed_at'] ) {
					$changes['confirmed_at'] = $now;
					$changes['confirmed_by'] = $user_id ? $user_id : null;
				}
				$changes['option_expires_at'] = null;
				$changes['no_show_at']        = null;
				break;
			case BookingStatus::OPTION:
				$changes['option_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + (int) $property['settings']['option_hours'] * HOUR_IN_SECONDS );
				break;
			case BookingStatus::CANCELLED:
				$changes['cancelled_at']  = $now;
				$changes['cancelled_by']  = $user_id ? $user_id : null;
				$changes['cancel_reason'] = self::text( $opts['reason'] ?? null, 255 );
				break;
			case BookingStatus::NO_SHOW:
				$changes['no_show_at'] = $now;
				break;
			case BookingStatus::COMPLETED:
				$changes['completed_at'] = $now;
				break;
		}

		$was_occupying = BookingStatus::occupies( $from );
		$occupies      = BookingStatus::occupies( $to );
		$result        = Abort::run(
			function () use ( $id, $version, $booking, $property, $changes, $was_occupying, $occupies ) {
				$locked = $this->repo->lock( $id );
				if ( ! $locked || (int) $locked['version'] !== $version ) {
					throw new Abort( self::conflict() );
				}
				$rooms = $this->repo->rooms( $id, true );
				if ( $occupies && ! $was_occupying ) {
					$this->ledger->lock_types( array_column( $rooms, 'room_type_id' ) );
					$lines = array();
					foreach ( $rooms as $room ) {
						$lines[] = array(
							'id'              => $room['id'],
							'booking_room_id' => $room['id'],
							'room_type_id'    => $room['room_type_id'],
							'unit_id'         => $room['unit_id'],
							'arrival'         => $room['arrival'],
							'departure'       => $room['departure'],
						);
					}
					$free = $this->stays->check_free( $lines, array_column( $rooms, 'id' ) );
					if ( $free instanceof \WP_Error ) {
						throw new Abort( $free );
					}
					$occupied = $this->stays->occupy( (int) $property['id'], $lines, false );
					if ( $occupied instanceof \WP_Error ) {
						throw new Abort( $occupied );
					}
					$this->repo->set_occupies( $id, true );
				} elseif ( ! $occupies && $was_occupying ) {
					foreach ( $rooms as $room ) {
						$this->ledger->release_room( (int) $room['id'] );
					}
					$this->repo->set_occupies( $id, false );
				}
				if ( ! $this->repo->update( $id, $version, $changes ) ) {
					throw new Abort( self::conflict() );
				}
				return true;
			}
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$after = (array) $this->repo->find( $id );
		if ( $booking['guest_id'] ) {
			$this->guest_repo->refresh_stats( (int) $booking['guest_id'] );
		}
		$this->audit->log(
			array(
				'action'      => 'booking.status_changed',
				'entity_type' => 'booking',
				'entity_id'   => $id,
				'property_id' => (int) $booking['property_id'],
				'actor_type'  => $opts['actor'] ?? null,
				'old'         => array( 'status' => $from ),
				'new'         => array( 'status' => $to ),
				'reason'      => $opts['reason'] ?? null,
			)
		);
		$hook = array(
			BookingStatus::CONFIRMED => Hooks::BOOKING_CONFIRMED,
			BookingStatus::CANCELLED => Hooks::BOOKING_CANCELLED,
			BookingStatus::NO_SHOW   => Hooks::BOOKING_NO_SHOW,
			BookingStatus::IN_HOUSE  => Hooks::BOOKING_CHECKED_IN,
			BookingStatus::COMPLETED => Hooks::BOOKING_COMPLETED,
		)[ $to ] ?? Hooks::BOOKING_UPDATED;
		Hooks::dispatch( $hook, $id, $this->context( $after ) + array( 'previous_status' => $from ) );
		return $this->payload( $after );
	}

	/**
	 * Assigns a room (unit) to a stay, or removes the assignment (null).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function assign_unit( int $booking_room_id, ?int $unit_id, int $version, int $user_id ): array|\WP_Error {
		$room = $this->repo->find_room( $booking_room_id );
		if ( ! $room || ! $room['occupies'] ) {
			return self::not_found();
		}
		$booking = (array) $this->repo->find( (int) $room['booking_id'] );
		if ( $version !== (int) $booking['version'] ) {
			return self::conflict();
		}
		if ( null !== $unit_id ) {
			$unit = $this->stays_unit_type( $unit_id );
			if ( null === $unit || $unit !== (int) $room['room_type_id'] ) {
				return Validation::error( array( 'unit_id' => __( 'Questa camera non appartiene alla tipologia della prenotazione.', 'booking-manager' ) ) );
			}
		}
		return $this->reassign( (int) $room['booking_id'], $version, $user_id, $booking_room_id, $unit_id );
	}

	/**
	 * Moves one stay in the planning: to another room and/or to another arrival day with
	 * the same number of nights. Validated on the server like any other change.
	 *
	 * @param array{unit_id?: int|null, arrival?: string, version: int, keep_price?: bool} $input
	 * @param array{user_id?: int, can_override?: bool} $ctx
	 * @return array<string, mixed>|\WP_Error
	 */
	public function move_room( int $booking_room_id, array $input, array $ctx ): array|\WP_Error {
		$room = $this->repo->find_room( $booking_room_id );
		if ( ! $room || ! $room['occupies'] ) {
			return self::not_found();
		}
		$unit_id = array_key_exists( 'unit_id', $input ) ? ( null !== $input['unit_id'] ? (int) $input['unit_id'] : null ) : $room['unit_id'];
		$type_id = $room['room_type_id'];
		if ( null !== $unit_id ) {
			$unit_type = $this->stays_unit_type( $unit_id );
			if ( null === $unit_type ) {
				return Validation::error( array( 'unit_id' => __( 'Camera non valida.', 'booking-manager' ) ) );
			}
			$type_id = $unit_type;
		}
		$arrival = isset( $input['arrival'] ) ? Validation::date( $input['arrival'] ) : $room['arrival'];
		if ( null === $arrival ) {
			return Validation::error( array( 'arrival' => __( 'Data non valida.', 'booking-manager' ) ) );
		}
		$nights    = Dates::count( $room['arrival'], $room['departure'] );
		$departure = Dates::add_days( $arrival, $nights );
		$rooms     = array();
		foreach ( $this->repo->rooms( (int) $room['booking_id'], true ) as $r ) {
			$same    = $r['id'] === $booking_room_id;
			$rooms[] = array(
				'id'           => $r['id'],
				'room_type_id' => $same ? $type_id : $r['room_type_id'],
				'rate_plan_id' => $same && $type_id !== $r['room_type_id'] ? null : $r['rate_plan_id'],
				'unit_id'      => $same ? $unit_id : $r['unit_id'],
				'arrival'      => $same ? $arrival : $r['arrival'],
				'departure'    => $same ? $departure : $r['departure'],
				'adults'       => $r['adults'],
				'children'     => $r['children'],
			);
		}
		return $this->change_stay(
			(int) $room['booking_id'],
			array(
				'version'               => (int) $input['version'],
				'rooms'                 => $rooms,
				'keep_price'            => ! empty( $input['keep_price'] ),
				'override_restrictions' => true,
			),
			array(
				'user_id'      => (int) ( $ctx['user_id'] ?? 0 ),
				'can_override' => true,
			)
		);
	}

	/** Room type of a unit (null when the unit does not exist or is archived). */
	private function stays_unit_type( int $unit_id ): ?int {
		global $wpdb;
		$table = \BookingManager\Database\Schema::table( 'units' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$type = $wpdb->get_var( $wpdb->prepare( "SELECT room_type_id FROM {$table} WHERE id = %d AND status = 'active'", $unit_id ) );
		return null !== $type ? (int) $type : null;
	}

	/**
	 * Assignment change only (no dates, no price): the room's nights move to another unit.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function reassign( int $booking_id, int $version, int $user_id, int $booking_room_id, ?int $unit_id ): array|\WP_Error {
		$result  = Abort::run(
			function () use ( $booking_id, $version, $booking_room_id, $unit_id, $user_id ) {
				$locked = $this->repo->lock( $booking_id );
				if ( ! $locked || (int) $locked['version'] !== $version ) {
					throw new Abort( self::conflict() );
				}
				$room = (array) $this->repo->find_room( $booking_room_id );
				$this->ledger->release_room( $booking_room_id );
				if ( null !== $unit_id && ! $this->ledger->occupy( $unit_id, (int) $room['property_id'], $room['arrival'], $room['departure'], $booking_room_id ) ) {
					throw new Abort( new \WP_Error( 'bkng_unit_taken', __( 'La camera scelta è già occupata in queste date.', 'booking-manager' ), array( 'status' => 409 ) ) );
				}
				$this->repo->update_room( $booking_room_id, array( 'unit_id' => $unit_id ) );
				if ( ! $this->repo->update( $booking_id, $version, array( 'updated_by' => $user_id ? $user_id : null ) ) ) {
					throw new Abort( self::conflict() );
				}
				return true;
			}
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$after = (array) $this->repo->find( $booking_id );
		$this->audit->log(
			array(
				'action'      => 'booking.unit_assigned',
				'entity_type' => 'booking',
				'entity_id'   => $booking_id,
				'property_id' => (int) $after['property_id'],
				'new'         => array(
					'booking_room_id' => $booking_room_id,
					'unit_id'         => $unit_id,
				),
			)
		);
		Hooks::dispatch( Hooks::BOOKING_UPDATED, $booking_id, $this->context( $after ) + array( 'change' => 'unit' ) );
		return $this->payload( $after );
	}

	/** Hourly: options past their expiry are cancelled and free their rooms. */
	public function expire_options(): int {
		global $wpdb;
		$table = \BookingManager\Database\Schema::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, version FROM {$table} WHERE status = %s AND option_expires_at IS NOT NULL AND option_expires_at <= %s LIMIT 100", BookingStatus::OPTION, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		$n    = 0;
		foreach ( $rows as $row ) {
			$done = $this->set_status(
				(int) $row['id'],
				(int) $row['version'],
				BookingStatus::CANCELLED,
				array(
					'reason'  => __( 'Opzione scaduta', 'booking-manager' ),
					'user_id' => 0,
					'actor'   => 'system',
				)
			);
			$n += $done instanceof \WP_Error ? 0 : 1;
		}
		return $n;
	}

	/**
	 * Daily: stays whose departure passed two days ago and are still «in soggiorno» are
	 * closed (completed). Arrivals never checked in are left to the staff (no-show is a
	 * decision, not an automatism).
	 */
	public function auto_complete(): int {
		global $wpdb;
		$table = \BookingManager\Database\Schema::table( 'bookings' );
		$n     = 0;
		foreach ( $this->properties->all( null, false ) as $property ) {
			$limit = Dates::add_days( $this->clock->today_in( (string) $property['timezone'] ), -2 );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, version FROM {$table} WHERE property_id = %d AND status = %s AND departure <= %s LIMIT 200", (int) $property['id'], BookingStatus::IN_HOUSE, $limit ), ARRAY_A );
			foreach ( $rows as $row ) {
				$done = $this->set_status(
					(int) $row['id'],
					(int) $row['version'],
					BookingStatus::COMPLETED,
					array(
						'user_id' => 0,
						'actor'   => 'system',
					)
				);
				$n   += $done instanceof \WP_Error ? 0 : 1;
			}
		}
		return $n;
	}

	/**
	 * What the staff app receives about a booking (rooms and guests included).
	 *
	 * @param array<string, mixed> $b
	 * @return array<string, mixed>
	 */
	public function payload( array $b ): array {
		$rooms = array_map(
			static fn( array $r ) => array(
				'id'             => $r['id'],
				'uuid'           => $r['uuid'],
				'room_type_id'   => $r['room_type_id'],
				'unit_id'        => $r['unit_id'],
				'rate_plan_id'   => $r['rate_plan_id'],
				'arrival'        => $r['arrival'],
				'departure'      => $r['departure'],
				'adults'         => $r['adults'],
				'children'       => $r['children'],
				'price_total'    => $r['price_total'],
				'nightly'        => $r['nightly'],
				'stay_status'    => $r['stay_status'],
				'checked_in_at'  => Clock::iso( $r['checked_in_at'] ),
				'checked_out_at' => Clock::iso( $r['checked_out_at'] ),
			),
			$this->repo->rooms( (int) $b['id'], true )
		);
		return array(
			'id'                => (int) $b['id'],
			'uuid'              => (string) $b['uuid'],
			'code'              => (string) $b['code'],
			'property_id'       => (int) $b['property_id'],
			'status'            => (string) $b['status'],
			'next_statuses'     => BookingStatus::next( (string) $b['status'] ),
			'payment_status'    => (string) $b['payment_status'],
			'source'            => (string) $b['source'],
			'guest_id'          => $b['guest_id'],
			'first_name'        => (string) $b['first_name'],
			'last_name'         => (string) $b['last_name'],
			'email'             => $b['email'],
			'phone'             => $b['phone'],
			'language'          => $b['language'],
			'arrival'           => (string) $b['arrival'],
			'departure'         => (string) $b['departure'],
			'nights'            => (int) $b['nights'],
			'adults'            => (int) $b['adults'],
			'children'          => (int) $b['children'],
			'currency'          => (string) $b['currency'],
			'total'             => (int) $b['total'],
			'taxes_total'       => (int) $b['taxes_total'],
			'deposit_due'       => (int) $b['deposit_due'],
			'deposit_due_date'  => $b['deposit_due_date'],
			'paid'              => (int) $b['paid'],
			'refunded'          => (int) $b['refunded'],
			'balance'           => (int) $b['balance'],
			'price_snapshot'    => $b['price_snapshot'],
			'terms_snapshot'    => $b['terms_snapshot'],
			'extras'            => $b['extras'],
			'customer_notes'    => $b['customer_notes'],
			'internal_notes'    => $b['internal_notes'],
			'arrival_time'      => $b['arrival_time'],
			'option_expires_at' => Clock::iso( $b['option_expires_at'] ),
			'external_source'   => $b['external_source'],
			'external_ref'      => $b['external_ref'],
			'cancel_reason'     => $b['cancel_reason'],
			'rooms'             => $rooms,
			'guests'            => $this->repo->guests( (int) $b['id'] ),
			'created_at'        => Clock::iso( $b['created_at'] ),
			'updated_at'        => Clock::iso( $b['updated_at'] ),
			'version'           => (int) $b['version'],
		);
	}

	/**
	 * Context of the public hooks: ids and statuses, no personal data.
	 *
	 * @param array<string, mixed> $b
	 * @return array<string, mixed>
	 */
	public function context( array $b ): array {
		$property = $this->properties->find( (int) $b['property_id'] );
		return array(
			'property_id'     => (int) $b['property_id'],
			'property_uuid'   => $property ? (string) $property['uuid'] : '',
			'booking_uuid'    => (string) $b['uuid'],
			'status'          => (string) $b['status'],
			'payment_status'  => (string) $b['payment_status'],
			'source'          => (string) $b['source'],
			'arrival'         => (string) $b['arrival'],
			'departure'       => (string) $b['departure'],
			'external_source' => $b['external_source'],
			'external_ref'    => $b['external_ref'],
		);
	}

	/**
	 * Inserts the rooms of a new booking and writes their nights.
	 *
	 * @param array<int, array<string, mixed>> $lines
	 * @return array<int, array<string, mixed>> lines with booking_room_id and unit_id
	 */
	private function write_rooms( int $booking_id, int $property_id, array $lines, bool $auto_assign ): array {
		foreach ( $lines as $i => $line ) {
			$lines[ $i ]['booking_room_id'] = $this->repo->insert_room(
				array(
					'booking_id'   => $booking_id,
					'property_id'  => $property_id,
					'room_type_id' => $line['room_type_id'],
					'unit_id'      => null,
					'rate_plan_id' => $line['rate_plan_id'],
					'arrival'      => $line['arrival'],
					'departure'    => $line['departure'],
					'adults'       => $line['adults'],
					'children'     => $line['children'],
					'price_total'  => $line['price_total'],
					'nightly'      => $line['quote']['nightly'],
					'occupies'     => 1,
					'sort_order'   => $i,
				)
			);
		}
		$occupied = $this->stays->occupy( $property_id, $lines, $auto_assign );
		if ( $occupied instanceof \WP_Error ) {
			throw new Abort( $occupied );
		}
		foreach ( $occupied as $line ) {
			if ( null !== $line['unit_id'] ) {
				$this->repo->update_room( (int) $line['booking_room_id'], array( 'unit_id' => $line['unit_id'] ) );
			}
		}
		return $occupied;
	}

	/**
	 * Rooms from the input: "rooms" lines, or one line from the top-level fields.
	 *
	 * @param array<string, mixed> $input
	 * @return array<int, array<string, mixed>>
	 */
	private static function rooms_input( array $input ): array {
		$rooms = isset( $input['rooms'] ) && is_array( $input['rooms'] ) ? array_values( $input['rooms'] ) : array();
		foreach ( $rooms as $i => $room ) {
			if ( is_array( $room ) ) {
				$rooms[ $i ] += array(
					'arrival'   => $input['arrival'] ?? null,
					'departure' => $input['departure'] ?? null,
				);
			}
		}
		return $rooms;
	}

	/**
	 * Guests of a new booking: the holder first, then the names sent for each room.
	 *
	 * @param array<string, mixed>             $input
	 * @param array<string, mixed>             $holder
	 * @param array<int, array<string, mixed>> $lines
	 * @return array<int, array<string, mixed>>
	 */
	private static function guest_list( array $input, array $holder, int $guest_id, array $lines ): array {
		$list = array(
			array(
				'booking_room_id' => $lines[0]['booking_room_id'] ?? null,
				'guest_id'        => $guest_id,
				'first_name'      => $holder['first_name'],
				'last_name'       => $holder['last_name'],
				'age_band'        => 'adult',
				'is_holder'       => true,
			),
		);
		foreach ( array_slice( (array) ( $input['guests'] ?? array() ), 0, 100 ) as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$index  = isset( $g['room_index'] ) ? (int) $g['room_index'] : 0;
			$list[] = array(
				'booking_room_id' => $lines[ $index ]['booking_room_id'] ?? null,
				'guest_id'        => null,
				'first_name'      => mb_substr( sanitize_text_field( (string) ( $g['first_name'] ?? '' ) ), 0, 100 ),
				'last_name'       => mb_substr( sanitize_text_field( (string) ( $g['last_name'] ?? '' ) ), 0, 100 ),
				'age_band'        => 'child' === ( $g['age_band'] ?? '' ) ? 'child' : 'adult',
				'is_holder'       => false,
			);
		}
		return $list;
	}

	private function unique_code(): string {
		do {
			$code = BookingCodes::booking_code();
		} while ( null !== $this->repo->find_by_code( $code ) );
		return $code;
	}

	/** @param mixed $value */
	private static function text( $value, int $max ): ?string {
		if ( null === $value ) {
			return null;
		}
		$text = sanitize_textarea_field( (string) $value );
		return '' !== $text ? mb_substr( $text, 0, $max ) : null;
	}

	/** @param mixed $value */
	private static function arrival_time( $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : null;
	}

	public static function not_found(): \WP_Error {
		return new \WP_Error( 'bkng_booking_not_found', __( 'Prenotazione non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
	}

	public static function conflict(): \WP_Error {
		return new \WP_Error( 'bkng_conflict', __( 'La prenotazione è stata modificata da qualcun altro. Ricarica e riprova.', 'booking-manager' ), array( 'status' => 409 ) );
	}
}
