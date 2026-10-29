<?php
/**
 * Portal calendars, more reliable (0.1.12):
 *
 * - ical_events: the events last read from each portal calendar, one row per event, so
 *   every room is rebuilt from all its sources together (overlaps, a source dropping
 *   nights another one still has, the record of what each portal says);
 * - ical_exports: the nights each room's link told the portals were closed, and when, so
 *   a closure that comes back from a portal (our own dates) is not read as a new one;
 * - ical_feeds: encrypted address (url) with its hash and a masked hint, last successful
 *   reading, retry time, consecutive failures, empty readings waiting for confirmation and
 *   a short lock against two readings of the same calendar at once;
 * - unit_blocks.source: where a closure comes from (manual, airbnb, booking…).
 *
 * Existing rows are kept: addresses are encrypted in place and the closures already
 * imported become events of their calendar.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database\Migrations;

use BookingManager\Core\SecretBox;
use BookingManager\Database\Migration;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Migration_20261029_000001_IcalSources extends Migration {

	public function up( \wpdb $wpdb ): void {
		$p = $wpdb->prefix . Schema::PREFIX;
		$c = $wpdb->get_charset_collate();
		$this->delta(
			array(
				"CREATE TABLE {$p}ical_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  feed_id bigint(20) unsigned NOT NULL,
  unit_id bigint(20) unsigned NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  uid varchar(190) NOT NULL,
  date_from date NOT NULL,
  date_to date NOT NULL,
  reservation tinyint(1) NOT NULL DEFAULT 0,
  first_seen_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY feed_uid (feed_id,uid),
  KEY unit (unit_id,date_to)
) {$c};",
				"CREATE TABLE {$p}ical_exports (
  unit_id bigint(20) unsigned NOT NULL,
  night date NOT NULL,
  exported_at datetime NOT NULL,
  PRIMARY KEY  (unit_id,night),
  KEY exported (exported_at)
) {$c};",
			)
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- internal table names.
		$feeds = Schema::table( 'ical_feeds' );
		$cols  = array(
			array( 'url_hash', 'char(64) NULL AFTER url' ),
			array( 'url_hint', 'varchar(190) NULL AFTER url_hash' ),
			array( 'last_success_at', 'datetime NULL AFTER last_sync_at' ),
			array( 'next_sync_at', 'datetime NULL AFTER last_success_at' ),
			array( 'failures', 'int(10) unsigned NOT NULL DEFAULT 0 AFTER last_error' ),
			array( 'empty_reads', 'int(10) unsigned NOT NULL DEFAULT 0 AFTER failures' ),
			array( 'locked_until', 'datetime NULL AFTER empty_reads' ),
		);
		$this->add_columns( $wpdb, $feeds, $cols );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$feeds} WHERE Key_name = %s", 'due' ) ) ) {
			$wpdb->query( "ALTER TABLE {$feeds} ADD KEY due (status,next_sync_at)" );
		}

		$blocks = Schema::table( 'unit_blocks' );
		$this->add_columns( $wpdb, $blocks, array( array( 'source', "varchar(20) NOT NULL DEFAULT 'manual' AFTER kind" ) ) );
		$wpdb->query( "UPDATE {$blocks} b JOIN {$feeds} f ON f.id = b.feed_id SET b.source = f.channel WHERE b.feed_id IS NOT NULL" );

		// Addresses of the portals carry the listing's private key: encrypted from now on.
		$box = new SecretBox();
		foreach ( (array) $wpdb->get_results( "SELECT id, url FROM {$feeds} WHERE url NOT LIKE 'rm1:%'", ARRAY_A ) as $row ) {
			$url = (string) $row['url'];
			$wpdb->update(
				$feeds,
				array(
					'url'      => $box->seal( array( 'url' => $url ) ),
					'url_hash' => \BookingManager\Channels\Ical\IcalFeedRepository::hash( $url ),
					'url_hint' => \BookingManager\Channels\Ical\IcalCalendar::hint( $url ),
				),
				array( 'id' => (int) $row['id'] )
			);
		}

		// The closures already imported become the events of their calendar.
		$events = Schema::table( 'ical_events' );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$events} (feed_id, unit_id, property_id, uid, date_from, date_to, reservation, first_seen_at, last_seen_at)
				SELECT b.feed_id, b.unit_id, b.property_id, b.external_uid, MIN(b.date_from), MAX(b.date_to), MAX(b.reason LIKE '%%prenotazione%%'), %s, %s
				FROM {$blocks} b JOIN {$feeds} f ON f.id = b.feed_id AND f.status = 'active'
				WHERE b.status = 'active' AND b.external_uid IS NOT NULL
				GROUP BY b.feed_id, b.unit_id, b.property_id, b.external_uid
				ON DUPLICATE KEY UPDATE date_from = LEAST(date_from, VALUES(date_from)), date_to = GREATEST(date_to, VALUES(date_to))",
				$now,
				$now
			)
		);
		// phpcs:enable
	}

	/** @param array<int, array{0: string, 1: string}> $columns name, definition */
	private function add_columns( \wpdb $wpdb, string $table, array $columns ): void {
		// Idempotent: a column may exist after a crash between ALTER and the record.
		foreach ( $columns as [ $name, $definition ] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $name ) ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- internal table name and fixed definition.
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$definition}" );
			}
		}
	}
}
