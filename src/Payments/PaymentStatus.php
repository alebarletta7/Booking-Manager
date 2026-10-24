<?php
/**
 * States of a payment and the transitions allowed between them.
 *
 *   created ─► pending ─► requires_action ─► authorized ─► paid ─► partially_refunded ─► refunded
 *        └──────────┴──────────┴───────────► failed / cancelled
 *
 * A provider can still report money taken after we considered the attempt failed or
 * cancelled (a delayed method, a session paid seconds before it expired): failed and
 * cancelled may therefore become paid. Nothing else ever goes backwards, so events that
 * arrive late or out of order cannot undo a newer state.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

defined( 'ABSPATH' ) || exit;

final class PaymentStatus {

	public const CREATED            = 'created';
	public const PENDING            = 'pending';
	public const REQUIRES_ACTION    = 'requires_action';
	public const AUTHORIZED         = 'authorized';
	public const PAID               = 'paid';
	public const FAILED             = 'failed';
	public const CANCELLED          = 'cancelled';
	public const PARTIALLY_REFUNDED = 'partially_refunded';
	public const REFUNDED           = 'refunded';

	public const ALL = array( self::CREATED, self::PENDING, self::REQUIRES_ACTION, self::AUTHORIZED, self::PAID, self::FAILED, self::CANCELLED, self::PARTIALLY_REFUNDED, self::REFUNDED );

	/** Waiting for the customer or the provider. */
	public const OPEN = array( self::CREATED, self::PENDING, self::REQUIRES_ACTION );

	/** Money was taken (possibly given back in part or in full). */
	public const SETTLED = array( self::PAID, self::PARTIALLY_REFUNDED, self::REFUNDED );

	private const TRANSITIONS = array(
		self::CREATED            => array( self::PENDING, self::REQUIRES_ACTION, self::AUTHORIZED, self::PAID, self::FAILED, self::CANCELLED ),
		self::PENDING            => array( self::REQUIRES_ACTION, self::AUTHORIZED, self::PAID, self::FAILED, self::CANCELLED ),
		self::REQUIRES_ACTION    => array( self::PENDING, self::AUTHORIZED, self::PAID, self::FAILED, self::CANCELLED ),
		self::AUTHORIZED         => array( self::PAID, self::FAILED, self::CANCELLED ),
		self::PAID               => array( self::PARTIALLY_REFUNDED, self::REFUNDED ),
		self::PARTIALLY_REFUNDED => array( self::PARTIALLY_REFUNDED, self::REFUNDED ),
		self::FAILED             => array( self::PAID ),
		self::CANCELLED          => array( self::PAID ),
		self::REFUNDED           => array(),
	);

	public static function can( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	/** Status that follows from the amounts refunded so far. */
	public static function after_refunds( int $amount, int $refunded ): string {
		if ( $refunded <= 0 ) {
			return self::PAID;
		}
		return $refunded >= $amount ? self::REFUNDED : self::PARTIALLY_REFUNDED;
	}
}
