<?php
/**
 * Content of the check-in QR code: "BK1:<qr_id>.<signature>".
 *
 * - qr_id: 16 random characters stored on the booking (unique), regenerated on demand,
 *   so one booking's QR can be revoked without touching the others;
 * - signature: first 20 hex characters of HMAC-SHA256(qr_id) with a key that exists only
 *   in this site's database (option bkng_qr_key, 32 random bytes, not autoloaded).
 *
 * No personal data and no numeric id: a QR read by a stranger reveals nothing, and a
 * forged one is rejected before any database lookup (the signature does not match).
 *
 * Same scheme as Night Manager's tickets (NM1:) and Restaurant Manager's bookings
 * (RM1:), with its own key and prefix.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Checkin;

use BookingManager\Bookings\BookingCodes;

defined( 'ABSPATH' ) || exit;

final class QrToken {

	public const PREFIX     = 'BK1:';
	public const KEY_OPTION = 'bkng_qr_key';

	public function __construct( private ?string $key = null ) {}

	public function payload( string $qr_id ): string {
		return self::PREFIX . $qr_id . '.' . $this->signature( $qr_id );
	}

	/**
	 * The qr_id inside a scanned text, or null when the text is not one of our QR codes
	 * or the signature is wrong.
	 */
	public function verify( string $scanned ): ?string {
		$scanned = trim( $scanned );
		$pattern = '/^' . preg_quote( self::PREFIX, '/' ) . '([' . BookingCodes::ALPHABET . ']{16})\.([a-f0-9]{20})$/';
		if ( ! preg_match( $pattern, $scanned, $m ) ) {
			return null;
		}
		return hash_equals( $this->signature( $m[1] ), $m[2] ) ? $m[1] : null;
	}

	/** True when the text looks like a QR of ours (signed or not): to tell "invalid QR" from "unknown code". */
	public static function looks_like( string $scanned ): bool {
		return str_starts_with( strtoupper( trim( $scanned ) ), self::PREFIX );
	}

	public function signature( string $qr_id ): string {
		return substr( hash_hmac( 'sha256', $qr_id, $this->key() ), 0, 20 );
	}

	private function key(): string {
		if ( null === $this->key ) {
			$key = (string) get_option( self::KEY_OPTION, '' );
			if ( strlen( $key ) < 32 ) {
				$key = bin2hex( random_bytes( 32 ) );
				// Not autoloaded: read only when a QR is created or checked.
				add_option( self::KEY_OPTION, $key, '', false );
				// Another request may have created it first: use the stored one.
				$key = (string) get_option( self::KEY_OPTION, $key );
			}
			$this->key = $key;
		}
		return $this->key;
	}
}
