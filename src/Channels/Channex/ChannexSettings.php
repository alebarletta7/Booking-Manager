<?php
/**
 * Channex configuration (option bkng_channex, one for the site; not autoloaded).
 *
 * Since 0.2.6 every property can have its own connection (option bkng_channex_p{ID}:
 * key, webhook secret, environment, switch, state), decision D3. A property without one
 * uses the site's connection only if it belongs to the default customer (or the site
 * has no customers): the properties of the other customers need their own, and their
 * plan must include Channex. Which property is read is set with scoped().
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
 * BKNG_CHANNEX_BASE_URL in wp-config.php win over the stored values of the site's
 * connection (recommended: the key never reaches the database); they never apply to a
 * property's own connection.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

use BookingManager\Core\SecretBox;
use BookingManager\Tenants\Plans;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantRepository;

defined( 'ABSPATH' ) || exit;

class ChannexSettings {

	public const OPTION = 'bkng_channex';

	/** Key of the channel in channel_refs and sync_log. */
	public const CHANNEL = 'channex';

	public const STAGING_URL = 'https://staging.channex.io/api/v1';

	/** Option of a property's own connection: bkng_channex_p{ID}. */
	public const PROPERTY_OPTION = 'bkng_channex_p';

	/** The property being worked on (0: the site's connection, as before 0.2.6). */
	private int $property = 0;

	/** The property's own connection is read and written (not the site's). */
	private bool $own = false;

	public function __construct( private SecretBox $box, private ?Plans $plans = null, private ?TenantRepository $tenants = null ) {}

	/**
	 * Runs $fn with the connection of a property: its own, or the site's when it may share
	 * it. $own = true always works on its own (the screen that sets it up).
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public function scoped( int $property_id, callable $fn, bool $own = false ): mixed {
		$previous = array( $this->property, $this->own );
		$this->property = max( 0, $property_id );
		$this->own      = $this->property > 0 && ( $own || $this->has_own( $this->property ) || ! self::may_share( $this->property ) );
		try {
			return $fn();
		} finally {
			list( $this->property, $this->own ) = $previous;
		}
	}

	public function current_property(): int {
		return $this->property;
	}

	/** The connection read now is a property's own one. */
	public function is_own(): bool {
		return $this->own;
	}

	public function has_own( int $property_id ): bool {
		return $property_id > 0 && is_array( get_option( self::PROPERTY_OPTION . $property_id, null ) );
	}

	/** The site's connection is for the default customer's properties only. */
	public static function may_share( int $property_id ): bool {
		return ! Tenancy::ready() || Tenancy::of_property( $property_id ) === Tenancy::default_id();
	}

	/** The plan of the property's customer includes Channex (always for the site's connection). */
	public function allowed(): bool {
		if ( 0 === $this->property || ! $this->plans || ! $this->tenants || ! Tenancy::ready() ) {
			return true;
		}
		$tenant_id = Tenancy::of_property( $this->property );
		if ( $tenant_id === Tenancy::default_id() ) {
			return true;
		}
		$tenant = $this->tenants->find( $tenant_id );
		return $tenant && ! empty( $this->plans->features_of( $tenant )['channex'] );
	}

	private function option(): string {
		return $this->own ? self::PROPERTY_OPTION . $this->property : self::OPTION;
	}

	/** Removes a property's own connection (back to the site's, where allowed). */
	public function forget_own( int $property_id ): void {
		delete_option( self::PROPERTY_OPTION . $property_id );
	}

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
		$value = get_option( $this->option(), array() );
		return array_merge( self::defaults(), is_array( $value ) ? array_intersect_key( $value, self::defaults() ) : array() );
	}

	/** @param array<string, mixed> $changes */
	public function save( array $changes ): void {
		$config = array_merge( $this->config(), array_intersect_key( $changes, self::defaults() ) );
		if ( false === get_option( $this->option() ) ) {
			add_option( $this->option(), $config, '', false );
			return;
		}
		update_option( $this->option(), $config, false );
	}

	/** @return array{api_key: string, webhook_secret: string} */
	public function credentials(): array {
		$open = (array) ( $this->box->open( (string) $this->config()['credentials'] ) ?? array() );
		return array(
			'api_key'        => $this->site_constant( 'BKNG_CHANNEX_API_KEY' ) ?? (string) ( $open['api_key'] ?? '' ),
			'webhook_secret' => $this->site_constant( 'BKNG_CHANNEX_WEBHOOK_SECRET' ) ?? (string) ( $open['webhook_secret'] ?? '' ),
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
		$constant = $this->site_constant( 'BKNG_CHANNEX_BASE_URL' );
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
		return $this->allowed() && '' !== $this->api_key() && '' === (string) $this->config()['suspended'];
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
			'api_key_constant'       => null !== $this->site_constant( 'BKNG_CHANNEX_API_KEY' ),
			'has_webhook_secret'     => '' !== $creds['webhook_secret'],
			'webhook_secret_constant' => null !== $this->site_constant( 'BKNG_CHANNEX_WEBHOOK_SECRET' ),
			'base_url_constant'      => null !== $this->site_constant( 'BKNG_CHANNEX_BASE_URL' ),
			// 0.2.6: whose connection this is, and whether the plan allows it.
			'scope'                  => $this->own ? 'property' : 'site',
			'allowed'                => $this->allowed(),
		);
	}

	/** A wp-config.php constant: only for the site's connection. */
	private function site_constant( string $name ): ?string {
		return $this->own ? null : self::constant( $name );
	}

	private static function constant( string $name ): ?string {
		return defined( $name ) && is_string( constant( $name ) ) && '' !== constant( $name ) ? (string) constant( $name ) : null;
	}
}
