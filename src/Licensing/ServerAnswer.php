<?php
/**
 * What came back from the licence server, already classified. See LicenseClient.
 *
 * - signed: the server's signature checks out and the answer repeats our request id
 *   (an «ok» authorization, or a signed error such as site_changed);
 * - refused: an unsigned error the server is allowed to give (bad key, unknown
 *   activation, clock, limits). It can only make the plugin retry or show a message,
 *   never change what the licence is;
 * - unavailable: network, 5xx, 429, no JSON, or a signature that does not check out.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

defined( 'ABSPATH' ) || exit;

final class ServerAnswer {

	public const SIGNED      = 'signed';
	public const REFUSED     = 'refused';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * @param \stdClass|null       $data    the signed answer, as decoded
	 * @param string               $code    error code (refused, unavailable, or a signed error)
	 * @param array<string, mixed> $details server_time, retry_after… (never secrets)
	 */
	private function __construct(
		public readonly string $kind,
		public readonly ?\stdClass $data = null,
		public readonly string $code = '',
		public readonly array $details = array(),
		public readonly string $kid = ''
	) {}

	public static function signed( \stdClass $data, string $kid ): self {
		$code = true === ( $data->ok ?? null ) ? '' : ( is_string( $data->error->code ?? null ) ? sanitize_key( $data->error->code ) : 'error' );
		return new self( self::SIGNED, $data, $code, array(), $kid );
	}

	public static function refused( string $code, ?\stdClass $error ): self {
		$details = array();
		if ( is_string( $error->server_time ?? null ) ) {
			$details['server_time'] = $error->server_time;
		}
		return new self( self::REFUSED, null, $code, $details );
	}

	/** @param array<string, mixed> $details */
	public static function unavailable( string $code, array $details = array() ): self {
		return new self( self::UNAVAILABLE, null, $code, $details );
	}

	/** A signed «ok»: an authorization to keep. */
	public function is_authorization(): bool {
		return self::SIGNED === $this->kind && '' === $this->code;
	}

	/** The JSON exactly as it can be verified again later. */
	public function json(): string {
		return null === $this->data ? '' : (string) json_encode( $this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- stored as received.
	}
}
