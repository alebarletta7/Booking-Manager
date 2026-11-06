<?php
/**
 * Signed start time of the public form: a booking sent less than MIN_SECONDS after the
 * form was opened (a bot) or with a token older than MAX_SECONDS is refused. The token
 * is fetched by the form when it opens, so a cached page never carries an old one.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Frontend;

defined( 'ABSPATH' ) || exit;

class FormToken {

	public const MIN_SECONDS = 3;
	public const MAX_SECONDS = 2 * HOUR_IN_SECONDS;

	public static function issue( int $now ): string {
		return $now . '.' . self::sign( (string) $now );
	}

	/**
	 * 'ok', 'too_fast', 'expired' or 'invalid' (unit-tested).
	 */
	public static function check( string $token, int $now ): string {
		if ( ! preg_match( '/^(\d{9,11})\.([a-f0-9]{32})$/', $token, $m ) || ! hash_equals( self::sign( $m[1] ), $m[2] ) ) {
			return 'invalid';
		}
		$age = $now - (int) $m[1];
		if ( $age < self::MIN_SECONDS ) {
			return 'too_fast';
		}
		return $age > self::MAX_SECONDS ? 'expired' : 'ok';
	}

	private static function sign( string $value ): string {
		return substr( hash_hmac( 'sha256', 'bkng-form|' . $value, wp_salt( 'nonce' ) ), 0, 32 );
	}
}
