<?php
/**
 * The licence of this site (WordPress administrators only: bkng_manage_settings and
 * manage_options, as in Beach Manager):
 *
 *   GET  /license                              state (+ updates from the licence server)
 *   POST /license/activate { license_key }     activates (empty key: the saved one)
 *   POST /license/refresh                      asks the server now («Verifica ora»)
 *   POST /license/deactivate                   frees the place, keeps the key
 *   POST /license/updates/check                asks for a new version now («Verifica aggiornamenti»)
 *
 * The key never comes back: only its last 4 characters. The installation key never
 * leaves the server.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Core\NoStore;
use BookingManager\Licensing\LicenseManager;
use BookingManager\Updates\UpdateChecker;

defined( 'ABSPATH' ) || exit;

class LicenseController extends RestController {

	public function __construct( Policy $policy, private LicenseManager $license, private UpdateChecker $updates ) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$admin = $this->admin_only();
		register_rest_route(
			self::REST_NAMESPACE,
			'/license',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn() => $this->send( $this->view() ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/license/activate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'activate' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/license/refresh',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function () {
					$this->license->refresh();
					return $this->send( $this->view( true ) );
				},
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/license/updates/check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn() => $this->send( $this->license->summary() + array( 'updates' => $this->updates->check_now() ) ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/license/deactivate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn() => $this->send( $this->license->deactivate() + array( 'updates' => $this->updates->state() ) ),
				'permission_callback' => $admin,
			)
		);
	}

	/** The licence is the site's, not the property's: WordPress administrators only. */
	private function admin_only(): callable {
		return function () {
			$basic = $this->policy->authorize( 'bkng_manage_settings' );
			if ( true !== $basic ) {
				return $basic;
			}
			return current_user_can( 'manage_options' )
				? true
				: new \WP_Error( 'bkng_forbidden', __( 'Solo un amministratore di WordPress può gestire la licenza.', 'booking-manager' ), array( 'status' => 403 ) );
		};
	}

	/** @return array<string, mixed> */
	private function view( bool $fresh = false ): array {
		return $this->license->summary() + array( 'updates' => $this->updates->state( $fresh ) );
	}

	/** @param array<string, mixed> $data */
	private function send( array $data ): \WP_REST_Response {
		return NoStore::apply( rest_ensure_response( $data ) );
	}

	public function activate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = (array) $request->get_json_params();
		$key  = is_string( $body['license_key'] ?? null ) ? $body['license_key'] : '';
		$done = $this->license->activate( $key );
		if ( $done instanceof \WP_Error ) {
			$data = (array) $done->get_error_data();
			// Only status, message and the sites of a full licence reach the browser.
			return new \WP_Error(
				$done->get_error_code(),
				$done->get_error_message(),
				array(
					'status' => $data['status'] ?? 400,
					'sites'  => array_values( (array) ( $data['sites'] ?? array() ) ),
				)
			);
		}
		return $this->send( $done + array( 'updates' => $this->updates->state( true ) ) );
	}
}
