<?php
/**
 * Outcome of a refund request sent to a provider.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

defined( 'ABSPATH' ) || exit;

final class RefundResult {

	public function __construct(
		public readonly ?string $external_ref,
		public readonly string $status,
		public readonly int $amount_cents,
		public readonly ?string $failure_message = null
	) {}
}
