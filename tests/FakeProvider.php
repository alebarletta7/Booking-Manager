<?php
/**
 * A payment provider for tests: hosted pages, status lookups, refunds and notifications
 * without any network. Never shipped in the plugin.
 *
 * Notifications are JSON {id, type, session, amount} authenticated by the header
 * X-Fake-Secret, which must equal the account's credential "secret".
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests;

use BookingManager\Payments\Capability;
use BookingManager\Payments\CheckoutRequest;
use BookingManager\Payments\CheckoutSession;
use BookingManager\Payments\PaymentAccount;
use BookingManager\Payments\ProviderEvent;
use BookingManager\Payments\Providers\AbstractProvider;
use BookingManager\Payments\RefundResult;
use BookingManager\Payments\TransactionType;
use BookingManager\Payments\WebhookNotification;

final class FakeProvider extends AbstractProvider {

	public const ID = 'fake';

	/** @var array<string, ProviderEvent> what get_payment_status() answers, by session id */
	public static array $status = array();

	/** @var array<int, array{amount: int, key: string}> refunds asked */
	public static array $refunds = array();

	public static bool $fail_checkout = false;

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'Fake';
	}

	public function capabilities( PaymentAccount $account ): array {
		return array( Capability::ONLINE_CHECKOUT, Capability::REFUNDS, Capability::PARTIAL_REFUNDS, Capability::MOVES_MONEY );
	}

	public function create_checkout_session( PaymentAccount $account, CheckoutRequest $request ): CheckoutSession|\WP_Error {
		if ( self::$fail_checkout ) {
			return new \WP_Error( 'fake_down', 'Provider down', array( 'status' => 400 ) );
		}
		return new CheckoutSession( 'cs_' . $request->payment_id, 'https://pay.example.org/cs_' . $request->payment_id, time() + 1800, null, false );
	}

	public function get_payment_status( PaymentAccount $account, array $payment ): ProviderEvent|\WP_Error {
		return self::$status[ (string) $payment['external_id'] ] ?? new ProviderEvent( ProviderEvent::IGNORED );
	}

	public function cancel_payment( PaymentAccount $account, array $payment ): bool|\WP_Error {
		return true;
	}

	public function refund_payment( PaymentAccount $account, array $payment, int $amount_cents, string $idempotency_key, string $reason ): RefundResult|\WP_Error {
		self::$refunds[] = array(
			'amount' => $amount_cents,
			'key'    => $idempotency_key,
		);
		return new RefundResult( 're_' . md5( $idempotency_key ), TransactionType::SUCCEEDED, $amount_cents );
	}

	public function handle_webhook( PaymentAccount $account, \WP_REST_Request $request ): WebhookNotification|\WP_Error {
		if ( ! hash_equals( (string) ( $account->credentials['secret'] ?? '' ), (string) $request->get_header( 'x_fake_secret' ) ) ) {
			return new \WP_Error( 'bkng_webhook_signature', 'Invalid signature', array( 'status' => 400 ) );
		}
		$body = (array) json_decode( $request->get_body(), true );
		$kind = 'paid' === ( $body['type'] ?? '' ) ? ProviderEvent::PAYMENT_SUCCEEDED : ProviderEvent::PAYMENT_FAILED;
		return new WebhookNotification(
			(string) ( $body['id'] ?? '' ),
			(string) ( $body['type'] ?? '' ),
			false,
			array( new ProviderEvent( $kind, null, (string) ( $body['session'] ?? '' ), null, isset( $body['amount'] ) ? (int) $body['amount'] : null, 'EUR' ) )
		);
	}
}
