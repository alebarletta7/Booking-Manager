<?php
/**
 * What a payment provider can do. The admin screens and the services check these before
 * offering an action: a provider never gets asked for something it does not support.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

defined( 'ABSPATH' ) || exit;

final class Capability {

	/** Customers can pay online (hosted checkout). */
	public const ONLINE_CHECKOUT = 'online_checkout';
	public const REFUNDS         = 'refunds';
	public const PARTIAL_REFUNDS = 'partial_refunds';
	/** Pre-authorization now, capture later. */
	public const AUTHORIZATION = 'authorization';
	public const CAPTURE       = 'capture';
	public const RECURRING     = 'recurring';
	public const TOKENIZATION  = 'tokenization';
	public const APPLE_PAY     = 'apple_pay';
	public const GOOGLE_PAY    = 'google_pay';
	/** Notifications carry a verifiable signature. */
	public const SIGNED_WEBHOOKS = 'signed_webhooks';
	/** Money really moves through the provider (false for cash/POS recorded by hand). */
	public const MOVES_MONEY = 'moves_money';
}
