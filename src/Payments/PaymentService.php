<?php
/**
 * The payments engine: provider-neutral.
 *
 * - A payment is the intention to collect an amount for a booking; every operation on it
 *   (capture, refund, failure, cancellation) is a separate row of the ledger.
 * - Online payments are confirmed ONLY by the provider: through its notification (webhook,
 *   authenticated) or by asking the provider server-to-server (reconciliation). The
 *   customer coming back to the "thank you" page proves nothing and changes nothing.
 * - Events are applied idempotently and only forward (PaymentStatus): duplicates and
 *   late or out-of-order notifications cannot pay, refund or confirm twice.
 * - A booking waiting for its online payment (an option) is confirmed when the payment
 *   arrives; a payment arriving after the booking was cancelled (option expired, guest or
 *   staff cancelled) is refunded automatically, and so is what exceeds the total.
 *
 * Ported from Beach Manager's payments engine.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

use BookingManager\Audit\AuditLogger;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingService;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Core\Clock;
use BookingManager\Core\Hooks;
use BookingManager\Core\Money;
use BookingManager\Database\LockFailed;
use BookingManager\Database\Transaction;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Payments\Providers\ManualProvider;
use BookingManager\Payments\Providers\PaymentProvider;

defined( 'ABSPATH' ) || exit;

class PaymentService {

	/** Smallest online payment accepted (cents): providers refuse tiny card charges. */
	public const MIN_ONLINE_CENTS = 50;

	public function __construct(
		private PaymentRepository $repo,
		private PaymentAccountService $accounts,
		private ProviderRegistry $providers,
		private BookingRepository $booking_repo,
		private BookingService $bookings,
		private PropertyRepository $properties,
		private AuditLogger $audit,
		private Clock $clock
	) {}

	public function repository(): PaymentRepository {
		return $this->repo;
	}

	/* ================================================================== reads */

	/**
	 * Payments of a booking with totals and what the current user may do.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function for_booking( int $booking_id, int $property_id ): array|\WP_Error {
		$booking = $this->booking_in( $booking_id, $property_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$payments = array_map( fn( $p ) => $this->present( $p, true ), $this->repo->for_booking( $booking_id ) );
		$account  = $this->accounts->active_online_account( $property_id );
		$provider = $account ? $this->providers->get( $account->provider ) : null;
		return array(
			'booking_id' => $booking_id,
			'currency'   => (string) ( $this->properties->find( $property_id )['currency'] ?? 'EUR' ),
			'totals'     => self::totals( $booking ),
			'payments'   => $payments,
			'online'     => array(
				'available' => null !== $account,
				'provider'  => $provider ? $provider->label() : null,
				'test_mode' => $account ? ! $account->is_live() : false,
			),
			'methods'    => ManualProvider::METHODS,
		);
	}

	/* ================================================================== online payments */

	/**
	 * Opens a hosted payment page for (part of) a booking. Reuses a page still open for the
	 * same amount, so double clicks and retries never create two payments.
	 *
	 * @return array<string, mixed>|\WP_Error payment as presented, with checkout_url
	 */
	public function start_online_payment( array $booking, int $amount, string $success_url, string $cancel_url, ?string $email = null ): array|\WP_Error {
		$property_id = (int) $booking['property_id'];
		$account     = $this->accounts->active_online_account( $property_id );
		$provider    = $account ? $this->providers->get( $account->provider ) : null;
		if ( ! $account || ! $provider || ! $provider->supports( Capability::ONLINE_CHECKOUT, $account ) ) {
			return new \WP_Error( 'bkng_payments_not_configured', __( 'I pagamenti online non sono attivi per questa struttura.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$due = self::totals( $booking )['due'];
		if ( $amount < self::MIN_ONLINE_CENTS || $amount > $due ) {
			return new \WP_Error(
				'bkng_validation',
				/* translators: %s: amount still due */
				sprintf( __( 'Importo non valido: da 0,50 al dovuto (%s).', 'booking-manager' ), Money::format( $due ) ),
				array( 'status' => 400 )
			);
		}

		try {
			return $this->with_lock(
				'b' . (int) $booking['id'],
				fn() => $this->open_checkout( $booking, $amount, $success_url, $cancel_url, $email, $account, $provider, $property_id )
			);
		} catch ( LockFailed $e ) {
			return self::write_error();
		}
	}

	/**
	 * @param array<string, mixed> $booking
	 * @return array<string, mixed>|\WP_Error
	 */
	private function open_checkout( array $booking, int $amount, string $success_url, string $cancel_url, ?string $email, PaymentAccount $account, PaymentProvider $provider, int $property_id ): array|\WP_Error {
		$now_ts = $this->clock->now_utc()->getTimestamp();
		foreach ( array_reverse( $this->repo->for_booking( (int) $booking['id'] ) ) as $open ) {
			if ( in_array( $open['status'], PaymentStatus::OPEN, true ) && (int) $open['payment_account_id'] === $account->id
				&& (int) $open['amount'] === $amount && ! empty( $open['checkout_url'] )
				&& $open['expires_at'] && strtotime( $open['expires_at'] . ' UTC' ) > $now_ts + 120 ) {
				$this->booking_repo->extend_option( (int) $booking['id'], gmdate( 'Y-m-d H:i:s', strtotime( $open['expires_at'] . ' UTC' ) + 5 * MINUTE_IN_SECONDS ) );
				return $this->present( $open ) + array( 'checkout_url' => (string) $open['checkout_url'] );
			}
		}

		// Only one payment page open per booking: the customer can never pay twice.
		$this->cancel_open_payments( (int) $booking['id'] );
		$location = (array) $this->properties->find( $property_id );
		$now      = $this->clock->now_mysql();
		$id       = $this->repo->insert(
			array(
				'property_id'        => $property_id,
				'booking_id'         => (int) $booking['id'],
				'gateway'            => $provider->id(),
				'payment_account_id' => $account->id,
				'amount'       => $amount,
				'currency'           => (string) ( $location['currency'] ?? 'EUR' ),
				'kind'               => self::kind_of( $booking, $amount ),
				'status'             => PaymentStatus::CREATED,
				'idempotency_key'    => wp_generate_uuid4(),
				'livemode'           => $account->is_live() ? 1 : 0,
				'created_by'         => get_current_user_id() ?: null,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		$payment = (array) $this->repo->find( $id );
		$result  = $provider->create_checkout_session(
			$account,
			new CheckoutRequest(
				$id,
				(int) $booking['id'],
				(string) $booking['code'],
				$amount,
				(string) $payment['currency'],
				sprintf(
					/* translators: 1: location name, 2: booking code */
					__( '%1$s — prenotazione %2$s', 'booking-manager' ),
					(string) ( $location['name'] ?? '' ),
					(string) $booking['code']
				),
				$email,
				$success_url,
				$cancel_url,
				$now_ts + 31 * 60,
				(string) $payment['idempotency_key'],
				substr( get_locale(), 0, 2 ),
				array( 'bkng_booking_code' => (string) $booking['code'] )
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->repo->update(
				$id,
				array(
					'status'          => PaymentStatus::FAILED,
					'failure_code'    => 'checkout_error',
					'failure_message' => mb_substr( $result->get_error_message(), 0, 255 ),
					'failed_at'       => $now,
					'updated_at'      => $now,
				)
			);
			$this->log( 'payment.checkout_failed', $payment, array( 'error' => $result->get_error_message() ) );
			return new \WP_Error( $result->get_error_code(), __( 'Non è stato possibile avviare il pagamento. Riprova tra poco.', 'booking-manager' ), array( 'status' => 502 ) );
		}
		$this->repo->update(
			$id,
			array(
				'status'              => PaymentStatus::PENDING,
				'external_id'         => $result->external_id,
				'provider_payment_id' => $result->provider_payment_id,
				'checkout_url'        => $result->url,
				'expires_at'          => $result->expires_at ? gmdate( 'Y-m-d H:i:s', $result->expires_at ) : null,
				'updated_at'          => $now,
			)
		);
		if ( $result->expires_at ) {
			// A booking waiting for this payment lives as long as its payment page.
			$this->booking_repo->extend_option( (int) $booking['id'], gmdate( 'Y-m-d H:i:s', $result->expires_at + 5 * MINUTE_IN_SECONDS ) );
		}
		$payment = (array) $this->repo->find( $id );
		$this->log( 'payment.created', $payment, array( 'external_id' => $result->external_id ) );
		return $this->present( $payment ) + array( 'checkout_url' => $result->url );
	}

	/* ================================================================== desk payments */

	/**
	 * Cash, card terminal or bank transfer received at the desk.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function record_manual( int $booking_id, int $property_id, int $amount, string $method, string $note, string $request_id ): array|\WP_Error {
		$booking = $this->booking_in( $booking_id, $property_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		if ( ! wp_is_uuid( $request_id ) ) {
			return new \WP_Error( 'bkng_validation', __( 'Richiesta non valida.', 'booking-manager' ), array( 'status' => 400 ) );
		}
		$existing = $this->repo->transaction_by( 'idempotency_key', $request_id );
		if ( $existing ) {
			return $this->present( (array) $this->repo->find( (int) $existing['payment_id'] ) );
		}
		// Also on a cancelled booking or a no-show: the penalty of the cancellation terms
		// is collected after the cancellation.
		$due = self::totals( $booking )['due'];
		if ( $amount <= 0 || $amount > $due ) {
			return new \WP_Error(
				'bkng_validation',
				/* translators: %s: amount due */
				sprintf( __( 'Importo non valido: al massimo il dovuto (%s).', 'booking-manager' ), Money::format( $due ) ),
				array( 'status' => 400 )
			);
		}
		if ( ! in_array( $method, ManualProvider::METHODS, true ) ) {
			return new \WP_Error( 'bkng_validation', __( 'Metodo di pagamento non valido.', 'booking-manager' ), array( 'status' => 400 ) );
		}
		$note = mb_substr( trim( sanitize_text_field( $note ) ), 0, 255 );
		$now  = $this->clock->now_mysql();
		$user = get_current_user_id() ?: null;
		try {
			$id = Transaction::run(
				function () use ( $booking, $amount, $method, $note, $request_id, $now, $user ) {
					$id = $this->repo->insert(
						array(
							'property_id'     => (int) $booking['property_id'],
							'booking_id'      => (int) $booking['id'],
							'gateway'         => ManualProvider::ID,
							'amount'    => $amount,
							'currency'        => (string) ( $this->properties->find( (int) $booking['property_id'] )['currency'] ?? 'EUR' ),
							'kind'            => self::kind_of( $booking, $amount ),
							'method'          => $method,
							'status'          => PaymentStatus::PAID,
							'idempotency_key' => wp_generate_uuid4(),
							'livemode'        => 1,
							'received_at'         => $now,
							'created_by'      => $user,
							'created_at'      => $now,
							'updated_at'      => $now,
						)
					);
					$this->repo->insert_transaction(
						array(
							'payment_id'      => $id,
							'booking_id'      => (int) $booking['id'],
							'property_id'     => (int) $booking['property_id'],
							'type'            => TransactionType::CAPTURE,
							'amount'    => $amount,
							'provider'        => ManualProvider::ID,
							'status'          => TransactionType::SUCCEEDED,
							'idempotency_key' => $request_id,
							'reason'          => '' === $note ? null : $note,
							'actor_type'      => 'user',
							'created_by'      => $user,
							'created_at'      => $now,
						)
					);
					return $id;
				}
			);
		} catch ( LockFailed $e ) {
			return self::write_error();
		}
		$payment = (array) $this->repo->find( $id );
		$this->sync_booking( (int) $booking['id'] );
		$this->log( 'payment.recorded', $payment, array( 'method' => $method ) );
		if ( $amount >= $due ) {
			$this->cancel_open_payments( (int) $booking['id'] ); // Paid in full at the desk: close online pages.
		}
		Hooks::dispatch( Hooks::PAYMENT_RECORDED, (int) $booking['id'], self::hook_context( $payment ) );
		return $this->present( $payment );
	}

	/* ================================================================== refunds */

	/**
	 * Gives money back, in full or in part (if the provider allows it). Every refund is a
	 * separate operation; the same request_id never refunds twice.
	 *
	 * @return array<string, mixed>|\WP_Error the payment as presented
	 */
	public function refund( int $payment_id, int $property_id, int $amount, string $reason, string $request_id, string $actor = 'user' ): array|\WP_Error {
		$payment = $this->repo->find( $payment_id );
		if ( ! $payment || (int) $payment['property_id'] !== $property_id ) {
			return new \WP_Error( 'bkng_not_found', __( 'Pagamento non trovato in questa struttura.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9\-]{8,64}$/', $request_id ) ) {
			return new \WP_Error( 'bkng_validation', __( 'Richiesta non valida.', 'booking-manager' ), array( 'status' => 400 ) );
		}
		$done = $this->repo->transaction_by( 'idempotency_key', $request_id );
		if ( $done ) {
			return (int) $done['payment_id'] === $payment_id
				? $this->present( (array) $this->repo->find( $payment_id ), true )
				: new \WP_Error( 'bkng_validation', __( 'Richiesta non valida.', 'booking-manager' ), array( 'status' => 400 ) );
		}
		[ $provider, $account ] = $this->provider_for( $payment );
		if ( ! $provider || ! $account ) {
			return new \WP_Error( 'bkng_payment_unsupported', __( 'Il sistema di pagamento di questo incasso non è più collegato.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		if ( ! $provider->supports( Capability::REFUNDS, $account ) ) {
			return new \WP_Error( 'bkng_payment_unsupported', __( 'Questo sistema di pagamento non permette rimborsi dal gestionale.', 'booking-manager' ), array( 'status' => 422 ) );
		}
		$reason = mb_substr( trim( sanitize_textarea_field( $reason ) ), 0, 255 );

		// Reserve the amount under the payment lock: two refunds at the same time cannot exceed it.
		try {
			return $this->refund_reserved( $payment_id, $amount, $reason, $request_id, $provider, $account, $actor );
		} catch ( LockFailed $e ) {
			return self::write_error();
		}
	}

	/** @return array<string, mixed>|\WP_Error */
	private function refund_reserved( int $payment_id, int $amount, string $reason, string $request_id, PaymentProvider $provider, PaymentAccount $account, string $actor ): array|\WP_Error {
		$tx = $this->with_lock(
			'p' . $payment_id,
			function () use ( $payment_id, $amount, $reason, $request_id, $provider, $account, $actor ) {
				$payment = (array) $this->repo->find( $payment_id );
				if ( ! in_array( $payment['status'], array( PaymentStatus::PAID, PaymentStatus::PARTIALLY_REFUNDED ), true ) ) {
					return new \WP_Error( 'bkng_refund_unavailable', __( 'Si può rimborsare solo un pagamento incassato.', 'booking-manager' ), array( 'status' => 409 ) );
				}
				$sums       = $this->repo->refund_sums( $payment_id );
				$refundable = (int) $payment['amount'] - $sums['succeeded'] - $sums['pending'];
				if ( $amount <= 0 || $amount > $refundable ) {
					return new \WP_Error(
						'bkng_refund_too_much',
						/* translators: %s: amount still refundable */
						sprintf( __( 'Puoi rimborsare al massimo %s.', 'booking-manager' ), Money::format( max( 0, $refundable ) ) ),
						array( 'status' => 400, 'refundable_cents' => max( 0, $refundable ) )
					);
				}
				$full = $amount === $refundable && 0 === $sums['succeeded'] + $sums['pending'];
				if ( ! $full && ! $provider->supports( Capability::PARTIAL_REFUNDS, $account ) ) {
					return new \WP_Error( 'bkng_payment_unsupported', __( 'Questo sistema di pagamento permette solo il rimborso totale.', 'booking-manager' ), array( 'status' => 422 ) );
				}
				$now = $this->clock->now_mysql();
				$id  = $this->repo->insert_transaction(
					array(
						'payment_id'      => $payment_id,
						'booking_id'      => (int) $payment['booking_id'],
						'property_id'     => (int) $payment['property_id'],
						'type'            => $amount === (int) $payment['amount'] ? TransactionType::REFUND : TransactionType::PARTIAL_REFUND,
						'amount'    => $amount,
						'currency'        => (string) $payment['currency'],
						'provider'        => (string) $payment['gateway'],
						'status'          => TransactionType::PENDING,
						'idempotency_key' => $request_id,
						'reason'          => '' === $reason ? null : $reason,
						'actor_type'      => $actor,
						'created_by'      => 'system' === $actor ? null : ( get_current_user_id() ?: null ),
						'created_at'      => $now,
						'updated_at'      => $now,
					)
				);
				return array(
					'id'      => $id,
					'payment' => $payment,
				);
			}
		);
		if ( is_wp_error( $tx ) ) {
			return $tx;
		}

		$payment = $tx['payment'];
		$this->log( 'payment.refund_requested', $payment, array( 'amount' => $amount, 'reason' => $reason ), $actor );
		$result = $provider->refund_payment( $account, $payment, $amount, $request_id, $reason );
		$now    = $this->clock->now_mysql();
		if ( is_wp_error( $result ) && self::uncertain( $result ) ) {
			// No clear answer: the provider may have refunded. The amount stays reserved; the
			// notification or the scheduled retry (same idempotency key) will settle it.
			$this->repo->update_transaction( $tx['id'], array( 'response' => wp_json_encode( array( 'error' => $result->get_error_message() ) ), 'updated_at' => $now ) );
			$this->log( 'payment.refund_uncertain', $payment, array( 'amount' => $amount, 'error' => $result->get_error_message() ), $actor );
			return $this->present( (array) $this->repo->find( $payment_id ), true ) + array(
				'warning' => __( 'Il sistema di pagamento non ha risposto: il rimborso è in verifica e verrà completato automaticamente. Non ripeterlo.', 'booking-manager' ),
			);
		}
		if ( is_wp_error( $result ) ) {
			$this->repo->update_transaction(
				$tx['id'],
				array(
					'status'     => TransactionType::FAILED,
					'response'   => wp_json_encode( array( 'error' => $result->get_error_message() ) ),
					'updated_at' => $now,
				)
			);
			$this->log( 'payment.refund_failed', $payment, array( 'amount' => $amount, 'error' => $result->get_error_message() ), $actor );
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
		}
		$this->with_lock(
			'p' . $payment_id,
			function () use ( $tx, $result, $now, $payment_id ) {
				// The provider's notification may have been faster than its answer: never go back.
				$current = (array) $this->repo->transaction_by( 'id', $tx['id'] );
				if ( ! $this->claim_refund_ref( $current, $result->external_ref ) && $result->external_ref ) {
					$this->apply_refund_totals( $payment_id ); // Counted on the notification's row.
					return array();
				}
				$changes = array(
					'response'   => wp_json_encode( array_filter( array( 'failure' => $result->failure_message ) ) ),
					'updated_at' => $now,
				);
				if ( self::refund_moves( (string) $current['status'], $result->status ) ) {
					$changes['status'] = $result->status;
				}
				$this->repo->update_transaction( (int) $tx['id'], $changes );
				$this->apply_refund_totals( $payment_id );
				return array();
			}
		);
		$fresh = (array) $this->repo->find( $payment_id );
		$this->log( TransactionType::FAILED === $result->status ? 'payment.refund_failed' : 'payment.refunded', $fresh, array( 'amount' => $amount, 'refund' => $result->external_ref, 'status' => $result->status ), $actor );
		if ( TransactionType::SUCCEEDED === $result->status ) {
			Hooks::dispatch( Hooks::PAYMENT_REFUNDED, (int) $fresh['booking_id'], self::hook_context( $fresh ) );
		}
		return $this->present( $fresh, true );
	}

	/* ================================================================== provider notifications */

	/**
	 * Receives a webhook: authenticates it, stores it once, applies it once.
	 * Returns a WP_Error with status 5xx when the provider should retry.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function handle_webhook( string $provider_id, int $account_id, \WP_REST_Request $request ): array|\WP_Error {
		$account  = $this->accounts->find( $account_id );
		$provider = $this->providers->get( $provider_id );
		if ( ! $account || ! $provider || $account->provider !== $provider_id ) {
			return new \WP_Error( 'bkng_not_found', 'Unknown endpoint', array( 'status' => 404 ) );
		}
		$notification = $provider->handle_webhook( $account, $request );
		if ( is_wp_error( $notification ) ) {
			$this->audit->log(
				array(
					'action'      => 'payment.webhook_rejected',
					'entity_type' => 'payment_account',
					'entity_id'   => $account->id,
					'property_id' => $account->property_id,
					'actor_type'  => 'provider',
					'user_id'     => null,
					'new'         => array(
						'provider' => $provider_id,
						'error'    => $notification->get_error_code(),
					),
				)
			);
			return $notification;
		}

		$now      = $this->clock->now_mysql();
		$recorded = $this->repo->record_event(
			array(
				'gateway'            => $provider_id,
				'payment_account_id' => $account->id,
				// Per account: two locations may share one provider account and both receive the event.
				'event_id'           => mb_substr( $account->id . ':' . $notification->event_id, 0, 190 ),
				'event_type'         => mb_substr( $notification->event_type, 0, 80 ),
				'livemode'           => null === $notification->livemode ? null : (int) $notification->livemode,
				'status'             => 'received',
				'payload'            => wp_json_encode( $notification->summary ),
				'received_at'        => $now,
			)
		);
		$event = $recorded['row'];
		if ( $recorded['duplicate'] && in_array( $event['status'], array( 'processed', 'ignored' ), true ) ) {
			return array(
				'received'  => true,
				'duplicate' => true,
			);
		}
		if ( null !== $notification->livemode && $notification->livemode !== $account->is_live() ) {
			// A test event on a live endpoint (or the opposite): never mix the two.
			$this->repo->update_event( (int) $event['id'], array( 'status' => 'ignored', 'processed_at' => $now, 'error' => 'mode mismatch' ) );
			return array( 'received' => true );
		}

		$this->repo->update_event( (int) $event['id'], array( 'attempts' => (int) $event['attempts'] + 1 ) );
		try {
			$payment_id = null;
			foreach ( $notification->events as $provider_event ) {
				$payment_id = $this->apply_event( $account, $provider, $provider_event, 'webhook' ) ?? $payment_id;
			}
			$this->repo->update_event(
				(int) $event['id'],
				array(
					'status'       => null === $payment_id ? 'ignored' : 'processed',
					'payment_id'   => $payment_id,
					'processed_at' => $this->clock->now_mysql(),
					'error'        => null,
				)
			);
		} catch ( \Throwable $e ) {
			$this->repo->update_event(
				(int) $event['id'],
				array(
					'status' => 'failed',
					'error'  => mb_substr( get_class( $e ) . ': ' . $e->getMessage(), 0, 500 ),
				)
			);
			return new \WP_Error( 'bkng_webhook_failed', 'Processing failed, retry later', array( 'status' => 500 ) );
		}
		return array( 'received' => true );
	}

	/**
	 * Asks the provider where an open payment stands and applies the answer (safety net
	 * for lost notifications; the provider's answer, never the browser's).
	 *
	 * @param array<string, mixed> $payment
	 */
	public function reconcile( array $payment ): void {
		$in_flight = in_array( $payment['status'], array( PaymentStatus::CANCELLED, PaymentStatus::FAILED ), true ) && ! empty( $payment['provider_payment_id'] ) && empty( $payment['received_at'] );
		if ( ! in_array( $payment['status'], PaymentStatus::OPEN, true ) && ! $in_flight ) {
			return;
		}
		[ $provider, $account ] = $this->provider_for( $payment );
		if ( ! $provider || ! $account || ManualProvider::ID === $provider->id() ) {
			return;
		}
		$event = $provider->get_payment_status( $account, $payment );
		if ( is_wp_error( $event ) ) {
			return;
		}
		$this->apply_event( $account, $provider, $event, 'reconcile' );
	}

	/** Scheduled: open online payments older than a few minutes are checked with the provider. */
	public function reconcile_open(): int {
		$count  = 0;
		$before = gmdate( 'Y-m-d H:i:s', $this->clock->now_utc()->getTimestamp() - 5 * 60 );
		foreach ( $this->repo->open_before( $before ) as $payment ) {
			$this->reconcile( $payment );
			++$count;
		}
		foreach ( $this->repo->paid_unsettled() as $payment ) {
			$this->settle_booking( $payment );
		}
		foreach ( $this->repo->uncertain_refunds( $before ) as $tx ) {
			$this->retry_refund( $tx );
		}
		return $count;
	}

	/**
	 * Asks again for a refund whose outcome was unclear, with the same idempotency key:
	 * the provider returns the original refund if it exists, or makes it now.
	 *
	 * @param array<string, mixed> $tx
	 */
	private function retry_refund( array $tx ): void {
		$payment = $this->repo->find( (int) $tx['payment_id'] );
		if ( ! $payment ) {
			return;
		}
		[ $provider, $account ] = $this->provider_for( $payment );
		if ( ! $provider || ! $account ) {
			return;
		}
		$result = $provider->refund_payment( $account, $payment, (int) $tx['amount'], (string) $tx['idempotency_key'], (string) $tx['reason'] );
		if ( is_wp_error( $result ) ) {
			if ( ! self::uncertain( $result ) ) {
				$this->repo->update_transaction( (int) $tx['id'], array( 'status' => TransactionType::FAILED, 'updated_at' => $this->clock->now_mysql() ) );
				$this->apply_refund_totals( (int) $payment['id'] );
			}
			return;
		}
		$this->with_lock(
			'p' . (int) $payment['id'],
			function () use ( $tx, $result, $payment ) {
				$current = (array) $this->repo->transaction_by( 'id', $tx['id'] );
				if ( ! $this->claim_refund_ref( $current, $result->external_ref ) && $result->external_ref ) {
					$this->apply_refund_totals( (int) $payment['id'] );
					return array();
				}
				$changes = array( 'updated_at' => $this->clock->now_mysql() );
				if ( self::refund_moves( (string) $current['status'], $result->status ) ) {
					$changes['status'] = $result->status;
				}
				$this->repo->update_transaction( (int) $tx['id'], $changes );
				$this->apply_refund_totals( (int) $payment['id'] );
				return array();
			}
		);
		if ( TransactionType::SUCCEEDED === $result->status ) {
			Hooks::dispatch( Hooks::PAYMENT_REFUNDED, (int) $payment['booking_id'], self::hook_context( $payment ) );
		}
	}

	/**
	 * A booking was cancelled or expired: its open payment pages are closed, so nobody pays
	 * for a spot that is no longer theirs. If the provider says it was paid meanwhile, the
	 * late-payment rules apply.
	 */
	public function cancel_open_payments( int $booking_id ): void {
		foreach ( $this->repo->for_booking( $booking_id ) as $payment ) {
			if ( ! in_array( $payment['status'], PaymentStatus::OPEN, true ) ) {
				continue;
			}
			[ $provider, $account ] = $this->provider_for( $payment );
			if ( ! $provider || ! $account ) {
				continue;
			}
			$result = $provider->cancel_payment( $account, $payment );
			if ( is_wp_error( $result ) ) {
				$this->reconcile( $payment );
				continue;
			}
			$this->apply_event( $account, $provider, new ProviderEvent( ProviderEvent::PAYMENT_CANCELED, (int) $payment['id'] ), 'system' );
		}
	}

	/**
	 * Applies one provider-neutral event. Returns the payment id it concerned.
	 */
	public function apply_event( PaymentAccount $account, PaymentProvider $provider, ProviderEvent $event, string $source ): ?int {
		if ( ProviderEvent::IGNORED === $event->kind ) {
			return null;
		}
		$payment = $this->locate( $account, $provider, $event, $source );
		if ( ! $payment ) {
			return null;
		}
		if ( ProviderEvent::PAYMENT_APPROVED === $event->kind ) {
			return $this->capture_approved( $account, $provider, $payment, $source );
		}
		if ( ProviderEvent::PAYMENT_SUCCEEDED === $event->kind
			&& ( ( null !== $event->amount_cents && $event->amount_cents !== (int) $payment['amount'] )
				|| ( null !== $event->currency && strtoupper( $event->currency ) !== strtoupper( (string) $payment['currency'] ) ) ) ) {
			$this->log( 'payment.amount_mismatch', $payment, array( 'reported' => $event->amount_cents, 'currency' => $event->currency, 'source' => $source ), 'provider' );
			return (int) $payment['id'];
		}
		$id      = (int) $payment['id'];
		$outcome = $this->with_lock(
			'p' . $id,
			fn() => Transaction::run( fn() => $this->apply_locked( $id, $event, $source ) )
		);
		$fresh = (array) $this->repo->find( $id );

		if ( ! empty( $outcome['paid'] ) ) {
			$this->sync_booking( (int) $fresh['booking_id'] );
			$this->log( 'payment.paid', $fresh, array( 'source' => $source, 'provider_payment_id' => $fresh['provider_payment_id'] ), 'provider' );
			Hooks::dispatch( Hooks::PAYMENT_RECORDED, (int) $fresh['booking_id'], self::hook_context( $fresh ) );
		}
		if ( ProviderEvent::PAYMENT_SUCCEEDED === $event->kind ) {
			// Also on a retried notification: a previous attempt may have stopped half way.
			$this->settle_booking( $fresh );
		}
		if ( ! empty( $outcome['failed'] ) ) {
			$this->log( 'payment.failed', $fresh, array( 'source' => $source, 'code' => $fresh['failure_code'] ), 'provider' );
			Hooks::dispatch( Hooks::PAYMENT_FAILED, (int) $fresh['booking_id'], self::hook_context( $fresh ) );
		}
		if ( ! empty( $outcome['cancelled'] ) ) {
			$this->log( 'payment.cancelled', $fresh, array( 'source' => $source ), 'system' === $source ? 'system' : 'provider' );
		}
		if ( ! empty( $outcome['refunds'] ) ) {
			$this->sync_booking( (int) $fresh['booking_id'] );
			$this->log( 'payment.refund_updated', $fresh, $outcome['refunds'], 'provider' );
			Hooks::dispatch( Hooks::PAYMENT_REFUNDED, (int) $fresh['booking_id'], self::hook_context( $fresh ) );
		}
		if ( ! empty( $outcome['dispute'] ) ) {
			$this->log( 'payment.dispute_opened', $fresh, array( 'reason' => $event->failure_code, 'amount' => $event->amount_cents ), 'provider' );
		}
		return $id;
	}

	/**
	 * The customer approved the payment: we take the money now, but only while the payment
	 * is still wanted. A page closed meanwhile (booking expired or cancelled) is never
	 * captured: the approval lapses at the provider and the customer is not charged.
	 * The capture carries a fixed idempotency key, so a webhook and a reconciliation
	 * arriving together capture once.
	 *
	 * @param array<string, mixed> $payment
	 */
	private function capture_approved( PaymentAccount $account, PaymentProvider $provider, array $payment, string $source ): int {
		$id = (int) $payment['id'];
		// Under the payment lock (re-entrant): a cancellation cannot slip in between the
		// check and the capture, and the captured outcome is applied before anyone else.
		$this->with_lock(
			'p' . $id,
			function () use ( $account, $provider, $id, $source ) {
				$payment = (array) $this->repo->find( $id );
				if ( ! in_array( $payment['status'], PaymentStatus::OPEN, true ) ) {
					if ( in_array( $payment['status'], array( PaymentStatus::CANCELLED, PaymentStatus::FAILED ), true ) ) {
						// No longer wanted: release what the customer approved, if the provider holds funds.
						$provider->cancel_payment( $account, $payment );
					}
					return null;
				}
				$result = $provider->capture_payment( $account, $payment, (int) $payment['amount'], 'capture-' . $payment['idempotency_key'] );
				if ( is_wp_error( $result ) ) {
					$this->log( 'payment.capture_failed', $payment, array( 'source' => $source, 'error' => $result->get_error_message() ), 'provider' );
					return null;
				}
				if ( ProviderEvent::PAYMENT_APPROVED !== $result->kind ) {
					$this->apply_event( $account, $provider, $result, $source );
				}
				return null;
			}
		);
		return $id;
	}

	/**
	 * The part of apply_event() that runs under the payment lock, in a transaction.
	 *
	 * @return array<string, mixed> what changed
	 */
	private function apply_locked( int $id, ProviderEvent $event, string $source ): array {
		$payment = (array) $this->repo->find( $id, true );
		$status  = (string) $payment['status'];
		$now     = $this->clock->now_mysql();
		$base    = array(
			'payment_id'  => $id,
			'booking_id'  => (int) $payment['booking_id'],
			'property_id' => (int) $payment['property_id'],
			'currency'    => (string) $payment['currency'],
			'provider'    => (string) $payment['gateway'],
			'actor_type'  => 'system' === $source ? 'system' : 'provider',
			'created_at'  => $now,
		);
		$ids     = array_filter(
			array(
				'provider_payment_id' => $event->provider_payment_id && ! $payment['provider_payment_id'] ? $event->provider_payment_id : null,
				'method'              => $event->method && ! $payment['method'] ? mb_substr( $event->method, 0, 30 ) : null,
			)
		);

		switch ( $event->kind ) {
			case ProviderEvent::PAYMENT_SUCCEEDED:
				if ( in_array( $status, PaymentStatus::SETTLED, true ) || ! PaymentStatus::can( $status, PaymentStatus::PAID ) ) {
					if ( $ids ) {
						$this->repo->update( $id, $ids );
					}
					return array();
				}
				$this->repo->update(
					$id,
					$ids + array(
						'status'     => PaymentStatus::PAID,
						'received_at'    => $now,
						'version'    => (int) $payment['version'] + 1,
						'updated_at' => $now,
					)
				);
				$this->repo->insert_transaction(
					$base + array(
						'type'         => TransactionType::CAPTURE,
						'amount' => $event->amount_cents ?? (int) $payment['amount'],
						'status'       => TransactionType::SUCCEEDED,
						'external_ref' => $event->provider_payment_id ?? $payment['provider_payment_id'] ?? $event->external_id ?? $payment['external_id'],
					)
				);
				return array( 'paid' => true );

			case ProviderEvent::PAYMENT_AUTHORIZED:
				if ( PaymentStatus::can( $status, PaymentStatus::AUTHORIZED ) ) {
					$this->repo->update( $id, $ids + array( 'status' => PaymentStatus::AUTHORIZED, 'version' => (int) $payment['version'] + 1, 'updated_at' => $now ) );
					$this->repo->insert_transaction( $base + array( 'type' => TransactionType::AUTHORIZATION, 'amount' => $event->amount_cents ?? (int) $payment['amount'], 'status' => TransactionType::SUCCEEDED, 'external_ref' => 'auth:' . ( $event->provider_payment_id ?? $payment['provider_payment_id'] ?? $id ) ) );
				}
				return array();

			case ProviderEvent::PAYMENT_PROCESSING:
			case ProviderEvent::PAYMENT_REQUIRES_ACTION:
				$to   = ProviderEvent::PAYMENT_PROCESSING === $event->kind ? PaymentStatus::PENDING : PaymentStatus::REQUIRES_ACTION;
				$note = $event->failure_code ? array(
					// Why the provider holds the money (e.g. a review).
					'failure_code'    => self::short( $event->failure_code, 60 ),
					'failure_message' => self::short( $event->failure_message, 255 ),
				) : array();
				$this->repo->update( $id, $ids + $note + ( PaymentStatus::can( $status, $to ) ? array( 'status' => $to, 'updated_at' => $now ) : array() ) );
				return array();

			case ProviderEvent::ATTEMPT_FAILED:
				if ( ! in_array( $status, PaymentStatus::OPEN, true ) ) {
					return array();
				}
				$ledger = $this->repo->transactions( $id );
				$last   = $ledger ? end( $ledger ) : null;
				if ( $last && TransactionType::PAYMENT_FAILED === $last['type'] && ( json_decode( (string) $last['response'], true )['code'] ?? null ) === $event->failure_code ) {
					return array(); // The same failed attempt reported again (re-read of the order).
				}
				$this->repo->update( $id, $ids + array( 'failure_code' => self::short( $event->failure_code, 60 ), 'failure_message' => self::short( $event->failure_message, 255 ), 'updated_at' => $now ) );
				$this->repo->insert_transaction( $base + array( 'type' => TransactionType::PAYMENT_FAILED, 'amount' => $event->amount_cents ?? (int) $payment['amount'], 'status' => TransactionType::FAILED, 'response' => wp_json_encode( array_filter( array( 'code' => $event->failure_code, 'message' => $event->failure_message ) ) ) ) );
				return array();

			case ProviderEvent::PAYMENT_FAILED:
				if ( ! PaymentStatus::can( $status, PaymentStatus::FAILED ) ) {
					return array();
				}
				$this->repo->update( $id, $ids + array( 'status' => PaymentStatus::FAILED, 'failed_at' => $now, 'failure_code' => self::short( $event->failure_code, 60 ) ?? $payment['failure_code'], 'failure_message' => self::short( $event->failure_message, 255 ) ?? $payment['failure_message'], 'version' => (int) $payment['version'] + 1, 'updated_at' => $now ) );
				$this->repo->insert_transaction( $base + array( 'type' => TransactionType::PAYMENT_FAILED, 'amount' => (int) $payment['amount'], 'status' => TransactionType::FAILED, 'external_ref' => 'failed:' . $id ) );
				return array( 'failed' => true );

			case ProviderEvent::PAYMENT_CANCELED:
				if ( ! PaymentStatus::can( $status, PaymentStatus::CANCELLED ) ) {
					return array();
				}
				$this->repo->update( $id, $ids + array( 'status' => PaymentStatus::CANCELLED, 'cancelled_at' => $now, 'checkout_url' => null, 'version' => (int) $payment['version'] + 1, 'updated_at' => $now ) );
				$this->repo->insert_transaction( $base + array( 'type' => TransactionType::CANCELLATION, 'amount' => 0, 'status' => TransactionType::SUCCEEDED, 'external_ref' => 'cancelled:' . $id ) );
				return array( 'cancelled' => true );

			case ProviderEvent::REFUND_UPDATED:
				if ( ! in_array( $status, PaymentStatus::SETTLED, true ) || ! $event->refund_id ) {
					return array();
				}
				$tx = $this->repo->transaction_by( 'external_ref', $event->refund_id, $id )
					?? ( $event->refund_request ? $this->repo->transaction_by( 'idempotency_key', $event->refund_request, $id ) : null );
				if ( $tx ) {
					if ( ! $tx['external_ref'] ) {
						$this->repo->update_transaction( (int) $tx['id'], array( 'external_ref' => $event->refund_id ) );
					}
					if ( ! self::refund_moves( (string) $tx['status'], (string) $event->refund_status ) ) {
						return array();
					}
					$this->repo->update_transaction( (int) $tx['id'], array( 'status' => $event->refund_status, 'updated_at' => $now ) );
				} elseif ( $this->adopt_pending_refund( $id, $event ) ) {
					// Our own refund, reported before the provider answered our request.
					$tx = (array) $this->repo->transaction_by( 'external_ref', $event->refund_id, $id );
					if ( self::refund_moves( (string) $tx['status'], (string) $event->refund_status ) ) {
						$this->repo->update_transaction( (int) $tx['id'], array( 'status' => $event->refund_status, 'updated_at' => $now ) );
					}
				} else {
					// Refunded from the provider's own dashboard: recorded here too.
					$sums = $this->repo->refund_sums( $id );
					$this->repo->insert_transaction(
						$base + array(
							'type'         => (int) $event->amount_cents >= (int) $payment['amount'] - $sums['succeeded'] ? TransactionType::REFUND : TransactionType::PARTIAL_REFUND,
							'amount' => (int) $event->amount_cents,
							'status'       => (string) $event->refund_status,
							'external_ref' => $event->refund_id,
							'reason'       => __( 'Rimborso fatto dal pannello del sistema di pagamento', 'booking-manager' ),
							'updated_at'   => $now,
						)
					);
				}
				$this->apply_refund_totals( $id );
				return array(
					'refunds' => array(
						'refund' => $event->refund_id,
						'status' => $event->refund_status,
						'amount' => $event->amount_cents,
					),
				);

			case ProviderEvent::DISPUTE_OPENED:
				return array( 'dispute' => true );
		}
		return array();
	}

	/**
	 * A refund notification without our key (Nexi) may concern a refund we have just asked
	 * for and whose answer has not arrived yet: the pending request of the same amount, still
	 * without a provider reference, takes the reference instead of a second row being added.
	 */
	private function adopt_pending_refund( int $payment_id, ProviderEvent $event ): bool {
		if ( $event->refund_request || ! $event->amount_cents ) {
			return false;
		}
		foreach ( $this->repo->transactions( $payment_id ) as $tx ) {
			if ( in_array( $tx['type'], array( TransactionType::REFUND, TransactionType::PARTIAL_REFUND ), true )
				&& TransactionType::PENDING === $tx['status'] && empty( $tx['external_ref'] )
				&& (int) $tx['amount'] === (int) $event->amount_cents ) {
				$this->repo->update_transaction( (int) $tx['id'], array( 'external_ref' => $event->refund_id ) );
				return true;
			}
		}
		return false;
	}

	/**
	 * Gives a refund row the provider's reference, unless another row already holds it (the
	 * notification recorded it meanwhile): then this row is withdrawn, so it counts once.
	 *
	 * @return bool whether the row now holds the reference
	 */
	private function claim_refund_ref( array $tx, ?string $ref ): bool {
		if ( ! $ref || ! empty( $tx['external_ref'] ) ) {
			return ! empty( $tx['external_ref'] );
		}
		$other = $this->repo->transaction_by( 'external_ref', $ref, (int) $tx['payment_id'] );
		if ( $other && (int) $other['id'] !== (int) $tx['id'] ) {
			$this->repo->update_transaction( (int) $tx['id'], array( 'status' => TransactionType::CANCELED, 'reason' => mb_substr( trim( (string) $tx['reason'] . ' — ' . __( 'già registrato dalla notifica', 'booking-manager' ) ), 0, 255 ), 'updated_at' => $this->clock->now_mysql() ) );
			return false;
		}
		$this->repo->update_transaction( (int) $tx['id'], array( 'external_ref' => $ref ) );
		return true;
	}

	/** Recomputes the refunded amount and the status from the ledger. */
	private function apply_refund_totals( int $payment_id ): void {
		$payment  = (array) $this->repo->find( $payment_id );
		$refunded = min( (int) $payment['amount'], $this->repo->refund_sums( $payment_id )['succeeded'] );
		$status   = PaymentStatus::after_refunds( (int) $payment['amount'], $refunded );
		$changes  = array(
			'refunded' => $refunded,
			'updated_at'     => $this->clock->now_mysql(),
		);
		if ( $status !== $payment['status'] && in_array( $payment['status'], PaymentStatus::SETTLED, true ) ) {
			// Derived from the ledger: a refund that fails after succeeding lowers it again.
			$changes['status']  = $status;
			$changes['version'] = (int) $payment['version'] + 1;
		}
		$this->repo->update( $payment_id, $changes );
		$this->sync_booking( (int) $payment['booking_id'] );
	}

	/**
	 * What a paid online payment means for its booking. Safe to run again at any time:
	 * every branch is idempotent (confirm once, refund with a fixed key).
	 *
	 * @param array<string, mixed> $payment
	 */
	public function settle_booking( array $payment ): void {
		if ( ManualProvider::ID === $payment['gateway'] || PaymentStatus::PAID !== $payment['status'] || $this->repo->has_late_refund( (int) $payment['id'] ) ) {
			return; // Not ours to settle, or already being given back.
		}
		$booking = $this->booking_repo->find( (int) $payment['booking_id'] );
		if ( ! $booking ) {
			return;
		}
		if ( BookingStatus::OPTION === $booking['status'] ) {
			// The option waited for this payment: confirmed now, by the system.
			for ( $try = 0; $try < 2; $try++ ) {
				$confirmed = $this->bookings->set_status(
					(int) $booking['id'],
					(int) $booking['version'],
					BookingStatus::CONFIRMED,
					array(
						'user_id' => 0,
						'actor'   => 'system',
						'reason'  => __( 'Pagamento online ricevuto.', 'booking-manager' ),
					)
				);
				if ( ! is_wp_error( $confirmed ) || 'bkng_conflict' !== $confirmed->get_error_code() ) {
					break;
				}
				$booking = (array) $this->booking_repo->find( (int) $booking['id'] );
			}
			$booking = (array) $this->booking_repo->find( (int) $booking['id'] );
		}
		if ( BookingStatus::CANCELLED === $booking['status'] && $booking['cancelled_at'] && (string) $payment['received_at'] >= (string) $booking['cancelled_at'] ) {
			$this->late_refund( $payment, __( 'Pagamento arrivato dopo l\'annullamento della prenotazione (o dopo la scadenza dell\'opzione).', 'booking-manager' ) );
			return;
		}
		if ( in_array( $booking['status'], array( BookingStatus::CONFIRMED, BookingStatus::IN_HOUSE, BookingStatus::COMPLETED ), true ) ) {
			$this->refund_overpayment( $payment );
		}
	}

	/**
	 * Two payment pages paid for the same booking: what exceeds the total goes back.
	 *
	 * @param array<string, mixed> $payment
	 */
	private function refund_overpayment( array $payment ): void {
		$booking = (array) $this->booking_repo->find( (int) $payment['booking_id'] );
		$excess  = (int) $booking['paid'] - (int) $booking['refunded'] - (int) $booking['total'];
		if ( $excess <= 0 ) {
			return;
		}
		$fresh  = (array) $this->repo->find( (int) $payment['id'] );
		$amount = min( $excess, (int) $fresh['amount'] - (int) $fresh['refunded'] );
		if ( $amount > 0 ) {
			$this->refund( (int) $payment['id'], (int) $payment['property_id'], $amount, __( 'Pagato più del totale della prenotazione.', 'booking-manager' ), 'over-' . $payment['idempotency_key'], 'system' );
		}
	}

	/** @param array<string, mixed> $payment */
	private function late_refund( array $payment, string $reason ): void {
		if ( $this->repo->transaction_by( 'idempotency_key', 'late-' . $payment['idempotency_key'] ) ) {
			return; // Already handled.
		}
		$result = $this->refund( (int) $payment['id'], (int) $payment['property_id'], (int) $payment['amount'] - (int) $payment['refunded'], $reason, 'late-' . $payment['idempotency_key'], 'system' );
		$this->log(
			is_wp_error( $result ) ? 'payment.late_refund_failed' : 'payment.late_refund',
			$payment,
			array( 'error' => is_wp_error( $result ) ? $result->get_error_message() : null ),
			'system'
		);
		if ( is_wp_error( $result ) ) {
			$this->repo->update(
				(int) $payment['id'],
				array(
					/* translators: %s: error */
					'failure_message' => mb_substr( sprintf( __( 'Rimborso automatico non riuscito: %s. Rimborsa a mano.', 'booking-manager' ), $result->get_error_message() ), 0, 255 ),
				)
			);
			/**
			 * A late payment could not be refunded automatically: staff must act.
			 *
			 * @param int    $payment_id
			 * @param string $error
			 */
			do_action( 'bkng_payment_needs_attention', (int) $payment['id'], $result->get_error_message() );
		}
	}

	/* ================================================================== helpers */

	/**
	 * Finds the payment an event is about, only among this account's payments: a
	 * notification signed by one account can never touch another account's money.
	 *
	 * @return array<string, mixed>|null
	 */
	private function locate( PaymentAccount $account, PaymentProvider $provider, ProviderEvent $event, string $source ): ?array {
		$candidates = array();
		if ( $event->payment_ref ) {
			$candidates[] = $this->repo->find( $event->payment_ref );
		}
		if ( $event->external_id ) {
			$candidates[] = $this->repo->find_by( $provider->id(), 'external_id', $event->external_id );
		}
		if ( $event->provider_payment_id ) {
			$candidates[] = $this->repo->find_by( $provider->id(), 'provider_payment_id', $event->provider_payment_id );
		}
		foreach ( $candidates as $payment ) {
			if ( $payment && $payment['gateway'] === $provider->id() && (int) $payment['payment_account_id'] === $account->id && self::same_payment( $payment, $event, $source ) ) {
				return $payment;
			}
		}
		return null;
	}

	/**
	 * Is the provider object of the event really the one we created for this payment?
	 * A reference in metadata alone is never enough: anyone able to start a checkout on
	 * the same account (a payment link, a buy button) could name our payment.
	 *
	 * @param array<string, mixed> $payment
	 */
	private static function same_payment( array $payment, ProviderEvent $event, string $source ): bool {
		if ( $event->external_id ) {
			return (string) $payment['external_id'] === $event->external_id;
		}
		if ( $event->provider_payment_id ) {
			if ( $payment['provider_payment_id'] ) {
				return (string) $payment['provider_payment_id'] === $event->provider_payment_id;
			}
			// Before the payment is known, only harmless news may come through the intent.
			return ProviderEvent::ATTEMPT_FAILED === $event->kind;
		}
		return 'system' === $source; // Our own decisions (e.g. closing a page) carry only the id.
	}

	/**
	 * @param array<string, mixed> $payment
	 * @return array{0: ?PaymentProvider, 1: ?PaymentAccount}
	 */
	private function provider_for( array $payment ): array {
		$provider = $this->providers->get( (string) $payment['gateway'] );
		if ( ! $provider ) {
			return array( null, null );
		}
		if ( ManualProvider::ID === $provider->id() ) {
			return array( $provider, ManualProvider::account( (int) $payment['property_id'] ) );
		}
		$account = $payment['payment_account_id'] ? $this->accounts->find( (int) $payment['payment_account_id'] ) : null;
		return array( $provider, $account && $account->credentials ? $account : null );
	}

	/** Copies the payments' totals onto the booking (paid, refunded, balance, payment status). */
	private function sync_booking( int $booking_id ): void {
		$totals = $this->repo->booking_totals( $booking_id );
		$this->booking_repo->set_payment_totals( $booking_id, $totals['paid'], $totals['refunded'] );
	}

	/**
	 * @param array<string, mixed> $booking
	 * @return array{total: int, paid: int, refunded: int, due: int, deposit_due: int, deposit_missing: int}
	 */
	public static function totals( array $booking ): array {
		$total    = (int) $booking['total'];
		$paid     = (int) $booking['paid'];
		$refunded = (int) $booking['refunded'];
		$net      = $paid - $refunded;
		return array(
			'total'           => $total,
			'paid'            => $paid,
			'refunded'        => $refunded,
			'due'             => max( 0, $total - $net ),
			'deposit_due'     => (int) $booking['deposit_due'],
			'deposit_missing' => max( 0, min( $total, (int) $booking['deposit_due'] ) - $net ),
		);
	}

	/**
	 * deposit when the amount covers only part of the total and the booking asks for a
	 * deposit not yet collected; balance when something was already paid; full otherwise.
	 *
	 * @param array<string, mixed> $booking
	 */
	private static function kind_of( array $booking, int $amount ): string {
		$t = self::totals( $booking );
		if ( $amount >= $t['due'] ) {
			return $t['paid'] - $t['refunded'] > 0 ? 'balance' : 'full';
		}
		return $t['deposit_missing'] > 0 ? 'deposit' : 'partial';
	}

	/**
	 * Context of the payment hooks: ids, amounts and statuses, no personal data.
	 *
	 * @param array<string, mixed> $payment
	 * @return array<string, mixed>
	 */
	private static function hook_context( array $payment ): array {
		return array(
			'payment_id'  => (int) $payment['id'],
			'property_id' => (int) $payment['property_id'],
			'provider'    => (string) $payment['gateway'],
			'amount'      => (int) $payment['amount'],
			'refunded'    => (int) $payment['refunded'],
			'currency'    => (string) $payment['currency'],
			'status'      => (string) $payment['status'],
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	private function booking_in( int $booking_id, int $property_id ): array|\WP_Error {
		$booking = $this->booking_repo->find( $booking_id );
		if ( ! $booking || (int) $booking['property_id'] !== $property_id ) {
			return new \WP_Error( 'bkng_not_found', __( 'Prenotazione non trovata in questa struttura.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		return $booking;
	}

	/**
	 * @param array<string, mixed> $payment
	 * @return array<string, mixed>
	 */
	public function present( array $payment, bool $with_transactions = false ): array {
		[ $provider, $account ] = $this->provider_for( $payment );
		$caps                   = $provider && $account ? $provider->capabilities( $account ) : array();
		$sums                   = $this->repo->refund_sums( (int) $payment['id'] );
		$settled                = in_array( $payment['status'], array( PaymentStatus::PAID, PaymentStatus::PARTIALLY_REFUNDED ), true );
		$refundable             = $settled ? max( 0, (int) $payment['amount'] - $sums['succeeded'] - $sums['pending'] ) : 0;
		$out                    = array(
			'id'               => (int) $payment['id'],
			'booking_id'       => (int) $payment['booking_id'],
			'provider'         => (string) $payment['gateway'],
			'provider_label'   => $provider ? $provider->label() : (string) $payment['gateway'],
			'method'           => $payment['method'],
			'amount'     => (int) $payment['amount'],
			'refunded'   => (int) $payment['refunded'],
			'refund_pending'   => $sums['pending'],
			'refundable_cents' => $refundable,
			'currency'         => (string) $payment['currency'],
			'status'           => (string) $payment['status'],
			'kind'          => (string) $payment['kind'],
			'livemode'         => null === $payment['livemode'] ? null : (bool) (int) $payment['livemode'],
			'reference'        => $payment['provider_payment_id'] ?: $payment['external_id'],
			'failure_message'  => $payment['failure_message'],
			'created_at'       => \BookingManager\Core\Clock::iso( (string) $payment['created_at'] ),
			'received_at'          => $payment['received_at'] ? \BookingManager\Core\Clock::iso( (string) $payment['received_at'] ) : null,
			'can_refund'       => $refundable > 0 && in_array( Capability::REFUNDS, $caps, true ),
			'can_partial'      => in_array( Capability::PARTIAL_REFUNDS, $caps, true ),
			'checkout_open'    => in_array( $payment['status'], PaymentStatus::OPEN, true ) && ! empty( $payment['checkout_url'] ),
		);
		if ( $with_transactions ) {
			$out['transactions'] = array_map(
				fn( $t ) => array(
					'id'           => (int) $t['id'],
					'type'         => (string) $t['type'],
					'status'       => (string) $t['status'],
					'amount' => (int) $t['amount'],
					'reference'    => $t['external_ref'],
					'reason'       => $t['reason'] ?? null,
					'actor_type'   => $t['actor_type'] ?? null,
					'created_at'   => \BookingManager\Core\Clock::iso( (string) $t['created_at'] ),
				),
				$this->repo->transactions( (int) $payment['id'] )
			);
		}
		return $out;
	}

	/**
	 * @param callable(): mixed $callback
	 * @return mixed
	 */
	private function with_lock( string $key, callable $callback ) {
		global $wpdb;
		$name = 'bkng_pay_' . md5( $wpdb->prefix . $key );
		$mine = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name ) );
		if ( ! $mine && '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ) {
			throw new LockFailed( 'payment lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal.
		}
		try {
			return $callback();
		} finally {
			if ( ! $mine ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
		}
	}

	/**
	 * @param array<string, mixed> $payment
	 * @param array<string, mixed> $extra
	 */
	private function log( string $action, array $payment, array $extra, string $actor = '' ): void {
		$this->audit->log(
			array_filter(
				array(
					'action'      => $action,
					'entity_type' => 'payment',
					'entity_id'   => (int) $payment['id'],
					'property_id' => (int) $payment['property_id'],
					'actor_type'  => '' === $actor ? null : $actor,
					'new'         => array(
						'booking_id' => (int) $payment['booking_id'],
						'provider'   => (string) $payment['gateway'],
						'amount'     => (int) $payment['amount'],
						'status'     => (string) $payment['status'],
						'reference'  => $payment['provider_payment_id'] ?: $payment['external_id'],
					) + array_filter( $extra, fn( $v ) => null !== $v && '' !== $v ),
				),
				fn( $v ) => null !== $v
			) + ( in_array( $actor, array( 'provider', 'system' ), true ) ? array( 'user_id' => null ) : array() )
		);
	}

	/** Final refund states never change; succeeded may still turn into failed (bank return). */
	private static function refund_moves( string $from, string $to ): bool {
		if ( $from === $to || in_array( $from, array( TransactionType::FAILED, TransactionType::CANCELED ), true ) ) {
			return false;
		}
		return ! ( TransactionType::SUCCEEDED === $from && TransactionType::PENDING === $to );
	}

	/** The provider did not answer clearly (network, 5xx): the operation may have happened. */
	private static function uncertain( \WP_Error $error ): bool {
		$data = (array) $error->get_error_data();
		return in_array( $error->get_error_code(), array( 'bkng_stripe_unreachable', 'bkng_stripe_bad_response', 'bkng_provider_unreachable', 'bkng_provider_bad_response' ), true )
			|| (int) ( $data['http_status'] ?? 0 ) >= 500;
	}

	private static function short( ?string $value, int $max ): ?string {
		return null === $value || '' === $value ? null : mb_substr( $value, 0, $max );
	}

	private static function write_error(): \WP_Error {
		return new \WP_Error( 'bkng_write_failed', __( 'Non è stato possibile salvare il pagamento: riprova.', 'booking-manager' ), array( 'status' => 500 ) );
	}
}
