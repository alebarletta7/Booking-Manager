<?php
/**
 * Fixed-window counters for public endpoints (public booking form, manage links).
 *
 * With a persistent object cache (Redis, Memcached) the counter is atomic: wp_cache_add()
 * creates it only if missing and wp_cache_incr() increments it in the cache server, so
 * parallel requests can never all read the same value. Without one the counter lives in
 * a transient (read, then write): two requests arriving at the very same moment may
 * both pass, so the limit can be exceeded by the few requests that race. That is
 * acceptable for its purpose (slowing down abuse, not accounting); the hard limits
 * (covers per slot, bookings per phone) are checked against the database.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	private const GROUP = 'bkng_rate';

	/**
	 * Counts one hit and says whether it is within the limit.
	 *
	 * @param string $bucket Short name of the counter (e.g. "book").
	 * @param string $key    Who is counted (an IP from ip_key(), an e-mail, an order id).
	 */
	public static function hit( string $bucket, string $key, int $limit, int $window ): bool {
		$id = self::id( $bucket, $key );
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			wp_cache_add( $id, 0, self::GROUP, $window );
			$count = wp_cache_incr( $id, 1, self::GROUP );
			if ( false !== $count ) {
				return (int) $count <= $limit;
			}
			// The cache refused the increment: fall back to the transient.
		}
		$count = (int) get_transient( $id );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $id, $count + 1, $window );
		return true;
	}

	/**
	 * Cache key of a counter (unit-tested): salted hash, so neither IP addresses nor
	 * e-mails are stored in clear in the options table, and short enough for a transient.
	 */
	public static function id( string $bucket, string $key, ?string $salt = null ): string {
		$salt = $salt ?? ( function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : '' );
		return 'bkng_rl_' . substr( sanitize_key( $bucket ), 0, 12 ) . '_' . substr( hash( 'sha256', $key . '|' . $salt ), 0, 24 );
	}

	/**
	 * The part of an address that identifies one client (unit-tested): the whole IPv4
	 * address, the /64 network of an IPv6 address (a single connection usually owns a
	 * whole /64, so counting single IPv6 addresses would be trivial to dodge). IPv4
	 * mapped in IPv6 counts as IPv4. Anything that is not an IP is returned unchanged.
	 */
	public static function ip_key( string $ip ): string {
		$packed = filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
		if ( false === $packed ) {
			return $ip;
		}
		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return (string) inet_ntop( substr( $packed, 12 ) );
		}
		if ( 4 === strlen( $packed ) ) {
			return (string) inet_ntop( $packed );
		}
		return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}
}
