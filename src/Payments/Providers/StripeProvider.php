<?php
/**
 * Stripe, connected through a restricted API key (rk_…) of the establishment's own Stripe
 * account: the establishment is the merchant and Stripe pays it directly; the plugin vendor
 * never touches the money. This is the model Stripe documents for self-hosted plugins
 * (docs.stripe.com/stripe-apps/plugins/decide-migration): Connect requires a central
 * platform server, and full secret keys (sk_…) must no longer be pasted into plugins.
 *
 * Checkout is Stripe's hosted page (cards, Apple Pay, Google Pay and the other methods the
 * establishment enables in its Dashboard): card data never reaches WordPress.
 *
 * Restricted key permissions needed: Checkout Sessions (write), Charges and Refunds (write),
 * PaymentIntents (read), Webhook Endpoints (write — only to register the webhook by itself).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments\Providers;

use BookingManager\Core\Clock;
use BookingManager\Payments\Capability;
use BookingManager\Payments\CheckoutRequest;
use BookingManager\Payments\CheckoutSession;
use BookingManager\Payments\PaymentAccount;
use BookingManager\Payments\ProviderEvent;
use BookingManager\Payments\RefundResult;
use BookingManager\Payments\TransactionType;
use BookingManager\Payments\WebhookNotification;

defined( 'ABSPATH' ) || exit;

class StripeProvider extends AbstractProvider {

	public const ID = 'stripe';

	/** Seconds a signed webhook stays valid (Stripe libraries' default). */
	public const TOLERANCE = 300;

	public const EVENTS = array(
		'checkout.session.completed',
		'checkout.session.async_payment_succeeded',
		'checkout.session.async_payment_failed',
		'checkout.session.expired',
		'payment_intent.payment_failed',
		'refund.created',
		'refund.updated',
		'refund.failed',
		'charge.dispute.created',
	);

	public function __construct( private StripeClient $client, private Clock $clock ) {}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'Stripe';
	}

	public function capabilities( PaymentAccount $account ): array {
		return array(
			Capability::ONLINE_CHECKOUT,
			Capability::REFUNDS,
			Capability::PARTIAL_REFUNDS,
			Capability::APPLE_PAY,
			Capability::GOOGLE_PAY,
			Capability::SIGNED_WEBHOOKS,
			Capability::MOVES_MONEY,
		);
	}

	public function config_fields(): array {
		return array(
			array(
				'name'        => 'secret_key',
				'label'       => __( 'Chiave limitata Stripe', 'booking-manager' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'placeholder' => 'rk_live_…',
				'help'        => __( 'Inizia con rk_test_ (prova) o rk_live_ (reale). Non usare la chiave segreta sk_.', 'booking-manager' ),
			),
			array(
				'name'        => 'webhook_secret',
				'label'       => __( 'Segreto del webhook (solo se lo crei a mano)', 'booking-manager' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => false,
				'placeholder' => 'whsec_…',
				'help'        => __( 'Lascia vuoto: il gestionale registra il webhook da solo.', 'booking-manager' ),
			),
		);
	}

	public function configure( array $input, PaymentAccount $account ): array|\WP_Error {
		$key    = trim( (string) ( $input['secret_key'] ?? '' ) );
		$secret = trim( (string) ( $input['webhook_secret'] ?? '' ) );
		$errors = array();
		if ( '' === $key ) {
			$key = (string) ( $account->credentials['secret_key'] ?? '' );
			if ( '' === $key ) {
				$errors['secret_key'] = __( 'Incolla la chiave limitata di Stripe.', 'booking-manager' );
			}
		} elseif ( preg_match( '/^sk_(test|live)_/', $key ) ) {
			$errors['secret_key'] = __( 'Questa è la chiave segreta completa: per sicurezza Stripe chiede ai plugin di usare una chiave limitata (rk_…). Creala dall\'app Booking Manager su Stripe o da Sviluppatori → Chiavi API.', 'booking-manager' );
		} elseif ( ! preg_match( '/^rk_(test|live)_[A-Za-z0-9]{16,}$/', $key ) ) {
			$errors['secret_key'] = __( 'La chiave deve iniziare con rk_test_ o rk_live_.', 'booking-manager' );
		}
		if ( ! isset( $errors['secret_key'] ) && ( str_starts_with( $key, 'rk_live_' ) ) !== $account->is_live() ) {
			$errors['secret_key'] = $account->is_live()
				? __( 'Per la modalità reale serve una chiave rk_live_.', 'booking-manager' )
				: __( 'Per la modalità di prova serve una chiave rk_test_.', 'booking-manager' );
		}
		if ( '' !== $secret && ! preg_match( '/^whsec_[A-Za-z0-9]{16,}$/', $secret ) ) {
			$errors['webhook_secret'] = __( 'Il segreto del webhook inizia con whsec_.', 'booking-manager' );
		}
		if ( $errors ) {
			return new \WP_Error( 'bkng_validation', __( 'Controlla i dati di Stripe.', 'booking-manager' ), array( 'status' => 400, 'fields' => $errors ) );
		}
		$credentials = array( 'secret_key' => $key );
		$settings    = array( 'key_hint' => substr( $key, 0, 8 ) . '…' . substr( $key, -4 ) );
		if ( '' !== $secret ) {
			$credentials['webhook_secret'] = $secret;
			$settings['webhook']           = 'manual';
		}
		return array(
			'credentials' => $credentials,
			'settings'    => $settings,
		);
	}

	public function verify( PaymentAccount $account ): array|\WP_Error {
		$r = $this->client->request( 'GET', 'checkout/sessions', $this->key( $account ), array( 'limit' => 1 ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array( 'livemode' => $account->is_live() );
	}

	public function setup_webhook( PaymentAccount $account, string $url ): array|\WP_Error {
		if ( 'manual' === ( $account->settings['webhook'] ?? '' ) && ! empty( $account->credentials['webhook_secret'] ) ) {
			return array();
		}
		$this->remove_webhook( $account );
		$r = $this->client->request(
			'POST',
			'webhook_endpoints',
			$this->key( $account ),
			array(
				'url'            => $url,
				'enabled_events' => self::EVENTS,
				'description'    => 'Booking Manager — ' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'metadata'       => array( 'bkng_account' => (string) $account->id ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array(
			'credentials' => array( 'webhook_secret' => (string) ( $r['secret'] ?? '' ) ),
			'settings'    => array(
				'webhook'             => 'auto',
				'webhook_endpoint_id' => (string) ( $r['id'] ?? '' ),
			),
		);
	}

	public function remove_webhook( PaymentAccount $account ): void {
		$id = (string) ( $account->settings['webhook_endpoint_id'] ?? '' );
		if ( '' !== $id && ! empty( $account->credentials['secret_key'] ) ) {
			$this->client->request( 'DELETE', 'webhook_endpoints/' . rawurlencode( $id ), $this->key( $account ) );
		}
	}

	public function create_checkout_session( PaymentAccount $account, CheckoutRequest $request ): CheckoutSession|\WP_Error {
		$now      = $this->clock->now_utc()->getTimestamp();
		$expires  = max( $now + 31 * 60, min( $now + 23 * 3600, $request->expires_at ) );
		$metadata = array_merge(
			$request->metadata,
			array(
				'bkng_payment_id' => (string) $request->payment_id,
				'bkng_booking_id' => (string) $request->booking_id,
			)
		);
		$params   = array(
			'mode'                => 'payment',
			'submit_type'         => 'book',
			'locale'              => in_array( $request->locale, array( 'it', 'en', 'de', 'fr', 'es' ), true ) ? $request->locale : 'auto',
			'client_reference_id' => (string) $request->payment_id,
			'success_url'         => $request->success_url,
			'cancel_url'          => $request->cancel_url,
			'expires_at'          => $expires,
			'line_items'          => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => strtolower( $request->currency ),
						'unit_amount'  => $request->amount_cents,
						'product_data' => array( 'name' => mb_substr( $request->description, 0, 250 ) ),
					),
				),
			),
			'metadata'            => $metadata,
			'payment_intent_data' => array(
				'description' => mb_substr( $request->description, 0, 250 ),
				'metadata'    => $metadata,
			),
		);
		if ( $request->customer_email ) {
			$params['customer_email'] = $request->customer_email;
		}
		$r = $this->client->request( 'POST', 'checkout/sessions', $this->key( $account ), $params, $request->idempotency_key );
		if ( is_wp_error( $r ) && self::managed_payments_refusal( $r ) ) {
			// The account has Managed Payments (Stripe as merchant of record, digital goods
			// only) switched on by default: a stay is not a digital product and the
			// property must stay the merchant, so this session opts out explicitly.
			$params['managed_payments'] = array( 'enabled' => 'false' );
			$r                          = $this->client->request( 'POST', 'checkout/sessions', $this->key( $account ), $params, $request->idempotency_key . '-direct' );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( empty( $r['id'] ) || empty( $r['url'] ) ) {
			return new \WP_Error( 'bkng_stripe_bad_response', __( 'Risposta non valida da Stripe.', 'booking-manager' ), array( 'status' => 502 ) );
		}
		return new CheckoutSession( (string) $r['id'], (string) $r['url'], isset( $r['expires_at'] ) ? (int) $r['expires_at'] : $expires, self::id_of( $r['payment_intent'] ?? null ), isset( $r['livemode'] ) ? (bool) $r['livemode'] : null );
	}

	public function get_payment_status( PaymentAccount $account, array $payment ): ProviderEvent|\WP_Error {
		if ( empty( $payment['external_id'] ) ) {
			return new ProviderEvent( ProviderEvent::IGNORED );
		}
		$r = $this->client->request( 'GET', 'checkout/sessions/' . rawurlencode( (string) $payment['external_id'] ), $this->key( $account ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return $this->from_session( $r, null );
	}

	public function cancel_payment( PaymentAccount $account, array $payment ): bool|\WP_Error {
		if ( empty( $payment['external_id'] ) ) {
			return true;
		}
		$r = $this->client->request( 'POST', 'checkout/sessions/' . rawurlencode( (string) $payment['external_id'] ) . '/expire', $this->key( $account ), array(), 'expire-' . $payment['idempotency_key'] );
		return is_wp_error( $r ) ? $r : true;
	}

	public function refund_payment( PaymentAccount $account, array $payment, int $amount_cents, string $idempotency_key, string $reason ): RefundResult|\WP_Error {
		if ( empty( $payment['provider_payment_id'] ) ) {
			return new \WP_Error( 'bkng_refund_unavailable', __( 'Il pagamento non risulta ancora incassato da Stripe.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$r = $this->client->request(
			'POST',
			'refunds',
			$this->key( $account ),
			array(
				'payment_intent' => (string) $payment['provider_payment_id'],
				'amount'         => $amount_cents,
				'reason'         => 'requested_by_customer',
				'metadata'       => array(
					'bkng_payment_id' => (string) $payment['id'],
					'bkng_booking_id' => (string) $payment['booking_id'],
					'bkng_refund_key' => $idempotency_key,
				),
			),
			$idempotency_key
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return new RefundResult( (string) ( $r['id'] ?? '' ), self::refund_status( (string) ( $r['status'] ?? '' ) ), (int) ( $r['amount'] ?? $amount_cents ), isset( $r['failure_reason'] ) ? (string) $r['failure_reason'] : null );
	}

	public function handle_webhook( PaymentAccount $account, \WP_REST_Request $request ): WebhookNotification|\WP_Error {
		$secret = (string) ( $account->credentials['webhook_secret'] ?? '' );
		$body   = (string) $request->get_body();
		if ( '' === $secret || ! $this->signature_ok( $body, (string) $request->get_header( 'stripe_signature' ), $secret ) ) {
			return new \WP_Error( 'bkng_webhook_signature', 'Invalid signature', array( 'status' => 400 ) );
		}
		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) || ! is_array( $event['data']['object'] ?? null ) ) {
			return new \WP_Error( 'bkng_webhook_payload', 'Invalid payload', array( 'status' => 400 ) );
		}
		$type     = (string) $event['type'];
		$object   = $event['data']['object'];
		$livemode = isset( $event['livemode'] ) ? (bool) $event['livemode'] : null;
		return new WebhookNotification(
			(string) $event['id'],
			$type,
			$livemode,
			array( $this->translate( $type, $object, $livemode ) ),
			array(
				'type'           => $type,
				'object'         => (string) ( $object['object'] ?? '' ),
				'object_id'      => (string) ( $object['id'] ?? '' ),
				'payment_intent' => self::id_of( $object['payment_intent'] ?? null ),
				'amount'         => $object['amount_total'] ?? $object['amount'] ?? null,
				'status'         => $object['status'] ?? null,
				'payment_status' => $object['payment_status'] ?? null,
				'livemode'       => $livemode,
			)
		);
	}

	/** Stripe-Signature: t=timestamp,v1=hmac[,v1=…] — HMAC-SHA256 of "t.body" with the endpoint secret. */
	public function signature_ok( string $body, string $header, string $secret ): bool {
		$timestamp  = null;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] && ctype_digit( $kv[1] ) ) {
				$timestamp = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$signatures[] = $kv[1];
			}
		}
		if ( null === $timestamp || ! $signatures || abs( $this->clock->now_utc()->getTimestamp() - $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string, mixed> $object */
	private function translate( string $type, array $object, ?bool $livemode ): ProviderEvent {
		switch ( $type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
			case 'checkout.session.async_payment_failed':
			case 'checkout.session.expired':
				return $this->from_session( $object, $type, $livemode );
			case 'payment_intent.payment_failed':
				$error = is_array( $object['last_payment_error'] ?? null ) ? $object['last_payment_error'] : array();
				return new ProviderEvent(
					ProviderEvent::ATTEMPT_FAILED,
					self::ref( $object ),
					null,
					(string) ( $object['id'] ?? '' ),
					isset( $object['amount'] ) ? (int) $object['amount'] : null,
					null,
					null,
					null,
					null,
					isset( $error['decline_code'] ) ? (string) $error['decline_code'] : ( isset( $error['code'] ) ? (string) $error['code'] : null ),
					isset( $error['message'] ) ? mb_substr( (string) $error['message'], 0, 250 ) : null,
					$livemode
				);
			case 'refund.created':
			case 'refund.updated':
			case 'refund.failed':
				return new ProviderEvent(
					ProviderEvent::REFUND_UPDATED,
					self::ref( $object ),
					null,
					self::id_of( $object['payment_intent'] ?? null ),
					(int) ( $object['amount'] ?? 0 ),
					isset( $object['currency'] ) ? strtoupper( (string) $object['currency'] ) : null,
					null,
					(string) ( $object['id'] ?? '' ),
					'refund.failed' === $type ? TransactionType::FAILED : self::refund_status( (string) ( $object['status'] ?? '' ) ),
					isset( $object['failure_reason'] ) ? (string) $object['failure_reason'] : null,
					null,
					$livemode,
					isset( $object['metadata']['bkng_refund_key'] ) ? (string) $object['metadata']['bkng_refund_key'] : null
				);
			case 'charge.dispute.created':
				return new ProviderEvent( ProviderEvent::DISPUTE_OPENED, null, null, self::id_of( $object['payment_intent'] ?? null ), (int) ( $object['amount'] ?? 0 ), null, null, null, null, isset( $object['reason'] ) ? (string) $object['reason'] : null, null, $livemode );
		}
		return new ProviderEvent( ProviderEvent::IGNORED );
	}

	/** @param array<string, mixed> $session */
	private function from_session( array $session, ?string $type, ?bool $livemode = null ): ProviderEvent {
		$livemode = $livemode ?? ( isset( $session['livemode'] ) ? (bool) $session['livemode'] : null );
		$status   = (string) ( $session['status'] ?? '' );
		$paid     = (string) ( $session['payment_status'] ?? '' );
		if ( 'checkout.session.async_payment_failed' === $type ) {
			$kind = ProviderEvent::PAYMENT_FAILED;
		} elseif ( 'checkout.session.expired' === $type || 'expired' === $status ) {
			$kind = 'paid' === $paid ? ProviderEvent::PAYMENT_SUCCEEDED : ProviderEvent::PAYMENT_CANCELED;
		} elseif ( 'complete' === $status && in_array( $paid, array( 'paid', 'no_payment_required' ), true ) ) {
			$kind = ProviderEvent::PAYMENT_SUCCEEDED;
		} elseif ( 'complete' === $status ) {
			$kind = ProviderEvent::PAYMENT_PROCESSING; // Delayed method: the outcome comes later.
		} else {
			$kind = ProviderEvent::IGNORED; // Still open: the customer is on the page.
		}
		return new ProviderEvent(
			$kind,
			self::ref( $session ),
			(string) ( $session['id'] ?? '' ),
			self::id_of( $session['payment_intent'] ?? null ),
			isset( $session['amount_total'] ) ? (int) $session['amount_total'] : null,
			isset( $session['currency'] ) ? strtoupper( (string) $session['currency'] ) : null,
			null, // The session lists allowed methods, not the one used.
			null,
			null,
			null,
			null,
			$livemode
		);
	}

	private static function managed_payments_refusal( \WP_Error $error ): bool {
		$message = strtolower( $error->get_error_message() );
		return str_contains( $message, 'managed-payments' ) || str_contains( $message, 'managed payments' ) || str_contains( $message, 'tax code' );
	}

	/** @param array<string, mixed> $object */
	private static function ref( array $object ): ?int {
		$value = $object['metadata']['bkng_payment_id'] ?? $object['client_reference_id'] ?? null;
		return is_scalar( $value ) && ctype_digit( (string) $value ) ? (int) $value : null;
	}

	/** @param mixed $value an id or an expanded object */
	private static function id_of( $value ): ?string {
		if ( is_array( $value ) ) {
			$value = $value['id'] ?? null;
		}
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	private static function refund_status( string $status ): string {
		return match ( $status ) {
			'succeeded' => TransactionType::SUCCEEDED,
			'failed' => TransactionType::FAILED,
			'canceled' => TransactionType::CANCELED,
			default => TransactionType::PENDING, // pending, requires_action
		};
	}

	private function key( PaymentAccount $account ): string {
		return (string) ( $account->credentials['secret_key'] ?? '' );
	}
}
