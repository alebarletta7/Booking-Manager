<?php
/**
 * Properties (strutture):
 *
 *   GET  /properties                     the properties the caller can see
 *   POST /properties                     new property (bkng_manage_properties)
 *   GET  /properties/{id}
 *   PUT  /properties/{id}                details (bkng_manage_property_settings)
 *   PUT  /properties/{id}/settings       per-property settings
 *   POST /properties/{id}/archive|restore
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Properties\PropertyService;

defined( 'ABSPATH' ) || exit;

class PropertiesController extends RestController {

	public function __construct( Policy $policy, private PropertyService $service, private PropertyRepository $repo ) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$load = fn( int $id ) => $this->repo->find( $id ) ? array( 'property_id' => $id ) : null;

		register_rest_route(
			self::REST_NAMESPACE,
			'/properties',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->require_cap( 'bkng_access' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->create( (array) $r->get_json_params(), get_current_user_id() ), 201 ),
					'permission_callback' => $this->require_cap( 'bkng_manage_properties' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( PropertyService::payload( (array) $this->repo->find( (int) $r['id'] ) ) ),
					'permission_callback' => $this->require_cap_for_record( 'bkng_access', $load ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->update( (int) $r['id'], (array) $r->get_json_params() ) ),
					'permission_callback' => $this->require_cap_for_record( 'bkng_manage_property_settings', $load ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<id>\d+)/settings',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->update_settings( (int) $r['id'], (array) $r->get_json_params() ) ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_manage_property_settings', $load ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<id>\d+)/(?P<action>archive|restore)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( 'archive' === $r['action'] ? $this->service->archive( (int) $r['id'] ) : $this->service->restore( (int) $r['id'] ) ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_manage_properties', $load ),
			)
		);
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$allowed = $this->policy->scope()->allowed_ids( get_current_user_id() );
		$all     = rest_sanitize_boolean( $request->get_param( 'include_archived' ) );
		return rest_ensure_response( array_map( array( PropertyService::class, 'payload' ), $this->repo->all( $allowed, $all ) ) );
	}
}
