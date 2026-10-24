<?php
/**
 * The visitor's IP address as the plugin sees it: public booking rate limit, Turnstile's
 * remoteip and the (truncated) IP of the audit log all read it from here.
 *
 * By default it is REMOTE_ADDR, the only address the web server itself vouches for;
 * behind Cloudflare's proxy, the visitor's address Cloudflare passes on (see direct()).
 * Behind another proxy or CDN the bkng_client_ip filter may return the real one.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class ClientIp {

	/** REMOTE_ADDR when it is a valid IP address, otherwise "". */
	public static function remote_addr(): string {
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated just below.
		return false === filter_var( $raw, FILTER_VALIDATE_IP ) ? '' : $raw;
	}

	/**
	 * Cloudflare's published address ranges (cloudflare.com/ips). Only a request coming
	 * from one of them may tell the visitor's address in CF-Connecting-IP: on a site
	 * without Cloudflare the header is ignored, so nobody can fake it.
	 */
	private const CLOUDFLARE = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/** True when the address belongs to Cloudflare's network. */
	public static function is_cloudflare( string $ip ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		if ( false === $bin ) {
			return false;
		}
		foreach ( self::CLOUDFLARE as $cidr ) {
			[ $net, $bits ] = explode( '/', $cidr );
			$net            = inet_pton( $net );
			if ( strlen( $net ) !== strlen( $bin ) ) {
				continue;
			}
			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
			if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * REMOTE_ADDR, or the visitor's address from CF-Connecting-IP when the request
	 * really comes from Cloudflare (sites behind its proxy, the orange cloud).
	 */
	public static function direct(): string {
		$remote = self::remote_addr();
		if ( '' === $remote || ! self::is_cloudflare( $remote ) || empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return $remote;
		}
		$real = trim( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated just below.
		return false === filter_var( $real, FILTER_VALIDATE_IP ) ? $remote : $real;
	}

	public static function current( ?\WP_REST_Request $request = null ): string {
		$remote = self::direct();
		/**
		 * The visitor's address (public booking rate limit, captcha, audit log). Behind a proxy
		 * or CDN that hides it, return the real one, from a header your proxy sets and
		 * that you trust only when the request comes from that proxy.
		 *
		 * @param string                $ip      REMOTE_ADDR (the visitor's, behind Cloudflare).
		 * @param \WP_REST_Request|null $request The REST request, when there is one.
		 */
		$ip = (string) apply_filters( 'bkng_client_ip', $remote, $request );
		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? $remote : $ip;
	}
}
