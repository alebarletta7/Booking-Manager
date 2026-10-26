<?php
/**
 * Turns the rooms asked for a booking into stays that can be written: checks the room
 * types, the guests per room, the stay rules, the free rooms (under the type locks),
 * assigns the rooms and prices every line.
 *
 * Used by BookingService for new bookings and for changes of dates and rooms. Writers
 * call it inside a transaction: reserve() locks the room types first.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Bookings;

use BookingManager\Availability\Ledger;
use BookingManager\Availability\RestrictionChecker;
use BookingManager\Availability\RestrictionRepository;
use BookingManager\Core\Clock;
use BookingManager\Core\Dates;
use BookingManager\Core\Validation;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Inventory\UnitRepository;
use BookingManager\Rates\PricingEngine;
use BookingManager\Rates\Policies;
use BookingManager\Rates\RateRepository;

defined( 'ABSPATH' ) || exit;

class StayBuilder {

	public function __construct(
		private Ledger $ledger,
		private RoomTypeRepository $types,
		private UnitRepository $units,
		private RateRepository $rates,
		private RestrictionRepository $restrictions,
		private Clock $clock
	) {}

	/**
	 * Validates the room lines (no database lock needed).
	 *
	 * @param array<string, mixed>             $property
	 * @param array<int, array<string, mixed>> $input rooms: room_type_id, unit_id?, rate_plan_id?, arrival, departure, adults, children, price_total? (manual)
	 * @param array{allow_manual_price?: bool, online?: bool} $opts
	 * @return array<int, array<string, mixed>>|\WP_Error normalized lines with 'type' and 'plan' rows
	 */
	public function normalize( array $property, array $input, array $opts = array() ): array|\WP_Error {
		$errors = array();
		$lines  = array();
		$max    = (int) $property['settings']['max_rooms_per_booking'];
		if ( ! $input ) {
			return Validation::error( array( 'rooms' => __( 'Scegli almeno una camera.', 'booking-manager' ) ) );
		}
		if ( count( $input ) > max( $max, empty( $opts['online'] ) ? 50 : $max ) ) {
			/* translators: %d: maximum number of rooms. */
			return Validation::error( array( 'rooms' => sprintf( __( 'Si possono prenotare al massimo %d camere insieme.', 'booking-manager' ), $max ) ) );
		}
		$types = $this->types->find_many( array_map( static fn( $r ) => (int) ( is_array( $r ) ? ( $r['room_type_id'] ?? 0 ) : 0 ), $input ) );
		foreach ( array_values( $input ) as $i => $raw ) {
			$raw  = is_array( $raw ) ? $raw : array();
			$key  = 'rooms.' . $i;
			$type = $types[ (int) ( $raw['room_type_id'] ?? 0 ) ] ?? null;
			if ( null === $type && ! empty( $raw['unit_id'] ) ) {
				$unit = $this->units->find( (int) $raw['unit_id'] );
				$type = $unit ? $this->types->find( (int) $unit['room_type_id'] ) : null;
			}
			if ( null === $type || (int) $type['property_id'] !== (int) $property['id'] || 'active' !== $type['status'] ) {
				$errors[ $key . '.room_type_id' ] = __( 'Tipologia di camera non valida.', 'booking-manager' );
				continue;
			}
			if ( ! empty( $opts['online'] ) && ! $type['online_enabled'] ) {
				$errors[ $key . '.room_type_id' ] = __( 'Questa camera non è prenotabile online.', 'booking-manager' );
				continue;
			}
			$arrival   = Validation::date( $raw['arrival'] ?? null );
			$departure = Validation::date( $raw['departure'] ?? null );
			if ( null === $arrival || null === $departure ) {
				$errors[ $key . '.dates' ] = __( 'Date non valide.', 'booking-manager' );
				continue;
			}
			$nights = Dates::count( $arrival, $departure );
			if ( $nights < 1 || $nights > Dates::MAX_NIGHTS ) {
				$errors[ $key . '.dates' ] = __( 'La partenza deve essere successiva all\'arrivo.', 'booking-manager' );
				continue;
			}
			$adults   = Validation::int_between( $raw['adults'] ?? 1, 1, 50 );
			$children = Validation::int_between( $raw['children'] ?? 0, 0, 50 );
			if ( null === $adults || null === $children ) {
				$errors[ $key . '.guests' ] = __( 'Numero di ospiti non valido.', 'booking-manager' );
				continue;
			}
			if ( $adults > $type['max_adults'] || $children > $type['max_children'] || $adults + $children > $type['max_occupancy'] ) {
				/* translators: 1: room type, 2: maximum guests. */
				$errors[ $key . '.guests' ] = sprintf( __( '«%1$s» ospita al massimo %2$d persone.', 'booking-manager' ), $type['name'], $type['max_occupancy'] );
				continue;
			}
			$plan = null;
			if ( ! empty( $raw['rate_plan_id'] ) ) {
				$plan = $this->rates->find_plan( (int) $raw['rate_plan_id'] );
				if ( ! $plan || (int) $plan['property_id'] !== (int) $property['id'] || 'active' !== $plan['status']
					|| ( $plan['room_type_ids'] && ! in_array( (int) $type['id'], $plan['room_type_ids'], true ) )
					|| ( ! empty( $opts['online'] ) && ! $plan['is_public'] ) ) {
					$errors[ $key . '.rate_plan_id' ] = __( 'Tariffa non valida per questa camera.', 'booking-manager' );
					continue;
				}
			} else {
				foreach ( $this->rates->plans( (int) $property['id'] ) as $candidate ) {
					if ( ( ! $candidate['room_type_ids'] || in_array( (int) $type['id'], $candidate['room_type_ids'], true ) ) && ( empty( $opts['online'] ) || $candidate['is_public'] ) ) {
						$plan = $candidate;
						break;
					}
				}
			}
			$unit_id = null;
			if ( ! empty( $raw['unit_id'] ) ) {
				$unit = $this->units->find( (int) $raw['unit_id'] );
				if ( ! $unit || (int) $unit['room_type_id'] !== (int) $type['id'] || 'active' !== $unit['status'] ) {
					$errors[ $key . '.unit_id' ] = __( 'Questa camera non appartiene alla tipologia scelta.', 'booking-manager' );
					continue;
				}
				$unit_id = (int) $unit['id'];
			}
			$manual = null;
			if ( isset( $raw['price_total'] ) && '' !== (string) $raw['price_total'] && null !== $raw['price_total'] ) {
				if ( empty( $opts['allow_manual_price'] ) ) {
					$errors[ $key . '.price_total' ] = __( 'Non puoi impostare un prezzo manuale.', 'booking-manager' );
					continue;
				}
				$manual = Validation::int_between( $raw['price_total'], 0, 100000000 );
				if ( null === $manual ) {
					$errors[ $key . '.price_total' ] = __( 'Prezzo non valido.', 'booking-manager' );
					continue;
				}
			}
			$lines[] = array(
				'id'           => isset( $raw['id'] ) ? (int) $raw['id'] : 0,
				'type'         => $type,
				'plan'         => $plan,
				'room_type_id' => (int) $type['id'],
				'rate_plan_id' => $plan ? (int) $plan['id'] : null,
				'unit_id'      => $unit_id,
				'arrival'      => $arrival,
				'departure'    => $departure,
				'adults'       => $adults,
				'children'     => $children,
				'manual_price' => $manual,
			);
		}
		return $errors ? Validation::error( $errors ) : $lines;
	}

	/**
	 * Stay rules for every line.
	 *
	 * @param array<string, mixed>             $property
	 * @param array<int, array<string, mixed>> $lines
	 * @return array<string, string> code => message (empty when allowed)
	 */
	public function rule_errors( array $property, array $lines, bool $online ): array {
		$tz     = (string) $property['timezone'];
		$today  = $this->clock->today_in( $tz );
		$time   = $online ? substr( $this->clock->now_in( $tz ), 11, 5 ) : null;
		$errors = array();
		foreach ( $lines as $line ) {
			$rows   = $this->restrictions->between( (int) $property['id'], $line['arrival'], $line['departure'] );
			$errors = array_merge( $errors, RestrictionChecker::check( $property['settings'], $rows, $line['arrival'], $line['departure'], $today, $line['room_type_id'], $line['rate_plan_id'], $time ) );
		}
		return $errors;
	}

	/**
	 * Checks that there are enough free rooms for every line. Inside a transaction, after
	 * lock_types().
	 *
	 * @param array<int, array<string, mixed>> $lines
	 * @param int[]                            $exclude_rooms stays being replaced
	 * @return true|\WP_Error 409 bkng_unavailable
	 */
	public function check_free( array $lines, array $exclude_rooms = array(), int $exclude_hold = 0 ): bool|\WP_Error {
		$by_type = array();
		foreach ( $lines as $line ) {
			$by_type[ $line['room_type_id'] ][] = $line;
		}
		foreach ( $by_type as $type_id => $group ) {
			$from = min( array_column( $group, 'arrival' ) );
			$to   = max( array_column( $group, 'departure' ) );
			$free = $this->ledger->free_by_night( (int) $type_id, $from, $to, $exclude_rooms, $exclude_hold );
			$need = array();
			foreach ( $group as $line ) {
				foreach ( Dates::nights( $line['arrival'], $line['departure'] ) as $night ) {
					$need[ $night ] = ( $need[ $night ] ?? 0 ) + 1;
				}
			}
			foreach ( $need as $night => $count ) {
				if ( $count > ( $free[ $night ] ?? 0 ) ) {
					$name = $group[0]['type']['name'] ?? ( $this->types->find( (int) $type_id )['name'] ?? '' );
					return self::unavailable( (string) $name, $night );
				}
			}
		}
		return true;
	}

	/**
	 * Writes the nights of the rooms chosen or assigned for each line. Inside a
	 * transaction. Lines without a room keep unit_id null when auto-assignment is off or
	 * no single room is free for the whole stay.
	 *
	 * @param array<int, array<string, mixed>> $lines with booking_room_id set
	 * @return array<int, array<string, mixed>>|\WP_Error lines with the unit_id written
	 */
	public function occupy( int $property_id, array $lines, bool $auto_assign ): array|\WP_Error {
		$taken = array();
		foreach ( $lines as $i => $line ) {
			$unit_id = $line['unit_id'];
			if ( null === $unit_id && $auto_assign ) {
				foreach ( $this->ledger->free_units( $line['room_type_id'], $line['arrival'], $line['departure'] ) as $candidate ) {
					if ( ! in_array( $candidate, $taken, true ) ) {
						$unit_id = $candidate;
						break;
					}
				}
			}
			if ( null === $unit_id ) {
				continue;
			}
			if ( ! $this->ledger->occupy( $unit_id, $property_id, $line['arrival'], $line['departure'], (int) $line['booking_room_id'] ) ) {
				$unit = $this->units->find( $unit_id );
				/* translators: %s: room code. */
				return new \WP_Error( 'bkng_unit_taken', sprintf( __( 'La camera %s è già occupata in queste date.', 'booking-manager' ), $unit ? $unit['code'] : '' ), array( 'status' => 409 ) );
			}
			$taken[]                = $unit_id;
			$lines[ $i ]['unit_id'] = $unit_id;
		}
		return $lines;
	}

	/**
	 * Price of every line, the booking totals and the terms to keep in the booking.
	 *
	 * @param array<string, mixed>             $property
	 * @param array<int, array<string, mixed>> $lines
	 * @param int[]                            $extras optional adjustments chosen
	 * @return array{lines: array<int, array<string, mixed>>, total: int, taxes: int, deposit: int, deposit_due_date: ?string, price_snapshot: array<string, mixed>, terms_snapshot: array<string, mixed>}
	 */
	public function price( array $property, array $lines, array $extras ): array {
		$adjustments = $this->rates->adjustments( (int) $property['id'] );
		$today       = $this->clock->today_in( (string) $property['timezone'] );
		$total       = 0;
		$taxes       = 0;
		$deposit     = 0;
		$due         = null;
		$snap        = array();
		$terms       = array();
		foreach ( $lines as $i => $line ) {
			$prices = $this->rates->prices_for( $line['room_type_id'], $line['arrival'], $line['departure'] );
			$quote  = PricingEngine::quote( $line['type'], $line['plan'], $prices, $adjustments, $line['arrival'], $line['departure'], $line['adults'], $line['children'], $extras, $property['settings'] );
			if ( null !== $line['manual_price'] ) {
				$quote['manual']   = true;
				$quote['subtotal'] = (int) $line['manual_price'];
				$quote['total']    = $quote['subtotal'] + $quote['tax'];
			}
			$lines[ $i ]['quote']       = $quote;
			$lines[ $i ]['price_total'] = $quote['subtotal'];
			$total                     += $quote['total'];
			$taxes                     += $quote['tax'];
			$policy                     = $line['plan'] ? $line['plan']['deposit_policy'] : Policies::deposit( null );
			$deposit                   += Policies::deposit_amount( $policy, $quote['subtotal'], $quote['first_night'] );
			$line_due                   = Policies::deposit_due_date( $policy, $line['arrival'], $today );
			if ( null !== $line_due && ( null === $due || $line_due < $due ) ) {
				$due = $line_due;
			}
			$snap[] = array(
				'room_type'   => (string) $line['type']['name'],
				'rate_plan'   => $line['plan'] ? (string) $line['plan']['name'] : null,
				'arrival'     => $line['arrival'],
				'departure'   => $line['departure'],
				'adults'      => $line['adults'],
				'children'    => $line['children'],
				'nightly'     => $quote['nightly'],
				'modifier'    => $quote['modifier'],
				'adjustments' => $quote['adjustments'],
				'subtotal'    => $quote['subtotal'],
				'tax'         => $quote['tax'],
				'total'       => $quote['total'],
				'manual'      => ! empty( $quote['manual'] ),
			);
			if ( $line['plan'] ) {
				// Keyed by rate plan id: the guest's cancellation reads the terms of each room's plan.
				$terms[ (string) $line['plan']['id'] ] = array(
					'rate_plan_id' => (int) $line['plan']['id'],
					'rate_plan'    => (string) $line['plan']['name'],
					'meal_plan'    => (string) $line['plan']['meal_plan'],
					'cancellation' => Policies::with_options( $line['plan']['cancellation_policy'], $adjustments, (int) $line['room_type_id'], (int) $line['plan']['id'], $line['arrival'], $quote['nights'], $extras ),
					'deposit'      => $line['plan']['deposit_policy'],
				);
			}
		}
		return array(
			'lines'            => $lines,
			'total'            => $total,
			'taxes'            => $taxes,
			'deposit'          => min( $deposit, $total ),
			'deposit_due_date' => $deposit > 0 ? $due : null,
			'price_snapshot'   => array(
				'currency' => (string) $property['currency'],
				'rooms'    => $snap,
				'extras'   => array_values( array_map( 'intval', $extras ) ),
				'total'    => $total,
				'taxes'    => $taxes,
			),
			'terms_snapshot'   => array(
				'plans'      => $terms,
				'terms_text' => (string) $property['settings']['terms_text'],
				'checkin'    => array( (string) $property['checkin_from'], (string) $property['checkin_to'] ),
				'checkout'   => (string) $property['checkout_until'],
			),
		);
	}

	public static function unavailable( string $type_name, string $night ): \WP_Error {
		return new \WP_Error(
			'bkng_unavailable',
			/* translators: 1: room type, 2: date. */
			sprintf( __( 'Non ci sono più camere «%1$s» libere per la notte del %2$s.', 'booking-manager' ), $type_name, wp_date( 'j F Y', strtotime( $night . ' 12:00:00 UTC' ) ) ),
			array(
				'status' => 409,
				'night'  => $night,
			)
		);
	}
}
