<?php
/**
 * The connection of one property to one provider in one mode (test or live).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

defined( 'ABSPATH' ) || exit;

final class PaymentAccount {

	public const STATUS_INCOMPLETE = 'incomplete';
	public const STATUS_ACTIVE     = 'active';
	public const STATUS_ERROR      = 'error';

	/**
	 * @param array<string, mixed> $credentials decrypted secrets (never leave the server)
	 * @param array<string, mixed> $settings    non-secret configuration and provider facts
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $property_id,
		public readonly string $provider,
		public readonly string $mode,
		public readonly string $status,
		public readonly array $credentials,
		public readonly array $settings
	) {}

	public function is_live(): bool {
		return 'live' === $this->mode;
	}

	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->status;
	}

	/** @param array<string, mixed> $settings */
	public function with_settings( array $settings ): self {
		return new self( $this->id, $this->property_id, $this->provider, $this->mode, $this->status, $this->credentials, array_merge( $this->settings, $settings ) );
	}

	/** @param array<string, mixed> $credentials */
	public function with_credentials( array $credentials ): self {
		return new self( $this->id, $this->property_id, $this->provider, $this->mode, $this->status, array_merge( $this->credentials, $credentials ), $this->settings );
	}
}
