<?php
/**
 * The payment status of a booking, from its total, the deposit asked and the money
 * collected net of refunds (pure, unit-tested). Kept apart from the booking status.
 *
 *   unpaid          nothing collected
 *   deposit_paid    the deposit asked is covered, the balance is still due
 *   partially_paid  something collected, less than the deposit (or no deposit asked)
 *   paid            the total is covered
 *   refunded        money was collected and all of it was given back
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Bookings;

defined( 'ABSPATH' ) || exit;

final class PaymentSummary {

	public const UNPAID         = 'unpaid';
	public const DEPOSIT_PAID   = 'deposit_paid';
	public const PARTIALLY_PAID = 'partially_paid';
	public const PAID           = 'paid';
	public const REFUNDED       = 'refunded';

	public const ALL = array( self::UNPAID, self::DEPOSIT_PAID, self::PARTIALLY_PAID, self::PAID, self::REFUNDED );

	public static function status( int $total, int $deposit_due, int $paid, int $refunded ): string {
		$net = $paid - $refunded;
		if ( $paid > 0 && $net <= 0 ) {
			return self::REFUNDED;
		}
		if ( $net <= 0 ) {
			return self::UNPAID;
		}
		if ( $net >= $total ) {
			return self::PAID;
		}
		return $deposit_due > 0 && $net >= $deposit_due ? self::DEPOSIT_PAID : self::PARTIALLY_PAID;
	}
}
