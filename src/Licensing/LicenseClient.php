<?php
/**
 * Talks to the licence server through WordPress' HTTP API (docs/LICENZA.md, protocol in
 * the server's docs/API.md).
 *
 * Every answer becomes a ServerAnswer:
 * - signed: the server's signature checks out AND the answer repeats our request id;
 * - refused: an unsigned error the server is allowed to give;
 * - unavailable: timeout, DNS, certificate, 5xx, 429, no JSON, or a signature that does
 *   not check out (that one also leaves a line in the audit log). Never «expired».
 *
 * Nothing secret is logged: not the licence key, not the installation key, not bodies.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

defined( 'ABSPATH' ) || exit;

class LicenseClient {

	public const TIMEOUT = 15;

	/** @var callable|null fn( string $endpoint, ?string $kid ): void */
	private $on_bad_signature;

	/**
	 * @var callable|null fn( string $url, array $args ): array{status: int, body: string, retry_after: int}|\WP_Error
	 *                    (tests); null = wp_remote_post
	 */
	private $transport;

	public function __construct( private ServerConfig $server, ?callable $on_bad_signature = null, ?callable $transport = null ) {
		$this->on_bad_signature = $on_bad_signature;
		$this->transport        = $transport;
	}

	public function server(): ServerConfig {
		return $this->server;
	}

	/**
	 * POST /api/v1/license/activate, authenticated by the licence key.
	 *
	 * @param array<string, mixed> $body
	 */
	public function activate( array $body, int $timeout = self::TIMEOUT ): ServerAnswer {
		$request_id = self::new_request_id();
		return $this->post( 'activate', self::encode( $body ), array( 'X-BM-Request-Id' => $request_id ), $request_id, $timeout );
	}

	/**
	 * validate, heartbeat, deactivate or update/…, signed with the installation's key.
	 *
	 * @param array<string, mixed>|null $body null sends {}
	 */
	public function signed( string $endpoint, string $activation_id, string $secret_b64, ?array $body, int $clock_offset = 0, int $timeout = self::TIMEOUT ): ServerAnswer {
		$json       = null === $body ? '{}' : self::encode( $body );
		$request_id = self::new_request_id();
		$timestamp  = time() + $clock_offset;
		try {
			$signature = Signature::sign_request( $secret_b64, 'POST', self::path( $endpoint ), $timestamp, $request_id, $json );
		} catch ( \InvalidArgumentException $e ) {
			return ServerAnswer::unavailable( 'key_unreadable' );
		}
		$headers = array(
			'X-BM-Activation' => $activation_id,
			'X-BM-Timestamp'  => (string) $timestamp,
			'X-BM-Request-Id' => $request_id,
			'X-BM-Signature'  => $signature,
		);
		return $this->post( $endpoint, $json, $headers, $request_id, $timeout );
	}

	/** «validate» is /api/v1/license/validate, «update/check» is /api/v1/update/check. */
	public static function path( string $endpoint ): string {
		return ( str_contains( $endpoint, '/' ) ? '/api/v1/' : '/api/v1/license/' ) . $endpoint;
	}

	/** UUID v4 in lower case, as the server expects in X-BM-Request-Id. */
	public static function new_request_id(): string {
		$b    = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
	}

	/** @param array<string, mixed> $body */
	private static function encode( array $body ): string {
		return (string) json_encode( $body, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the exact bytes that are signed.
	}

	/**
	 * Classifies a raw HTTP answer (public for tests).
	 */
	public function classify( string $endpoint, int $status, string $body, int $retry_after, string $request_id ): ServerAnswer {
		$data = json_decode( $body, false, 32 );
		if ( $status >= 500 ) {
			return ServerAnswer::unavailable( 'http_5xx' );
		}
		if ( 429 === $status ) {
			return ServerAnswer::unavailable( 'rate_limited', array( 'retry_after' => $retry_after ) );
		}
		if ( ! $data instanceof \stdClass || ! is_bool( $data->ok ?? null ) ) {
			return ServerAnswer::unavailable( 'bad_response' );
		}

		if ( isset( $data->signature ) ) {
			try {
				$kid = Signature::verify_answer( $data, $this->server->public_keys );
			} catch ( \InvalidArgumentException $e ) {
				$kid = null;
			}
			// A valid signature on somebody else's answer (replayed) is no answer either.
			if ( null === $kid || ( $data->request_id ?? null ) !== $request_id ) {
				if ( null !== $this->on_bad_signature ) {
					( $this->on_bad_signature )( $endpoint, is_string( $data->signature->kid ?? null ) ? $data->signature->kid : null );
				}
				return ServerAnswer::unavailable( 'bad_signature' );
			}
			return ServerAnswer::signed( $data, $kid );
		}

		if ( true === $data->ok ) {
			return ServerAnswer::unavailable( 'unsigned' ); // A success always carries a signature.
		}
		$error = $data->error ?? null;
		$code  = $error instanceof \stdClass && is_string( $error->code ?? null ) ? sanitize_key( $error->code ) : '';
		return '' === $code ? ServerAnswer::unavailable( 'bad_response' ) : ServerAnswer::refused( $code, $error );
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function post( string $endpoint, string $json, array $headers, string $request_id, int $timeout ): ServerAnswer {
		if ( ! $this->server->is_configured() ) {
			return ServerAnswer::unavailable( 'not_configured' );
		}
		$url  = $this->server->endpoint( self::path( $endpoint ) );
		$args = array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => $headers + array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'        => $json,
			'user-agent'  => 'BookingManager/' . ( defined( 'BKNG_VERSION' ) ? BKNG_VERSION : '' ), // Not loaded at uninstall.
		);
		if ( null !== $this->transport ) {
			$raw = ( $this->transport )( $url, $args );
		} else {
			$response = wp_remote_post( $url, $args );
			$raw      = is_wp_error( $response ) ? $response : array(
				'status'      => (int) wp_remote_retrieve_response_code( $response ),
				'body'        => (string) wp_remote_retrieve_body( $response ),
				'retry_after' => (int) wp_remote_retrieve_header( $response, 'retry-after' ),
			);
		}
		if ( $raw instanceof \WP_Error ) {
			return ServerAnswer::unavailable( self::network_error( $raw ) );
		}
		return $this->classify( $endpoint, (int) $raw['status'], (string) $raw['body'], (int) ( $raw['retry_after'] ?? 0 ), $request_id );
	}

	private static function network_error( \WP_Error $error ): string {
		$message = strtolower( $error->get_error_message() );
		return match ( true ) {
			str_contains( $message, 'timed out' ) || str_contains( $message, 'timeout' ) => 'timeout',
			str_contains( $message, 'resolve' ) => 'dns',
			str_contains( $message, 'ssl' ) || str_contains( $message, 'certificate' ) || str_contains( $message, 'tls' ) => 'tls',
			default => 'network',
		};
	}
}
