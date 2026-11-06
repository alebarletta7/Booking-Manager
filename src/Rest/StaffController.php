<?php
/**
 * /staff — operators, their Booking Manager role and properties (bkng_manage_staff).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Staff\StaffService;

defined( 'ABSPATH' ) || exit;

class StaffController extends RestController {

	public function __construct( Policy $policy, private StaffService $staff ) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$cap   = $this->require_cap( 'bkng_manage_staff' );
		$actor = fn() => wp_get_current_user();

		register_rest_route(
			self::REST_NAMESPACE,
			'/staff',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn() => rest_ensure_response(
						array(
							'staff' => $this->staff->list( $actor() ),
							'roles' => $this->staff->roles(),
						)
					),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->staff->add( $actor(), (array) $r->get_json_params() ), 201 ),
					'permission_callback' => $cap,
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/staff/candidates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => rest_ensure_response( $this->staff->candidates( (string) $r->get_param( 'search' ) ) ),
				'permission_callback' => $cap,
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/staff/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->staff->update( $actor(), (int) $r['id'], (array) $r->get_json_params() ) ),
					'permission_callback' => $cap,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->staff->remove( $actor(), (int) $r['id'] ) ),
					'permission_callback' => $cap,
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/roles/(?P<slug>[a-z_]+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond(
					$r->get_param( 'reset' )
						? $this->staff->reset_role( $actor(), (string) $r['slug'] )
						: $this->staff->update_role( $actor(), (string) $r['slug'], array_values( (array) $r->get_param( 'caps' ) ) )
				),
				'permission_callback' => $this->require_cap( 'bkng_manage_roles' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/capabilities',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn() => rest_ensure_response(
					array(
						'groups' => \BookingManager\Access\Capabilities::groups(),
						'caps'   => array_map(
							static fn( $cap, $def ) => array(
								'cap'   => $cap,
								'group' => $def['group'],
								'label' => \BookingManager\Access\Capabilities::labels()[ $cap ] ?? $cap,
							),
							array_keys( \BookingManager\Access\Capabilities::definitions() ),
							\BookingManager\Access\Capabilities::definitions()
						),
					)
				),
				'permission_callback' => $cap,
			)
		);
	}
}
