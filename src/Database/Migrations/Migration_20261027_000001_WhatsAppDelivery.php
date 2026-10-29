<?php
/**
 * WhatsApp like Night Manager: what Meta says happened after a message was accepted.
 *
 * - notifications.delivery_status / delivery_at: «consegnato», «letto» or «non
 *   consegnato», as Meta reports them through the webhook (only moving forward).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database\Migrations;

use BookingManager\Database\Migration;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Migration_20261027_000001_WhatsAppDelivery extends Migration {

	public function up( \wpdb $wpdb ): void {
		$table   = Schema::table( 'notifications' );
		$columns = array(
			array( 'delivery_status', 'varchar(20) NULL AFTER provider_message_id' ),
			array( 'delivery_at', 'datetime NULL AFTER delivery_status' ),
		);
		// Idempotent: a column may exist after a crash between ALTER and the record.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name is internal.
		foreach ( $columns as [ $name, $definition ] ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $name ) ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$definition}" );
			}
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'provider_message_id' ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY provider_message_id (provider_message_id)" );
		}
		// phpcs:enable
	}
}
