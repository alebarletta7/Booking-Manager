<?php
/**
 * SaaS platform, step 1 (0.2.0): customers (tenants) above the properties.
 *
 * Additive only: three new tables and nullable tenant_id columns with their indexes.
 * No row is changed here; existing data is linked to the first tenant only by the
 * explicit command `wp booking-manager tenant migrate` (see src/Tenants/TenantMigration.php).
 * Until then tenant_id NULL means "the default tenant" and the plugin works as before.
 *
 * Only the tables that hold data not tied to a property get the column: everything
 * else already has property_id and inherits the tenant from the property.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database\Migrations;

use BookingManager\Database\Migration;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Migration_20261106_000001_Tenants extends Migration {

	/**
	 * Columns added to existing tables: table => [ [column, definition, [index name => columns]] ].
	 */
	public const COLUMNS = array(
		'properties'      => array( 'tenant_id', 'bigint(20) unsigned NULL AFTER uuid', array( 'tenant_status' => 'tenant_id,status' ) ),
		'guests'          => array(
			'tenant_id',
			'bigint(20) unsigned NULL AFTER uuid',
			array(
				'tenant_email' => 'tenant_id,email_hash',
				'tenant_phone' => 'tenant_id,phone_hash',
				'tenant_name'  => 'tenant_id,last_name',
			),
		),
		'guest_consents'  => array( 'tenant_id', 'bigint(20) unsigned NULL AFTER guest_id', array( 'tenant_id' => 'tenant_id' ) ),
		'audit_log'       => array( 'tenant_id', 'bigint(20) unsigned NULL AFTER id', array( 'tenant_date' => 'tenant_id,created_at' ) ),
		'notifications'   => array( 'tenant_id', 'bigint(20) unsigned NULL AFTER id', array( 'tenant_date' => 'tenant_id,created_at' ) ),
	);

	public function up( \wpdb $wpdb ): void {
		$p = $wpdb->prefix . Schema::PREFIX;
		$c = $wpdb->get_charset_collate();

		$this->delta(
			array(
				"CREATE TABLE {$p}tenants (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  name varchar(190) NOT NULL,
  business_type varchar(40) NOT NULL DEFAULT 'struttura_ricettiva',
  status varchar(20) NOT NULL DEFAULT 'active',
  plan varchar(40) NOT NULL DEFAULT 'unlimited',
  limits longtext NULL,
  features longtext NULL,
  settings longtext NULL,
  activated_at datetime NULL,
  expires_at datetime NULL,
  suspended_at datetime NULL,
  suspend_reason varchar(255) NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY status (status)
) {$c};",
				"CREATE TABLE {$p}tenant_users (
  user_id bigint(20) unsigned NOT NULL,
  tenant_id bigint(20) unsigned NOT NULL,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (user_id),
  KEY tenant_id (tenant_id)
) {$c};",
				"CREATE TABLE {$p}tenant_domains (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id bigint(20) unsigned NOT NULL,
  host varchar(253) NOT NULL,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY host (host),
  KEY tenant_id (tenant_id)
) {$c};",
			)
		);

		// Idempotent: a column or index may exist after a crash between ALTER and the record.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- internal table names.
		foreach ( self::COLUMNS as $short => [ $column, $definition, $indexes ] ) {
			$table = Schema::table( $short );
			if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
			}
			foreach ( $indexes as $name => $columns ) {
				if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $name ) ) ) {
					$wpdb->query( "ALTER TABLE {$table} ADD KEY {$name} ({$columns})" );
				}
			}
		}
		$notifications = Schema::table( 'notifications' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$notifications} LIKE %s", 'subject' ) ) ) {
			$wpdb->query( "ALTER TABLE {$notifications} ADD COLUMN subject varchar(255) NULL AFTER recipient" );
		}
		// phpcs:enable
	}
}
