<?php
/**
 * The contract every payment provider implements. The rest of the plugin talks only to
 * this interface (through PaymentService) and never knows which provider is in use.
 *
 * Generic operations → methods:
 *   createCheckoutSession() → create_checkout_session()
 *   createPayment()         → PaymentService::start_online_payment() (payment row + checkout)
 *   getPaymentStatus()      → get_payment_status()
 *   capturePayment()        → capture_payment()
 *   cancelPayment()         → cancel_payment()
 *   refundPayment()         → refund_payment()
 *   handleWebhook()         → handle_webhook() (authenticates and translates a notification)
 *
 * Adding a provider = a new class implementing this interface (usually extending
 * AbstractProvider), registered through the `bkng_payment_providers` filter.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments\Providers;

use BookingManager\Payments\CheckoutRequest;
use BookingManager\Payments\CheckoutSession;
use BookingManager\Payments\PaymentAccount;
use BookingManager\Payments\ProviderEvent;
use BookingManager\Payments\RefundResult;
use BookingManager\Payments\WebhookNotification;

defined( 'ABSPATH' ) || exit;

interface PaymentProvider {

	/** Stable identifier stored with payments ("stripe", "nexi", "manual"). */
	public function id(): string;

	public function label(): string;

	/**
	 * Capabilities of this provider for this account (some depend on the merchant's contract).
	 *
	 * @return string[] Capability::* values
	 */
	public function capabilities( PaymentAccount $account ): array;

	public function supports( string $capability, PaymentAccount $account ): bool;

	/**
	 * Configuration fields shown to the administrator.
	 *
	 * @return array<int, array{name: string, label: string, type: string, secret: bool, required: bool, help?: string, placeholder?: string}>
	 */
	public function config_fields(): array;

	/**
	 * Validates what the administrator entered.
	 *
	 * @param array<string, mixed> $input
	 * @return array{credentials: array<string, mixed>, settings: array<string, mixed>}|\WP_Error
	 */
	public function configure( array $input, PaymentAccount $account ): array|\WP_Error;

	/**
	 * Checks the credentials against the provider (a harmless read call).
	 *
	 * @return array<string, mixed>|\WP_Error settings to remember (e.g. livemode, account name)
	 */
	public function verify( PaymentAccount $account ): array|\WP_Error;

	/**
	 * Registers our notification endpoint at the provider, when the provider allows it.
	 *
	 * @return array{credentials?: array<string, mixed>, settings?: array<string, mixed>}|\WP_Error
	 */
	public function setup_webhook( PaymentAccount $account, string $url ): array|\WP_Error;

	/** Removes what setup_webhook() created (best effort). */
	public function remove_webhook( PaymentAccount $account ): void;

	public function create_checkout_session( PaymentAccount $account, CheckoutRequest $request ): CheckoutSession|\WP_Error;

	/**
	 * Asks the provider where a payment stands (reconciliation, never the browser's word).
	 *
	 * @param array<string, mixed> $payment payments row
	 */
	public function get_payment_status( PaymentAccount $account, array $payment ): ProviderEvent|\WP_Error;

	/** @param array<string, mixed> $payment */
	public function capture_payment( PaymentAccount $account, array $payment, int $amount_cents, string $idempotency_key ): ProviderEvent|\WP_Error;

	/**
	 * Stops an open payment (closes the hosted page or voids an authorization).
	 *
	 * @param array<string, mixed> $payment
	 */
	public function cancel_payment( PaymentAccount $account, array $payment ): bool|\WP_Error;

	/** @param array<string, mixed> $payment */
	public function refund_payment( PaymentAccount $account, array $payment, int $amount_cents, string $idempotency_key, string $reason ): RefundResult|\WP_Error;

	/**
	 * Authenticates a notification (signature or provider lookup) and translates it.
	 */
	public function handle_webhook( PaymentAccount $account, \WP_REST_Request $request ): WebhookNotification|\WP_Error;
}
