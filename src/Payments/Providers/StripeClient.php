<?php
/**
 * Minimal Stripe REST client over the WordPress HTTP API (no SDK shipped in the plugin).
 *
 * Only the official endpoints documented at docs.stripe.com/api are used. POST requests
 * carry an Idempotency-Key when the caller gives one, so a retry never charges or refunds
 * twice. Error messages are Stripe's own (they never contain card data).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments\Providers;

defined( 'ABSPATH' ) || exit;

class StripeClient {

	public const BASE = 'https://api.stripe.com/v1/';

	/**
	 * @param array<string, mixed> $params form parameters (nested arrays allowed)
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $method, string $path, string $key, array $params = array(), ?string $idempotency_key = null ): array|\WP_Error {
		$url  = self::BASE . ltrim( $path, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'User-Agent'    => 'BookingManager/' . ( defined( 'BKNG_VERSION' ) ? BKNG_VERSION : 'dev' ) . ' (WordPress)',
			),
		);
		if ( 'GET' === $method || 'DELETE' === $method ) {
			if ( $params ) {
				$url .= '?' . http_build_query( $params, '', '&' );
			}
		} else {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']                    = http_build_query( $params, '', '&' );
			if ( null !== $idempotency_key ) {
				$args['headers']['Idempotency-Key'] = $idempotency_key;
			}
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) && null !== $idempotency_key ) {
			// Network hiccup: the same key makes the retry safe.
			$response = wp_remote_request( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'bkng_stripe_unreachable', __( 'Stripe non risponde: riprova tra poco.', 'booking-manager' ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'bkng_stripe_bad_response', __( 'Risposta non valida da Stripe.', 'booking-manager' ), array( 'status' => 502 ) );
		}
		if ( $code >= 400 ) {
			$error = is_array( $body['error'] ?? null ) ? $body['error'] : array();
			return new \WP_Error(
				'bkng_stripe_error',
				/* translators: %s: message from Stripe */
				sprintf( __( 'Stripe: %s', 'booking-manager' ), (string) ( $error['message'] ?? __( 'errore sconosciuto', 'booking-manager' ) ) ),
				array(
					'status'      => 401 === $code || 403 === $code ? 400 : ( $code >= 500 ? 502 : 422 ),
					'http_status' => $code,
					'stripe_code' => (string) ( $error['code'] ?? $error['type'] ?? '' ),
				)
			);
		}
		return $body;
	}
}
