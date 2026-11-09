<?php
/**
 * WhatsApp, like Night Manager's: one WhatsApp Business number per customer (0.2.6; before,
 * one for the whole site). The customer's administrator (bkng_manage_properties) manages
 * its own; the platform (bkng_manage_settings) its own, or any customer's with ?tenant=ID.
 * Every request is scoped to that customer (WhatsAppSettings::scoped).
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
 *   GET    /whatsapp/webhook[/{customer uuid}]    subscription check (verify token)
 *   POST   /whatsapp/webhook[/{customer uuid}]    delivery updates (signed with the app secret)
 *   (without the uuid: the default customer, the address used before 0.2.6)
 *
 * Secrets are never returned: secret fields only say whether they are set.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Access\PropertyScope;
use BookingManager\Notifications\NotificationRepository;
use BookingManager\Notifications\WhatsApp\WhatsAppService;
use BookingManager\Notifications\WhatsApp\WhatsAppSettings;
use BookingManager\Tenants\TenantRepository;

defined( 'ABSPATH' ) || exit;

class WhatsAppController extends RestController {

	private const KEY = '(?P<key>[a-z_]{3,30})';

	public function __construct(
		Policy $policy,
		private WhatsAppService $service,
		private ?WhatsAppSettings $settings = null,
		private ?PropertyScope $scope = null
	) {
		parent::__construct( $policy );
	}

	/**
	 * The platform, or the administrator of a customer for its own customer only.
	 */
	public function can_manage( \WP_REST_Request $request ): bool|\WP_Error {
		$asked = (int) $request->get_param( 'tenant' );
		if ( current_user_can( 'bkng_manage_settings' ) ) {
			return $this->policy->authorize( 'bkng_manage_settings' );
		}
		$basic = $this->policy->authorize( 'bkng_manage_properties' );
		if ( true !== $basic ) {
			return $basic;
		}
		$own = $this->scope ? $this->scope->tenant_of( get_current_user_id() ) : 0;
		if ( $own <= 0 || ( $asked > 0 && $asked !== $own ) ) {
			return $this->policy->authorize( 'bkng_manage_settings' );
		}
		return true;
	}

	/** The customer of the request: never from the request, except for the platform. */
	private function tenant( \WP_REST_Request $request ): int {
		$asked = (int) $request->get_param( 'tenant' );
		if ( $asked > 0 && current_user_can( 'bkng_manage_settings' ) ) {
			return $asked;
		}
		return $this->scope ? $this->scope->acting_tenant( get_current_user_id() ) : 0;
	}

	/**
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private function in_tenant( int $tenant, callable $fn ): mixed {
		return $this->settings ? $this->settings->scoped( $tenant, $fn ) : $fn();
	}

	public function register_routes(): void {
		$manage = array( $this, 'can_manage' );
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
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->in_tenant( $this->tenant( $r ), fn() => $callback( $r ) ) ),
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
					$this->in_tenant(
						$this->tenant( $r ),
						fn() => $this->service->log(
							array(
								'status'   => (string) $r->get_param( 'status' ),
								'page'     => (int) ( $r->get_param( 'page' ) ?: 1 ),
								'per_page' => (int) ( $r->get_param( 'per_page' ) ?: 30 ),
							)
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
		foreach ( array( '/whatsapp/webhook', '/whatsapp/webhook/(?P<customer>[0-9a-fA-F-]{36})' ) as $hook ) {
			register_rest_route(
				PublicController::REST_NAMESPACE,
				$hook,
				array(
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => fn( \WP_REST_Request $r ) => $this->for_webhook( $r, fn() => $this->webhook_verify( $r ) ),
						'permission_callback' => '__return_true', // Checked against the verify token.
					),
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => fn( \WP_REST_Request $r ) => $this->for_webhook( $r, fn() => $this->webhook( $r ) ),
						'permission_callback' => '__return_true', // Authenticated by Meta's signature.
					),
				)
			);
		}
	}

	/** Meta's calls, with the settings of the customer in the address (or the default one). */
	private function for_webhook( \WP_REST_Request $request, callable $fn ): \WP_REST_Response {
		$uuid = (string) $request['customer'];
		if ( '' === $uuid ) {
			return $fn();
		}
		$tenant = ( new TenantRepository() )->find_by_uuid( $uuid );
		if ( ! $tenant ) {
			return new \WP_REST_Response( array( 'received' => false ), 404 );
		}
		return $this->in_tenant( (int) $tenant['id'], $fn );
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
