<?php
/**
 * Minimal Nexi XPay (Italy) REST client over the WordPress HTTP API.
 *
 * Endpoints and headers from developer.nexi.it (API reference "phoenix-0.0/psp/api/v1"):
 * every call carries the merchant's X-Api-Key and a fresh Correlation-Id; the post
 * operations (captures, refunds, cancels) also an Idempotency-Key in UUID format.
 * Error bodies are {"errors":[{"code","description"}]}; 401 and 404 come without a body.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments\Providers;

defined( 'ABSPATH' ) || exit;

class NexiClient {

	public const LIVE = 'https://xpay.nexigroup.com/api/phoenix-0.0/psp/api/v1/';
	public const TEST = 'https://xpaysandbox.nexigroup.com/api/phoenix-0.0/psp/api/v1/';

	/**
	 * @param array<string, mixed>|null $body JSON body (POST)
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $method, string $path, string $api_key, bool $live, ?array $body = null, ?string $idempotency_key = null ): array|\WP_Error {
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'X-Api-Key'      => $api_key,
				'Correlation-Id' => wp_generate_uuid4(),
				'Accept'         => 'application/json',
				'User-Agent'     => 'BookingManager/' . ( defined( 'BKNG_VERSION' ) ? BKNG_VERSION : 'dev' ) . ' (WordPress)',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}
		if ( null !== $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = self::uuid( $idempotency_key );
		}
		$url      = ( $live ? self::LIVE : self::TEST ) . ltrim( $path, '/' );
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) && ( 'GET' === $method || null !== $idempotency_key ) ) {
			// Network hiccup: reads and idempotent operations can be repeated safely.
			$response = wp_remote_request( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'bkng_provider_unreachable', __( 'Nexi non risponde: riprova tra poco.', 'booking-manager' ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = '' === $raw ? array() : json_decode( $raw, true );
		if ( $code >= 400 ) {
			$errors  = is_array( $data ) && is_array( $data['errors'] ?? null ) ? $data['errors'] : array();
			$message = match ( true ) {
				401 === $code => __( 'chiave API non valida per questo ambiente', 'booking-manager' ),
				404 === $code => __( 'non trovato', 'booking-manager' ),
				(bool) $errors => implode( '; ', array_map( fn( $e ) => trim( (string) ( $e['description'] ?? '' ) . ' (' . (string) ( $e['code'] ?? '' ) . ')' ), array_slice( $errors, 0, 3 ) ) ),
				default => __( 'errore sconosciuto', 'booking-manager' ),
			};
			return new \WP_Error(
				'bkng_nexi_error',
				/* translators: %s: message from Nexi */
				sprintf( __( 'Nexi: %s', 'booking-manager' ), $message ),
				array(
					'status'      => 401 === $code ? 400 : ( $code >= 500 ? 502 : 422 ),
					'http_status' => $code,
					'nexi_code'   => (string) ( $errors[0]['code'] ?? '' ),
				)
			);
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bkng_provider_bad_response', __( 'Risposta non valida da Nexi.', 'booking-manager' ), array( 'status' => 502 ) );
		}
		return $data;
	}

	/** Nexi wants idempotency keys in UUID format: a stable UUID derived from ours. */
	public static function uuid( string $key ): string {
		$h = md5( 'bmgr-nexi|' . $key );
		return sprintf( '%s-%s-4%s-%s%s-%s', substr( $h, 0, 8 ), substr( $h, 8, 4 ), substr( $h, 13, 3 ), dechex( 8 | ( hexdec( $h[16] ) & 3 ) ), substr( $h, 17, 3 ), substr( $h, 20, 12 ) );
	}
}
