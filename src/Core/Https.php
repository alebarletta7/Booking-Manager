<?php
/**
 * HTTPS required (0.2.7). When the site's address is https://, the public pages that
 * carry a guest's data or token (the embeddable page /booking/{slug}/ and the "manage
 * booking" link) are redirected to https with a 301, and the public API
 * (booking-manager/public/v1) refuses plain http with 403 instead of answering: a
 * redirect would make the browser resend the request body in clear.
 *
 * A site still on http:// (local development) is left alone. Behind a proxy that ends
 * TLS, WordPress must see the request as https (is_ssl(): $_SERVER['HTTPS'] set from
 * X-Forwarded-Proto in wp-config.php), otherwise define( 'BKNG_FORCE_HTTPS', false ).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class Https {

	public const ERROR_CODE = 'bkng_https_required';

	public function hooks(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'rest' ), 5, 3 );
	}

	/** The site is on https and the check is not switched off. */
	public static function required(): bool {
		if ( defined( 'BKNG_FORCE_HTTPS' ) && false === constant( 'BKNG_FORCE_HTTPS' ) ) {
			return false;
		}
		/**
		 * Whether the plugin's public pages and API require https.
		 *
		 * @param bool $required true when the site's address is https://
		 */
		return (bool) apply_filters( 'bkng_force_https', 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) );
	}

	/** The current request should have come over https. */
	public static function missing(): bool {
		return ! is_ssl() && self::required();
	}

	/** https:// address of the current request (same host and port as the site). */
	public static function upgrade( string $request_uri ): string {
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		return 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . ( $port ? ':' . $port : '' ) . '/' . ltrim( $request_uri, '/' );
	}

	/** Sends the 301 to https when needed; returns only when nothing had to be done. */
	public static function redirect_if_needed(): void {
		if ( ! self::missing() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only appended to our own host.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		wp_safe_redirect( self::upgrade( $uri ), 301 );
		exit;
	}

	/**
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public function rest( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		if ( ! str_starts_with( ltrim( $request->get_route(), '/' ), 'booking-manager/public/' ) || ! self::missing() ) {
			return $result;
		}
		return new \WP_Error( self::ERROR_CODE, __( 'Questa pagina va aperta con https://.', 'booking-manager' ), array( 'status' => 403 ) );
	}
}
