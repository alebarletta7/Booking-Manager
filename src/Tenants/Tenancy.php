<?php
/**
 * Which tenant a row belongs to, for the repositories that write tenant_id.
 *
 * - default_id(): the first tenant, created by `wp booking-manager tenant migrate`
 *   (0 before the migration: rows are then written with tenant_id NULL, which means
 *   "the default tenant", exactly as the rows written before 0.2.0).
 * - of_property(): the tenant of a property (cached per request).
 * - stamp(): the value to write for a row of a property, or of no property.
 *
 * It never reads anything sent by the browser.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Tenancy {

	/** Id of the default tenant (set by the data migration). */
	public const DEFAULT_OPTION = 'bkng_default_tenant';

	/** UTC time the data migration finished, or absent. */
	public const MIGRATED_OPTION = 'bkng_tenancy_migrated';

	/** First migration that has the tenant columns. */
	public const SCHEMA_MIGRATION = 'Migration_20261106_000001_Tenants';

	private static ?bool $ready = null;

	/** @var array<int, int> user id => tenant id */
	private static array $users = array();

	/** @var array<int, int> property id => tenant id (0 = none) */
	private static array $properties = array();

	public static function default_id(): int {
		return max( 0, (int) get_option( self::DEFAULT_OPTION, 0 ) );
	}

	public static function migrated(): bool {
		return (bool) get_option( self::MIGRATED_OPTION, false );
	}

	/** Tenant of a property; the default tenant for a property still without one. */
	public static function of_property( int $property_id ): int {
		if ( $property_id <= 0 ) {
			return 0;
		}
		if ( ! self::ready() ) {
			return 0;
		}
		if ( ! array_key_exists( $property_id, self::$properties ) ) {
			global $wpdb;
			$table = Schema::table( 'properties' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			self::$properties[ $property_id ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT tenant_id FROM {$table} WHERE id = %d", $property_id ) );
		}
		return self::$properties[ $property_id ] > 0 ? self::$properties[ $property_id ] : self::default_id();
	}

	/** Customer of a user (0 = platform administrator or not linked). */
	public static function of_user( int $user_id ): int {
		if ( $user_id <= 0 || ! self::ready() ) {
			return 0;
		}
		if ( ! array_key_exists( $user_id, self::$users ) ) {
			global $wpdb;
			$table                    = Schema::table( 'tenant_users' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			self::$users[ $user_id ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT tenant_id FROM {$table} WHERE user_id = %d", $user_id ) );
		}
		return self::$users[ $user_id ];
	}

	/** tenant_id to write: the property's tenant, else the default tenant, else NULL. */
	public static function stamp( ?int $property_id = null ): ?int {
		$id = null !== $property_id && $property_id > 0 ? self::of_property( $property_id ) : self::default_id();
		return $id > 0 ? $id : null;
	}

	/**
	 * True once the database has the tenant columns (Migration_20261106_000001_Tenants).
	 * Before that, during an update, rows are written exactly as before 0.2.0.
	 */
	public static function ready(): bool {
		if ( null === self::$ready ) {
			$last        = (string) get_option( \BookingManager\Database\Migrator::LAST_OPTION, '' );
			self::$ready = '' !== $last && strcmp( $last, self::SCHEMA_MIGRATION ) >= 0;
		}
		return self::$ready;
	}

	/**
	 * Adds tenant_id to a row about to be inserted: the value given (if a positive id),
	 * else the property's tenant, else the default tenant, else NULL. Unchanged if the
	 * database does not have the column yet.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function with_tenant( array $row, ?int $property_id = null, mixed $given = null ): array {
		if ( ! self::ready() ) {
			unset( $row['tenant_id'] );
			return $row;
		}
		$row['tenant_id'] = is_numeric( $given ) && (int) $given > 0 ? (int) $given : self::stamp( $property_id );
		return $row;
	}

	public static function flush(): void {
		self::$properties = array();
		self::$users      = array();
		self::$ready      = null;
	}
}
