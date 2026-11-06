<?php
/**
 * An authenticated notification received from a provider.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

defined( 'ABSPATH' ) || exit;

final class WebhookNotification {

	/**
	 * @param ProviderEvent[]      $events
	 * @param array<string, mixed> $summary what we keep of the payload: ids, types, amounts — no personal data
	 */
	public function __construct(
		public readonly string $event_id,
		public readonly string $event_type,
		public readonly ?bool $livemode,
		public readonly array $events,
		public readonly array $summary = array()
	) {}
}
