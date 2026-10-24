<?php
/**
 * Payment status of a booking, money formatting, guest cancellation terms.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Unit;

use BookingManager\Bookings\PaymentSummary;
use BookingManager\Core\Money;
use BookingManager\Frontend\PublicBookingService;

final class PaymentSummaryTest extends \WP_UnitTestCase {

	public function test_payment_status(): void {
		$this->assertSame( 'unpaid', PaymentSummary::status( 30000, 9000, 0, 0 ) );
		$this->assertSame( 'partially_paid', PaymentSummary::status( 30000, 9000, 5000, 0 ) );
		$this->assertSame( 'deposit_paid', PaymentSummary::status( 30000, 9000, 9000, 0 ) );
		$this->assertSame( 'partially_paid', PaymentSummary::status( 30000, 0, 9000, 0 ) );
		$this->assertSame( 'paid', PaymentSummary::status( 30000, 9000, 30000, 0 ) );
		$this->assertSame( 'paid', PaymentSummary::status( 30000, 9000, 35000, 0 ) );
		$this->assertSame( 'refunded', PaymentSummary::status( 30000, 9000, 30000, 30000 ) );
		$this->assertSame( 'partially_paid', PaymentSummary::status( 30000, 9000, 30000, 25000 ) );
	}

	public function test_money(): void {
		$this->assertSame( '€ 1.234,50', Money::format( 123450 ) );
		$this->assertSame( '-€ 0,05', Money::format( -5 ) );
		$this->assertSame( 'SEK 10,00', Money::format( 1000, 'sek' ) );
	}

	public function test_guest_cancellation_terms(): void {
		$booking = array(
			'status'  => 'confirmed',
			'arrival' => '2026-12-20',
		);
		$room    = array(
			'rate_plan_id' => 7,
			'arrival'      => '2026-12-20',
			'price_total'  => 30000,
			'nightly'      => array( array( 'date' => '2026-12-20', 'price' => 10000 ) ),
		);
		$free    = array( '7' => array( 'cancellation' => array( 'refundable' => true, 'free_until_days' => 7, 'penalty' => 'first_night' ) ) );
		$this->assertSame( array( 'online' => true, 'penalty' => 0 ), PublicBookingService::cancellation_terms( $booking, array( $room ), $free, '2026-12-01', true ) );
		$this->assertSame( array( 'online' => true, 'penalty' => 10000 ), PublicBookingService::cancellation_terms( $booking, array( $room ), $free, '2026-12-18', true ) );
		$this->assertFalse( PublicBookingService::cancellation_terms( $booking, array( $room ), $free, '2026-12-01', false )['online'] );
		$this->assertFalse( PublicBookingService::cancellation_terms( $booking, array( $room ), $free, '2026-12-20', true )['online'], 'not on the arrival day' );
		$nr = array( '7' => array( 'cancellation' => array( 'refundable' => false ) ) );
		$this->assertSame( array( 'online' => false, 'penalty' => 30000 ), PublicBookingService::cancellation_terms( $booking, array( $room ), $nr, '2026-12-01', true ) );
	}
}
