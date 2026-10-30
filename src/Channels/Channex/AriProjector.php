<?php
/**
 * What Channex must show for each night, read from the plugin (the only source):
 *
 * - availability of a room type = free rooms of the Ledger (active rooms − stays −
 *   out-of-service periods − holds of the public form);
 * - for a room type and plan (Channex rate plan):
 *     rate                 price of a night at the base occupancy (price periods, base
 *                          price of the type, plan modifier), integer cents; with
 *                          per_person, rates[] for each occupancy from 1 to the maximum;
 *     min_stay_arrival     stay rules read on the arrival date, as the booking form does
 *     max_stay             (property settings, then the rules of that date); 0 = none;
 *     closed_to_arrival    «arrivi chiusi» or arrival weekdays that exclude the date;
 *     closed_to_departure  «partenze chiuse» or departure weekdays that exclude the date;
 *     stop_sell            closure of the night, plan or type archived, price 0.
 *
 * compress() turns night values into the ranges Channex accepts (consecutive nights with
 * equal values become one date_from/date_to item, both inclusive).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

use BookingManager\Availability\Ledger;
use BookingManager\Availability\RestrictionChecker;
use BookingManager\Availability\RestrictionRepository;
use BookingManager\Core\Dates;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Rates\PricingEngine;
use BookingManager\Rates\RateRepository;

defined( 'ABSPATH' ) || exit;

class AriProjector {

	/** Nights sent ahead (full sync and "all dates" changes). */
	public const HORIZON_DAYS = 500;

	public function __construct(
		private Ledger $ledger,
		private RoomTypeRepository $types,
		private RateRepository $rates,
		private RestrictionRepository $restrictions
	) {}

	/**
	 * Free rooms of a type for each date.
	 *
	 * @param string[] $dates Y-m-d, sorted
	 * @return array<string, int>
	 */
	public function availability( int $room_type_id, array $dates ): array {
		if ( ! $dates ) {
			return array();
		}
		$type = $this->types->find( $room_type_id );
		$free = $this->ledger->free_by_night( $room_type_id, min( $dates ), Dates::add_days( max( $dates ), 1 ) );
		$out  = array();
		foreach ( $dates as $date ) {
			$out[ $date ] = $type && 'active' === $type['status'] ? (int) ( $free[ $date ] ?? 0 ) : 0;
		}
		return $out;
	}

	/**
	 * Rates and rules of a type and plan (0: no plan) for each date.
	 *
	 * @param array<string, mixed> $property PropertyRepository::find()
	 * @param string[]             $dates    Y-m-d, sorted
	 * @return array<string, array<string, mixed>>
	 */
	public function restrictions( array $property, int $room_type_id, int $rate_plan_id, bool $per_person, array $dates ): array {
		if ( ! $dates ) {
			return array();
		}
		$from   = min( $dates );
		$to     = max( $dates );
		$type   = $this->types->find( $room_type_id );
		$plan   = $rate_plan_id > 0 ? $this->rates->find_plan( $rate_plan_id ) : null;
		$closed = ! $type || 'active' !== $type['status'] || (int) $type['property_id'] !== (int) $property['id']
			|| ( $rate_plan_id > 0 && ( ! $plan || 'active' !== $plan['status'] || (int) $plan['property_id'] !== (int) $property['id']
				|| ( $plan['room_type_ids'] && ! in_array( $room_type_id, $plan['room_type_ids'], true ) ) ) );
		$prices = $type ? $this->rates->prices_for( $room_type_id, $from, $to ) : array();
		$rules  = array_values(
			array_filter(
				$this->restrictions->between( (int) $property['id'], $from, $to ),
				static fn( array $r ) => RestrictionChecker::applies( $r, $room_type_id, $rate_plan_id > 0 ? $rate_plan_id : null )
			)
		);
		$out = array();
		foreach ( $dates as $date ) {
			$out[ $date ] = $closed ? array( 'stop_sell' => true ) : self::night( (array) $type, $plan, $prices, $rules, (array) $property['settings'], $per_person, $date );
		}
		return $out;
	}

	/**
	 * Values of one night (pure, unit-tested).
	 *
	 * @param array<string, mixed>             $type
	 * @param array<string, mixed>|null        $plan
	 * @param array<int, array<string, mixed>> $prices   price periods of the type
	 * @param array<int, array<string, mixed>> $rules    restrictions that apply to the type and plan
	 * @param array<string, mixed>             $settings property settings
	 * @return array<string, mixed>
	 */
	public static function night( array $type, ?array $plan, array $prices, array $rules, array $settings, bool $per_person, string $date ): array {
		$plan_id  = null !== $plan ? (int) $plan['id'] : null;
		$modifier = null !== $plan ? (int) $plan['modifier_bp'] : 0;
		$price    = static function ( int $adults ) use ( $type, $plan_id, $prices, $date, $modifier ): int {
			$night = PricingEngine::night_price( $type, $plan_id, $prices, $date, $adults, 0 );
			return max( 0, $night + PricingEngine::percent( $night, $modifier ) );
		};

		$values = array();
		if ( $per_person ) {
			$rates = array();
			for ( $occupancy = 1; $occupancy <= max( 1, (int) $type['max_occupancy'] ); $occupancy++ ) {
				$rates[] = array(
					'occupancy' => $occupancy,
					'rate'      => $price( min( $occupancy, max( 1, (int) $type['max_adults'] ) ) ),
				);
			}
			$sellable        = (bool) array_filter( $rates, static fn( array $r ) => $r['rate'] > 0 );
			$values['rates'] = $rates;
		} else {
			$rate     = $price( max( 1, (int) $type['base_occupancy'] ) );
			$sellable = $rate > 0;
			if ( $sellable ) {
				$values['rate'] = $rate;
			}
		}

		$on       = array_values( array_filter( $rules, static fn( array $r ) => (string) $r['date_from'] <= $date && (string) $r['date_to'] >= $date ) );
		$min_stay = max( 1, (int) ( $settings['min_stay'] ?? 1 ) );
		$max_stay = (int) ( $settings['max_stay'] ?? Dates::MAX_NIGHTS );
		$cta      = false;
		$ctd      = false;
		$stop     = ! $sellable;
		foreach ( $on as $r ) {
			if ( null !== $r['min_stay'] && '' !== (string) $r['min_stay'] ) {
				$min_stay = max( $min_stay, (int) $r['min_stay'] );
			}
			if ( null !== $r['max_stay'] && '' !== (string) $r['max_stay'] ) {
				$max_stay = min( $max_stay, (int) $r['max_stay'] );
			}
			$cta  = $cta || ! empty( $r['closed_to_arrival'] ) || ! Dates::on_weekdays( $r['arrival_weekdays'] ?? null, $date );
			$ctd  = $ctd || ! empty( $r['closed_to_departure'] ) || ! Dates::on_weekdays( $r['departure_weekdays'] ?? null, $date );
			$stop = $stop || ! empty( $r['closed'] );
		}
		$values['min_stay_arrival']    = $min_stay;
		$values['max_stay']            = $max_stay >= Dates::MAX_NIGHTS ? 0 : max( $min_stay, $max_stay );
		$values['closed_to_arrival']   = $cta;
		$values['closed_to_departure'] = $ctd;
		$values['stop_sell']           = $stop;
		return $values;
	}

	/**
	 * Consecutive dates with equal values become one range (pure, unit-tested).
	 *
	 * @param array<string, mixed>                $base   fields of every item (property_id, room_type_id or rate_plan_id)
	 * @param array<string, array<string, mixed>> $nights date => values (e.g. ['availability' => 3])
	 * @return array<int, array<string, mixed>>
	 */
	public static function compress( array $base, array $nights ): array {
		ksort( $nights );
		$out   = array();
		$start = null;
		$prev  = null;
		$last  = null;
		$emit  = static function ( string $from, string $to, array $values ) use ( $base, &$out ): void {
			$out[] = $base + ( $from === $to ? array( 'date' => $from ) : array(
				'date_from' => $from,
				'date_to'   => $to,
			) ) + $values;
		};
		foreach ( $nights as $date => $values ) {
			$date = (string) $date;
			if ( null !== $start && $values === $prev && Dates::add_days( (string) $last, 1 ) === $date ) {
				$last = $date;
				continue;
			}
			if ( null !== $start ) {
				$emit( $start, (string) $last, (array) $prev );
			}
			$start = $date;
			$last  = $date;
			$prev  = $values;
		}
		if ( null !== $start ) {
			$emit( $start, (string) $last, (array) $prev );
		}
		return $out;
	}

	/**
	 * Every date of the horizon from $today.
	 *
	 * @return string[]
	 */
	public static function horizon( string $today, int $days = self::HORIZON_DAYS ): array {
		return Dates::nights( $today, Dates::add_days( $today, $days ) );
	}
}
