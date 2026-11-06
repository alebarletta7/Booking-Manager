<?php
/**
 * Cloudflare API access (option bkng_cloudflare), as in Night Manager 0.16.3.
 *
 * The API token is best defined in wp-config.php (BKNG_CLOUDFLARE_API_TOKEN, with
 * BKNG_CLOUDFLARE_ZONE_ID): it then never reaches the database. A token typed in the
 * admin is stored encrypted (SecretBox, like the Turnstile secret) and never sent back:
 * only a masked hint.
 *
 * Nothing here decides the visitor's IP: ClientIp recognises Cloudflare by itself.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Cloudflare;

use BookingManager\Core\SecretBox;
use BookingManager\Core\Validation;

defined( 'ABSPATH' ) || exit;

class CloudflareSettings {

	public const OPTION = 'bkng_cloudflare';

	public const CONST_ZONE  = 'BKNG_CLOUDFLARE_ZONE_ID';
	public const CONST_TOKEN = 'BKNG_CLOUDFLARE_API_TOKEN';

	public function __construct( private SecretBox $box ) {}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'zone_id'          => '',
			'api_token'        => '',
			'rules_applied_at' => null,
		);
	}

	/** @return array<string, mixed> */
	private function stored(): array {
		$value = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $value ) ? $value : array() );
	}

	/** @param array<string, mixed> $stored */
	private function save( array $stored ): void {
		update_option( self::OPTION, $stored, false );
	}

	public function zone_id(): string {
		if ( defined( self::CONST_ZONE ) && is_string( constant( self::CONST_ZONE ) ) ) {
			return strtolower( trim( (string) constant( self::CONST_ZONE ) ) );
		}
		return (string) $this->stored()['zone_id'];
	}

	public function api_token(): string {
		if ( defined( self::CONST_TOKEN ) && is_string( constant( self::CONST_TOKEN ) ) ) {
			return trim( (string) constant( self::CONST_TOKEN ) );
		}
		$sealed = (string) $this->stored()['api_token'];
		$data   = '' === $sealed ? null : $this->box->open( $sealed );
		return (string) ( $data['token'] ?? '' );
	}

	public function api_ready(): bool {
		return self::valid_zone_id( $this->zone_id() ) && '' !== $this->api_token();
	}

	public function mark_applied( string $when ): void {
		$stored                     = $this->stored();
		$stored['rules_applied_at'] = $when;
		$this->save( $stored );
	}

	public static function valid_zone_id( string $zone ): bool {
		return (bool) preg_match( '/^[0-9a-f]{32}$/', $zone );
	}

	/** API tokens: letters, digits, "-" and "_" (40 characters today; some room for change). */
	public static function valid_token( string $token ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9_.-]{20,200}$/', $token );
	}

	/** "…a1b2", or null. */
	public static function hint( string $token ): ?string {
		return '' === $token ? null : '…' . substr( $token, -4 );
	}

	/**
	 * What the admin screen may see: never the token.
	 *
	 * @return array<string, mixed>
	 */
	public function public_view(): array {
		$token  = $this->api_token();
		$sealed = (string) $this->stored()['api_token'];
		return array(
			'zone_id'          => $this->zone_id(),
			'zone_id_source'   => defined( self::CONST_ZONE ) ? 'wp-config' : ( '' === $this->zone_id() ? null : 'settings' ),
			'api_token_hint'   => self::hint( $token ),
			'api_token_source' => defined( self::CONST_TOKEN ) ? 'wp-config' : ( '' === $token ? null : 'settings' ),
			// Saved but no longer readable (WordPress salts or BKNG_SECRET_KEY changed).
			'api_token_lost'   => ! defined( self::CONST_TOKEN ) && '' !== $sealed && '' === $token,
			'api_ready'        => $this->api_ready(),
			'rules_applied_at' => $this->stored()['rules_applied_at'],
		);
	}

	/**
	 * Saves the settings. An empty token leaves the stored one; clear_api_token removes it.
	 *
	 * @param array<string, mixed> $input {zone_id?, api_token?, clear_api_token?}
	 * @return array<string, mixed>|\WP_Error The public view.
	 */
	public function update( array $input ): array|\WP_Error {
		$stored = $this->stored();
		$errors = array();

		if ( array_key_exists( 'zone_id', $input ) ) {
			$zone = strtolower( trim( (string) $input['zone_id'] ) );
			if ( defined( self::CONST_ZONE ) ) {
				if ( $zone !== $this->zone_id() ) {
					$errors['zone_id'] = __( 'Lo Zone ID è definito in wp-config.php: cambialo lì.', 'booking-manager' );
				}
			} elseif ( '' !== $zone && ! self::valid_zone_id( $zone ) ) {
				$errors['zone_id'] = __( 'Zone ID non valido: sono 32 caratteri (cifre e lettere da a a f), li trovi nella pagina Panoramica del dominio su Cloudflare.', 'booking-manager' );
			} else {
				$stored['zone_id'] = $zone;
			}
		}
		if ( ! empty( $input['clear_api_token'] ) && ! defined( self::CONST_TOKEN ) ) {
			$stored['api_token'] = '';
		}
		$token = trim( (string) ( $input['api_token'] ?? '' ) );
		if ( '' !== $token ) {
			if ( defined( self::CONST_TOKEN ) ) {
				$errors['api_token'] = __( 'Il token è definito in wp-config.php: cambialo lì.', 'booking-manager' );
			} elseif ( ! self::valid_token( $token ) ) {
				$errors['api_token'] = __( 'Token non valido: copialo per intero dalla pagina «Token API» di Cloudflare.', 'booking-manager' );
			} else {
				$stored['api_token'] = $this->box->seal( array( 'token' => $token ) );
			}
		}
		if ( $errors ) {
			return Validation::error( $errors );
		}
		$this->save( $stored );
		return $this->public_view();
	}
}
