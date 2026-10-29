<?php
/**
 * Something a provider tells us about a payment, in provider-neutral terms. Webhooks and
 * status lookups of every provider are translated into these; PaymentService applies them.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

defined( 'ABSPATH' ) || exit;

final class ProviderEvent {

	public const PAYMENT_SUCCEEDED       = 'payment_succeeded';
	public const PAYMENT_AUTHORIZED      = 'payment_authorized';
	/** The customer approved on the provider's page; the money moves only when we capture (terminals with explicit capture). */
	public const PAYMENT_APPROVED        = 'payment_approved';
	public const PAYMENT_PROCESSING      = 'payment_processing';
	public const PAYMENT_REQUIRES_ACTION = 'payment_requires_action';
	/** A single attempt failed; the customer may still retry on the same page. */
	public const ATTEMPT_FAILED   = 'attempt_failed';
	public const PAYMENT_FAILED   = 'payment_failed';
	public const PAYMENT_CANCELED = 'payment_canceled';
	public const REFUND_UPDATED   = 'refund_updated';
	public const DISPUTE_OPENED   = 'dispute_opened';
	public const IGNORED          = 'ignored';

	public function __construct(
		public readonly string $kind,
		public readonly ?int $payment_ref = null,
		public readonly ?string $external_id = null,
		public readonly ?string $provider_payment_id = null,
		public readonly ?int $amount_cents = null,
		public readonly ?string $currency = null,
		public readonly ?string $method = null,
		public readonly ?string $refund_id = null,
		public readonly ?string $refund_status = null,
		public readonly ?string $failure_code = null,
		public readonly ?string $failure_message = null,
		public readonly ?bool $livemode = null,
		public readonly ?string $refund_request = null
	) {}
}
