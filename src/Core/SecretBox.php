<?php
/**
 * Encryption of provider credentials at rest (WhatsApp and future channels) (libsodium secretbox, authenticated).
 *
 * The key comes from the BKNG_SECRET_KEY constant in wp-config.php when defined (recommended:
 * keeps working if the WordPress salts change, never stored in the database). Without it the
 * key is derived from the WordPress authentication salts. Secrets are never written to the
 * database in clear, never returned by the REST API and never logged.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

class SecretBox {

	private const PREFIX = 'rm1:';

	private const NONCE_BYTES = 24; // crypto_secretbox nonce.
	private const KEY_BYTES   = 32; // crypto_secretbox key.

	/**
	 * Many local stacks (e.g. XAMPP on Windows) ship PHP without the sodium extension
	 * enabled: WordPress bundles the sodium_compat polyfill, loaded here when needed.
	 */
	private static function load_sodium(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) && file_exists( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
			require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
		}
	}

	/** Is a dedicated key configured in wp-config.php? */
	public static function has_dedicated_key(): bool {
		return defined( 'BKNG_SECRET_KEY' ) && is_string( BKNG_SECRET_KEY ) && strlen( BKNG_SECRET_KEY ) >= 32;
	}

	/** @param array<string, mixed> $data */
	public function seal( array $data ): string {
		self::load_sodium();
		$nonce = random_bytes( self::NONCE_BYTES );
		$box   = sodium_crypto_secretbox( (string) wp_json_encode( $data ), $nonce, $this->key() );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext.
	}

	/**
	 * @return array<string, mixed>|null null when the value cannot be decrypted (key changed, tampered)
	 */
	public function open( ?string $sealed ): ?array {
		if ( null === $sealed || '' === $sealed ) {
			return array();
		}
		if ( ! str_starts_with( $sealed, self::PREFIX ) ) {
			return null;
		}
		self::load_sodium();
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext.
		if ( false === $raw || strlen( $raw ) <= self::NONCE_BYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, self::NONCE_BYTES ), substr( $raw, 0, self::NONCE_BYTES ), $this->key() );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( false === $plain ) {
			return null;
		}
		$data = json_decode( $plain, true );
		return is_array( $data ) ? $data : null;
	}

	private function key(): string {
		$material = self::has_dedicated_key() ? (string) BKNG_SECRET_KEY : wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( 'booking-manager-secrets|' . $material, '', self::KEY_BYTES );
	}
}
