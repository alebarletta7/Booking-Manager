<?php
/**
 * Read-only availability for the staff app and the public form: free rooms per type
 * and night, and the offers (type × rate plan, with price) for a search.
 *
 * What is shown here is a snapshot: the booking itself checks again under the locks.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Availability;

use BookingManager\Core\Clock;
use BookingManager\Core\Dates;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Rates\Policies;
use BookingManager\Rates\PricingEngine;
use BookingManager\Rates\RateRepository;

defined( 'ABSPATH' ) || exit;

class AvailabilityService {

	public function __construct(
		private Ledger $ledger,
		private RoomTypeRepository $types,
		private RateRepository $rates,
		private RestrictionRepository $restrictions,
		private Clock $clock
	) {}

	/**
	 * Free rooms of every active type for each night of [from, to).
	 *
	 * @return array<int, array{room_type_id: int, name: string, capacity: int, free: array<string, int>}>
	 */
	public function grid( int $property_id, string $from, string $to ): array {
		$out = array();
		foreach ( $this->types->for_property( $property_id ) as $type ) {
			$out[] = array(
				'room_type_id' => (int) $type['id'],
				'name'         => (string) $type['name'],
				'is_hidden'    => (bool) $type['is_hidden'],
				'capacity'     => $this->ledger->capacity( (int) $type['id'] ),
				'free'         => $this->ledger->free_by_night( (int) $type['id'], $from, $to ),
			);
		}
		return $out;
	}

	/**
	 * Offers for a stay: every type that can host the guests and has free rooms, with
	 * the price of each plan that applies.
	 *
	 * @param array<string, mixed> $property
	 * @param int[]                $extras optional adjustments chosen
	 * @return array{rules: array<string, string>, offers: array<int, array<string, mixed>>}
	 */
	public function search( array $property, string $arrival, string $departure, int $adults, int $children, bool $online, array $extras = array() ): array {
		$tz      = (string) $property['timezone'];
		$today   = $this->clock->today_in( $tz );
		$time    = $online ? substr( $this->clock->now_in( $tz ), 11, 5 ) : null;
		$rows    = $this->restrictions->between( (int) $property['id'], $arrival, $departure );
		$general = RestrictionChecker::check( $property['settings'], $rows, $arrival, $departure, $today, null, null, $time );
		if ( isset( $general['dates'] ) || isset( $general['arrival'] ) ) {
			return array(
				'rules'  => $general,
				'offers' => array(),
			);
		}
		$plans       = $this->rates->plans( (int) $property['id'] );
		$adjustments = $this->rates->adjustments( (int) $property['id'] );
		$offers      = array();
		foreach ( $this->types->for_property( (int) $property['id'] ) as $type ) {
			if ( $online && ! $type['online_enabled'] ) {
				continue;
			}
			if ( $adults > $type['max_adults'] || $children > $type['max_children'] || $adults + $children > $type['max_occupancy'] ) {
				continue;
			}
			$free = $this->ledger->free( (int) $type['id'], $arrival, $departure );
			if ( $free < 1 ) {
				continue;
			}
			$prices = $this->rates->prices_for( (int) $type['id'], $arrival, $departure );
			$rates  = array();
			foreach ( $plans as $plan ) {
				if ( ( $online && ! $plan['is_public'] ) || ( $plan['room_type_ids'] && ! in_array( (int) $type['id'], $plan['room_type_ids'], true ) ) ) {
					continue;
				}
				$rules = RestrictionChecker::check( $property['settings'], $rows, $arrival, $departure, $today, (int) $type['id'], (int) $plan['id'], $time );
				if ( $rules ) {
					continue;
				}
				$public_adj = $online ? array_values( array_filter( $adjustments, static fn( array $a ) => $a['is_public'] ) ) : $adjustments;
				$quote      = PricingEngine::quote( $type, $plan, $prices, $public_adj, $arrival, $departure, $adults, $children, $extras, $property['settings'] );
				$rates[]    = array(
					'rate_plan_id' => (int) $plan['id'],
					'name'         => (string) $plan['name'],
					'description'  => (string) $plan['description'],
					'meal_plan'    => (string) $plan['meal_plan'],
					// With the options chosen: one that includes free cancellation changes the terms.
					'cancellation' => Policies::with_options( $plan['cancellation_policy'], $public_adj, (int) $type['id'], (int) $plan['id'], $arrival, $quote['nights'], $extras ),
					'deposit'      => $plan['deposit_policy'],
					'quote'        => $quote,
					'options'      => self::option_prices( $type, (int) $plan['id'], $public_adj, $quote, $arrival, $adults + $children ),
				);
			}
			if ( ! $plans ) {
				$rules = RestrictionChecker::check( $property['settings'], $rows, $arrival, $departure, $today, (int) $type['id'], null, $time );
				if ( ! $rules ) {
					$quote   = PricingEngine::quote( $type, null, $prices, $adjustments, $arrival, $departure, $adults, $children, $extras, $property['settings'] );
					$rates[] = array(
						'rate_plan_id' => null,
						'name'         => __( 'Tariffa base', 'booking-manager' ),
						'description'  => '',
						'meal_plan'    => 'room_only',
						'cancellation' => null,
						'deposit'      => null,
						'quote'        => $quote,
						'options'      => self::option_prices( $type, null, $online ? array_filter( $adjustments, static fn( array $a ) => $a['is_public'] ) : $adjustments, $quote, $arrival, $adults + $children ),
					);
				}
			}
			if ( ! $rates ) {
				continue;
			}
			$offers[] = array(
				'room_type_id' => (int) $type['id'],
				'free'         => $free,
				'rates'        => $rates,
			);
		}
		return array(
			'rules'  => $offers ? array() : $general,
			'offers' => $offers,
		);
	}

	/** Max nights a search may span, to keep requests small. */
	public static function valid_range( string $from, string $to, int $max = 400 ): bool {
		$n = Dates::count( $from, $to );
		return $n >= 1 && $n <= $max;
	}

	/**
	 * What each optional supplement would add to this stay at this rate (cents), so the
	 * guest sees the price of breakfast or free cancellation before choosing it.
	 *
	 * @param array<string, mixed>             $type
	 * @param array<int, array<string, mixed>> $adjustments
	 * @param array<string, mixed>             $quote PricingEngine::quote()
	 * @return array<int, array{id: int, amount: int}>
	 */
	private static function option_prices( array $type, ?int $plan_id, array $adjustments, array $quote, string $arrival, int $persons ): array {
		$out = array();
		foreach ( $adjustments as $adj ) {
			if ( empty( $adj['is_optional'] ) || 'supplement' !== $adj['kind'] ) {
				continue;
			}
			if ( ! PricingEngine::adjustment_applies( $adj, (int) $type['id'], $plan_id, $arrival, (int) $quote['nights'], array( (int) $adj['id'] ) ) ) {
				continue;
			}
			$out[] = array(
				'id'     => (int) $adj['id'],
				'amount' => PricingEngine::adjustment_amount( $adj, max( 0, (int) $quote['room'] + (int) $quote['modifier'] ), (int) $quote['nights'], $persons ),
			);
		}
		return $out;
	}
}
