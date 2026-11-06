<?php
/**
 * Ed25519 both ways, byte for byte as the licence server does it
 * (src/Security/RequestSignature.php, Signer.php and CanonicalJson.php on the server).
 *
 * - Requests: signed with the installation's private key on
 *   METHOD \n PATH \n TIMESTAMP \n REQUEST-ID \n SHA256(body)
 *   PATH is always the API path (/api/v1/license/validate), wherever the server lives.
 * - Answers: the server signs the canonical JSON of the whole answer without its
 *   "signature" block (object keys in alphabetical order at every level, lists in their
 *   order, no escaped slashes or accents, no decimal numbers). The plugin rebuilds the
 *   same bytes from the decoded answer and checks them with the public key named by kid.
 *
 * Answers are decoded as objects (json_decode without associative arrays), so an empty
 * object {} and an empty list [] stay apart, as the server signed them.
 *
 * Without the sodium extension WordPress' own sodium_compat is used.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

defined( 'ABSPATH' ) || exit;

final class Signature {

	private static function load_sodium(): void {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) && defined( 'WPINC' ) && file_exists( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
			require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
		}
	}

	/**
	 * A new key pair for one activation.
	 *
	 * @return array{public: string, secret: string} both in base64
	 */
	public static function new_key_pair(): array {
		self::load_sodium();
		$pair = sodium_crypto_sign_keypair();
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary keys.
		return array(
			'public' => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
			'secret' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ),
		);
		// phpcs:enable
	}

	/** The text a request signature covers. */
	public static function request_text( string $method, string $path, int $timestamp, string $request_id, string $body ): string {
		return strtoupper( $method ) . "\n" . $path . "\n" . $timestamp . "\n" . $request_id . "\n" . hash( 'sha256', $body );
	}

	/**
	 * Base64 signature of a request (the X-BM-Signature header).
	 *
	 * @throws \InvalidArgumentException when the installation key is not a 64-byte secret key
	 */
	public static function sign_request( string $secret_b64, string $method, string $path, int $timestamp, string $request_id, string $body ): string {
		self::load_sodium();
		$secret = base64_decode( $secret_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key.
		if ( false === $secret || 64 !== strlen( $secret ) ) {
			throw new \InvalidArgumentException( 'Invalid installation key' );
		}
		return base64_encode( sodium_crypto_sign_detached( self::request_text( $method, $path, $timestamp, $request_id, $body ), $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary signature.
	}

	/**
	 * Is this decoded answer signed by one of the keys?
	 *
	 * @param array<string, string> $public_keys kid => base64
	 * @return string|null the kid that signed it
	 */
	public static function verify_answer( \stdClass $answer, array $public_keys ): ?string {
		$block = $answer->signature ?? null;
		if ( ! $block instanceof \stdClass || ! is_string( $block->kid ?? null ) || ! is_string( $block->sig ?? null ) || ! isset( $public_keys[ $block->kid ] ) ) {
			return null;
		}
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary key and signature.
		$sig = base64_decode( $block->sig, true );
		$key = base64_decode( $public_keys[ $block->kid ], true );
		// phpcs:enable
		if ( false === $sig || 64 !== strlen( $sig ) || false === $key || 32 !== strlen( $key ) ) {
			return null;
		}
		$data = clone $answer;
		unset( $data->signature );
		try {
			self::load_sodium();
			return sodium_crypto_sign_verify_detached( $sig, self::canonical( $data ), $key ) ? $block->kid : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The bytes the server signed.
	 *
	 * @param mixed $data
	 * @throws \InvalidArgumentException on a decimal number, which signed answers never carry
	 */
	public static function canonical( $data ): string {
		// Plain json_encode with the server's flags: the bytes must be exactly the same.
		return (string) json_encode( self::sort( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function sort( $value ) {
		if ( is_float( $value ) ) {
			throw new \InvalidArgumentException( 'Signed data cannot contain decimal numbers' );
		}
		if ( $value instanceof \stdClass ) {
			$props = get_object_vars( $value );
			ksort( $props, SORT_STRING );
			$out = new \stdClass();
			foreach ( $props as $k => $v ) {
				$out->{(string) $k} = self::sort( $v );
			}
			return $out;
		}
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				return array_map( array( self::class, 'sort' ), $value );
			}
			// An associative array (built locally, not decoded) is an object, as on the server.
			ksort( $value, SORT_STRING );
			$out = new \stdClass();
			foreach ( $value as $k => $v ) {
				$out->{(string) $k} = self::sort( $v );
			}
			return $out;
		}
		return $value;
	}
}
