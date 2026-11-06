<?php
/**
 * What a provider needs to open a hosted payment page.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

defined( 'ABSPATH' ) || exit;

final class CheckoutRequest {

	/** @param array<string, string> $metadata */
	public function __construct(
		public readonly int $payment_id,
		public readonly int $booking_id,
		public readonly string $reference,
		public readonly int $amount_cents,
		public readonly string $currency,
		public readonly string $description,
		public readonly ?string $customer_email,
		public readonly string $success_url,
		public readonly string $cancel_url,
		public readonly int $expires_at,
		public readonly string $idempotency_key,
		public readonly string $locale = 'it',
		public readonly array $metadata = array()
	) {}
}
