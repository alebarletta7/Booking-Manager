<?php
/**
 * Creates default roles, keeps capabilities in sync across versions and enforces
 * anti-escalation rules for anyone delegated to manage roles/staff.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Access;

defined( 'ABSPATH' ) || exit;

class RoleManager {

	public const KNOWN_CAPS_OPTION = 'bkng_known_caps';
	public const NEW_CAPS_OPTION   = 'bkng_new_caps_pending';

	/** Non-plugin capabilities a plugin-only role may contain. */
	private const ALLOWED_CORE_CAPS = array( 'read', 'level_0' );

	/** Roles that can never be edited through delegated role management. */
	private const PROTECTED_ROLES = array( 'administrator', Capabilities::ROLE_ADMIN );

	/**
	 * New capability → the one it was split from, so nobody loses what they could do.
	 * Add an entry when a later release splits a permission.
	 *
	 * @var array<string, string>
	 */
	private const INHERITS = array();

	public function hooks(): void {
		// A WordPress administrator always has every plugin capability: never locked out.
		add_filter( 'user_has_cap', array( $this, 'grant_admins' ), 10, 4 );
	}

	/**
	 * Creates our preset roles (only if missing: customers' edits are never overwritten)
	 * and grants every plugin capability to the administrator role.
	 */
	public function install(): void {
		foreach ( Capabilities::role_names() as $slug => $name ) {
			if ( null !== get_role( $slug ) ) {
				continue;
			}
			$caps = array( 'read' => true );
			foreach ( Capabilities::defaults_for( $slug ) as $cap ) {
				$caps[ $cap ] = true;
			}
			add_role( $slug, $name, $caps );
		}
		$this->sync();
	}

	/**
	 * Handles capabilities introduced by plugin updates:
	 * - administrator and bkng_admin receive them automatically;
	 * - other (possibly customized) roles do NOT, an admin notice lists them instead.
	 */
	public function sync(): void {
		$all   = Capabilities::all();
		$known = get_option( self::KNOWN_CAPS_OPTION, null );
		$new   = is_array( $known ) ? array_values( array_diff( $all, $known ) ) : array();

		foreach ( array( 'administrator', Capabilities::ROLE_ADMIN ) as $slug ) {
			$role = get_role( $slug );
			if ( null === $role ) {
				continue;
			}
			foreach ( $all as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}

		// A permission split out of an existing one goes to every role that had that one,
		// so nobody loses what they could already do (also when the list of known
		// permissions is missing: harmless on a fresh install, the defaults match).
		$split = is_array( $known ) ? array_intersect_key( self::INHERITS, array_flip( $new ) ) : self::INHERITS;
		foreach ( $split as $cap => $from ) {
			foreach ( wp_roles()->role_objects as $role ) {
				if ( $role->has_cap( $from ) && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
		$new = array_values( array_diff( $new, array_keys( self::INHERITS ) ) );

		if ( $new ) {
			$pending = (array) get_option( self::NEW_CAPS_OPTION, array() );
			update_option( self::NEW_CAPS_OPTION, array_values( array_unique( array_merge( $pending, $new ) ) ), false );
		}
		update_option( self::KNOWN_CAPS_OPTION, $all, false );
	}

	/** Removes plugin roles and capabilities (used only by a data-deleting uninstall). */
	public function remove_all(): void {
		foreach ( array_keys( Capabilities::role_names() ) as $slug ) {
			remove_role( $slug );
		}
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( Capabilities::all() as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
		delete_option( self::KNOWN_CAPS_OPTION );
		delete_option( self::NEW_CAPS_OPTION );
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @param string[]            $caps
	 * @param array<int, mixed>   $args
	 * @return array<string, bool>
	 */
	public function grant_admins( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( $caps as $cap ) {
				if ( is_string( $cap ) && Capabilities::exists( $cap ) ) {
					$allcaps[ $cap ] = true;
				}
			}
		}
		return $allcaps;
	}

	/* --------------------------------------------------------------------------------
	 * Anti-escalation rules (used by staff / role management endpoints).
	 * ------------------------------------------------------------------------------ */

	public static function is_full_admin( \WP_User $user ): bool {
		return user_can( $user, 'manage_options' );
	}

	/**
	 * May $actor grant these capabilities to a role?
	 *
	 * @param string[] $caps
	 */
	public function can_grant_caps( \WP_User $actor, array $caps ): bool|\WP_Error {
		if ( ! user_can( $actor, 'bkng_manage_roles' ) ) {
			return new \WP_Error( 'bkng_forbidden', __( 'Non hai il permesso di gestire i ruoli.', 'booking-manager' ), array( 'status' => 403 ) );
		}
		foreach ( $caps as $cap ) {
			if ( ! is_string( $cap ) || ! Capabilities::exists( $cap ) ) {
				return new \WP_Error(
					'bkng_cap_not_allowed',
					/* translators: %s: capability name. */
					sprintf( __( 'Il permesso "%s" non appartiene a Booking Manager e non può essere assegnato da qui.', 'booking-manager' ), (string) $cap ),
					array( 'status' => 403 )
				);
			}
			if ( ! self::is_full_admin( $actor ) && ! user_can( $actor, $cap ) ) {
				return new \WP_Error(
					'bkng_cap_escalation',
					/* translators: %s: capability name. */
					sprintf( __( 'Non puoi assegnare il permesso "%s" perché non lo possiedi.', 'booking-manager' ), $cap ),
					array( 'status' => 403 )
				);
			}
		}
		return true;
	}

	/** May $actor change the capabilities of $role_slug? */
	public function can_edit_role( \WP_User $actor, string $role_slug ): bool|\WP_Error {
		if ( ! user_can( $actor, 'bkng_manage_roles' ) ) {
			return new \WP_Error( 'bkng_forbidden', __( 'Non hai il permesso di gestire i ruoli.', 'booking-manager' ), array( 'status' => 403 ) );
		}
		$role = get_role( $role_slug );
		if ( null === $role ) {
			return new \WP_Error( 'bkng_role_not_found', __( 'Ruolo inesistente.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		if ( self::is_full_admin( $actor ) ) {
			return 'administrator' === $role_slug
				? new \WP_Error( 'bkng_role_protected', __( 'Il ruolo Amministratore di WordPress non si modifica da qui.', 'booking-manager' ), array( 'status' => 403 ) )
				: true;
		}
		if ( in_array( $role_slug, self::PROTECTED_ROLES, true ) || in_array( $role_slug, $actor->roles, true ) ) {
			return new \WP_Error( 'bkng_role_protected', __( 'Non puoi modificare questo ruolo.', 'booking-manager' ), array( 'status' => 403 ) );
		}
		if ( ! $this->is_plugin_only_role( $role_slug ) ) {
			return new \WP_Error( 'bkng_role_not_plugin', __( 'Puoi modificare solo ruoli dedicati a Booking Manager.', 'booking-manager' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** May $actor assign $role_slug to a staff member? */
	public function can_assign_role( \WP_User $actor, string $role_slug ): bool|\WP_Error {
		if ( ! user_can( $actor, 'bkng_manage_staff' ) ) {
			return new \WP_Error( 'bkng_forbidden', __( 'Non hai il permesso di gestire gli operatori.', 'booking-manager' ), array( 'status' => 403 ) );
		}
		$role = get_role( $role_slug );
		if ( null === $role ) {
			return new \WP_Error( 'bkng_role_not_found', __( 'Ruolo inesistente.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		// Through this plugin only plugin roles can ever be assigned (never editor, administrator…).
		if ( ! $this->is_plugin_only_role( $role_slug ) ) {
			return new \WP_Error( 'bkng_role_not_plugin', __( 'Puoi assegnare solo ruoli dedicati a Booking Manager.', 'booking-manager' ), array( 'status' => 403 ) );
		}
		if ( ! self::is_full_admin( $actor ) ) {
			if ( Capabilities::ROLE_ADMIN === $role_slug ) {
				return new \WP_Error( 'bkng_role_protected', __( 'Non puoi assegnare questo ruolo.', 'booking-manager' ), array( 'status' => 403 ) );
			}
			foreach ( array_keys( array_filter( $role->capabilities ) ) as $cap ) {
				if ( Capabilities::exists( $cap ) && ! user_can( $actor, $cap ) ) {
					return new \WP_Error( 'bkng_cap_escalation', __( 'Questo ruolo ha permessi che tu non possiedi.', 'booking-manager' ), array( 'status' => 403 ) );
				}
			}
		}
		return true;
	}

	/** A role whose granted capabilities are only bkng_* (plus "read"). */
	public function is_plugin_only_role( string $role_slug ): bool {
		$role = get_role( $role_slug );
		if ( null === $role ) {
			return false;
		}
		foreach ( array_keys( array_filter( $role->capabilities ) ) as $cap ) {
			if ( ! Capabilities::exists( (string) $cap ) && ! in_array( $cap, self::ALLOWED_CORE_CAPS, true ) ) {
				return false;
			}
		}
		return true;
	}
}
