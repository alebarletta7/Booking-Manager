<?php
/**
 * Channex (channel manager towards Booking.com and the other portals).
 *
 * Only what the integration needs and the plugin did not have: availability, prices and
 * rules stay where they are (units, unit_nights, holds, rate_prices, restrictions) and
 * are read when they are sent; the links between our ids and Channex's live in
 * channel_refs (channel "channex").
 *
 * - cx_dirty: what must be sent again, one row per kind, type, plan and night; a new
 *   change bumps dirty_seq, so a row changed while it was being sent stays queued;
 * - cx_state: pause after errors (429, 5xx), spacing of the calls (10 a minute per
 *   property and kind), last full sync, feed and reconciliation of each property;
 * - cx_tasks: every call that sent availability or rates, with Channex's task id and
 *   warnings (needed for the certification);
 * - cx_revisions: reservations received (one row per revision): idempotency, outcome,
 *   acknowledgement; the payload is kept without the card ("guarantee");
 * - cx_webhook_log: calls received from Channex (event, signature valid, hash only).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database\Migrations;

use BookingManager\Database\Migration;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Migration_20261029_000001_Channex extends Migration {

	public function up( \wpdb $wpdb ): void {
		$this->delta( self::statements( $wpdb->prefix . Schema::PREFIX, $wpdb->get_charset_collate() ) );
	}

	/**
	 * @return string[]
	 */
	public static function statements( string $p, string $c ): array {
		return array(
			"CREATE TABLE {$p}cx_dirty (
  kind varchar(20) NOT NULL,
  room_type_id bigint(20) unsigned NOT NULL,
  rate_plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
  stay_date date NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  dirty_seq int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (kind,room_type_id,rate_plan_id,stay_date),
  KEY property_kind (property_id,kind,updated_at)
) {$c};",

			"CREATE TABLE {$p}cx_state (
  property_id bigint(20) unsigned NOT NULL,
  paused_until datetime NULL,
  consecutive_failures smallint(5) unsigned NOT NULL DEFAULT 0,
  last_avail_call_at datetime NULL,
  last_restr_call_at datetime NULL,
  last_full_sync_at datetime NULL,
  last_feed_poll_at datetime NULL,
  last_reconcile_at datetime NULL,
  last_error varchar(255) NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (property_id)
) {$c};",

			"CREATE TABLE {$p}cx_tasks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  kind varchar(30) NOT NULL,
  channex_task_id varchar(36) NULL,
  values_count int(10) unsigned NOT NULL DEFAULT 0,
  http_status smallint(5) unsigned NOT NULL DEFAULT 0,
  warnings longtext NULL,
  error varchar(255) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY property_date (property_id,created_at),
  KEY created_at (created_at)
) {$c};",

			"CREATE TABLE {$p}cx_revisions (
  revision_id varchar(36) NOT NULL,
  channex_booking_id varchar(36) NOT NULL,
  channex_property_id varchar(36) NOT NULL,
  property_id bigint(20) unsigned NULL,
  status varchar(20) NOT NULL,
  ota_name varchar(64) NULL,
  ota_code varchar(64) NULL,
  arrival date NULL,
  departure date NULL,
  cx_inserted_at datetime NULL,
  local_booking_id bigint(20) unsigned NULL,
  outcome varchar(20) NULL,
  overbooked tinyint(1) NOT NULL DEFAULT 0,
  needs_attention tinyint(1) NOT NULL DEFAULT 0,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  processed_at datetime NULL,
  acked_at datetime NULL,
  error text NULL,
  payload longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (revision_id),
  KEY booking (channex_booking_id),
  KEY property_date (property_id,created_at),
  KEY attention (needs_attention,property_id)
) {$c};",

			"CREATE TABLE {$p}cx_webhook_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event varchar(64) NOT NULL,
  property_id bigint(20) unsigned NULL,
  channex_property_id varchar(36) NULL,
  signature_valid tinyint(1) NOT NULL DEFAULT 0,
  payload_hash char(64) NOT NULL,
  received_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY received_at (received_at)
) {$c};",
		);
	}
}
