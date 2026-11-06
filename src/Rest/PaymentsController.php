<?php
/**
 * Payments in the admin app, and the providers' notifications:
 *
 *   GET    /properties/{property_id}/payment-accounts                     overview (bkng_manage_payment_accounts)
 *   PUT    /properties/{property_id}/payment-accounts/{provider}/{mode}   connect / update credentials
 *   POST   /properties/{property_id}/payment-accounts/{provider}/{mode}/verify
 *   DELETE /properties/{property_id}/payment-accounts/{provider}/{mode}   disconnect
 *   POST   /properties/{property_id}/payment-accounts/select { provider, mode }
 *
 *   GET    /bookings/{id}/payments                                        bkng_view_payments
 *   POST   /bookings/{id}/payments { amount, method, note, request_id }   desk payment (bkng_record_payment)
 *   POST   /bookings/{id}/payment-link { amount }                         hosted page for the guest (bkng_record_payment)
 *   POST   /payments/{id}/refund { amount, reason, request_id }           bkng_refund_payment
 *   POST   /payments/{id}/sync                                            ask the provider now
 *
 *   POST   /webhooks/{provider}/{account_id}                              public, authenticated by the provider
 *
 * Secrets are never sent back: the overview shows only a hint of the key.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Bookings\ManageLink;
use BookingManager\Core\NoStore;
use BookingManager\Payments\PaymentAccountService;
use BookingManager\Payments\PaymentRepository;
use BookingManager\Payments\PaymentService;

defined( 'ABSPATH' ) || exit;

class PaymentsController extends RestController {

	public function __construct(
		Policy $policy,
		private PaymentService $payments,
		private PaymentRepository $repo,
		private PaymentAccountService $accounts,
		private BookingRepository $bookings,
		private ManageLink $manage
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$ns       = self::REST_NAMESPACE;
		$base     = '/properties/(?P<property_id>\d+)/payment-accounts';
		$account  = $base . '/(?P<provider>[a-z0-9_-]{2,20})/(?P<mode>test|live)';
		$manage   = $this->require_cap_for_property( 'bkng_manage_payment_accounts' );
		$booking  = fn( int $id ) => $this->bookings->find( $id );
		$payment  = fn( int $id ) => $this->repo->find( $id );

		register_rest_route(
			$ns,
			$base,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::safely( fn() => $this->accounts->overview( (int) $r['property_id'] ) ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			$ns,
			$base . '/select',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::safely( fn() => $this->accounts->select( (int) $r['property_id'], sanitize_key( (string) $r->get_param( 'provider' ) ), sanitize_key( (string) $r->get_param( 'mode' ) ) ) ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			$ns,
			$account,
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => fn( \WP_REST_Request $r ) => self::safely( fn() => $this->accounts->save( (int) $r['property_id'], (string) $r['provider'], (string) $r['mode'], self::body( $r ) ) ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::safely( fn() => $this->accounts->disconnect( (int) $r['property_id'], (string) $r['provider'], (string) $r['mode'] ) ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			$ns,
			$account . '/verify',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::safely( fn() => $this->accounts->verify_now( (int) $r['property_id'], (string) $r['provider'], (string) $r['mode'] ) ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			$ns,
			'/bookings/(?P<id>\d+)/payments',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => function ( \WP_REST_Request $r ) {
						$b = (array) $this->bookings->find( (int) $r['id'] );
						return self::respond( $this->payments->for_booking( (int) $b['id'], (int) $b['property_id'] ) );
					},
					'permission_callback' => $this->require_cap_for_record( 'bkng_view_payments', $booking ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => function ( \WP_REST_Request $r ) {
						$b = (array) $this->bookings->find( (int) $r['id'] );
						return self::respond(
							$this->payments->record_manual(
								(int) $b['id'],
								(int) $b['property_id'],
								(int) $r->get_param( 'amount' ),
								sanitize_key( (string) $r->get_param( 'method' ) ),
								(string) $r->get_param( 'note' ),
								(string) $r->get_param( 'request_id' )
							),
							201
						);
					},
					'permission_callback' => $this->require_cap_for_record( 'bkng_record_payment', $booking ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/bookings/(?P<id>\d+)/payment-link',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'payment_link' ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_record_payment', $booking ),
			)
		);
		register_rest_route(
			$ns,
			'/payments/(?P<id>\d+)/refund',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $r ) {
					$p = (array) $this->repo->find( (int) $r['id'] );
					return self::respond(
						$this->payments->refund(
							(int) $p['id'],
							(int) $p['property_id'],
							(int) $r->get_param( 'amount' ),
							(string) $r->get_param( 'reason' ),
							(string) $r->get_param( 'request_id' )
						)
					);
				},
				'permission_callback' => $this->require_cap_for_record( 'bkng_refund_payment', $payment ),
			)
		);
		register_rest_route(
			$ns,
			'/payments/(?P<id>\d+)/sync',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $r ) {
					$this->payments->reconcile( (array) $this->repo->find( (int) $r['id'] ) );
					return rest_ensure_response( $this->payments->present( (array) $this->repo->find( (int) $r['id'] ), true ) );
				},
				'permission_callback' => $this->require_cap_for_record( 'bkng_view_payments', $payment ),
			)
		);

		register_rest_route(
			$ns,
			'/webhooks/(?P<provider>[a-z0-9_-]{2,20})/(?P<account>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $r ) {
					$result = $this->payments->handle_webhook( (string) $r['provider'], (int) $r['account'], $r );
					return NoStore::apply( is_wp_error( $result ) ? rest_convert_error_to_response( $result ) : rest_ensure_response( $result ) );
				},
				// The provider authenticates the call (signature or order lookup) in handle_webhook().
				'permission_callback' => '__return_true',
			)
		);
	}

	/** A hosted payment page for the amount due (or the given amount), to send to the guest. */
	public function payment_link( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$booking = (array) $this->bookings->find( (int) $request['id'] );
		if ( in_array( $booking['status'], array( BookingStatus::CANCELLED, BookingStatus::COMPLETED, BookingStatus::NO_SHOW ), true ) ) {
			return new \WP_Error( 'bkng_booking_closed', __( 'La prenotazione è conclusa o annullata.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$amount = (int) $request->get_param( 'amount' );
		$amount = $amount > 0 ? $amount : PaymentService::totals( $booking )['due'];
		$back   = $this->manage->url( $booking );
		$result = $this->payments->start_online_payment( $booking, $amount, add_query_arg( 'bkng_paid', '1', $back ), add_query_arg( 'bkng_paid', '0', $back ), $booking['email'] ?? null );
		return self::respond( $result, 201 );
	}

	/**
	 * Runs a settings operation; an unexpected PHP error becomes a readable message (the
	 * details go to the site's error log, never to the screen: they may hold provider answers).
	 *
	 * @param callable(): mixed $operation
	 */
	private static function safely( callable $operation ): \WP_REST_Response|\WP_Error {
		try {
			return self::respond( $operation() );
		} catch ( \Throwable $e ) {
			error_log( 'Booking Manager payments: ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostics for the site owner.
			return new \WP_Error( 'bkng_internal_error', __( 'Errore interno durante l\'operazione: i dettagli sono nel log degli errori del sito.', 'booking-manager' ), array( 'status' => 500 ) );
		}
	}

	/** @return array<string, mixed> */
	private static function body( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : array();
	}
}
