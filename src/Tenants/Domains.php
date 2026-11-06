<?php
/**
 * Authorised domains of a tenant: only host names, used to build the
 * Content-Security-Policy frame-ancestors of its public pages. A domain is never
 * a proof of identity: it grants nothing on the API.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

defined( 'ABSPATH' ) || exit;

final class Domains {

	/**
	 * Normalises what an administrator typed ("https://www.Hotel.it/", "hotel.it")
	 * to a bare lower-case host in ASCII (punycode), or null if it is not a public
	 * host name: no scheme other than http(s), no path, port, user, IP, localhost or
	 * wildcard.
	 */
	public static function normalize( string $input ): ?string {
		$input = trim( strtolower( $input ) );
		if ( '' === $input ) {
			return null;
		}
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#', $input ) ) {
			if ( ! preg_match( '#^https?://#', $input ) ) {
				return null;
			}
			$parts = wp_parse_url( $input );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['query'] ) || ( isset( $parts['path'] ) && '/' !== $parts['path'] ) ) {
				return null;
			}
			$input = (string) $parts['host'];
		}
		$input = rtrim( $input, '.' );
		if ( function_exists( 'idn_to_ascii' ) && preg_match( '/[^\x20-\x7e]/', $input ) ) {
			$ascii = idn_to_ascii( $input, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$input = false === $ascii ? '' : strtolower( $ascii );
		}
		if ( strlen( $input ) > 253 || ! str_contains( $input, '.' ) || filter_var( $input, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		foreach ( explode( '.', $input ) as $label ) {
			if ( ! preg_match( '/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label ) ) {
				return null;
			}
		}
		if ( preg_match( '/(^|\.)(localhost|local|test|invalid|example|localdomain)$/', $input ) || preg_match( '/^[0-9.]+$/', $input ) ) {
			return null;
		}
		return $input;
	}
}
