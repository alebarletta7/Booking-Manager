<?php
/**
 * Optional Cloudflare Turnstile check on the public booking form (same scheme as Night
 * Manager). Active only when both keys are set in Impostazioni; the secret key is stored
 * encrypted (SecretBox) and never sent to a browser or written to a log.
 *
 * Without keys the form is still protected by the honeypot field, the minimum filling
 * time and the per-IP rate limits (PublicBookingService).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Frontend;

use BookingManager\Core\SecretBox;
use BookingManager\Core\Validation;

defined( 'ABSPATH' ) || exit;

class Captcha {

	public const OPTION     = 'bkng_captcha';
	public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	public const ACTION     = 'bkng_booking';

	public function __construct( private SecretBox $box ) {}

	public function site_key(): string {
		$o = get_option( self::OPTION, array() );
		return is_array( $o ) ? (string) ( $o['site_key'] ?? '' ) : '';
	}

	private function secret(): string {
		$o    = get_option( self::OPTION, array() );
		$data = is_array( $o ) && ! empty( $o['secret'] ) ? $this->box->open( (string) $o['secret'] ) : null;
		return (string) ( $data['secret'] ?? '' );
	}

	public function enabled(): bool {
		return '' !== $this->site_key() && '' !== $this->secret();
	}

	/**
	 * Saves the keys. $secret null keeps the stored one, '' removes it.
	 */
	public function configure( string $site_key, ?string $secret ): void {
		$o             = get_option( self::OPTION, array() );
		$o             = is_array( $o ) ? $o : array();
		$o['site_key'] = substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $site_key ) ?? '', 0, 100 );
		if ( null !== $secret ) {
			$secret      = trim( $secret );
			$o['secret'] = '' === $secret ? '' : $this->box->seal( array( 'secret' => $secret ) );
		}
		update_option( self::OPTION, $o, false );
	}

	/** @return array{site_key: string, secret_set: bool, enabled: bool} */
	public function admin_view(): array {
		return array(
			'site_key'   => $this->site_key(),
			'secret_set' => '' !== $this->secret(),
			'enabled'    => $this->enabled(),
		);
	}

	/** @return array{provider: string, site_key: string, action: string}|null */
	public function public_config(): ?array {
		return $this->enabled()
			? array(
				'provider' => 'turnstile',
				'site_key' => $this->site_key(),
				'action'   => self::ACTION,
			)
			: null;
	}

	/** True when the captcha is off or the token is valid. */
	public function verify( string $token, string $ip ): bool|\WP_Error {
		if ( ! $this->enabled() ) {
			return true;
		}
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return self::failed();
		}
		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $this->secret(),
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'bkng_captcha_unavailable', __( 'Verifica anti-robot non disponibile. Riprova tra poco.', 'booking-manager' ), array( 'status' => 503 ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return self::accepts( is_array( $data ) ? $data : array(), (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ? true : self::failed();
	}

	/**
	 * Reads Turnstile's answer (unit-tested): success, our action, our site.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function accepts( array $data, string $host ): bool {
		if ( true !== ( $data['success'] ?? false ) ) {
			return false;
		}
		if ( isset( $data['action'] ) && self::ACTION !== $data['action'] ) {
			return false;
		}
		return ! isset( $data['hostname'] ) || '' === $host || strtolower( (string) $data['hostname'] ) === strtolower( $host );
	}

	private static function failed(): \WP_Error {
		return Validation::error( array( 'captcha' => __( 'Conferma di non essere un robot e riprova.', 'booking-manager' ) ) );
	}
}
