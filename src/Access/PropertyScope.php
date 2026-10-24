<?php
/**
 * Which properties (strutture) a user may work on.
 *
 * - users with bkng_access_all_properties (and WordPress administrators) see every property;
 * - everybody else sees only the properties assigned in bkng_user_properties.
 *
 * Repositories use allowed_ids() to filter queries, so an operator of property A can
 * never read data of property B even by calling the REST API directly (IDOR protection).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Access;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class PropertyScope {

	/** @var array<int, int[]> */
	private array $cache = array();

	public function has_all( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'bkng_access_all_properties' );
	}

	/**
	 * @return int[]|null null = unrestricted (all properties), [] = none.
	 */
	public function allowed_ids( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( $this->has_all( $user_id ) ) {
			return null;
		}
		if ( ! isset( $this->cache[ $user_id ] ) ) {
			global $wpdb;
			$table = Schema::table( 'user_properties' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$ids                     = $wpdb->get_col( $wpdb->prepare( "SELECT property_id FROM {$table} WHERE user_id = %d", $user_id ) );
			$this->cache[ $user_id ] = array_map( 'intval', (array) $ids );
		}
		return $this->cache[ $user_id ];
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
		$this->cache = array();
	}
}
