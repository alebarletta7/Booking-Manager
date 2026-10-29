<?php
/**
 * Database table registry.
 *
 * Conventions (shared with Night, Beach and Restaurant Manager, never their tables):
 * - service dates (arrival, departure, nights) are DATE in the property's timezone,
 *   technical timestamps (*_at) DATETIME in UTC;
 * - amounts are integer cents (bigint), with the currency stored next to them;
 * - statuses are VARCHAR mapped to PHP constants (no MySQL ENUM);
 * - JSON is stored in LONGTEXT and never queried with SQL JSON functions
 *   (MariaDB 10.4 and MySQL 8 differ);
 * - no physical foreign keys (backup/migration plugins import tables in arbitrary
 *   order), relations are enforced by services;
 * - integer types in canonical form (bigint(20) unsigned) so dbDelta does not
 *   regenerate ALTERs on MariaDB.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** Schema version written to the bkng_db_version option after all migrations. */
	public const VERSION = '1.0.0';

	public const DB_VERSION_OPTION = 'bkng_db_version';

	/** Every Booking Manager table starts with {$wpdb->prefix} . PREFIX. */
	public const PREFIX = 'bkng_';

	/**
	 * Short names of every plugin table (without WordPress prefix and without "bkng_").
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array(
			'migrations',
			// Properties & access.
			'properties',
			'user_properties',
			// Inventory.
			'room_types',
			'units',
			'unit_blocks',
			'unit_nights',
			'unit_status_log',
			// Rates & availability.
			'rate_plans',
			'rate_prices',
			'rate_adjustments',
			'restrictions',
			'holds',
			// Guests.
			'guests',
			'guest_consents',
			// Bookings.
			'bookings',
			'booking_rooms',
			'booking_guests',
			'checkins',
			// Payments.
			'payment_accounts',
			'payments',
			'payment_transactions',
			'webhook_events',
			// Messages.
			'notifications',
			'templates',
			// Channels (prepared for OTAs and channel managers).
			'channel_refs',
			'sync_log',
			// Cross-cutting.
			'audit_log',
		);
	}

	/** Full table name, e.g. wp_bkng_bookings. */
	public static function table( string $short ): string {
		global $wpdb;
		return $wpdb->prefix . self::PREFIX . $short;
	}
}
