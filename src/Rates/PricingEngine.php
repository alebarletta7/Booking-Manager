<?php
/**
 * Price of a stay (pure, unit-tested; amounts in integer cents).
 *
 * For each night:
 * - the price period of the type with the highest priority that covers the night and its
 *   weekday wins (periods of the chosen plan first, then periods of no plan); without one,
 *   the base price of the room type;
 * - guests above the base occupancy pay the extra adult / extra child price of the
 *   period (or of the type).
 * Then the plan modifier (e.g. −10% non-refundable), then the adjustments that apply:
 * supplements (+), discounts and long-stay discounts (−), optional extras only when
 * chosen. The tourist tax is a separate line, never mixed with the price.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rates;

use BookingManager\Core\Dates;

defined( 'ABSPATH' ) || exit;

final class PricingEngine {

	public const KINDS = array( 'supplement', 'discount', 'long_stay' );
	public const PER   = array( 'stay', 'night', 'person_night', 'person_stay' );

	/**
	 * @param array<string, mixed>             $type        room type row (RoomTypeRepository::hydrate)
	 * @param array<string, mixed>|null        $plan        rate plan row or null
	 * @param array<int, array<string, mixed>> $prices      price periods of the type
	 * @param array<int, array<string, mixed>> $adjustments active adjustments of the property
	 * @param int[]                            $chosen      ids of the optional adjustments chosen
	 * @param array<string, mixed>             $tax         property settings (tourist_tax_*)
	 * @return array{nightly: array<int, array{date: string, price: int}>, nights: int, room: int, modifier: int, adjustments: array<int, array{id: int, kind: string, name: string, amount: int}>, subtotal: int, tax: int, total: int, first_night: int}
	 */
	public static function quote( array $type, ?array $plan, array $prices, array $adjustments, string $arrival, string $departure, int $adults, int $children, array $chosen = array(), array $tax = array() ): array {
		$plan_id = null !== $plan ? (int) $plan['id'] : null;
		$nightly = array();
		$room    = 0;
		foreach ( Dates::nights( $arrival, $departure ) as $night ) {
			$price     = self::night_price( $type, $plan_id, $prices, $night, $adults, $children );
			$nightly[] = array(
				'date'  => $night,
				'price' => $price,
			);
			$room     += $price;
		}
		$nights   = count( $nightly );
		$modifier = null !== $plan ? self::percent( $room, (int) $plan['modifier_bp'] ) : 0;
		$base     = max( 0, $room + $modifier );

		$lines = array();
		$sum   = 0;
		foreach ( $adjustments as $adj ) {
			if ( ! self::adjustment_applies( $adj, (int) $type['id'], $plan_id, $arrival, $nights, $chosen ) ) {
				continue;
			}
			$amount = self::adjustment_amount( $adj, $base, $nights, $adults + $children );
			if ( 0 === $amount ) {
				continue;
			}
			if ( 'supplement' !== $adj['kind'] ) {
				$amount = -abs( $amount );
			}
			$lines[] = array(
				'id'     => (int) $adj['id'],
				'kind'   => (string) $adj['kind'],
				'name'   => (string) $adj['name'],
				'amount' => $amount,
			);
			$sum    += $amount;
		}
		$subtotal = max( 0, $base + $sum );
		$taxed    = self::tourist_tax( $tax, $nights, $adults, $children );

		$first = $nightly ? $nightly[0]['price'] : 0;
		if ( null !== $plan && 0 !== (int) $plan['modifier_bp'] ) {
			$first = max( 0, $first + self::percent( $first, (int) $plan['modifier_bp'] ) );
		}

		return array(
			'nightly'     => $nightly,
			'nights'      => $nights,
			'room'        => $room,
			'modifier'    => $modifier,
			'adjustments' => $lines,
			'subtotal'    => $subtotal,
			'tax'         => $taxed,
			'total'       => $subtotal + $taxed,
			'first_night' => $first,
		);
	}

	/**
	 * @param array<string, mixed>             $type
	 * @param array<int, array<string, mixed>> $prices
	 */
	public static function night_price( array $type, ?int $plan_id, array $prices, string $night, int $adults, int $children ): int {
		$period = self::period_for( $prices, $plan_id, $night );
		$base   = null !== $period ? (int) $period['price_night'] : (int) $type['base_price'];
		$adult  = null !== $period && null !== $period['extra_adult_price'] ? (int) $period['extra_adult_price'] : (int) $type['extra_adult_price'];
		$child  = null !== $period && null !== $period['extra_child_price'] ? (int) $period['extra_child_price'] : (int) $type['extra_child_price'];

		$included     = max( 0, (int) $type['base_occupancy'] );
		$extra_adults = max( 0, $adults - $included );
		$free_slots   = max( 0, $included - $adults );
		$extra_kids   = max( 0, $children - $free_slots );
		return max( 0, $base + $extra_adults * $adult + $extra_kids * $child );
	}

	/**
	 * The period that sets the price of a night: periods of the plan beat periods of no
	 * plan; then the highest priority, then the most recent.
	 *
	 * @param array<int, array<string, mixed>> $prices
	 * @return array<string, mixed>|null
	 */
	public static function period_for( array $prices, ?int $plan_id, string $night ): ?array {
		$best = null;
		$rank = null;
		foreach ( $prices as $p ) {
			$p_plan = null !== $p['rate_plan_id'] ? (int) $p['rate_plan_id'] : null;
			if ( null !== $p_plan && $p_plan !== $plan_id ) {
				continue;
			}
			if ( (string) $p['date_from'] > $night || (string) $p['date_to'] < $night || ! Dates::on_weekdays( (string) $p['weekdays'], $night ) ) {
				continue;
			}
			$r = array( null !== $p_plan ? 1 : 0, (int) $p['priority'], (int) $p['id'] );
			if ( null === $rank || $r > $rank ) {
				$rank = $r;
				$best = $p;
			}
		}
		return $best;
	}

	/**
	 * @param array<string, mixed> $adj
	 * @param int[]                $chosen
	 */
	public static function adjustment_applies( array $adj, int $type_id, ?int $plan_id, string $arrival, int $nights, array $chosen ): bool {
		if ( 'active' !== ( $adj['status'] ?? 'active' ) || ! in_array( $adj['kind'], self::KINDS, true ) ) {
			return false;
		}
		if ( ! empty( $adj['is_optional'] ) && ! in_array( (int) $adj['id'], array_map( 'intval', $chosen ), true ) ) {
			return false;
		}
		if ( null !== $adj['rate_plan_id'] && (int) $adj['rate_plan_id'] !== $plan_id ) {
			return false;
		}
		if ( ! empty( $adj['room_type_ids'] ) && ! in_array( $type_id, array_map( 'intval', (array) $adj['room_type_ids'] ), true ) ) {
			return false;
		}
		if ( null !== $adj['min_nights'] && $nights < (int) $adj['min_nights'] ) {
			return false;
		}
		if ( null !== $adj['max_nights'] && (int) $adj['max_nights'] > 0 && $nights > (int) $adj['max_nights'] ) {
			return false;
		}
		if ( ! empty( $adj['date_from'] ) && $arrival < (string) $adj['date_from'] ) {
			return false;
		}
		return ! ( ! empty( $adj['date_to'] ) && $arrival > (string) $adj['date_to'] );
	}

	/** @param array<string, mixed> $adj */
	public static function adjustment_amount( array $adj, int $base, int $nights, int $persons ): int {
		if ( null !== $adj['percent_bp'] && 0 !== (int) $adj['percent_bp'] ) {
			return abs( self::percent( $base, (int) $adj['percent_bp'] ) );
		}
		$amount = (int) ( $adj['amount'] ?? 0 );
		return match ( (string) $adj['per'] ) {
			'night'        => $amount * $nights,
			'person_night' => $amount * $nights * max( 1, $persons ),
			'person_stay'  => $amount * max( 1, $persons ),
			default        => $amount,
		};
	}

	/**
	 * Tourist tax: amount per person per night, up to a number of nights, for guests of at
	 * least a minimum age (children are counted only when there is no minimum age).
	 *
	 * @param array<string, mixed> $tax
	 */
	public static function tourist_tax( array $tax, int $nights, int $adults, int $children ): int {
		if ( empty( $tax['tourist_tax_enabled'] ) || (int) ( $tax['tourist_tax_amount'] ?? 0 ) <= 0 ) {
			return 0;
		}
		$max     = (int) ( $tax['tourist_tax_max_nights'] ?? 0 );
		$nights  = $max > 0 ? min( $nights, $max ) : $nights;
		$persons = $adults + ( (int) ( $tax['tourist_tax_min_age'] ?? 0 ) > 0 ? 0 : $children );
		return (int) $tax['tourist_tax_amount'] * $nights * $persons;
	}

	/** $amount × basis points / 10000, rounded half away from zero. */
	public static function percent( int $amount, int $bp ): int {
		$value = $amount * $bp;
		return (int) ( $value >= 0 ? intdiv( $value + 5000, 10000 ) : -intdiv( -$value + 5000, 10000 ) );
	}
}
