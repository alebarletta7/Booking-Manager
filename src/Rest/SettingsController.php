<?php
/**
 * /settings — global settings of the plugin (bkng_manage_settings): data retention,
 * uninstall behaviour, audit IP, Turnstile keys of the public form.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Audit\AuditLogger;
use BookingManager\Core\Settings;
use BookingManager\Core\Validation;
use BookingManager\Frontend\Captcha;

defined( 'ABSPATH' ) || exit;

class SettingsController extends RestController {

	public function __construct( Policy $policy, private Settings $settings, private AuditLogger $audit, private Captcha $captcha ) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn() => rest_ensure_response( $this->view() ),
					'permission_callback' => $this->require_cap( 'bkng_manage_settings' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->require_cap( 'bkng_manage_settings' ),
				),
			)
		);
	}

	/** @return array<string, mixed> */
	private function view(): array {
		return $this->settings->all() + array( 'captcha' => $this->captcha->admin_view() );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body   = (array) $request->get_json_params();
		$input  = array_intersect_key( $body, Settings::defaults() );
		$errors = array();
		foreach ( array( 'guest_retention_months', 'audit_retention_months', 'notification_retention_months', 'checkin_retention_months' ) as $key ) {
			if ( array_key_exists( $key, $input ) && null === Validation::int_between( $input[ $key ], 0, 240 ) ) {
				$errors[ $key ] = __( 'Indica un numero di mesi tra 0 e 240 (0 = conserva sempre).', 'booking-manager' );
			}
		}
		if ( $errors ) {
			return Validation::error( $errors );
		}
		$old = $this->settings->all();
		$this->settings->update( $input );
		$new = $this->settings->all();
		$log = array(
			'old' => array_intersect_key( $old, $input ),
			'new' => array_intersect_key( $new, $input ),
		);
		if ( isset( $body['captcha'] ) && is_array( $body['captcha'] ) ) {
			$c = $body['captcha'];
			// The secret is written only when sent; it is never read back.
			$this->captcha->configure( (string) ( $c['site_key'] ?? $this->captcha->site_key() ), array_key_exists( 'secret', $c ) ? (string) $c['secret'] : null );
			$log['new']['captcha'] = array(
				'site_key'       => $this->captcha->site_key(),
				'secret_changed' => array_key_exists( 'secret', $c ),
			);
		}
		$this->audit->log(
			array(
				'action'      => 'settings.updated',
				'entity_type' => 'settings',
				'old'         => $log['old'],
				'new'         => $log['new'],
			)
		);
		return rest_ensure_response( $this->view() );
	}
}
