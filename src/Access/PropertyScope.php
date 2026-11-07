<?php
/**
 * Which properties (strutture) a user may work on.
 *
 * - platform administrators (WordPress administrators, bkng_platform_manage) see every
 *   property of every customer;
 * - everybody else works inside their customer (tenant, bkng_tenant_users): with
 *   bkng_access_all_properties every property of that customer, otherwise only the
 *   properties assigned in bkng_user_properties that belong to it;
 * - a user with a Booking Manager role but no customer sees nothing (after the data
 *   migration); before it (0.1.x data, tenant_id NULL) the rules are the old ones.
 *
 * Repositories use allowed_ids() to filter queries, so an operator of property A can
 * never read data of property B even by calling the REST API directly (IDOR protection).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Access;

use BookingManager\Database\Schema;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantRepository;

defined( 'ABSPATH' ) || exit;

class PropertyScope {

	private array $cache = array();

	/** @var array<int, int> user id => tenant id */
	private array $tenant_cache = array();

	private TenantRepository $tenants;

	public function __construct( ?TenantRepository $tenants = null ) {
		$this->tenants = $tenants ?? new TenantRepository();
	}

	/** Platform administrator: sees every customer. */
	public function is_platform( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'bkng_platform_manage' );
	}

	/** Every property of the user's scope (customer or whole platform). */
	public function has_all( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'bkng_access_all_properties' );
	}

	/** Customer of a user (0 = none: platform administrator or not linked). */
	public function tenant_of( int $user_id ): int {
		if ( $user_id <= 0 || ! Tenancy::ready() ) {
			return 0;
		}
		if ( ! isset( $this->tenant_cache[ $user_id ] ) ) {
			$this->tenant_cache[ $user_id ] = $this->tenants->tenant_of_user( $user_id );
		}
		return $this->tenant_cache[ $user_id ];
	}

	/**
	 * @return int[]|null null = all properties of the platform
	 */
	public function allowed_ids( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( $this->is_platform( $user_id ) ) {
			return null;
		}
		if ( ! isset( $this->cache[ $user_id ] ) ) {
			$this->cache[ $user_id ] = $this->compute( $user_id );
		}
		return $this->cache[ $user_id ];
	}

	/** @return int[]|null */
	private function compute( int $user_id ): ?array {
		if ( ! Tenancy::migrated() ) {
			// Data from before the customers (0.1.x): the old rules.
			return $this->has_all( $user_id ) ? null : $this->assigned_ids( $user_id );
		}
		$tenant = $this->tenant_of( $user_id );
		if ( $tenant <= 0 ) {
			return array();
		}
		$own = $this->tenant_property_ids( $tenant );
		if ( $this->has_all( $user_id ) ) {
			return $own;
		}
		return array_values( array_intersect( $this->assigned_ids( $user_id ), $own ) );
	}

	/** @return int[] properties of a customer, archived ones included */
	public function tenant_property_ids( int $tenant_id ): array {
		global $wpdb;
		$table = Schema::table( 'properties' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE tenant_id = %d ORDER BY id", $tenant_id ) ) );
	}

	public function can_access( int $user_id, int $property_id ): bool {
		if ( $property_id <= 0 ) {
			return false;
		}
		$allowed = $this->allowed_ids( $user_id );
		return null === $allowed || in_array( $property_id, $allowed, true );
	}

	/** @return int[] properties assigned explicitly (whatever bkng_access_all_properties says). */
	public function assigned_ids( int $user_id ): array {
		global $wpdb;
		$table = Schema::table( 'user_properties' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT property_id FROM {$table} WHERE user_id = %d ORDER BY property_id", $user_id ) ) );
	}

	public function assign( int $user_id, int $property_id, ?int $by = null ): void {
		global $wpdb;
		$table = Schema::table( 'user_properties' );
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				"INSERT IGNORE INTO {$table} (user_id, property_id, created_by, created_at) VALUES (%d, %d, %d, %s)",
				$user_id,
				$property_id,
				(int) $by,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		unset( $this->cache[ $user_id ] );
	}

	public function unassign( int $user_id, int $property_id ): void {
		global $wpdb;
		$wpdb->delete(
			Schema::table( 'user_properties' ),
			array(
				'user_id'     => $user_id,
				'property_id' => $property_id,
			),
			array( '%d', '%d' )
		);
		unset( $this->cache[ $user_id ] );
	}

	public function flush(): void {
		$this->cache        = array();
		$this->tenant_cache = array();
	}
}
