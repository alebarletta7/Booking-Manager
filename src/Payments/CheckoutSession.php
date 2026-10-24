<?php
/**
 * A hosted payment page opened at the provider.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

defined( 'ABSPATH' ) || exit;

final class CheckoutSession {

	public function __construct(
		public readonly string $external_id,
		public readonly string $url,
		public readonly ?int $expires_at = null,
		public readonly ?string $provider_payment_id = null,
		public readonly ?bool $livemode = null
	) {}
}
