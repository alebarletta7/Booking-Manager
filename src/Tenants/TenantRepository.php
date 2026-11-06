<?php
/**
 * Tenants (customers of the platform), their users and their authorised domains.
 * SQL only: rules live in TenantService.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class TenantRepository {

	public const FIELDS = array( 'name', 'business_type', 'status', 'plan', 'activated_at', 'expires_at', 'suspended_at', 'suspend_reason', 'is_default', 'created_by' );

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'tenants' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array<string, mixed>|null the tenant marked as default (the first one). */
	public function find_default(): ?array {
		global $wpdb;
		$table = Schema::table( 'tenants' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE is_default = 1 ORDER BY id LIMIT 1", ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function all(): array {
		global $wpdb;
		$table = Schema::table( 'tenants' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name, id", ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** @param array<string, mixed> $data */
	public function create( array $data ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array_intersect_key( $data, array_flip( self::FIELDS ) );
		foreach ( array( 'limits', 'features', 'settings' ) as $json ) {
			$row[ $json ] = wp_json_encode( is_array( $data[ $json ] ?? null ) ? $data[ $json ] : array() );
		}
		$row['uuid']       = wp_generate_uuid4();
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$wpdb->insert( Schema::table( 'tenants' ), $row );
		return (int) $wpdb->insert_id;
	}

	/* ------------------------------------------------------------------ users */

	public function tenant_of_user( int $user_id ): int {
		global $wpdb;
		$table = Schema::table( 'tenant_users' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT tenant_id FROM {$table} WHERE user_id = %d", $user_id ) );
	}

	/** Links a user to a tenant; a user already in a tenant keeps it (one tenant per user). */
	public function add_user( int $tenant_id, int $user_id, ?int $by = null ): bool {
		global $wpdb;
		$table = Schema::table( 'tenant_users' );
		Tenancy::flush();
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				"INSERT IGNORE INTO {$table} (user_id, tenant_id, created_by, created_at) VALUES (%d, %d, %d, %s)",
				$user_id,
				$tenant_id,
				(int) $by,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/** @return int[] */
	public function user_ids( int $tenant_id ): array {
		global $wpdb;
		$table = Schema::table( 'tenant_users' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE tenant_id = %d ORDER BY user_id", $tenant_id ) ) );
	}

	/* ------------------------------------------------------------------ domains */

	/** @return string[] */
	public function domains( int $tenant_id ): array {
		global $wpdb;
		$table = Schema::table( 'tenant_domains' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT host FROM {$table} WHERE tenant_id = %d ORDER BY host", $tenant_id ) ) );
	}

	/** Tenant that owns this host, or 0. */
	public function owner_of_domain( string $host ): int {
		global $wpdb;
		$table = Schema::table( 'tenant_domains' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT tenant_id FROM {$table} WHERE host = %s", $host ) );
	}

	/** Adds an already normalised host; false if another tenant has it. */
	public function add_domain( int $tenant_id, string $host, ?int $by = null ): bool {
		$owner = $this->owner_of_domain( $host );
		if ( $owner ) {
			return $owner === $tenant_id;
		}
		global $wpdb;
		return false !== $wpdb->insert(
			Schema::table( 'tenant_domains' ),
			array(
				'tenant_id'  => $tenant_id,
				'host'       => $host,
				'created_by' => $by,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		foreach ( array( 'id', 'created_by', 'version' ) as $int ) {
			$row[ $int ] = isset( $row[ $int ] ) ? (int) $row[ $int ] : null;
		}
		$row['is_default'] = ! empty( $row['is_default'] );
		foreach ( array( 'limits', 'features', 'settings' ) as $json ) {
			$decoded      = json_decode( (string) ( $row[ $json ] ?? '' ), true );
			$row[ $json ] = is_array( $decoded ) ? $decoded : array();
		}
		return $row;
	}
}
