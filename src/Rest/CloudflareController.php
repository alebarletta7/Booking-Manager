<?php
/**
 * /cloudflare — Cloudflare in Impostazioni (bkng_manage_settings): state of the request,
 * Zone ID and API token, connection check, recommended rules. The token goes in and
 * never comes out: responses carry only a masked hint. Adapted from Night Manager 0.16.3.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Audit\AuditLogger;
use BookingManager\Cloudflare\CloudflareService;
use BookingManager\Cloudflare\CloudflareSettings;

defined( 'ABSPATH' ) || exit;

class CloudflareController extends RestController {

	public function __construct(
		Policy $policy,
		private CloudflareSettings $settings,
		private CloudflareService $service,
		private AuditLogger $audit
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$manage = $this->require_cap( 'bkng_manage_settings' );
		register_rest_route(
			self::REST_NAMESPACE,
			'/cloudflare',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn() => rest_ensure_response( $this->service->view() ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $manage,
				),
			)
		);
		foreach ( array(
			'/cloudflare/verify' => fn() => rest_ensure_response( $this->service->verify() ),
			'/cloudflare/rules'  => fn() => rest_ensure_response( $this->service->apply_rules() ),
		) as $route => $callback ) {
			register_rest_route(
				self::REST_NAMESPACE,
				$route,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => $callback,
					'permission_callback' => $manage,
				)
			);
		}
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input  = (array) $request->get_json_params();
		$before = $this->settings->public_view();
		$after  = $this->settings->update( $input );
		if ( $after instanceof \WP_Error ) {
			return $after;
		}
		$changed = array();
		foreach ( array( 'zone_id', 'api_token_hint' ) as $key ) {
			if ( $before[ $key ] !== $after[ $key ] ) {
				// The hint only: the audit log never holds the token.
				$changed[ $key ] = $after[ $key ];
			}
		}
		if ( $changed ) {
			$this->audit->log(
				array(
					'action'      => 'cloudflare.settings_updated',
					'entity_type' => 'settings',
					'old'         => array_intersect_key( $before, $changed ),
					'new'         => $changed,
				)
			);
		}
		return rest_ensure_response( $this->service->view() );
	}
}
