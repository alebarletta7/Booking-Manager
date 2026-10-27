<?php
/**
 * WhatsApp (bkng_manage_settings), like Night Manager's: one WhatsApp Business number for
 * the whole site.
 *
 *   GET    /whatsapp                              settings + messages + variables
 *   PUT    /whatsapp/settings                     active, country_code
 *   PUT    /whatsapp/connection                   provider + its fields
 *   DELETE /whatsapp/connection
 *   PUT    /whatsapp/templates/{key}              body, is_active, reset
 *   POST   /whatsapp/templates/{key}/preview      body? → text
 *   POST   /whatsapp/templates/{key}/test         to (phone)
 *   POST   /whatsapp/templates/refresh            asks the provider about approvals
 *   GET    /whatsapp/messages?status&page&per_page   the log
 *   POST   /whatsapp/messages/{id}/resend
 *
 * And, public (booking-manager/public/v1), for Meta:
 *
 *   GET    /whatsapp/webhook                      subscription check (verify token)
 *   POST   /whatsapp/webhook                      delivery updates (signed with the app secret)
 *
 * Secrets are never returned: secret fields only say whether they are set.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Notifications\NotificationRepository;
use BookingManager\Notifications\WhatsApp\WhatsAppService;

defined( 'ABSPATH' ) || exit;

class WhatsAppController extends RestController {

	private const KEY = '(?P<key>[a-z_]{3,30})';

	public function __construct(
		Policy $policy,
		private WhatsAppService $service
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$manage = $this->require_cap( 'bkng_manage_settings' );
		$routes = array(
			'/whatsapp'                                => array(
				array( \WP_REST_Server::READABLE, fn() => $this->service->view() ),
			),
			'/whatsapp/settings'                       => array(
				array( 'PUT', fn( \WP_REST_Request $r ) => $this->service->save_settings( self::body( $r ) ) ),
			),
			'/whatsapp/connection'                     => array(
				array( 'PUT', fn( \WP_REST_Request $r ) => $this->service->connect( self::body( $r ) ) ),
				array( \WP_REST_Server::DELETABLE, fn() => $this->service->disconnect() ),
			),
			'/whatsapp/templates/refresh'              => array(
				array( \WP_REST_Server::CREATABLE, fn() => $this->service->refresh() ),
			),
			'/whatsapp/templates/' . self::KEY         => array(
				array( 'PUT', fn( \WP_REST_Request $r ) => $this->service->save_template( (string) $r['key'], self::body( $r ) ) ),
			),
			'/whatsapp/templates/' . self::KEY . '/preview' => array(
				array(
					\WP_REST_Server::CREATABLE,
					function ( \WP_REST_Request $r ) {
						$body = self::body( $r );
						return $this->service->preview(
							(string) $r['key'],
							isset( $body['body'] ) && is_string( $body['body'] ) ? $body['body'] : null,
							array_key_exists( 'button', $body ) ? (bool) rest_sanitize_boolean( $body['button'] ) : null
						);
					},
				),
			),
			'/whatsapp/templates/' . self::KEY . '/test' => array(
				array( \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->service->send_test( (string) $r['key'], (string) ( self::body( $r )['to'] ?? '' ) ) ),
			),
			'/whatsapp/messages/(?P<id>\d+)/resend'    => array(
				array( \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->service->resend( (int) $r['id'] ) ),
			),
		);
		foreach ( $routes as $route => $endpoints ) {
			$args = array();
			foreach ( $endpoints as [ $method, $callback ] ) {
				$args[] = array(
					'methods'             => $method,
					'callback'            => static fn( \WP_REST_Request $r ) => self::respond( $callback( $r ) ),
					'permission_callback' => $manage,
				);
			}
			register_rest_route( self::REST_NAMESPACE, $route, $args );
		}
		register_rest_route(
			self::REST_NAMESPACE,
			'/whatsapp/messages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond(
					$this->service->log(
						array(
							'status'   => (string) $r->get_param( 'status' ),
							'page'     => (int) ( $r->get_param( 'page' ) ?: 1 ),
							'per_page' => (int) ( $r->get_param( 'per_page' ) ?: 30 ),
						)
					)
				),
				'permission_callback' => $manage,
				'args'                => array(
					'status'   => array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), NotificationRepository::STATUSES ),
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);
		register_rest_route(
			PublicController::REST_NAMESPACE,
			'/whatsapp/webhook',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => $this->webhook_verify( $r ),
					'permission_callback' => '__return_true', // Checked against the verify token.
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( \WP_REST_Request $r ) => $this->webhook( $r ),
					'permission_callback' => '__return_true', // Authenticated by Meta's signature.
				),
			)
		);
	}

	/** Meta calls this once, when the webhook is saved: it must get the challenge back as is. */
	private function webhook_verify( \WP_REST_Request $request ): \WP_REST_Response {
		$mode      = (string) $request->get_param( 'hub_mode' );
		$token     = (string) $request->get_param( 'hub_verify_token' );
		$challenge = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $request->get_param( 'hub_challenge' ) );
		if ( 'subscribe' !== $mode || '' === $challenge || ! $this->service->verify_webhook( $token ) ) {
			return new \WP_REST_Response( array( 'received' => false ), 403 );
		}
		add_filter(
			'rest_pre_serve_request',
			static function () use ( $challenge ) {
				if ( ! headers_sent() ) {
					header( 'Content-Type: text/plain; charset=utf-8' );
				}
				echo esc_html( $challenge );
				return true;
			}
		);
		return new \WP_REST_Response( $challenge, 200 );
	}

	private function webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->service->webhook( (string) $request->get_body(), (string) $request->get_header( 'x_hub_signature_256' ) );
		if ( is_wp_error( $result ) ) {
			// Minimal body: no detail useful to someone probing the endpoint.
			return new \WP_REST_Response( array( 'received' => false ), (int) ( $result->get_error_data()['status'] ?? 400 ) );
		}
		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	/** @return array<string, mixed> */
	private static function body( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		return is_array( $body ) ? $body : array();
	}
}
