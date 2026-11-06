<?php
/**
 * The guest's "manage my booking" link: booking code + a 32-character token.
 *
 * The token is derived from the booking's uuid with a key that exists only in this
 * site's database (option bkng_manage_key), so the e-mails sent later by the queue can
 * include it without the plain token ever being stored. Only its SHA-256 is saved on the
 * booking and checked when the link is used.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Bookings;

defined( 'ABSPATH' ) || exit;

class ManageLink {

	public const KEY_OPTION = 'bkng_manage_key';

	/** Query arguments of the link. */
	public const ARG_CODE  = 'bkng_booking';
	public const ARG_TOKEN = 'bkng_token';

	public function __construct( private ?string $key = null ) {}

	public function token( string $uuid ): string {
		$raw  = hash_hmac( 'sha256', 'manage|' . $uuid, $this->key(), true );
		$raw .= hash_hmac( 'sha256', 'manage2|' . $uuid, $this->key(), true );
		$max  = strlen( BookingCodes::ALPHABET );
		$out  = '';
		for ( $i = 0; $i < 32; $i++ ) {
			$out .= BookingCodes::ALPHABET[ ord( $raw[ $i ] ) % $max ];
		}
		return $out;
	}

	public function hash( string $uuid ): string {
		return BookingCodes::hash_token( $this->token( $uuid ) );
	}

	/**
	 * Address of the page where the guest sees and cancels the booking.
	 *
	 * @param array<string, mixed> $booking
	 */
	public function url( array $booking ): string {
		$url = add_query_arg(
			array(
				self::ARG_CODE  => (string) $booking['code'],
				self::ARG_TOKEN => $this->token( (string) $booking['uuid'] ),
			),
			home_url( '/' )
		);
		/**
		 * Filters the "manage my booking" address sent to guests.
		 *
		 * @param string               $url
		 * @param array<string, mixed> $booking
		 */
		return (string) apply_filters( 'bkng_manage_url', $url, $booking );
	}

	private function key(): string {
		if ( null === $this->key ) {
			$key = (string) get_option( self::KEY_OPTION, '' );
			if ( strlen( $key ) < 32 ) {
				$key = bin2hex( random_bytes( 32 ) );
				add_option( self::KEY_OPTION, $key, '', false );
				$key = (string) get_option( self::KEY_OPTION, $key );
			}
			$this->key = $key;
		}
		return $this->key;
	}
}
