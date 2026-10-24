<?php
/**
 * Staff: which WordPress users work with Booking Manager, with which role and in which
 * properties.
 *
 * Only Booking Manager roles can be given from here (never editor or administrator), and
 * an operator can only give what they have themselves (RoleManager anti-escalation).
 * Nobody changes their own role, so an administrator cannot lock themselves out.
 *
 * Property scope: an operator who works only in some properties sees only the staff sharing
 * at least one of them, and edits or removes only staff whose properties are all among
 * their own (never someone who also works elsewhere, nor an all-properties user).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Staff;

use BookingManager\Access\Capabilities;
use BookingManager\Access\RoleManager;
use BookingManager\Access\PropertyScope;
use BookingManager\Audit\AuditLogger;
use BookingManager\Core\Validation;

defined( 'ABSPATH' ) || exit;

class StaffService {

	public function __construct(
		private RoleManager $roles,
		private PropertyScope $scope,
		private AuditLogger $audit
	) {}

	/** @return array<int, array<string, mixed>> Plugin roles with their labels. */
	public function roles(): array {
		$labels = self::staff_roles();
		$out    = array();
		foreach ( $labels as $slug => $label ) {
			if ( ! get_role( $slug ) ) {
				continue;
			}
			$out[] = array(
				'slug'  => $slug,
				'label' => $label,
				'caps'  => array_values( array_filter( array_keys( array_filter( get_role( $slug )->capabilities ) ), array( Capabilities::class, 'exists' ) ) ),
			);
		}
		return $out;
	}

	/** @return array<int, array<string, mixed>> */
	public function list( \WP_User $actor ): array {
		$users = get_users(
			array(
				'role__in' => array_merge( array_keys( self::staff_roles() ), array( 'administrator' ) ),
				'orderby'  => 'display_name',
				'number'   => 500,
			)
		);
		$mine  = $this->scope->allowed_ids( $actor->ID );
		if ( null !== $mine ) {
			$users = array_filter( $users, fn( \WP_User $u ) => $u->ID === $actor->ID || self::shares_property( $this->properties_of( $u ), $mine ) );
		}
		return array_values( array_map( fn( \WP_User $u ) => $this->format( $u, $actor ), $users ) );
	}

	/**
	 * WordPress users who are not staff yet, to add one.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function candidates( string $search ): array {
		$search = trim( $search );
		if ( mb_strlen( $search ) < 2 ) {
			return array();
		}
		$users = get_users(
			array(
				'search'         => '*' . $search . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'role__not_in'   => array_merge( array_keys( Capabilities::role_names() ), array( 'administrator' ) ),
				'number'         => 10,
			)
		);
		// Users with full rights through another role are administrators, not candidates.
		$users = array_filter( $users, fn( \WP_User $u ) => ! RoleManager::is_full_admin( $u ) );
		return array_values(
			array_map(
				fn( \WP_User $u ) => array(
					'id'           => $u->ID,
					'display_name' => $u->display_name,
					'email'        => $u->user_email,
				),
				$users
			)
		);
	}

	/**
	 * Adds a staff member: an existing user (user_id) or a new one (email + name), who
	 * receives the standard WordPress email to set a password.
	 *
	 * @param array<string, mixed> $input {user_id?|email, first_name?, last_name?, role, properties[]}
	 * @return array<string, mixed>|\WP_Error
	 */
	public function add( \WP_User $actor, array $input ): array|\WP_Error {
		$role = (string) ( $input['role'] ?? '' );
		if ( ! array_key_exists( $role, self::staff_roles() ) ) {
			return Validation::error( array( 'role' => __( 'Scegli un ruolo.', 'booking-manager' ) ) );
		}
		$ok = $this->roles->can_assign_role( $actor, $role );
		if ( true !== $ok ) {
			return $ok;
		}
		$properties = $this->property_ids( $actor, $input['properties'] ?? array() );
		if ( $properties instanceof \WP_Error ) {
			return $properties;
		}

		if ( ! empty( $input['user_id'] ) ) {
			$user = get_userdata( (int) $input['user_id'] );
			if ( ! $user ) {
				return Validation::error( array( 'user_id' => __( 'Utente non trovato.', 'booking-manager' ) ) );
			}
			if ( $this->is_staff( $user ) ) {
				return Validation::error( array( 'user_id' => __( 'Questo utente fa già parte dello staff.', 'booking-manager' ) ) );
			}
		} else {
			$email = strtolower( trim( (string) ( $input['email'] ?? '' ) ) );
			$first = trim( sanitize_text_field( (string) ( $input['first_name'] ?? '' ) ) );
			$last  = trim( sanitize_text_field( (string) ( $input['last_name'] ?? '' ) ) );
			if ( ! is_email( $email ) ) {
				return Validation::error( array( 'email' => __( 'Indirizzo email non valido.', 'booking-manager' ) ) );
			}
			if ( email_exists( $email ) ) {
				return Validation::error( array( 'email' => __( 'Esiste già un utente con questa email: cercalo per nome o email.', 'booking-manager' ) ) );
			}
			if ( '' === $first ) {
				return Validation::error( array( 'first_name' => __( 'Nome obbligatorio.', 'booking-manager' ) ) );
			}
			$user_id = wp_insert_user(
				array(
					'user_login'   => $this->login_for( $email ),
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24 ),
					'first_name'   => $first,
					'last_name'    => $last,
					'display_name' => trim( $first . ' ' . $last ),
					'role'         => $role,
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return new \WP_Error( 'bkng_user_create_failed', $user_id->get_error_message(), array( 'status' => 400 ) );
			}
			wp_new_user_notification( $user_id, null, 'user' );
			$user = get_userdata( $user_id );
		}

		$this->set_role( $user, $role );
		foreach ( $properties as $property_id ) {
			$this->scope->assign( $user->ID, $property_id, $actor->ID );
		}
		$this->audit->log(
			array(
				'action'      => 'staff.added',
				'entity_type' => 'user',
				'entity_id'   => $user->ID,
				'new'         => array(
					'role'   => $role,
					'properties' => $properties,
				),
			)
		);
		return $this->format( get_userdata( $user->ID ), $actor );
	}

	/**
	 * @param array<string, mixed> $input {role?, properties?}
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( \WP_User $actor, int $user_id, array $input ): array|\WP_Error {
		$user = $this->editable( $actor, $user_id );
		if ( $user instanceof \WP_Error ) {
			return $user;
		}
		$old = array(
			'role'   => $this->plugin_role( $user ),
			'properties' => $this->scope->allowed_ids( $user->ID ),
		);
		if ( array_key_exists( 'role', $input ) && $input['role'] !== $old['role'] ) {
			if ( ! array_key_exists( (string) $input['role'], self::staff_roles() ) ) {
				return Validation::error( array( 'role' => __( 'Scegli un ruolo.', 'booking-manager' ) ) );
			}
			$ok = $this->roles->can_assign_role( $actor, (string) $input['role'] );
			if ( true !== $ok ) {
				return $ok;
			}
			$this->set_role( $user, (string) $input['role'] );
		}
		if ( array_key_exists( 'properties', $input ) ) {
			$properties = $this->property_ids( $actor, $input['properties'] );
			if ( $properties instanceof \WP_Error ) {
				return $properties;
			}
			// Only the properties the actor manages change; others stay as they are.
			$managed = $this->scope->allowed_ids( $actor->ID );
			$current = (array) $this->current_assignments( $user->ID );
			foreach ( $current as $property_id ) {
				if ( ( null === $managed || in_array( $property_id, $managed, true ) ) && ! in_array( $property_id, $properties, true ) ) {
					$this->scope->unassign( $user->ID, $property_id );
				}
			}
			foreach ( $properties as $property_id ) {
				$this->scope->assign( $user->ID, $property_id, $actor->ID );
			}
		}
		$this->scope->flush();
		$user = get_userdata( $user_id );
		$this->audit->log(
			array(
				'action'      => 'staff.updated',
				'entity_type' => 'user',
				'entity_id'   => $user_id,
				'old'         => $old,
				'new'         => array(
					'role'   => $this->plugin_role( $user ),
					'properties' => $this->current_assignments( $user_id ),
				),
			)
		);
		return $this->format( $user, $actor );
	}

	/** Removes Booking Manager roles and properties; the WordPress account stays. */
	public function remove( \WP_User $actor, int $user_id ): bool|\WP_Error {
		$user = $this->editable( $actor, $user_id );
		if ( $user instanceof \WP_Error ) {
			return $user;
		}
		$old = $this->plugin_role( $user );
		foreach ( array_keys( Capabilities::role_names() ) as $slug ) {
			$user->remove_role( $slug );
		}
		if ( ! $user->roles ) {
			$user->add_role( 'subscriber' );
		}
		// Only the actor's properties are unassigned (editable() already made sure there are
		// no others, this keeps it true even if that rule changes).
		$managed = $this->scope->allowed_ids( $actor->ID );
		foreach ( $this->current_assignments( $user_id ) as $property_id ) {
			if ( null === $managed || in_array( $property_id, $managed, true ) ) {
				$this->scope->unassign( $user_id, $property_id );
			}
		}
		$this->audit->log(
			array(
				'action'      => 'staff.removed',
				'entity_type' => 'user',
				'entity_id'   => $user_id,
				'old'         => array( 'role' => $old ),
			)
		);
		return true;
	}

	/**
	 * Changes the permissions of a Booking Manager role (Staff e permessi). The role
	 * keeps bkng_access; nobody can give a permission they do not have, nor edit their own
	 * role or the protected ones (RoleManager).
	 *
	 * @param string[] $caps
	 * @return array<string, mixed>|\WP_Error the role as roles() lists it
	 */
	public function update_role( \WP_User $actor, string $slug, array $caps ): array|\WP_Error {
		if ( ! array_key_exists( $slug, self::staff_roles() ) ) {
			return new \WP_Error( 'bkng_role_not_found', __( 'Ruolo inesistente.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$ok = $this->roles->can_edit_role( $actor, $slug );
		if ( true !== $ok ) {
			return $ok;
		}
		$caps = array_values( array_unique( array_merge( array( 'bkng_access' ), array_map( 'strval', $caps ) ) ) );
		$role = get_role( $slug );
		$old  = array_values( array_filter( array_keys( array_filter( $role->capabilities ) ), array( Capabilities::class, 'exists' ) ) );
		// Only the permissions that change must be grantable by the actor.
		$changed = array_values( array_merge( array_diff( $caps, $old ), array_diff( $old, $caps ) ) );
		$ok      = $this->roles->can_grant_caps( $actor, $changed );
		if ( true !== $ok ) {
			return $ok;
		}
		foreach ( Capabilities::all() as $cap ) {
			if ( in_array( $cap, $caps, true ) ) {
				$role->add_cap( $cap );
			} elseif ( $role->has_cap( $cap ) ) {
				$role->remove_cap( $cap );
			}
		}
		$this->audit->log(
			array(
				'action'      => 'role.updated',
				'entity_type' => 'role',
				'old'         => array(
					'role' => $slug,
					'caps' => $old,
				),
				'new'         => array(
					'role'    => $slug,
					'added'   => array_values( array_diff( $caps, $old ) ),
					'removed' => array_values( array_diff( $old, $caps ) ),
				),
			)
		);
		foreach ( $this->roles() as $r ) {
			if ( $r['slug'] === $slug ) {
				return $r;
			}
		}
		return array();
	}

	/** Puts the default permissions back on a role. */
	public function reset_role( \WP_User $actor, string $slug ): array|\WP_Error {
		return $this->update_role( $actor, $slug, Capabilities::defaults_for( $slug ) );
	}

	/* -------------------------------------------------------------------- Internals */

	private function editable( \WP_User $actor, int $user_id ): \WP_User|\WP_Error {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $this->is_staff( $user ) ) {
			return new \WP_Error( 'bkng_not_found', __( 'Operatore non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		if ( $user->ID === $actor->ID ) {
			return new \WP_Error( 'bkng_staff_self', __( 'Non puoi modificare il tuo ruolo: chiedilo a un altro amministratore.', 'booking-manager' ), array( 'status' => 403 ) );
		}
		if ( RoleManager::is_full_admin( $user ) ) {
			return new \WP_Error( 'bkng_role_protected', __( 'Gli amministratori di WordPress hanno sempre accesso completo e non si modificano da qui.', 'booking-manager' ), array( 'status' => 403 ) );
		}
		$mine = $this->scope->allowed_ids( $actor->ID );
		if ( null !== $mine && ! self::properties_within( $this->properties_of( $user ), $mine ) ) {
			$visible = self::shares_property( $this->properties_of( $user ), $mine );
			return $visible
				? new \WP_Error( 'bkng_staff_other_properties', __( 'Questo operatore lavora anche in strutture che non gestisci: può modificarlo solo chi gestisce tutte le sue strutture.', 'booking-manager' ), array( 'status' => 403 ) )
				: new \WP_Error( 'bkng_not_found', __( 'Operatore non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$role = $this->plugin_role( $user );
		if ( $role ) {
			$ok = $this->roles->can_assign_role( $actor, $role );
			if ( true !== $ok ) {
				return $ok;
			}
		}
		return $user;
	}

	/**
	 * @param mixed $raw
	 * @return int[]|\WP_Error
	 */
	private function property_ids( \WP_User $actor, $raw ): array|\WP_Error {
		$ids     = array_values( array_unique( array_filter( array_map( 'intval', (array) $raw ) ) ) );
		$allowed = $this->scope->allowed_ids( $actor->ID );
		foreach ( $ids as $id ) {
			if ( null !== $allowed && ! in_array( $id, $allowed, true ) ) {
				return Validation::error( array( 'properties' => __( 'Puoi assegnare solo le strutture in cui lavori anche tu.', 'booking-manager' ) ) );
			}
		}
		return $ids;
	}

	/**
	 * Properties of a staff member: null when they reach every property.
	 *
	 * @return int[]|null
	 */
	private function properties_of( \WP_User $user ): ?array {
		return $this->scope->has_all( $user->ID ) || RoleManager::is_full_admin( $user ) ? null : $this->current_assignments( $user->ID );
	}

	/**
	 * Whether every property of a staff member is among the actor's (unit-tested).
	 * null = every property.
	 *
	 * @param int[]|null $target
	 * @param int[]|null $actor
	 */
	public static function properties_within( ?array $target, ?array $actor ): bool {
		if ( null === $actor ) {
			return true;
		}
		if ( null === $target ) {
			return false;
		}
		return ! array_diff( array_map( 'intval', $target ), array_map( 'intval', $actor ) );
	}

	/**
	 * Whether a staff member works in at least one of the actor's properties (unit-tested).
	 * null = every property.
	 *
	 * @param int[]|null $target
	 * @param int[]|null $actor
	 */
	public static function shares_property( ?array $target, ?array $actor ): bool {
		if ( null === $actor ) {
			return true;
		}
		if ( null === $target ) {
			return (bool) $actor;
		}
		return (bool) array_intersect( array_map( 'intval', $target ), array_map( 'intval', $actor ) );
	}

	private function set_role( \WP_User $user, string $role ): void {
		foreach ( array_keys( Capabilities::role_names() ) as $slug ) {
			if ( $slug !== $role ) {
				$user->remove_role( $slug );
			}
		}
		$user->add_role( $role );
		// A customer account promoted to staff keeps no "subscriber" leftovers.
		$user->remove_role( 'subscriber' );
	}

	/** @return int[] */
	private function current_assignments( int $user_id ): array {
		global $wpdb;
		$table = \BookingManager\Database\Schema::table( 'user_properties' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT property_id FROM {$table} WHERE user_id = %d", $user_id ) ) );
	}

	private function is_staff( \WP_User $user ): bool {
		return null !== $this->plugin_role( $user ) || RoleManager::is_full_admin( $user );
	}

	/**
	 * Roles of people that can be given from the Staff screen.
	 *
	 * @return array<string, string>
	 */
	private static function staff_roles(): array {
		return Capabilities::role_names();
	}

	private function plugin_role( \WP_User $user ): ?string {
		foreach ( $user->roles as $slug ) {
			if ( array_key_exists( $slug, self::staff_roles() ) ) {
				return $slug;
			}
		}
		return null;
	}

	private function login_for( string $email ): string {
		$base  = sanitize_user( strstr( $email, '@', true ) ?: 'staff', true );
		$base  = '' !== $base ? $base : 'staff';
		$login = $base;
		for ( $i = 2; username_exists( $login ); $i++ ) {
			$login = $base . $i;
		}
		return $login;
	}

	/** @return array<string, mixed> */
	private function format( \WP_User $user, \WP_User $actor ): array {
		$admin = RoleManager::is_full_admin( $user );
		$role  = $this->plugin_role( $user );
		$all   = $this->scope->has_all( $user->ID );
		return array(
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'role'         => $admin ? 'administrator' : $role,
			'role_label'   => $admin ? __( 'Amministratore WordPress', 'booking-manager' ) : ( Capabilities::role_names()[ $role ] ?? '' ),
			'all_properties'   => $all,
			'properties'       => $all ? array() : $this->current_assignments( $user->ID ),
			'editable'     => ! $admin && $user->ID !== $actor->ID && ( ! $role || true === $this->roles->can_assign_role( $actor, $role ) ) && self::properties_within( $all ? null : $this->current_assignments( $user->ID ), $this->scope->allowed_ids( $actor->ID ) ),
			'is_me'        => $user->ID === $actor->ID,
		);
	}
}
