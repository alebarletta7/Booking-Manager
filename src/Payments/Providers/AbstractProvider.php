<?php
/**
 * Defaults for providers: everything not supported answers with a clear error.
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

abstract class AbstractProvider implements PaymentProvider {

	public function supports( string $capability, PaymentAccount $account ): bool {
		return in_array( $capability, $this->capabilities( $account ), true );
	}

	public function config_fields(): array {
		return array();
	}

	public function configure( array $input, PaymentAccount $account ): array|\WP_Error {
		return array(
			'credentials' => array(),
			'settings'    => array(),
		);
	}

	public function verify( PaymentAccount $account ): array|\WP_Error {
		return array();
	}

	public function setup_webhook( PaymentAccount $account, string $url ): array|\WP_Error {
		return array();
	}

	public function remove_webhook( PaymentAccount $account ): void {}

	public function create_checkout_session( PaymentAccount $account, CheckoutRequest $request ): CheckoutSession|\WP_Error {
		return $this->unsupported();
	}

	public function get_payment_status( PaymentAccount $account, array $payment ): ProviderEvent|\WP_Error {
		return $this->unsupported();
	}

	public function capture_payment( PaymentAccount $account, array $payment, int $amount_cents, string $idempotency_key ): ProviderEvent|\WP_Error {
		return $this->unsupported();
	}

	public function cancel_payment( PaymentAccount $account, array $payment ): bool|\WP_Error {
		return $this->unsupported();
	}

	public function refund_payment( PaymentAccount $account, array $payment, int $amount_cents, string $idempotency_key, string $reason ): RefundResult|\WP_Error {
		return $this->unsupported();
	}

	public function handle_webhook( PaymentAccount $account, \WP_REST_Request $request ): WebhookNotification|\WP_Error {
		return $this->unsupported();
	}

	protected function unsupported(): \WP_Error {
		return new \WP_Error( 'bkng_payment_unsupported', __( 'Operazione non disponibile con questo sistema di pagamento.', 'booking-manager' ), array( 'status' => 422 ) );
	}
}
