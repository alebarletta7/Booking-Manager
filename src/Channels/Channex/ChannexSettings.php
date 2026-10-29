<?php
/**
 * Channex configuration (option bkng_channex, one for the site; not autoloaded).
 *
 *   active       the sync runs (sends, reads reservations)
 *   environment  staging | production
 *   base_url     production address given by Channex after the certification
 *   credentials  {api_key, webhook_secret} sealed by SecretBox; never sent back
 *   webhook_id   id of the webhook created on Channex
 *   nightly      full sync once a night (between 2 and 5, property by property)
 *   suspended    set when Channex answers 401: the sync stops until the key is saved again
 *
 * The constants BKNG_CHANNEX_API_KEY, BKNG_CHANNEX_WEBHOOK_SECRET and
 * BKNG_CHANNEX_BASE_URL in wp-config.php win over the stored values (recommended: the
 * key never reaches the database).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

use BookingManager\Core\SecretBox;

defined( 'ABSPATH' ) || exit;

class ChannexSettings {

	public const OPTION = 'bkng_channex';

	/** Key of the channel in channel_refs and sync_log. */
	public const CHANNEL = 'channex';

	public const STAGING_URL = 'https://staging.channex.io/api/v1';

	public function __construct( private SecretBox $box ) {}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'active'      => false,
			'environment' => 'staging',
			'base_url'    => '',
			'credentials' => '',
			'webhook_id'  => '',
			'nightly'     => true,
			'suspended'   => '',
		);
	}

	/** @return array<string, mixed> */
	public function config(): array {
		$value = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $value ) ? array_intersect_key( $value, self::defaults() ) : array() );
	}

	/** @param array<string, mixed> $changes */
	public function save( array $changes ): void {
		$config = array_merge( $this->config(), array_intersect_key( $changes, self::defaults() ) );
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $config, '', false );
			return;
		}
		update_option( self::OPTION, $config, false );
	}

	/** @return array{api_key: string, webhook_secret: string} */
	public function credentials(): array {
		$open = (array) ( $this->box->open( (string) $this->config()['credentials'] ) ?? array() );
		return array(
			'api_key'        => self::constant( 'BKNG_CHANNEX_API_KEY' ) ?? (string) ( $open['api_key'] ?? '' ),
			'webhook_secret' => self::constant( 'BKNG_CHANNEX_WEBHOOK_SECRET' ) ?? (string) ( $open['webhook_secret'] ?? '' ),
		);
	}

	/**
	 * Stores new credentials; an empty value keeps the old one, null removes it.
	 *
	 * @param array<string, string|null> $values api_key, webhook_secret
	 */
	public function save_credentials( array $values ): void {
		$open = (array) ( $this->box->open( (string) $this->config()['credentials'] ) ?? array() );
		foreach ( array( 'api_key', 'webhook_secret' ) as $key ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			if ( null === $values[ $key ] ) {
				unset( $open[ $key ] );
			} elseif ( '' !== trim( (string) $values[ $key ] ) ) {
				$open[ $key ] = trim( (string) $values[ $key ] );
			}
		}
		$this->save( array( 'credentials' => $this->box->seal( $open ) ) );
	}

	public function api_key(): string {
		return $this->credentials()['api_key'];
	}

	public function webhook_secret(): string {
		return $this->credentials()['webhook_secret'];
	}

	/** Address of the API: the constant, the production address saved, or staging. */
	public function base_url(): string {
		$constant = self::constant( 'BKNG_CHANNEX_BASE_URL' );
		if ( null !== $constant ) {
			return rtrim( $constant, '/' );
		}
		$config = $this->config();
		if ( 'production' === $config['environment'] && '' !== (string) $config['base_url'] ) {
			return rtrim( (string) $config['base_url'], '/' );
		}
		return self::STAGING_URL;
	}

	/** Key present and not refused by Channex. */
	public function connected(): bool {
		return '' !== $this->api_key() && '' === (string) $this->config()['suspended'];
	}

	/** Switched on and connected: changes are queued and sent, reservations read. */
	public function active(): bool {
		return ! empty( $this->config()['active'] ) && $this->connected();
	}

	/**
	 * What the screen may see: never the secrets, only whether they are set and where from.
	 *
	 * @return array<string, mixed>
	 */
	public function view(): array {
		$config = $this->config();
		$creds  = $this->credentials();
		return array(
			'active'                 => (bool) $config['active'],
			'environment'            => (string) $config['environment'],
			'base_url'               => (string) $config['base_url'],
			'effective_url'          => $this->base_url(),
			'nightly'                => (bool) $config['nightly'],
			'suspended'              => (string) $config['suspended'],
			'webhook_id'             => (string) $config['webhook_id'],
			'has_api_key'            => '' !== $creds['api_key'],
			'api_key_hint'           => '' !== $creds['api_key'] ? '…' . substr( $creds['api_key'], -4 ) : '',
			'api_key_constant'       => null !== self::constant( 'BKNG_CHANNEX_API_KEY' ),
			'has_webhook_secret'     => '' !== $creds['webhook_secret'],
			'webhook_secret_constant' => null !== self::constant( 'BKNG_CHANNEX_WEBHOOK_SECRET' ),
			'base_url_constant'      => null !== self::constant( 'BKNG_CHANNEX_BASE_URL' ),
		);
	}

	private static function constant( string $name ): ?string {
		return defined( $name ) && is_string( constant( $name ) ) && '' !== constant( $name ) ? (string) constant( $name ) : null;
	}
}
