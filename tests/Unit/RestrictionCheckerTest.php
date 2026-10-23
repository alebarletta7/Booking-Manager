<?php
/**
 * Stay rules.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Unit;

use BookingManager\Availability\RestrictionChecker;
use BookingManager\Properties\PropertySettings;
use PHPUnit\Framework\TestCase;

final class RestrictionCheckerTest extends TestCase {

	private static function rule( array $r ): array {
		return $r + array(
			'room_type_id'        => null,
			'rate_plan_id'        => null,
			'min_stay'            => null,
			'max_stay'            => null,
			'min_advance_days'    => null,
			'max_advance_days'    => null,
			'arrival_weekdays'    => null,
			'departure_weekdays'  => null,
			'closed'              => false,
			'closed_to_arrival'   => false,
			'closed_to_departure' => false,
		);
	}

	public function test_property_defaults(): void {
		$settings = PropertySettings::merge( array(), array( 'min_stay' => 2 ) );
		$this->assertArrayHasKey( 'min_stay', RestrictionChecker::check( $settings, array(), '2026-07-01', '2026-07-02', '2026-06-01' ) );
		$this->assertSame( array(), RestrictionChecker::check( $settings, array(), '2026-07-01', '2026-07-03', '2026-06-01' ) );
		$this->assertArrayHasKey( 'arrival', RestrictionChecker::check( $settings, array(), '2026-05-01', '2026-05-03', '2026-06-01' ) );
		$this->assertArrayHasKey( 'dates', RestrictionChecker::check( $settings, array(), '2026-07-03', '2026-07-03', '2026-06-01' ) );
	}

	public function test_rules_on_arrival_date_and_weekdays(): void {
		$settings = PropertySettings::defaults();
		$rules    = array( self::rule( array( 'date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'min_stay' => 7, 'arrival_weekdays' => '6' ) ) );
		// 2026-08-08 is a Saturday.
		$this->assertSame( array(), RestrictionChecker::check( $settings, $rules, '2026-08-08', '2026-08-15', '2026-06-01' ) );
		$errors = RestrictionChecker::check( $settings, $rules, '2026-08-10', '2026-08-12', '2026-06-01' );
		$this->assertArrayHasKey( 'min_stay', $errors );
		$this->assertArrayHasKey( 'arrival_weekday', $errors );
		// Arriving before the period: the rule of the arrival date does not apply.
		$this->assertSame( array(), RestrictionChecker::check( $settings, $rules, '2026-07-30', '2026-08-02', '2026-06-01' ) );
	}

	public function test_closed_nights_and_closed_to_departure(): void {
		$settings = PropertySettings::defaults();
		$rules    = array(
			self::rule( array( 'date_from' => '2026-12-24', 'date_to' => '2026-12-25', 'closed' => true ) ),
			self::rule( array( 'date_from' => '2026-12-31', 'date_to' => '2026-12-31', 'closed_to_departure' => true ) ),
		);
		$this->assertArrayHasKey( 'closed', RestrictionChecker::check( $settings, $rules, '2026-12-23', '2026-12-25', '2026-06-01' ) );
		// The night of the 23rd only: the departure day (24th) is not a night of the stay.
		$this->assertSame( array(), RestrictionChecker::check( $settings, $rules, '2026-12-22', '2026-12-24', '2026-06-01' ) );
		$this->assertArrayHasKey( 'closed_to_departure', RestrictionChecker::check( $settings, $rules, '2026-12-28', '2026-12-31', '2026-06-01' ) );
	}

	public function test_type_specific_rules_apply_only_to_that_type(): void {
		$settings = PropertySettings::defaults();
		$rules    = array( self::rule( array( 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'room_type_id' => 5, 'min_stay' => 3 ) ) );
		$this->assertArrayHasKey( 'min_stay', RestrictionChecker::check( $settings, $rules, '2026-07-10', '2026-07-11', '2026-06-01', 5 ) );
		$this->assertSame( array(), RestrictionChecker::check( $settings, $rules, '2026-07-10', '2026-07-11', '2026-06-01', 6 ) );
	}

	public function test_advance_and_same_day_cut_off(): void {
		$settings = PropertySettings::merge( array(), array( 'min_advance_days' => 2, 'max_advance_days' => 30, 'same_day_until' => '18:00' ) );
		$this->assertArrayHasKey( 'min_advance', RestrictionChecker::check( $settings, array(), '2026-06-02', '2026-06-03', '2026-06-01' ) );
		$this->assertArrayHasKey( 'max_advance', RestrictionChecker::check( $settings, array(), '2026-08-01', '2026-08-03', '2026-06-01' ) );
		$settings = PropertySettings::merge( array(), array( 'same_day_until' => '18:00' ) );
		$this->assertArrayHasKey( 'same_day', RestrictionChecker::check( $settings, array(), '2026-06-01', '2026-06-02', '2026-06-01', null, null, '19:30' ) );
		$this->assertSame( array(), RestrictionChecker::check( $settings, array(), '2026-06-01', '2026-06-02', '2026-06-01', null, null, '17:59' ) );
	}
}
