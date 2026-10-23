<?php
/**
 * booking-manager/public/v1 — the API of the public booking form. No login; every answer
 * is "no-store" (never cached by a CDN), every write is rate-limited and checked again on
 * the server.
 *
 *   GET  /properties                         properties taking online bookings
 *   GET  /properties/{slug}                  rules, room types, form token, captcha
 *   GET  /properties/{slug}/search           free room types and rates for a stay
 *   POST /properties/{slug}/holds            rooms held while the guest fills in the form
 *   POST /holds/release                      rooms let go (guest went back)
 *   POST /properties/{slug}/bookings         the booking
 *   GET  /manage/{code}?token                the guest's booking
 *   POST /manage/{code}/cancel?token         cancellation by the guest
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Core\ClientIp;
use BookingManager\Core\NoStore;
use BookingManager\Frontend\FormToken;
use BookingManager\Frontend\PublicBookingService;

defined( 'ABSPATH' ) || exit;

class PublicController {

	public const REST_NAMESPACE = 'booking-manager/public/v1';

	/**
	 * Address of a public route, in the form index.php?rest_route=/…: WordPress answers it
	 * with or without pretty permalinks and on servers whose rewrite rules do not pass
	 * /wp-json/ to WordPress.
	 */
	public static function url( string $path = '' ): string {
		return add_query_arg( 'rest_route', '/' . self::REST_NAMESPACE . '/' . ltrim( $path, '/' ), home_url( 'index.php' ) );
	}

	public function __construct( private PublicBookingService $service ) {}

	public function register_routes(): void {
		$public = '__return_true';
		$slug   = '(?P<slug>[a-z0-9\-]+)';
		$code   = '(?P<code>[A-Za-z0-9]{6,16})';
		$token  = array(
			'token' => array(
				'type'     => 'string',
				'required' => true,
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/properties',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn() => self::out( $this->service->properties() ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/' . $slug,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'property' ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/' . $slug . '/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::out( $this->service->search( (string) $r['slug'], $r->get_query_params(), ClientIp::current( $r ) ) ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/' . $slug . '/holds',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::out( $this->service->hold( (string) $r['slug'], (array) $r->get_json_params(), ClientIp::current( $r ) ), 201 ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/holds/release',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::out( $this->service->release( (string) ( $r->get_json_params()['token'] ?? '' ) ) ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/' . $slug . '/bookings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::out( $this->service->create( (string) $r['slug'], (array) $r->get_json_params(), ClientIp::current( $r ) ), 201 ),
				'permission_callback' => $public,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/manage/' . $code,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::out( $this->service->manage( (string) $r['code'], (string) $r->get_param( 'token' ), ClientIp::current( $r ) ) ),
				'permission_callback' => $public,
				'args'                => $token,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/manage/' . $code . '/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::out( $this->service->cancel( (string) $r['code'], (string) $r->get_param( 'token' ), ClientIp::current( $r ) ) ),
				'permission_callback' => $public,
				'args'                => $token,
			)
		);
	}

	/** A property for the form, with a fresh signed start time and the captcha settings. */
	public function property( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$property = $this->service->property( (string) $request['slug'] );
		if ( $property instanceof \WP_Error ) {
			return self::out( $property );
		}
		return self::out(
			$property + array(
				'captcha'    => $this->service->captcha_config(),
				'form_token' => FormToken::issue( time() ),
			)
		);
	}

	/**
	 * @param mixed $result
	 */
	private static function out( $result, int $status = 200 ): \WP_REST_Response|\WP_Error {
		if ( $result instanceof \WP_Error ) {
			return NoStore::apply( rest_convert_error_to_response( $result ) );
		}
		$response = rest_ensure_response( $result );
		$response->set_status( $status );
		return NoStore::apply( $response );
	}
}
