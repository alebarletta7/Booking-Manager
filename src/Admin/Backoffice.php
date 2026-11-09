<?php
/**
 * Works with the site's «Backoffice» (Custom Backoffice Manager, 0.2.11), the page
 * with one card per gestionale, like Beach, Night and Restaurant Manager:
 *
 * - who may open the Booking Manager card: when the Backoffice has no role chosen for
 *   Booking Manager (its default: «roles are empty on purpose»), the rule of this
 *   plugin applies, the same as its own app page: the bkng_access capability, which
 *   every Booking Manager role has. Roles the administrator did choose in Custom
 *   Backoffice → Gestione ruoli are respected as they are.
 * - «Cambia Gestionale» leads to the Backoffice page instead of the home page.
 *
 * Nothing here runs without that plugin: the filter is simply never called.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Admin;

defined( 'ABSPATH' ) || exit;

final class Backoffice {

	/** Our id in the Backoffice registry. */
	public const MANAGER = 'booking';

	public function hooks(): void {
		add_filter( 'cbm_user_can_access_manager', array( $this, 'can_access' ), 10, 4 );
	}

	/**
	 * @param mixed                $allowed    Decision of the Backoffice.
	 * @param mixed                $user_id    User.
	 * @param mixed                $manager_id Gestionale asked for.
	 * @param array<string, mixed> $result     Its evaluation (reason, matched roles…).
	 */
	public function can_access( $allowed, $user_id = 0, $manager_id = '', $result = array() ): bool {
		if ( $allowed || self::MANAGER !== $manager_id ) {
			return (bool) $allowed;
		}
		return self::decide( (string) ( $result['reason'] ?? '' ), self::roles_chosen(), (int) $user_id > 0 && user_can( (int) $user_id, 'bkng_access' ) );
	}

	/**
	 * The rule (unit-tested). Only a refusal for lack of role (no role chosen, or Members
	 * not active) is reconsidered; not installed, inactive or missing capabilities stay
	 * refused.
	 */
	public static function decide( string $reason, bool $roles_chosen, bool $has_access ): bool {
		if ( 'members_missing' === $reason ) {
			return $has_access;
		}
		return 'no_role' === $reason && ! $roles_chosen && $has_access;
	}

	/** Did the administrator choose roles for Booking Manager in the Backoffice? */
	private static function roles_chosen(): bool {
		if ( ! class_exists( '\CBM_Settings' ) || ! is_callable( array( '\CBM_Settings', 'manager' ) ) ) {
			return false;
		}
		$conf = (array) \CBM_Settings::manager( self::MANAGER );
		return ! empty( $conf['roles'] );
	}

	/** Where «Cambia Gestionale» leads: the Backoffice page when there is one. */
	public static function switch_url(): string {
		if ( class_exists( '\CBM_Settings' ) && is_callable( array( '\CBM_Settings', 'backoffice_url' ) ) ) {
			$url = (string) \CBM_Settings::backoffice_url();
			if ( '' !== $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}
}
