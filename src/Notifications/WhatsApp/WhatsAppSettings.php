<?php
/**
 * WhatsApp configuration (option bkng_whatsapp), like Night Manager's: one for the whole
 * site, because Booking Manager sends from one WhatsApp Business number for all its
 * properties (the property's name, address and phone go in the text).
 *
 *   provider      meta | test (WhatsAppProvider ids)
 *   active        «I messaggi WhatsApp partono da soli»
 *   country_code  for numbers written without one ("39")
 *   language      language of the texts sent for approval ("it")
 *   account       what the provider said we are connected to ("Hotel Rossi (+39 …)")
 *   identity      provider|business account: another account means new approvals
 *   credentials   the provider's fields + verify_token, sealed by SecretBox; never sent back
 *   texts         {key: {body, is_active, button?}}: the texts changed from the default
 *   templates     {key: {version, latest{name, text, params, button, status, reason, at}, approved{…}}}
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\WhatsApp;

use BookingManager\Core\SecretBox;

defined( 'ABSPATH' ) || exit;

class WhatsAppSettings {

	public const OPTION = 'bkng_whatsapp';

	/** @var array<string, WhatsAppProvider>|null */
	private ?array $providers = null;

	public function __construct( private SecretBox $box ) {}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'provider'     => '',
			'active'       => false,
			'country_code' => '39',
			'language'     => 'it',
			'account'      => '',
			'identity'     => '',
			'credentials'  => '',
			'texts'        => array(),
			'templates'    => array(),
		);
	}

	/**
	 * What is stored (credentials still sealed).
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$value  = get_option( self::OPTION, array() );
		$config = array_merge( self::defaults(), is_array( $value ) ? $value : array() );
		foreach ( array( 'texts', 'templates' ) as $key ) {
			$config[ $key ] = array_filter( is_array( $config[ $key ] ) ? $config[ $key ] : array(), 'is_array' );
		}
		return $config;
	}

	/** @param array<string, mixed> $config */
	public function save( array $config ): void {
		update_option( self::OPTION, array_intersect_key( $config, self::defaults() ), false );
	}

	/** @return array<string, WhatsAppProvider> */
	public function providers(): array {
		if ( null === $this->providers ) {
			/**
			 * WhatsApp providers available (objects implementing WhatsAppProvider), by id.
			 *
			 * @param array<string, WhatsAppProvider> $providers
			 */
			$list            = (array) apply_filters(
				'bkng_whatsapp_providers',
				array(
					MetaCloudProvider::ID => new MetaCloudProvider(),
					TestProvider::ID      => new TestProvider(),
				)
			);
			$this->providers = array_filter( $list, static fn( $p ) => $p instanceof WhatsAppProvider );
		}
		return $this->providers;
	}

	/** @param array<string, mixed> $config */
	public function provider_for( array $config ): ?WhatsAppProvider {
		return $this->providers()[ (string) ( $config['provider'] ?? '' ) ] ?? null;
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<string, string>|null null when unreadable (the site's keys changed)
	 */
	public function credentials( array $config ): ?array {
		$open = $this->box->open( (string) $config['credentials'] );
		return null === $open ? null : array_map( 'strval', $open );
	}

	/** @param array<string, string> $credentials */
	public function seal( array $credentials ): string {
		return $this->box->seal( $credentials );
	}

	/**
	 * Connected: a provider and the account it confirmed, with readable credentials.
	 *
	 * @param array<string, mixed>|null $config
	 */
	public function connected( ?array $config = null ): bool {
		$config ??= $this->config();
		return null !== $this->provider_for( $config ) && '' !== (string) $config['account'] && null !== $this->credentials( $config );
	}

	/** Connected and switched on: messages are queued and sent. */
	public function active(): bool {
		$config = $this->config();
		return ! empty( $config['active'] ) && $this->connected( $config );
	}

	public function country_code(): string {
		return (string) $this->config()['country_code'];
	}

	/**
	 * A guest's phone as WhatsApp wants it: digits only, with the country code
	 * (unit-tested). "+39 333 1234567" → "393331234567"; "3331234567" → "393331234567"
	 * with country code 39; null when it cannot be a mobile number.
	 */
	public static function wa_phone( ?string $phone, string $country_code ): ?string {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return null;
		}
		$plus   = str_starts_with( $phone, '+' ) || str_starts_with( $phone, '00' );
		$digits = (string) preg_replace( '/\D/', '', $phone );
		if ( str_starts_with( $phone, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		// A national number (Italian mobiles have 10 digits, others start with 0) gets the country code.
		if ( ! $plus && ( strlen( $digits ) <= 10 || str_starts_with( $digits, '0' ) ) ) {
			// Italy keeps the leading 0 of landlines; elsewhere the national 0 is dropped.
			$digits = $country_code . ( '39' === $country_code ? $digits : ltrim( $digits, '0' ) );
		}
		return preg_match( '/^[1-9]\d{7,14}$/', $digits ) ? $digits : null;
	}

	public static function valid_country_code( string $code ): bool {
		return (bool) preg_match( '/^[1-9]\d{0,3}$/', $code );
	}
}
