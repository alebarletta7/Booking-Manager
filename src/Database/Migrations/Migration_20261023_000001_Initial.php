<?php
/**
 * Initial schema (database version 1.0.0).
 *
 * Formatting follows dbDelta's strict rules: one column per line, two spaces after
 * "PRIMARY KEY", every index named, no backticks.
 *
 * Inventory model: every unit (room, apartment) belongs to a room type and a booking
 * always books a type; the unit is assigned at once (B&B, apartments) or later (hotel).
 * Two guards keep a night from being sold twice:
 * - the room type row is locked (SELECT … FOR UPDATE) while the nights of that type are
 *   counted and written, so two requests never count the same free room;
 * - unit_nights has PRIMARY KEY (unit_id, night): the database itself refuses to put
 *   two stays, or a stay and an out-of-service block, in the same room on the same night.
 * The departure day is never an occupied night.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database\Migrations;

use BookingManager\Database\Migration;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Migration_20261023_000001_Initial extends Migration {

	public function up( \wpdb $wpdb ): void {
		$this->delta( self::statements( $wpdb->prefix . Schema::PREFIX, $wpdb->get_charset_collate() ) );
	}

	/**
	 * @return string[]
	 */
	public static function statements( string $p, string $c ): array {
		return array(

			/* ----------------------------------------------------------- Properties & access */

			"CREATE TABLE {$p}properties (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  name varchar(190) NOT NULL,
  slug varchar(190) NOT NULL,
  kind varchar(20) NOT NULL DEFAULT 'bnb',
  timezone varchar(64) NOT NULL DEFAULT 'Europe/Rome',
  currency char(3) NOT NULL DEFAULT 'EUR',
  booking_mode varchar(10) NOT NULL DEFAULT 'request',
  checkin_from time NOT NULL DEFAULT '14:00:00',
  checkin_to time NOT NULL DEFAULT '20:00:00',
  checkout_until time NOT NULL DEFAULT '10:30:00',
  address varchar(255) NULL,
  city varchar(120) NULL,
  postcode varchar(16) NULL,
  province varchar(60) NULL,
  country char(2) NULL,
  phone varchar(32) NULL,
  email varchar(190) NULL,
  website varchar(255) NULL,
  company_name varchar(190) NULL,
  vat_number varchar(32) NULL,
  settings longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  archived_at datetime NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY slug (slug),
  KEY status (status)
) {$c};",

			"CREATE TABLE {$p}user_properties (
  user_id bigint(20) unsigned NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (user_id,property_id),
  KEY property_id (property_id)
) {$c};",

			/* ----------------------------------------------------------- Inventory */

			"CREATE TABLE {$p}room_types (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL,
  slug varchar(190) NOT NULL,
  description text NULL,
  max_adults smallint(5) unsigned NOT NULL DEFAULT 2,
  max_children smallint(5) unsigned NOT NULL DEFAULT 0,
  max_occupancy smallint(5) unsigned NOT NULL DEFAULT 2,
  base_occupancy smallint(5) unsigned NOT NULL DEFAULT 2,
  base_price bigint(20) NOT NULL DEFAULT 0,
  extra_adult_price bigint(20) NOT NULL DEFAULT 0,
  extra_child_price bigint(20) NOT NULL DEFAULT 0,
  size_sqm smallint(5) unsigned NULL,
  beds varchar(190) NULL,
  images longtext NULL,
  amenities longtext NULL,
  is_hidden tinyint(1) NOT NULL DEFAULT 0,
  online_enabled tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(10) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY property_slug (property_id,slug),
  KEY property_status (property_id,status)
) {$c};",

			"CREATE TABLE {$p}units (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  room_type_id bigint(20) unsigned NOT NULL,
  code varchar(32) NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  floor varchar(20) NULL,
  notes text NULL,
  op_status varchar(20) NOT NULL DEFAULT 'ready',
  op_note varchar(255) NULL,
  op_updated_at datetime NULL,
  op_updated_by bigint(20) unsigned NULL,
  sort_order int(10) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY property_code (property_id,code),
  KEY type_status (room_type_id,status)
) {$c};",

			"CREATE TABLE {$p}unit_blocks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  unit_id bigint(20) unsigned NOT NULL,
  room_type_id bigint(20) unsigned NOT NULL,
  date_from date NOT NULL,
  date_to date NOT NULL,
  kind varchar(20) NOT NULL DEFAULT 'out_of_service',
  reason varchar(190) NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY unit_dates (unit_id,date_from,date_to),
  KEY type_dates (room_type_id,status,date_from,date_to)
) {$c};",

			"CREATE TABLE {$p}unit_nights (
  unit_id bigint(20) unsigned NOT NULL,
  night date NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  booking_room_id bigint(20) unsigned NULL,
  block_id bigint(20) unsigned NULL,
  PRIMARY KEY  (unit_id,night),
  KEY booking_room (booking_room_id),
  KEY block (block_id),
  KEY property_night (property_id,night)
) {$c};",

			"CREATE TABLE {$p}unit_status_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  unit_id bigint(20) unsigned NOT NULL,
  from_status varchar(20) NOT NULL,
  to_status varchar(20) NOT NULL,
  note varchar(255) NULL,
  user_id bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY unit_date (unit_id,created_at),
  KEY property_date (property_id,created_at)
) {$c};",

			/* ----------------------------------------------------------- Rates & availability */

			"CREATE TABLE {$p}rate_plans (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL,
  description text NULL,
  room_type_ids longtext NULL,
  modifier_bp int(11) NOT NULL DEFAULT 0,
  meal_plan varchar(20) NOT NULL DEFAULT 'room_only',
  cancellation_policy longtext NULL,
  deposit_policy longtext NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  is_public tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(10) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY property_status (property_id,status)
) {$c};",

			"CREATE TABLE {$p}rate_prices (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  rate_plan_id bigint(20) unsigned NULL,
  room_type_id bigint(20) unsigned NOT NULL,
  label varchar(190) NULL,
  date_from date NOT NULL,
  date_to date NOT NULL,
  weekdays varchar(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  price_night bigint(20) NOT NULL,
  extra_adult_price bigint(20) NULL,
  extra_child_price bigint(20) NULL,
  priority int(10) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_dates (room_type_id,date_from,date_to),
  KEY plan (rate_plan_id)
) {$c};",

			"CREATE TABLE {$p}rate_adjustments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  rate_plan_id bigint(20) unsigned NULL,
  kind varchar(20) NOT NULL,
  name varchar(190) NOT NULL,
  description text NULL,
  amount bigint(20) NULL,
  percent_bp int(11) NULL,
  per varchar(20) NOT NULL DEFAULT 'stay',
  min_nights smallint(5) unsigned NULL,
  max_nights smallint(5) unsigned NULL,
  date_from date NULL,
  date_to date NULL,
  room_type_ids longtext NULL,
  is_optional tinyint(1) NOT NULL DEFAULT 0,
  is_public tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(10) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY property_status (property_id,status)
) {$c};",

			"CREATE TABLE {$p}restrictions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  room_type_id bigint(20) unsigned NULL,
  rate_plan_id bigint(20) unsigned NULL,
  date_from date NOT NULL,
  date_to date NOT NULL,
  min_stay smallint(5) unsigned NULL,
  max_stay smallint(5) unsigned NULL,
  min_advance_days smallint(5) unsigned NULL,
  max_advance_days smallint(5) unsigned NULL,
  arrival_weekdays varchar(20) NULL,
  departure_weekdays varchar(20) NULL,
  closed tinyint(1) NOT NULL DEFAULT 0,
  closed_to_arrival tinyint(1) NOT NULL DEFAULT 0,
  closed_to_departure tinyint(1) NOT NULL DEFAULT 0,
  note varchar(190) NULL,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY property_dates (property_id,date_from,date_to)
) {$c};",

			"CREATE TABLE {$p}holds (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  token_hash char(64) NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  room_type_id bigint(20) unsigned NOT NULL,
  arrival date NOT NULL,
  departure date NOT NULL,
  quantity smallint(5) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'active',
  booking_id bigint(20) unsigned NULL,
  expires_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY token_hash (token_hash),
  KEY type_status (room_type_id,status,arrival,departure),
  KEY expires (status,expires_at)
) {$c};",

			/* ----------------------------------------------------------- Guests */

			"CREATE TABLE {$p}guests (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  email varchar(190) NULL,
  email_hash char(64) NULL,
  phone varchar(32) NULL,
  phone_hash char(64) NULL,
  country char(2) NULL,
  language varchar(10) NULL,
  notes text NULL,
  stays_count int(10) unsigned NOT NULL DEFAULT 0,
  last_stay date NULL,
  anonymized_at datetime NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY email_hash (email_hash),
  KEY phone_hash (phone_hash),
  KEY last_name (last_name)
) {$c};",

			"CREATE TABLE {$p}guest_consents (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  guest_id bigint(20) unsigned NOT NULL,
  property_id bigint(20) unsigned NULL,
  purpose varchar(30) NOT NULL,
  granted tinyint(1) NOT NULL DEFAULT 0,
  text_version varchar(40) NULL,
  source varchar(20) NOT NULL DEFAULT 'staff',
  booking_id bigint(20) unsigned NULL,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY guest_purpose (guest_id,purpose)
) {$c};",

			/* ----------------------------------------------------------- Bookings */

			"CREATE TABLE {$p}bookings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  code varchar(16) NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL,
  payment_status varchar(24) NOT NULL DEFAULT 'unpaid',
  source varchar(20) NOT NULL DEFAULT 'manual',
  guest_id bigint(20) unsigned NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  email varchar(190) NULL,
  phone varchar(32) NULL,
  language varchar(10) NULL,
  arrival date NOT NULL,
  departure date NOT NULL,
  nights smallint(5) unsigned NOT NULL,
  adults smallint(5) unsigned NOT NULL DEFAULT 1,
  children smallint(5) unsigned NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT 'EUR',
  total bigint(20) NOT NULL DEFAULT 0,
  taxes_total bigint(20) NOT NULL DEFAULT 0,
  deposit_due bigint(20) NOT NULL DEFAULT 0,
  deposit_due_date date NULL,
  paid bigint(20) NOT NULL DEFAULT 0,
  refunded bigint(20) NOT NULL DEFAULT 0,
  balance bigint(20) NOT NULL DEFAULT 0,
  price_snapshot longtext NULL,
  terms_snapshot longtext NULL,
  extras longtext NULL,
  customer_notes text NULL,
  internal_notes text NULL,
  arrival_time varchar(10) NULL,
  option_expires_at datetime NULL,
  external_source varchar(60) NULL,
  external_ref varchar(190) NULL,
  idempotency_key varchar(64) NULL,
  qr_id varchar(32) NULL,
  qr_version smallint(5) unsigned NOT NULL DEFAULT 0,
  manage_token_hash char(64) NULL,
  privacy_accepted_at datetime NULL,
  confirmed_at datetime NULL,
  confirmed_by bigint(20) unsigned NULL,
  cancelled_at datetime NULL,
  cancelled_by bigint(20) unsigned NULL,
  cancel_reason varchar(255) NULL,
  no_show_at datetime NULL,
  completed_at datetime NULL,
  created_by bigint(20) unsigned NULL,
  updated_by bigint(20) unsigned NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY code (code),
  UNIQUE KEY idempotency_key (idempotency_key),
  UNIQUE KEY qr_id (qr_id),
  KEY property_arrival (property_id,arrival),
  KEY property_departure (property_id,departure),
  KEY property_status (property_id,status),
  KEY property_created (property_id,created_at),
  KEY guest (guest_id),
  KEY email (email),
  KEY last_name (last_name),
  KEY external (external_source,external_ref),
  KEY status_option (status,option_expires_at)
) {$c};",

			"CREATE TABLE {$p}booking_rooms (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  booking_id bigint(20) unsigned NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  room_type_id bigint(20) unsigned NOT NULL,
  unit_id bigint(20) unsigned NULL,
  rate_plan_id bigint(20) unsigned NULL,
  arrival date NOT NULL,
  departure date NOT NULL,
  adults smallint(5) unsigned NOT NULL DEFAULT 1,
  children smallint(5) unsigned NOT NULL DEFAULT 0,
  price_total bigint(20) NOT NULL DEFAULT 0,
  nightly longtext NULL,
  occupies tinyint(1) NOT NULL DEFAULT 1,
  stay_status varchar(20) NOT NULL DEFAULT 'expected',
  checked_in_at datetime NULL,
  checked_in_by bigint(20) unsigned NULL,
  checked_out_at datetime NULL,
  checked_out_by bigint(20) unsigned NULL,
  sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY booking (booking_id),
  KEY type_dates (room_type_id,occupies,arrival,departure),
  KEY unit_dates (unit_id,arrival,departure),
  KEY property_dates (property_id,arrival,departure)
) {$c};",

			"CREATE TABLE {$p}booking_guests (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  booking_room_id bigint(20) unsigned NULL,
  guest_id bigint(20) unsigned NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  age_band varchar(10) NOT NULL DEFAULT 'adult',
  is_holder tinyint(1) NOT NULL DEFAULT 0,
  sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY booking (booking_id),
  KEY guest (guest_id)
) {$c};",

			"CREATE TABLE {$p}checkins (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NULL,
  booking_id bigint(20) unsigned NULL,
  booking_room_id bigint(20) unsigned NULL,
  kind varchar(10) NOT NULL,
  method varchar(10) NOT NULL,
  result varchar(20) NOT NULL,
  timing varchar(10) NULL,
  note varchar(255) NULL,
  user_id bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY property_created (property_id,created_at),
  KEY booking (booking_id),
  KEY created_at (created_at)
) {$c};",

			/* ----------------------------------------------------------- Payments */

			"CREATE TABLE {$p}payment_accounts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  provider varchar(20) NOT NULL,
  mode varchar(10) NOT NULL DEFAULT 'test',
  status varchar(20) NOT NULL DEFAULT 'incomplete',
  credentials longtext NULL,
  settings longtext NULL,
  last_verified_at datetime NULL,
  last_error varchar(255) NULL,
  created_by bigint(20) unsigned NULL,
  updated_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY property_provider_mode (property_id,provider,mode)
) {$c};",

			"CREATE TABLE {$p}payments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  booking_id bigint(20) unsigned NOT NULL,
  kind varchar(20) NOT NULL DEFAULT 'deposit',
  method varchar(20) NOT NULL,
  gateway varchar(20) NOT NULL DEFAULT 'manual',
  payment_account_id bigint(20) unsigned NULL,
  amount bigint(20) NOT NULL,
  refunded bigint(20) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT 'EUR',
  status varchar(20) NOT NULL DEFAULT 'requested',
  due_date date NULL,
  external_id varchar(190) NULL,
  provider_payment_id varchar(190) NULL,
  checkout_url text NULL,
  expires_at datetime NULL,
  idempotency_key varchar(64) NOT NULL,
  failure_code varchar(60) NULL,
  failure_message varchar(255) NULL,
  livemode tinyint(1) NULL,
  reference varchar(190) NULL,
  note varchar(255) NULL,
  meta longtext NULL,
  received_at datetime NULL,
  failed_at datetime NULL,
  cancelled_at datetime NULL,
  created_by bigint(20) unsigned NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY idempotency_key (idempotency_key),
  UNIQUE KEY gateway_external (gateway,external_id),
  KEY provider_payment (gateway,provider_payment_id),
  KEY booking (booking_id),
  KEY property_status_date (property_id,status,created_at),
  KEY status_due (status,due_date)
) {$c};",

			"CREATE TABLE {$p}payment_transactions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  payment_id bigint(20) unsigned NOT NULL,
  booking_id bigint(20) unsigned NOT NULL,
  property_id bigint(20) unsigned NOT NULL,
  type varchar(20) NOT NULL,
  amount bigint(20) NOT NULL,
  currency char(3) NOT NULL DEFAULT 'EUR',
  status varchar(20) NOT NULL,
  provider varchar(20) NULL,
  external_ref varchar(190) NULL,
  idempotency_key varchar(64) NULL,
  reason varchar(255) NULL,
  actor_type varchar(20) NOT NULL DEFAULT 'system',
  response longtext NULL,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  UNIQUE KEY payment_ref (payment_id,external_ref),
  KEY booking (booking_id),
  KEY property_date (property_id,created_at)
) {$c};",

			"CREATE TABLE {$p}webhook_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  gateway varchar(20) NOT NULL,
  payment_account_id bigint(20) unsigned NULL,
  event_id varchar(190) NOT NULL,
  event_type varchar(80) NULL,
  livemode tinyint(1) NULL,
  payment_id bigint(20) unsigned NULL,
  status varchar(20) NOT NULL DEFAULT 'received',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  payload longtext NULL,
  error text NULL,
  received_at datetime NOT NULL,
  processed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY gateway_event (gateway,event_id),
  KEY status_received (status,received_at),
  KEY payment (payment_id)
) {$c};",

			/* ----------------------------------------------------------- Messages */

			"CREATE TABLE {$p}notifications (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  booking_id bigint(20) unsigned NULL,
  channel varchar(20) NOT NULL,
  template_key varchar(40) NOT NULL,
  audience varchar(10) NOT NULL DEFAULT 'guest',
  recipient varchar(190) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'queued',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime NOT NULL,
  provider varchar(40) NULL,
  provider_message_id varchar(190) NULL,
  last_error text NULL,
  dedupe_key varchar(120) NULL,
  sent_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY status_next (status,next_attempt_at),
  KEY booking_id (booking_id),
  KEY property_created (property_id,created_at)
) {$c};",

			"CREATE TABLE {$p}templates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  channel varchar(20) NOT NULL DEFAULT 'email',
  template_key varchar(40) NOT NULL,
  language varchar(10) NOT NULL DEFAULT 'it',
  subject varchar(255) NULL,
  body longtext NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  updated_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY property_template (property_id,channel,template_key,language)
) {$c};",

			/* ----------------------------------------------------------- Channels */

			"CREATE TABLE {$p}channel_refs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NOT NULL,
  entity_type varchar(20) NOT NULL,
  entity_id bigint(20) unsigned NOT NULL,
  channel varchar(40) NOT NULL,
  external_id varchar(190) NOT NULL,
  meta longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY channel_external (channel,entity_type,external_id),
  KEY entity (entity_type,entity_id)
) {$c};",

			"CREATE TABLE {$p}sync_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NULL,
  channel varchar(40) NOT NULL,
  direction varchar(10) NOT NULL,
  entity_type varchar(20) NULL,
  entity_id bigint(20) unsigned NULL,
  external_id varchar(190) NULL,
  idempotency_key varchar(120) NULL,
  status varchar(20) NOT NULL,
  message varchar(255) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY channel_date (channel,created_at)
) {$c};",

			/* ----------------------------------------------------------- Audit */

			"CREATE TABLE {$p}audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  property_id bigint(20) unsigned NULL,
  user_id bigint(20) unsigned NULL,
  actor_type varchar(20) NOT NULL DEFAULT 'user',
  action varchar(60) NOT NULL,
  entity_type varchar(40) NOT NULL,
  entity_id bigint(20) unsigned NULL,
  old_values longtext NULL,
  new_values longtext NULL,
  reason text NULL,
  ip varchar(45) NULL,
  request_id char(36) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entity (entity_type,entity_id),
  KEY property_date (property_id,created_at),
  KEY user_date (user_id,created_at),
  KEY action_date (action,created_at)
) {$c};",
		);
	}
}
