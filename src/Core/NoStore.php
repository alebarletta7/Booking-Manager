<?php
/**
 * Headers of private or dynamic responses (booking management page, QR images, REST):
 * never stored by the browser, by a proxy or by a CDN such as Cloudflare.
 *
 * CDN-Cache-Control and Cloudflare-CDN-Cache-Control are read by the CDN only (and
 * not forwarded to the browser), so they hold even where an edge rule would otherwise
 * override Cache-Control.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class NoStore {

	public const HEADERS = array(
		'Cache-Control'                => 'no-store, private',
		'CDN-Cache-Control'            => 'no-store',
		'Cloudflare-CDN-Cache-Control' => 'no-store',
	);

	/** Sends the headers on a plain PHP response. */
	public static function send(): void {
		if ( headers_sent() ) {
			return;
		}
		foreach ( self::HEADERS as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/** Adds the headers to a REST response. */
	public static function apply( \WP_REST_Response $response ): \WP_REST_Response {
		foreach ( self::HEADERS as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}
}
