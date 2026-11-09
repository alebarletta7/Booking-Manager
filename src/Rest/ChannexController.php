<?php
/**
 * Channex (channel manager towards Booking.com and the other portals):
 *
 *   GET  /channex                                 settings (no secrets), linked properties, warnings
 *   PUT  /channex/settings                        switch, environment, address, key, webhook secret
 *   POST /channex/test                            checks the key (properties seen by it)
 *   POST /channex/webhook                         creates the webhook on Channex
 *   POST /channex/webhook/test                    asks Channex to call it once
 *   POST /channex/signing-key                     generates the webhook key (kept on the site)
 *   POST /channex/alerts/read                     warnings read
 *   POST /properties/{id}/channex/connection/alerts-read   a property's warnings read (0.2.18)
 *   GET|PUT|DELETE /properties/{id}/channex/connection   the property's own connection (0.2.6)
 *   POST /properties/{id}/channex/connection/(test|webhook|webhook-test|signing-key)
 *   GET  /properties/{id}/channex                 mapping, Channex's options, sync state, reservations
 *   PUT  /properties/{id}/channex/mapping         saves the mapping (starts a full sync)
 *   POST /properties/{id}/channex/(push|pull|full-sync)
 *   POST /channex/revisions/{revision}/(retry|resolve)
 *
 * The site's connection (/channex/*) is the platform's; everything under a property needs
 * bkng_manage_integrations on it. Public, signed by Channex:
 *
 *   POST /public/channex/webhook                  the site's connection
 *   POST /public/channex/webhook/{property uuid}  a property's own connection (0.2.6)
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Channels\Channex\ChannexAdmin;
use BookingManager\Channels\Channex\ChannexBookings;
use BookingManager\Channels\Channex\ChannexRevisions;
use BookingManager\Channels\Channex\ChannexSettings;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class ChannexController extends RestController {

	private const REVISION = '(?P<revision>[0-9a-fA-F-]{36})';

	public function __construct(
		Policy $policy,
		private ChannexAdmin $admin,
		private ChannexBookings $inbound,
		private ChannexRevisions $revisions,
		private ?ChannexSettings $settings = null,
		private ?PropertyRepository $properties = null
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$ns     = self::REST_NAMESPACE;
		// The Channex account, webhook and keys are one for the whole site until each
		// property gets its own connection (0.2.6): only the platform touches them.
		$manage = $this->require_cap( 'bkng_platform_manage' );
		$global = array(
			'/channex'               => array( \WP_REST_Server::READABLE, fn() => $this->admin->overview() ),
			'/channex/settings'      => array( 'PUT', fn( \WP_REST_Request $r ) => $this->admin->save_settings( self::body( $r ) ) ),
			'/channex/test'          => array( \WP_REST_Server::CREATABLE, fn() => $this->admin->test() ),
			'/channex/webhook'       => array( \WP_REST_Server::CREATABLE, fn() => $this->admin->register_webhook() ),
			'/channex/webhook/test'  => array( \WP_REST_Server::CREATABLE, fn() => $this->admin->test_webhook() ),
			'/channex/signing-key'   => array( \WP_REST_Server::CREATABLE, fn() => $this->admin->create_signing_key() ),
			'/channex/alerts/read'   => array( \WP_REST_Server::CREATABLE, fn() => $this->admin->read_alerts() ),
		);
		foreach ( $global as $route => [ $method, $callback ] ) {
			register_rest_route(
				$ns,
				$route,
				array(
					'methods'             => $method,
					'callback'            => static fn( \WP_REST_Request $r ) => self::respond( $callback( $r ) ),
					'permission_callback' => $manage,
				)
			);
		}

		$base     = '/properties/(?P<property_id>\d+)/channex';
		$property = $this->require_cap_for_property( 'bkng_manage_integrations' );
		$routes   = array(
			''           => array( \WP_REST_Server::READABLE, fn( \WP_REST_Request $r ) => $this->admin->property( (int) $r['property_id'] ) ),
			'/mapping'   => array( 'PUT', fn( \WP_REST_Request $r ) => $this->admin->save_mapping( (int) $r['property_id'], self::body( $r ) ) ),
			'/push'      => array( \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->admin->push_now( (int) $r['property_id'] ) ),
			'/pull'      => array( \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->admin->pull_now( (int) $r['property_id'] ) ),
			'/full-sync' => array( \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->admin->full_sync( (int) $r['property_id'] ) ),
		);
		$pid    = static fn( \WP_REST_Request $r ) => (int) $r['property_id'];
		$routes += array(
			'/connection'                => array(
				array( \WP_REST_Server::READABLE, fn( \WP_REST_Request $r ) => $this->admin->connection( $pid( $r ) ) ),
				array( 'PUT', fn( \WP_REST_Request $r ) => $this->admin->on_property( $pid( $r ), 'save_settings', self::body( $r ) ) ),
				array( \WP_REST_Server::DELETABLE, fn( \WP_REST_Request $r ) => $this->admin->on_property( $pid( $r ), 'forget' ) ),
			),
			'/connection/test'           => array( \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->admin->on_property( $pid( $r ), 'test' ) ),
			'/connection/webhook'        => array( \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->admin->on_property( $pid( $r ), 'register_webhook' ) ),
			'/connection/webhook-test'   => array( \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->admin->on_property( $pid( $r ), 'test_webhook' ) ),
			'/connection/signing-key'    => array( \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->admin->on_property( $pid( $r ), 'create_signing_key' ) ),
			'/connection/alerts-read'    => array( \WP_REST_Server::CREATABLE, fn( \WP_REST_Request $r ) => $this->admin->on_property( $pid( $r ), 'read_alerts' ) ),
		);
		foreach ( $routes as $route => $endpoints ) {
			$endpoints = is_array( $endpoints[0] ) ? $endpoints : array( $endpoints );
			register_rest_route(
				$ns,
				$base . $route,
				array_map(
					static fn( array $e ) => array(
						'methods'             => $e[0],
						'callback'            => static fn( \WP_REST_Request $r ) => self::respond( ( $e[1] )( $r ) ),
						'permission_callback' => $property,
						'args'                => array( 'property_id' => self::property_arg() ),
					),
					$endpoints
				)
			);
		}

		// A revision of a property the caller cannot see answers 404, like a missing one.
		$revision = function ( \WP_REST_Request $r ) {
			$basic = $this->policy->authorize( 'bkng_manage_integrations' );
			if ( true !== $basic ) {
				return $basic;
			}
			$row = $this->revisions->find( strtolower( (string) $r['revision'] ) );
			if ( ! $row || ! $row['property_id'] || true !== $this->policy->authorize( 'bkng_manage_integrations', (int) $row['property_id'] ) ) {
				return new \WP_Error( 'bkng_not_found', __( 'Elemento non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
			}
			return true;
		};
		register_rest_route(
			$ns,
			'/channex/revisions/' . self::REVISION . '/retry',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->admin->retry( strtolower( (string) $r['revision'] ) ) ),
				'permission_callback' => $revision,
			)
		);
		register_rest_route(
			$ns,
			'/channex/revisions/' . self::REVISION . '/resolve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->admin->resolve( strtolower( (string) $r['revision'] ) ) ),
				'permission_callback' => $revision,
			)
		);

		register_rest_route(
			PublicController::REST_NAMESPACE,
			'/channex/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => $this->webhook( $r ),
				'permission_callback' => '__return_true', // Authenticated by Channex's HMAC signature.
			)
		);
		register_rest_route(
			PublicController::REST_NAMESPACE,
			'/channex/webhook/(?P<property>[0-9a-fA-F-]{36})',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $r ) {
					$property = $this->properties ? $this->properties->find_by_uuid( (string) $r['property'] ) : null;
					if ( ! $property || ! $this->settings ) {
						return new \WP_REST_Response( array( 'received' => false ), 404 );
					}
					// Checked with this property's own secret, acting only on this property.
					return $this->settings->scoped( (int) $property['id'], fn() => $this->webhook( $r ), true );
				},
				'permission_callback' => '__return_true', // Authenticated by the property's own secret.
			)
		);
	}

	/** Answers at once: the reservations are read by a job, not in this request. */
	private function webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$status = $this->inbound->webhook(
			(string) $request->get_body(),
			(string) $request->get_header( 'x_hmac_timestamp' ),
			(string) $request->get_header( 'x_hmac_signature_256' ),
			(string) $request->get_header( strtolower( str_replace( '-', '_', ChannexBookings::TOKEN_HEADER ) ) )
		);
		// Minimal body: nothing useful to someone probing the endpoint.
		return new \WP_REST_Response( array( 'received' => 200 === $status ), $status );
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
