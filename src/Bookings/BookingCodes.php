<?php
/**
 * Random identifiers of a booking.
 *
 * - code: 8 characters the guest reads on the phone or types at the reception;
 * - qr_id: 16 characters inside the QR (never the numeric id, never personal data);
 * - manage token: 32 characters in the guest's "manage my booking" link; only its
 *   SHA-256 is stored.
 *
 * The alphabet has no ambiguous characters (no 0/O, 1/I/L).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Bookings;

defined( 'ABSPATH' ) || exit;

final class BookingCodes {

	public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	public static function random( int $length ): string {
		$out = '';
		$max = strlen( self::ALPHABET ) - 1;
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= self::ALPHABET[ random_int( 0, $max ) ];
		}
		return $out;
	}

	public static function booking_code(): string {
		return self::random( 8 );
	}

	public static function qr_id(): string {
		return self::random( 16 );
	}

	public static function hash_token( string $token ): string {
		return hash( 'sha256', 'bkng-manage|' . $token );
	}

	/** A code as typed by a person: upper case, spaces and dashes removed. */
	public static function clean( string $code ): string {
		return strtoupper( (string) preg_replace( '/[\s\-]+/', '', trim( $code ) ) );
	}

	public static function looks_like_code( string $code ): bool {
		return 1 === preg_match( '/^[' . self::ALPHABET . ']{6,16}$/', $code );
	}
}
