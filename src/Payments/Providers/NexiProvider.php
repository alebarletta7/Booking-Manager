<?php
/**
 * Nexi XPay (Italy), connected with an API key of the establishment's own XPay terminal:
 * the establishment is the merchant and Nexi pays it directly. Nexi offers no OAuth or
 * partner onboarding for this: the key is generated in the XPay Back Office
 * (Amministrazione → APIKey) and pasted here.
 *
 * Checkout is Nexi's hosted payment page (POST /orders/hpp): card data never reaches
 * WordPress. The methods on the page (cards, Apple Pay, Google Pay, PayPal, Bancomat Pay,
 * MyBank…) are the ones in the merchant's contract.
 *
 * Notifications: Nexi posts the outcome to the notificationUrl given with each order. They
 * carry no signature (only the securityToken of the order), so their content is never
 * trusted: a notification only tells us to read the order with the merchant's API key
 * (GET /orders/{orderId}) and the answer of that authenticated call is what counts. A
 * forged notification can at most make us read the real state again.
 *
 * Not available through the API, therefore not offered: closing a hosted page before it is
 * used (a page abandoned after the booking expired can still be paid: the late-payment
 * rules then confirm or refund the booking).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments\Providers;

use BookingManager\Core\Clock;
use BookingManager\Database\Schema;
use BookingManager\Payments\Capability;
use BookingManager\Payments\CheckoutRequest;
use BookingManager\Payments\CheckoutSession;
use BookingManager\Payments\PaymentAccount;
use BookingManager\Payments\PaymentAccountService;
use BookingManager\Payments\ProviderEvent;
use BookingManager\Payments\RefundResult;
use BookingManager\Payments\TransactionType;
use BookingManager\Payments\WebhookNotification;

defined( 'ABSPATH' ) || exit;

class NexiProvider extends AbstractProvider {

	public const ID = 'nexi';

	/** Our order ids: "BM" + 16 characters derived from the payment's idempotency key (Nexi allows 18). */
	private const ORDER_ID = '/^BM[0-9A-Z]{16}$/';

	/** A hosted page with no attempt this long after its expiry is considered abandoned. */
	private const ABANDONED_AFTER = 2 * HOUR_IN_SECONDS;

	private const LANGUAGES = array(
		'it' => 'ita',
		'en' => 'eng',
		'de' => 'deu',
		'fr' => 'fra',
		'es' => 'spa',
	);

	public function __construct( private NexiClient $client, private Clock $clock ) {}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'Nexi XPay';
	}

	public function capabilities( PaymentAccount $account ): array {
		$capabilities = array(
			Capability::ONLINE_CHECKOUT,
			Capability::REFUNDS,
			Capability::PARTIAL_REFUNDS,
			Capability::MOVES_MONEY,
		);
		// Only what the terminal really has enabled (read from Nexi when connecting).
		$circuits = array_map( 'strtoupper', (array) ( $account->settings['circuits'] ?? array() ) );
		foreach ( $circuits as $circuit ) {
			if ( str_contains( $circuit, 'APPLE' ) ) {
				$capabilities[] = Capability::APPLE_PAY;
			} elseif ( str_contains( $circuit, 'GOOGLE' ) ) {
				$capabilities[] = Capability::GOOGLE_PAY;
			}
		}
		return array_values( array_unique( $capabilities ) );
	}

	public function config_fields(): array {
		return array(
			array(
				'name'        => 'api_key',
				'label'       => __( 'Chiave API XPay', 'booking-manager' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
				'help'        => __( 'La generi nel Back Office XPay, in Amministrazione → APIKey. Per la modalità di prova usa una chiave dell\'ambiente di test Nexi.', 'booking-manager' ),
			),
		);
	}

	public function configure( array $input, PaymentAccount $account ): array|\WP_Error {
		$key = strtolower( trim( (string) ( $input['api_key'] ?? '' ) ) );
		if ( '' === $key ) {
			$key = (string) ( $account->credentials['api_key'] ?? '' );
		}
		if ( '' === $key ) {
			$error = __( 'Incolla la chiave API di XPay.', 'booking-manager' );
		} elseif ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key ) ) {
			$error = __( 'La chiave API di XPay ha la forma xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx.', 'booking-manager' );
		}
		if ( isset( $error ) ) {
			return new \WP_Error( 'bkng_validation', __( 'Controlla i dati di Nexi.', 'booking-manager' ), array( 'status' => 400, 'fields' => array( 'api_key' => $error ) ) );
		}
		return array(
			'credentials' => array( 'api_key' => $key ),
			'settings'    => array( 'key_hint' => substr( $key, 0, 4 ) . '…' . substr( $key, -4 ) ),
		);
	}

	public function verify( PaymentAccount $account ): array|\WP_Error {
		$r = $this->client->request( 'GET', 'payment_methods', $this->key( $account ), $account->is_live() );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$circuits = array();
		foreach ( (array) ( $r['paymentMethods'] ?? array() ) as $method ) {
			if ( is_array( $method ) && ! empty( $method['circuit'] ) ) {
				$circuits[] = sanitize_text_field( (string) $method['circuit'] );
			}
		}
		return array(
			'livemode' => $account->is_live(),
			'circuits' => array_values( array_unique( $circuits ) ),
		);
	}

	/**
	 * Nothing to register: the address goes with each order. A local site cannot be
	 * reached by Nexi (the caller then uses test mode without notifications).
	 */
	public function setup_webhook( PaymentAccount $account, string $url ): array|\WP_Error {
		if ( ! PaymentAccountService::publicly_reachable() ) {
			return new \WP_Error( 'bkng_webhook_unreachable', __( 'Nexi può inviare le notifiche solo a un sito raggiungibile da Internet.', 'booking-manager' ) );
		}
		return array( 'settings' => array( 'webhook' => 'per_order' ) );
	}

	public function create_checkout_session( PaymentAccount $account, CheckoutRequest $request ): CheckoutSession|\WP_Error {
		$order_id = self::order_id( $request->idempotency_key );
		$session  = array(
			'actionType'  => 'PAY',
			'amount'      => (string) $request->amount_cents,
			'captureType' => 'IMPLICIT',
			'language'    => self::LANGUAGES[ $request->locale ] ?? 'ita',
			'resultUrl'   => $request->success_url,
			'cancelUrl'   => $request->cancel_url,
		);
		if ( 'local' !== ( $account->settings['webhook'] ?? '' ) ) {
			$session['notificationUrl'] = PaymentAccountService::webhook_url( $account );
		}
		$r = $this->client->request(
			'POST',
			'orders/hpp',
			$this->key( $account ),
			$account->is_live(),
			array(
				'order'          => array(
					'orderId'     => $order_id,
					'amount'      => (string) $request->amount_cents,
					'currency'    => strtoupper( $request->currency ),
					'description' => mb_substr( $request->description, 0, 255 ),
				),
				'paymentSession' => $session,
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( empty( $r['hostedPage'] ) || ! preg_match( '#^https?://#', (string) $r['hostedPage'] ) ) {
			return new \WP_Error( 'bkng_provider_bad_response', __( 'Risposta non valida da Nexi.', 'booking-manager' ), array( 'status' => 502 ) );
		}
		return new CheckoutSession( $order_id, (string) $r['hostedPage'], $request->expires_at, null, $account->is_live() );
	}

	public function get_payment_status( PaymentAccount $account, array $payment ): ProviderEvent|\WP_Error {
		if ( empty( $payment['external_id'] ) ) {
			return new ProviderEvent( ProviderEvent::IGNORED );
		}
		$order = $this->order( $account, (string) $payment['external_id'], true );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		return $this->payment_event( $account, $order, $payment );
	}

	/**
	 * Takes the money of an authorization that the terminal did not capture by itself
	 * (terminals set up for explicit capture).
	 */
	public function capture_payment( PaymentAccount $account, array $payment, int $amount_cents, string $idempotency_key ): ProviderEvent|\WP_Error {
		$order = $this->order( $account, (string) $payment['external_id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$auth = self::find_operation( $order, array( 'AUTHORIZATION' ), array( 'AUTHORIZED' ) );
		if ( ! $auth ) {
			return $this->payment_event( $account, $order, $payment );
		}
		$r = $this->client->request(
			'POST',
			'operations/' . rawurlencode( (string) $auth['operationId'] ) . '/captures',
			$this->key( $account ),
			$account->is_live(),
			array(
				'amount'   => (string) $amount_cents,
				'currency' => strtoupper( (string) $payment['currency'] ),
			),
			$idempotency_key
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$order = $this->order( $account, (string) $payment['external_id'] );
		return is_wp_error( $order ) ? $order : $this->payment_event( $account, $order, $payment );
	}

	/**
	 * The hosted page cannot be closed through the API. If the customer already authorized
	 * without the money being captured (terminals with explicit capture), the authorization
	 * is released so the customer's funds are not held: the refunds operation on an
	 * authorization performs its cancellation (developer.nexi.it, "refund or cancellation
	 * based on order status").
	 */
	public function cancel_payment( PaymentAccount $account, array $payment ): bool|\WP_Error {
		if ( empty( $payment['external_id'] ) ) {
			return true;
		}
		$order = $this->order( $account, (string) $payment['external_id'], true );
		if ( is_wp_error( $order ) ) {
			return true; // Nothing more we can do now; a late payment follows the late-payment rules.
		}
		$auth = self::find_operation( $order, array( 'AUTHORIZATION' ), array( 'AUTHORIZED' ) );
		if ( $auth && ! self::find_operation( $order, array( 'CAPTURE' ), array( 'EXECUTED' ) ) && ! self::find_operation( $order, array( 'VOID' ), array( 'EXECUTED', 'VOIDED' ) ) ) {
			$this->client->request(
				'POST',
				'operations/' . rawurlencode( (string) $auth['operationId'] ) . '/refunds',
				$this->key( $account ),
				$account->is_live(),
				array(
					'amount'      => (string) ( $auth['operationAmount'] ?? $payment['amount'] ),
					'currency'    => strtoupper( (string) $payment['currency'] ),
					'description' => __( 'Prenotazione non più valida: autorizzazione annullata', 'booking-manager' ),
				),
				'void-' . $payment['idempotency_key']
			);
		}
		return true;
	}

	public function refund_payment( PaymentAccount $account, array $payment, int $amount_cents, string $idempotency_key, string $reason ): RefundResult|\WP_Error {
		$operation = (string) ( $payment['provider_payment_id'] ?? '' );
		if ( '' === $operation ) {
			return new \WP_Error( 'bkng_refund_unavailable', __( 'Il pagamento non risulta ancora incassato da Nexi.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$r = $this->client->request(
			'POST',
			'operations/' . rawurlencode( $operation ) . '/refunds',
			$this->key( $account ),
			$account->is_live(),
			array(
				'amount'      => (string) $amount_cents,
				'currency'    => strtoupper( (string) $payment['currency'] ),
				'description' => mb_substr( '' !== $reason ? $reason : __( 'Rimborso prenotazione', 'booking-manager' ), 0, 255 ),
			),
			$idempotency_key
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( empty( $r['operationId'] ) ) {
			return new \WP_Error( 'bkng_provider_bad_response', __( 'Risposta non valida da Nexi.', 'booking-manager' ), array( 'status' => 502 ) );
		}
		// The refund is an online operation: accepted means done (a later failure arrives as a notification).
		return new RefundResult( (string) $r['operationId'], TransactionType::SUCCEEDED, $amount_cents );
	}

	public function handle_webhook( PaymentAccount $account, \WP_REST_Request $request ): WebhookNotification|\WP_Error {
		$data      = json_decode( (string) $request->get_body(), true );
		$operation = is_array( $data ) && is_array( $data['operation'] ?? null ) ? $data['operation'] : null;
		$order_id  = (string) ( $operation['orderId'] ?? '' );
		if ( ! $operation || '' === (string) ( $data['eventId'] ?? '' ) || '' === $order_id ) {
			return new \WP_Error( 'bkng_webhook_payload', 'Invalid payload', array( 'status' => 400 ) );
		}
		$event_id = mb_substr( (string) $data['eventId'], 0, 100 );
		$type     = mb_substr( (string) ( $operation['operationType'] ?? '' ) . '.' . (string) ( $operation['operationResult'] ?? '' ), 0, 80 );
		if ( ! preg_match( self::ORDER_ID, $order_id ) ) {
			// Not one of our orders (another shop on the same terminal): nothing to read.
			return new WebhookNotification( $event_id, $type, null, array( new ProviderEvent( ProviderEvent::IGNORED ) ), array( 'order' => $order_id ) );
		}
		if ( ! self::known_order( $order_id ) ) {
			// Ours in shape but never created here: refused without asking Nexi, so a flood
			// of made-up notifications cannot use up the terminal's API calls.
			return new \WP_Error( 'bkng_webhook_signature', 'Unknown order for this terminal', array( 'status' => 400 ) );
		}
		// The notification is only a hint: the authenticated read is the truth.
		$order = $this->order( $account, $order_id );
		if ( is_wp_error( $order ) ) {
			$status = (int) ( $order->get_error_data()['http_status'] ?? 0 );
			if ( 404 === $status ) {
				return new \WP_Error( 'bkng_webhook_signature', 'Unknown order for this terminal', array( 'status' => 400 ) );
			}
			return new \WP_Error( 'bkng_webhook_failed', 'Order lookup failed', array( 'status' => 500 ) );
		}
		$events = array_merge( array( $this->payment_event( $account, $order, null ) ), $this->refund_events( $account, $order ) );
		return new WebhookNotification(
			$event_id,
			$type,
			$account->is_live(),
			$events,
			array(
				'type'      => $type,
				'order'     => $order_id,
				'operation' => (string) ( $operation['operationId'] ?? '' ),
				'amount'    => $operation['operationAmount'] ?? null,
				'verified'  => 'order_lookup',
			)
		);
	}

	/* ------------------------------------------------------------------ helpers */

	private static function known_order( string $order_id ): bool {
		global $wpdb;
		$t = Schema::table( 'payments' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE gateway = %s AND external_id = %s LIMIT 1", self::ID, $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
	}

	/**
	 * GET /orders/{orderId}. With $missing_ok, a 404 (the customer never got as far as the
	 * page) is returned as an order without operations.
	 *
	 * @return array{order: array<string, mixed>, operations: array<int, array<string, mixed>>, captured: ?int}|\WP_Error
	 */
	private function order( PaymentAccount $account, string $order_id, bool $missing_ok = false ): array|\WP_Error {
		$r = $this->client->request( 'GET', 'orders/' . rawurlencode( $order_id ), $this->key( $account ), $account->is_live() );
		if ( is_wp_error( $r ) && $missing_ok && 404 === (int) ( $r->get_error_data()['http_status'] ?? 0 ) ) {
			$r = array( 'orderStatus' => array( 'order' => array( 'orderId' => $order_id ) ) );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$status = is_array( $r['orderStatus'] ?? null ) ? $r['orderStatus'] : array();
		// The operations list sits next to the order status (accept both placements).
		$operations = $r['operations'] ?? $status['operations'] ?? array();
		$order      = is_array( $status['order'] ?? null ) ? $status['order'] : array();
		$captured   = $status['capturedAmount'] ?? $order['capturedAmount'] ?? null;
		if ( ( $order['orderId'] ?? $order_id ) !== $order_id ) {
			return new \WP_Error( 'bkng_provider_bad_response', __( 'Risposta non valida da Nexi.', 'booking-manager' ), array( 'status' => 502 ) );
		}
		return array(
			'order'      => $order + array( 'orderId' => $order_id ),
			'operations' => array_values( array_filter( (array) $operations, 'is_array' ) ),
			'captured'   => is_numeric( $captured ) ? (int) $captured : null,
		);
	}

	/**
	 * Where the payment stands, from the order's operations.
	 *
	 * @param array{order: array<string, mixed>, operations: array<int, array<string, mixed>>, captured: ?int} $order
	 * @param array<string, mixed>|null $payment our row, when known (for the abandonment check)
	 */
	private function payment_event( PaymentAccount $account, array $order, ?array $payment ): ProviderEvent {
		$order_id = (string) $order['order']['orderId'];
		$amount   = isset( $order['order']['amount'] ) && is_numeric( $order['order']['amount'] ) ? (int) $order['order']['amount'] : null;
		$currency = isset( $order['order']['currency'] ) ? strtoupper( (string) $order['order']['currency'] ) : null;
		$live     = $account->is_live();

		$capture = self::find_operation( $order, array( 'CAPTURE' ), array( 'EXECUTED' ) )
			?? self::find_operation( $order, array( 'AUTHORIZATION' ), array( 'EXECUTED' ) );
		if ( $capture || ( null !== $order['captured'] && null !== $amount && $order['captured'] >= $amount && $amount > 0 ) ) {
			$operation = $capture ?? self::find_operation( $order, array( 'AUTHORIZATION' ), array( 'AUTHORIZED', 'EXECUTED' ) );
			return new ProviderEvent( ProviderEvent::PAYMENT_SUCCEEDED, null, $order_id, $operation ? (string) $operation['operationId'] : null, $amount, $currency, $operation ? self::method( $operation ) : null, null, null, null, null, $live );
		}
		if ( self::find_operation( $order, array( 'VOID' ), array( 'EXECUTED', 'VOIDED' ) ) ) {
			return new ProviderEvent( ProviderEvent::PAYMENT_CANCELED, null, $order_id, null, null, null, null, null, null, null, null, $live );
		}
		$auth = self::find_operation( $order, array( 'AUTHORIZATION' ), array( 'AUTHORIZED' ) );
		if ( $auth ) {
			// Authorized but not captured: the terminal wants an explicit capture.
			return new ProviderEvent( ProviderEvent::PAYMENT_APPROVED, null, $order_id, null, $amount, $currency, self::method( $auth ), null, null, null, null, $live );
		}
		if ( $payment && ! empty( $payment['expires_at'] ) && strtotime( $payment['expires_at'] . ' UTC' ) + self::ABANDONED_AFTER < $this->clock->now_utc()->getTimestamp() ) {
			// Nothing paid long after the page expired (perhaps after a declined card): abandoned.
			return new ProviderEvent( ProviderEvent::PAYMENT_CANCELED, null, $order_id, null, null, null, null, null, null, null, null, $live );
		}
		$last = self::latest( $order['operations'] );
		if ( $last ) {
			$result = strtoupper( (string) ( $last['operationResult'] ?? '' ) );
			if ( 'PENDING' === $result || 'THREEDS_VALIDATED' === $result ) {
				return new ProviderEvent( ProviderEvent::PAYMENT_PROCESSING, null, $order_id, null, null, null, null, null, null, null, null, $live );
			}
			if ( 'CANCELED' === $result || 'VOIDED' === $result ) {
				return new ProviderEvent( ProviderEvent::PAYMENT_CANCELED, null, $order_id, null, null, null, null, null, null, null, null, $live );
			}
			if ( in_array( $result, array( 'DECLINED', 'DENIED_BY_RISK', 'THREEDS_FAILED', 'FAILED' ), true ) ) {
				// One attempt failed; the customer can try again with a new payment page.
				return new ProviderEvent( ProviderEvent::ATTEMPT_FAILED, null, $order_id, null, null, null, null, null, null, strtolower( $result ), self::failure_message( $result ), $live );
			}
		}
		return new ProviderEvent( ProviderEvent::IGNORED ); // The customer is still on the page.
	}

	/**
	 * One event per refund operation of the order (made here or in the Nexi Back Office).
	 *
	 * @param array{order: array<string, mixed>, operations: array<int, array<string, mixed>>, captured: ?int} $order
	 * @return ProviderEvent[]
	 */
	private function refund_events( PaymentAccount $account, array $order ): array {
		$capture = self::find_operation( $order, array( 'CAPTURE' ), array( 'EXECUTED' ) )
			?? self::find_operation( $order, array( 'AUTHORIZATION' ), array( 'EXECUTED' ) );
		$events  = array();
		foreach ( $order['operations'] as $operation ) {
			if ( 'REFUND' !== strtoupper( (string) ( $operation['operationType'] ?? '' ) ) || empty( $operation['operationId'] ) ) {
				continue;
			}
			$events[] = new ProviderEvent(
				ProviderEvent::REFUND_UPDATED,
				null,
				(string) $order['order']['orderId'],
				$capture ? (string) $capture['operationId'] : null,
				is_numeric( $operation['operationAmount'] ?? null ) ? (int) $operation['operationAmount'] : 0,
				isset( $operation['operationCurrency'] ) ? strtoupper( (string) $operation['operationCurrency'] ) : null,
				null,
				(string) $operation['operationId'],
				self::refund_status( (string) ( $operation['operationResult'] ?? '' ) ),
				null,
				null,
				$account->is_live()
			);
		}
		return $events;
	}

	/**
	 * The latest operation of one of the types with one of the results.
	 *
	 * @param array{operations: array<int, array<string, mixed>>} $order
	 * @param string[] $types
	 * @param string[] $results
	 * @return array<string, mixed>|null
	 */
	private static function find_operation( array $order, array $types, array $results ): ?array {
		$found = null;
		foreach ( $order['operations'] as $operation ) {
			if ( in_array( strtoupper( (string) ( $operation['operationType'] ?? '' ) ), $types, true )
				&& in_array( strtoupper( (string) ( $operation['operationResult'] ?? '' ) ), $results, true )
				&& ! empty( $operation['operationId'] ) ) {
				$found = $operation;
			}
		}
		return $found;
	}

	/**
	 * The most recent operation, by operationTime (the API does not promise an order).
	 *
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<string, mixed>|null
	 */
	private static function latest( array $operations ): ?array {
		$latest = null;
		foreach ( $operations as $operation ) {
			if ( null === $latest || strcmp( (string) ( $operation['operationTime'] ?? '' ), (string) ( $latest['operationTime'] ?? '' ) ) >= 0 ) {
				$latest = $operation;
			}
		}
		return $latest;
	}

	/** @param array<string, mixed> $operation */
	private static function method( array $operation ): ?string {
		$circuit = (string) ( $operation['paymentCircuit'] ?? $operation['paymentMethod'] ?? '' );
		return '' === $circuit ? null : strtolower( mb_substr( sanitize_key( $circuit ), 0, 30 ) );
	}

	private static function failure_message( string $result ): string {
		return match ( $result ) {
			'DECLINED' => __( 'Pagamento rifiutato dalla banca.', 'booking-manager' ),
			'DENIED_BY_RISK' => __( 'Pagamento bloccato dai controlli antifrode.', 'booking-manager' ),
			'THREEDS_FAILED' => __( 'Autenticazione 3D Secure non riuscita o annullata.', 'booking-manager' ),
			default => __( 'Pagamento non riuscito per un problema tecnico.', 'booking-manager' ),
		};
	}

	private static function refund_status( string $result ): string {
		return match ( strtoupper( $result ) ) {
			// VOIDED: the refund was carried out as the cancellation of the operation — money back all the same.
			'EXECUTED', 'REFUNDED', 'VOIDED' => TransactionType::SUCCEEDED,
			'DECLINED', 'DENIED_BY_RISK', 'FAILED' => TransactionType::FAILED,
			'CANCELED' => TransactionType::CANCELED,
			default => TransactionType::PENDING,
		};
	}

	/** "BM" + 16 base-36 characters, stable for a payment (a repeated request names the same order). */
	public static function order_id( string $idempotency_key ): string {
		$hash = hash( 'sha256', 'bmgr-order|' . $idempotency_key );
		$id   = '';
		foreach ( array( 0, 13 ) as $start ) {
			// 13 hex digits = 52 bits: exact in base_convert's float arithmetic.
			$id .= str_pad( strtoupper( base_convert( substr( $hash, $start, 13 ), 16, 36 ) ), 8, '0', STR_PAD_LEFT );
		}
		return 'BM' . substr( $id, -16 );
	}

	private function key( PaymentAccount $account ): string {
		return (string) ( $account->credentials['api_key'] ?? '' );
	}
}
