<?php
/**
 * Named MySQL locks (GET_LOCK) shared by every request on the same database server.
 *
 * Lock names are server-wide, not per database: two WordPress sites (or two multisite
 * blogs) on the same MySQL server must never wait on each other's locks, so every name
 * carries the table prefix. MySQL refuses names longer than 64 characters: a longer
 * name is replaced by a hash of itself.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class DbLock {

	/** Longest lock name MySQL accepts. */
	public const MAX_LENGTH = 64;

	/**
	 * Full lock name for a key such as "event_12" (unit-tested): "<prefix>bkng_event_12",
	 * or "bkng_" + sha1 of it when that is too long.
	 */
	public static function name( string $key, string $prefix ): string {
		$name = $prefix . 'bkng_' . $key;
		return strlen( $name ) <= self::MAX_LENGTH ? $name : 'bkng_' . sha1( $name );
	}

	/** Takes the lock, waiting up to $timeout seconds. True when it is ours. */
	public static function acquire( string $key, int $timeout = 5 ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::name( $key, (string) $wpdb->prefix ), $timeout ) );
	}

	public static function release( string $key ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::name( $key, (string) $wpdb->prefix ) ) );
	}
}
