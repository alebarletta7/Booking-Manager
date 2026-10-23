<?php
/**
 * /me — who is the current operator, what can they do, which properties can they see.
 *
 * The admin app reads this once at boot to build navigation (purely cosmetic: every
 * endpoint re-checks permissions server-side).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Capabilities;
use BookingManager\Access\Policy;
use BookingManager\Access\PropertyScope;
use BookingManager\Core\Clock;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class MeController extends RestController {

	public const PREFS_META = 'bkng_prefs';

	public function __construct(
		Policy $policy,
		private PropertyScope $scope,
		private PropertyRepository $properties,
		private Clock $clock
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/me',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn() => rest_ensure_response( $this->payload( get_current_user_id() ) ),
				'permission_callback' => $this->require_cap( 'bkng_access' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/me/preferences',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_preferences' ),
				'permission_callback' => $this->require_cap( 'bkng_access' ),
				'args'                => array(
					'operational_mode' => array(
						'type' => 'boolean',
					),
					'property_id'      => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function payload( int $user_id ): array {
		$user = get_userdata( $user_id );
		$caps = array();
		foreach ( Capabilities::all() as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				$caps[] = $cap;
			}
		}

		$properties = array();
		foreach ( $this->properties->all( $this->scope->allowed_ids( $user_id ), false ) as $property ) {
			$properties[] = array(
				'id'           => (int) $property['id'],
				'uuid'         => (string) $property['uuid'],
				'name'         => (string) $property['name'],
				'slug'         => (string) $property['slug'],
				'kind'         => (string) $property['kind'],
				'room_is_type' => in_array( $property['kind'], PropertyRepository::ROOM_IS_TYPE, true ),
				'timezone'     => (string) $property['timezone'],
				'currency'     => (string) $property['currency'],
				'today'        => $this->clock->today_in( (string) $property['timezone'] ),
				'housekeeping' => ! empty( $property['settings']['housekeeping_enabled'] ),
			);
		}

		$prefs = $this->preferences( $user_id );
		$ids   = array_column( $properties, 'id' );
		if ( ! in_array( $prefs['property_id'], $ids, true ) ) {
			$prefs['property_id'] = $ids[0] ?? null;
		}

		$role_names = wp_roles()->get_names();
		$roles      = array();
		foreach ( $user ? $user->roles : array() as $slug ) {
			$roles[] = translate_user_role( $role_names[ $slug ] ?? $slug );
		}

		return array(
			'id'           => $user_id,
			'display_name' => $user ? $user->display_name : '',
			'roles'        => $roles,
			'capabilities' => $caps,
			'is_wp_admin'  => user_can( $user_id, 'manage_options' ),
			'properties'   => $properties,
			'preferences'  => $prefs,
		);
	}

	/**
	 * @return array{operational_mode: bool, operational_locked: bool, property_id: int|null}
	 */
	public function preferences( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::PREFS_META, true );
		$stored = is_array( $stored ) ? $stored : array();
		$locked = self::operational_by_default( $user_id );
		return array(
			// Staff always work in operational mode; administrators choose.
			'operational_mode'   => $locked || ! empty( $stored['operational_mode'] ),
			'operational_locked' => $locked,
			'property_id'        => isset( $stored['property_id'] ) ? (int) $stored['property_id'] : null,
		);
	}

	/**
	 * Staff (any Booking Manager access, but not an administrator of WordPress or of
	 * Booking Manager) always work in operational mode, without the WordPress menus.
	 */
	public static function operational_by_default( int $user_id ): bool {
		return $user_id > 0
			&& user_can( $user_id, 'bkng_access' )
			&& ! user_can( $user_id, 'manage_options' )
			&& ! user_can( $user_id, 'bkng_manage_settings' );
	}

	public function update_preferences( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user_id = get_current_user_id();
		$prefs   = $this->preferences( $user_id );

		if ( null !== $request->get_param( 'operational_mode' ) ) {
			if ( $prefs['operational_locked'] && ! $request->get_param( 'operational_mode' ) ) {
				return new \WP_Error( 'bkng_forbidden', __( 'La modalità operativa è sempre attiva per il tuo profilo.', 'booking-manager' ), array( 'status' => 403 ) );
			}
			$prefs['operational_mode'] = (bool) $request->get_param( 'operational_mode' );
		}
		if ( null !== $request->get_param( 'property_id' ) ) {
			$property_id = (int) $request->get_param( 'property_id' );
			if ( ! $this->scope->can_access( $user_id, $property_id ) ) {
				return new \WP_Error( 'bkng_property_not_found', __( 'Struttura non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
			}
			$prefs['property_id'] = $property_id;
		}

		update_user_meta( $user_id, self::PREFS_META, array_intersect_key( $prefs, array_flip( array( 'operational_mode', 'property_id' ) ) ) );
		return rest_ensure_response( $prefs );
	}
}
