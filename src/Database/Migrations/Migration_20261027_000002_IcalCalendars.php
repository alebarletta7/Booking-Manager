<?php
/**
 * Calendars of Booking.com, Airbnb and the other portals, synchronised with iCal links.
 *
 * - ical_feeds: the portal calendars imported for each room (name, address, last result);
 * - unit_blocks.feed_id / external_uid: the room closures written by a feed, one per
 *   event of the portal (kind "channel"), so the next synchronisation updates them.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database\Migrations;

use BookingManager\Database\Migration;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Migration_20261027_000002_IcalCalendars extends Migration {

	public function up( \wpdb $wpdb ): void {
		$p = $wpdb->prefix . Schema::PREFIX;
		$c = $wpdb->get_charset_collate();
		$this->delta(
			array(
				"CREATE TABLE {$p}ical_feeds (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  unit_id bigint(20) unsigned NOT NULL,
  channel varchar(40) NOT NULL,
  url text NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  last_sync_at datetime NULL,
  last_status varchar(20) NULL,
  last_error varchar(255) NULL,
  events int(10) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY unit (unit_id,status),
  KEY property (property_id,status)
) {$c};",
			)
		);
		$table = Schema::table( 'unit_blocks' );
		// Idempotent: a column may exist after a crash between ALTER and the record.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name is internal.
		foreach ( array( array( 'feed_id', 'bigint(20) unsigned NULL AFTER reason' ), array( 'external_uid', 'varchar(190) NULL AFTER feed_id' ) ) as [ $name, $definition ] ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $name ) ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$definition}" );
			}
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'feed' ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY feed (feed_id,status)" );
		}
		// phpcs:enable
	}
}
