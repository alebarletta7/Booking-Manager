<?php
/**
 * The platform panel (0.2.2), all under bkng_platform_manage (WordPress administrators):
 *
 * - /platform/tenants (GET list, POST create), /platform/tenants/{id} (GET, PUT),
 *   /platform/tenants/{id}/(suspend|activate|archive) (POST, with a reason);
 * - /platform/tenants/{id}/domains (GET, POST {host}, DELETE ?host=);
 * - /platform/tenants/{id}/users (GET, POST like /staff, in that customer);
 * - /platform/plans (GET, PUT {plans: [...]}); /platform/stats?from&to (GET).
 *
 * And /tenant: the customer of the current user, read only (status, plan, limits, use,
 * domains). The customer comes from the user, never from the request.
 *
 * /tenant/profile (0.2.4, GET and PUT, bkng_manage_properties): contacts, WhatsApp, logo,
 * font and return page of the user's own customer. Only the platform may name another
 * customer, with ?tenant=ID.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Access\PropertyScope;
use BookingManager\Core\Validation;
use BookingManager\Tenants\TenantProfile;
use BookingManager\Tenants\TenantService;

defined( 'ABSPATH' ) || exit;

class PlatformController extends RestController {

	public function __construct( Policy $policy, private TenantService $tenants, private PropertyScope $scope, private TenantProfile $profile ) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$platform = $this->require_cap( 'bkng_platform_manage' );
		$id       = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/platform/tenants',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => rest_ensure_response( $this->tenants->list( (string) $r->get_param( 'search' ), (string) $r->get_param( 'status' ) ) ),
					'permission_callback' => $platform,
					'args'                => array(
						'search' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->tenants->create( (array) $r->get_json_params() ), 201 ),
					'permission_callback' => $platform,
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/platform/tenants/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->tenants->get( (int) $r['id'] ) ),
					'permission_callback' => $platform,
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->tenants->update( (int) $r['id'], (array) $r->get_json_params() ) ),
					'permission_callback' => $platform,
					'args'                => $id,
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/platform/tenants/(?P<id>\d+)/(?P<action>suspend|activate|archive)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->tenants->set_status( (int) $r['id'], (string) $r['action'], (string) $r->get_param( 'reason' ) ) ),
				'permission_callback' => $platform,
				'args'                => $id,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/platform/tenants/(?P<id>\d+)/domains',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->domains( (int) $r['id'] ) ),
					'permission_callback' => $platform,
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->tenants->add_domain( (int) $r['id'], (string) $r->get_param( 'host' ) ) ),
					'permission_callback' => $platform,
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->tenants->remove_domain( (int) $r['id'], (string) $r->get_param( 'host' ) ) ),
					'permission_callback' => $platform,
					'args'                => $id,
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/platform/tenants/(?P<id>\d+)/users',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->tenants->users( (int) $r['id'] ) ),
					'permission_callback' => $platform,
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->tenants->add_user( (int) $r['id'], (array) $r->get_json_params() ), 201 ),
					'permission_callback' => $platform,
					'args'                => $id,
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/platform/plans',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn() => rest_ensure_response( $this->tenants->plans() ),
					'permission_callback' => $platform,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->tenants->save_plans( $r->get_param( 'plans' ) ) ),
					'permission_callback' => $platform,
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/platform/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => $platform,
				'args'                => array(
					'from' => array( 'type' => 'string' ),
					'to'   => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/tenant',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn() => rest_ensure_response( $this->tenants->own( $this->scope->tenant_of( get_current_user_id() ) ) ),
				'permission_callback' => $this->require_cap( 'bkng_access' ),
			)
		);
		$own = array(
			'tenant' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/tenant/profile',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->profile->payload( $this->profile_tenant( $r ) ) ?? TenantService::not_found() ),
					'permission_callback' => array( $this, 'can_edit_profile' ),
					'args'                => $own,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->profile->save( $this->profile_tenant( $r ), (array) $r->get_json_params() ) ),
					'permission_callback' => array( $this, 'can_edit_profile' ),
					'args'                => $own,
				),
			)
		);
	}

	/** The administrator of a customer, on its own customer; the platform on any. */
	public function can_edit_profile( \WP_REST_Request $request ): bool|\WP_Error {
		$basic = $this->policy->authorize( 'bkng_manage_properties' );
		if ( true !== $basic ) {
			return $basic;
		}
		$asked = (int) $request->get_param( 'tenant' );
		if ( $asked > 0 && $asked !== $this->scope->tenant_of( get_current_user_id() ) ) {
			return $this->policy->authorize( 'bkng_platform_manage' );
		}
		return $this->profile_tenant( $request ) > 0 ? true : $this->policy->authorize( 'bkng_platform_manage' );
	}

	/** The customer whose profile is read or written: never taken from the request, except by the platform. */
	private function profile_tenant( \WP_REST_Request $request ): int {
		$asked = (int) $request->get_param( 'tenant' );
		if ( $asked > 0 && current_user_can( 'bkng_platform_manage' ) ) {
			return $asked;
		}
		return $this->scope->tenant_of( get_current_user_id() );
	}

	/** @return string[]|\WP_Error */
	private function domains( int $id ): array|\WP_Error {
		$tenant = $this->tenants->get( $id );
		return $tenant instanceof \WP_Error ? $tenant : $tenant['domains'];
	}

	public function stats( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$to   = Validation::date( (string) $request->get_param( 'to' ) ) ?? gmdate( 'Y-m-d' );
		$from = Validation::date( (string) $request->get_param( 'from' ) ) ?? gmdate( 'Y-m-01', strtotime( $to . ' UTC' ) );
		if ( $from > $to ) {
			return Validation::error( array( 'from' => __( 'Il periodo non è valido.', 'booking-manager' ) ) );
		}
		return rest_ensure_response( $this->tenants->stats( $from, $to ) );
	}
}
