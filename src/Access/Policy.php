<?php
/**
 * Central authorization: capability AND property.
 *
 * Every REST permission_callback goes through here; UI button visibility is never
 * considered a security control.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Access;

defined( 'ABSPATH' ) || exit;

class Policy {

	public function __construct( private PropertyScope $scope ) {}

	public function scope(): PropertyScope {
		return $this->scope;
	}

	/**
	 * @param int|null $property_id When given, the user must also have access to it.
	 */
	public function authorize( string $cap, ?int $property_id = null, ?int $user_id = null ): bool|\WP_Error {
		$user_id = $user_id ?? get_current_user_id();

		if ( $user_id <= 0 ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'Devi effettuare l\'accesso.', 'booking-manager' ), array( 'status' => 401 ) );
		}
		if ( ! user_can( $user_id, 'bkng_access' ) || ! user_can( $user_id, $cap ) ) {
			return new \WP_Error( 'bkng_forbidden', __( 'Non hai il permesso per questa operazione.', 'booking-manager' ), array( 'status' => 403 ) );
		}
		if ( null !== $property_id && ! $this->scope->can_access( $user_id, $property_id ) ) {
			// 404 instead of 403: do not reveal that the property exists.
			return new \WP_Error( 'bkng_property_not_found', __( 'Struttura non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		return true;
	}

	public function can( string $cap, ?int $property_id = null, ?int $user_id = null ): bool {
		return true === $this->authorize( $cap, $property_id, $user_id );
	}
}
