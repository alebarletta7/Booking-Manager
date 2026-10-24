<?php
/**
 * Price of a stay: periods, extra guests, plan modifier, adjustments, tourist tax.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Unit;

use BookingManager\Rates\PricingEngine;
use BookingManager\Rates\Policies;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase {

	private const TYPE = array(
		'id'                => 7,
		'base_price'        => 10000,
		'extra_adult_price' => 3000,
		'extra_child_price' => 1500,
		'base_occupancy'    => 2,
	);

	private static function price( array $p ): array {
		return $p + array(
			'id'                => 1,
			'rate_plan_id'      => null,
			'weekdays'          => '1,2,3,4,5,6,7',
			'extra_adult_price' => null,
			'extra_child_price' => null,
			'priority'          => 0,
		);
	}

	private static function adj( array $a ): array {
		return $a + array(
			'id'            => 1,
			'status'        => 'active',
			'rate_plan_id'  => null,
			'room_type_ids' => array(),
			'min_nights'    => null,
			'max_nights'    => null,
			'date_from'     => null,
			'date_to'       => null,
			'is_optional'   => false,
			'amount'        => null,
			'percent_bp'    => null,
			'per'           => 'stay',
		);
	}

	public function test_base_price_for_every_night_without_periods(): void {
		$q = PricingEngine::quote( self::TYPE, null, array(), array(), '2026-07-01', '2026-07-04', 2, 0 );
		$this->assertSame( 3, $q['nights'] );
		$this->assertSame( 30000, $q['room'] );
		$this->assertSame( 30000, $q['total'] );
		$this->assertSame( '2026-07-03', $q['nightly'][2]['date'] );
	}

	public function test_departure_day_is_not_priced(): void {
		$q = PricingEngine::quote( self::TYPE, null, array(), array(), '2026-07-01', '2026-07-02', 2, 0 );
		$this->assertCount( 1, $q['nightly'] );
	}

	public function test_period_with_highest_priority_wins_and_weekdays_filter(): void {
		$prices = array(
			self::price( array( 'id' => 1, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'price_night' => 15000 ) ),
			self::price( array( 'id' => 2, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'price_night' => 18000, 'weekdays' => '5,6', 'priority' => 5 ) ),
		);
		// 2026-07-03 is a Friday, 2026-07-04 a Saturday, 2026-07-05 a Sunday.
		$q = PricingEngine::quote( self::TYPE, null, $prices, array(), '2026-07-03', '2026-07-06', 2, 0 );
		$this->assertSame( array( 18000, 18000, 15000 ), array_column( $q['nightly'], 'price' ) );
	}

	public function test_plan_periods_beat_generic_periods(): void {
		$prices = array(
			self::price( array( 'id' => 1, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'price_night' => 15000, 'priority' => 9 ) ),
			self::price( array( 'id' => 2, 'rate_plan_id' => 3, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'price_night' => 12000 ) ),
		);
		$this->assertSame( 12000, PricingEngine::night_price( self::TYPE, 3, $prices, '2026-07-10', 2, 0 ) );
		$this->assertSame( 15000, PricingEngine::night_price( self::TYPE, 4, $prices, '2026-07-10', 2, 0 ) );
	}

	public function test_extra_guests_above_base_occupancy(): void {
		$this->assertSame( 13000, PricingEngine::night_price( self::TYPE, null, array(), '2026-07-10', 3, 0 ) );
		$this->assertSame( 11500, PricingEngine::night_price( self::TYPE, null, array(), '2026-07-10', 2, 1 ) );
		// One adult and one child fit in the base occupancy.
		$this->assertSame( 10000, PricingEngine::night_price( self::TYPE, null, array(), '2026-07-10', 1, 1 ) );
	}

	public function test_plan_modifier_and_adjustments(): void {
		$plan = array(
			'id'          => 3,
			'modifier_bp' => -1000,
		);
		$adjustments = array(
			self::adj( array( 'id' => 1, 'kind' => 'supplement', 'name' => 'Pulizie', 'amount' => 3000 ) ),
			self::adj( array( 'id' => 2, 'kind' => 'long_stay', 'name' => 'Settimana', 'percent_bp' => 1000, 'min_nights' => 7 ) ),
			self::adj( array( 'id' => 3, 'kind' => 'supplement', 'name' => 'Colazione', 'amount' => 800, 'per' => 'person_night', 'is_optional' => true ) ),
		);
		$q = PricingEngine::quote( self::TYPE, $plan, array(), $adjustments, '2026-07-01', '2026-07-04', 2, 0 );
		$this->assertSame( -3000, $q['modifier'] );
		$this->assertSame( array( 'Pulizie' ), array_column( $q['adjustments'], 'name' ) );
		$this->assertSame( 27000 + 3000, $q['total'] );

		$q = PricingEngine::quote( self::TYPE, $plan, array(), $adjustments, '2026-07-01', '2026-07-08', 2, 0, array( 3 ) );
		$this->assertSame( array( 'Pulizie', 'Settimana', 'Colazione' ), array_column( $q['adjustments'], 'name' ) );
		// 7 nights: 70000 − 10% = 63000; +3000; −6300; +800 × 2 × 7.
		$this->assertSame( 63000 + 3000 - 6300 + 11200, $q['total'] );
	}

	public function test_tourist_tax_is_a_separate_line(): void {
		$tax = array(
			'tourist_tax_enabled'    => true,
			'tourist_tax_amount'     => 200,
			'tourist_tax_max_nights' => 5,
			'tourist_tax_min_age'    => 14,
		);
		$q = PricingEngine::quote( self::TYPE, null, array(), array(), '2026-07-01', '2026-07-08', 2, 1, array(), $tax );
		$this->assertSame( 200 * 5 * 2, $q['tax'] );
		$this->assertSame( $q['subtotal'] + $q['tax'], $q['total'] );
	}

	public function test_percent_rounds_half_away_from_zero(): void {
		$this->assertSame( 1, PricingEngine::percent( 5, 1000 ) );
		$this->assertSame( -1, PricingEngine::percent( 5, -1000 ) );
		$this->assertSame( 0, PricingEngine::percent( 4, 1000 ) );
	}

	public function test_deposit_and_cancellation_policies(): void {
		$deposit = Policies::deposit( array( 'type' => 'percent', 'value' => 3000, 'due_days' => 0 ) );
		$this->assertSame( 9000, Policies::deposit_amount( $deposit, 30000, 10000 ) );
		$this->assertSame( '2026-06-01', Policies::deposit_due_date( $deposit, '2026-07-01', '2026-06-01' ) );
		$this->assertSame( 10000, Policies::deposit_amount( Policies::deposit( array( 'type' => 'first_night' ) ), 30000, 10000 ) );
		$this->assertSame( 0, Policies::deposit_amount( Policies::deposit( array( 'type' => 'bogus' ) ), 30000, 10000 ) );

		$cancel = Policies::cancellation( array( 'free_until_days' => 7, 'penalty' => 'first_night' ) );
		$this->assertSame( 0, Policies::cancellation_penalty( $cancel, '2026-07-10', '2026-07-01', 30000, 10000 ) );
		$this->assertSame( 10000, Policies::cancellation_penalty( $cancel, '2026-07-10', '2026-07-05', 30000, 10000 ) );
		$this->assertSame( 30000, Policies::cancellation_penalty( Policies::cancellation( array( 'refundable' => false ) ), '2026-07-10', '2026-06-01', 30000, 10000 ) );
	}
}
