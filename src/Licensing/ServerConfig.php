<?php
/**
 * Where the licence server is, and the public keys that prove an answer comes from it.
 *
 * Nothing here is secret: the address and the public keys are the same in every copy of
 * the plugin. Each customer is told apart by their own licence key.
 *
 * The public keys (kid k1 = primary, k2 = backup kept offline) are written in
 * PUBLIC_KEYS below, in the release, exactly as Beach Manager does: the licence server
 * signs with one key pair for every product. Nothing has to be added to wp-config.php:
 * a fresh install talks to the server and is locked until the licence is entered.
 *
 * Only a site declared as a test one (WP_ENVIRONMENT_TYPE = local or development) may
 * point to another server from wp-config.php; on any other site those lines are
 * ignored, otherwise one line in wp-config.php would be enough to trust a fake server:
 *
 *   define( 'BKNG_LICENSE_SERVER', 'http://licenze.test' );
 *   define( 'BKNG_LICENSE_PUBLIC_KEYS', 'k1:BASE64,k2:BASE64' );
 *
 * On a test site, BKNG_LICENSE_PUBLIC_KEYS with no valid key leaves the licence module
 * «non configurato» (it blocks nothing): for development only.
 *
 * http:// is accepted only on a local/development site.
 *
 * From 0.14.1 (security fix NM-01) the environment type alone is not enough, because it
 * is one more line of wp-config.php: the site's own address must also be a test one
 * that nobody can reach from the internet (localhost, 127.x, *.local, *.test,
 * *.localhost, *.invalid, *.example), the same reserved names the licence server uses.
 * Elsewhere the constants are ignored, «non configurato» included.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

defined( 'ABSPATH' ) || exit;

final class ServerConfig {

	/** The licence server (the same host as Beach Manager's). */
	public const URL = 'https://licenze.alessandrobarletta.it';

	/**
	 * kid => Ed25519 public key in base64: k1 the primary one, k2 the backup kept offline.
	 * The same values as Beach Manager's ServerConfig::PUBLIC_KEYS (same server).
	 *
	 * @var array<string, string>
	 */
	public const PUBLIC_KEYS = array(
		'k1' => 'gBx5QI/noDX4WYWyvvY8CzUs+KMMvxJ98L+R7tcjDtY=',
		'k2' => 'VdfFb5WnMj8w4RvGnan88UTKPFGTymiE0DomYQeHHNs=',
	);

	public const CONST_SERVER = 'BKNG_LICENSE_SERVER';
	public const CONST_KEYS   = 'BKNG_LICENSE_PUBLIC_KEYS';

	/**
	 * @param array<string, string> $public_keys kid => base64
	 * @param string                $source      release | wp-config | none (where the keys come from)
	 */
	public function __construct( public readonly string $url, public readonly array $public_keys, public readonly string $source = 'release' ) {}

	/** Endings reserved for tests and local machines (RFC 2606 / 6761, mDNS). */
	private const TEST_SUFFIXES = array( 'localhost', 'local', 'test', 'invalid', 'example' );

	/** The values of this release, with the wp-config.php constants where allowed. */
	public static function from_environment( ?string $environment = null, ?string $home = null ): self {
		$environment ??= function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$home        ??= function_exists( 'home_url' ) ? home_url() : '';
		return self::resolve(
			$environment,
			self::URL,
			self::PUBLIC_KEYS,
			defined( self::CONST_SERVER ) ? constant( self::CONST_SERVER ) : null,
			defined( self::CONST_KEYS ) ? constant( self::CONST_KEYS ) : null,
			LicenseState::domain_of( $home )
		);
	}

	/** localhost, 127.x or a reserved ending: a copy nobody outside this machine or LAN reaches. */
	public static function is_local_host( string $host ): bool {
		if ( 'localhost' === $host || 1 === preg_match( '/^127\.\d+\.\d+\.\d+$/D', $host ) ) {
			return true;
		}
		$labels = explode( '.', $host );
		return count( $labels ) > 1 && in_array( end( $labels ), self::TEST_SUFFIXES, true );
	}

	/**
	 * The pure rule (tested): which address and keys to use. With release keys only a
	 * test site may override them; a release without keys (only in tests, or a custom
	 * build) takes them from the constants on any site.
	 *
	 * @param array<string, string> $builtin_keys
	 * @param mixed                 $const_url  BKNG_LICENSE_SERVER, null when not defined
	 * @param mixed                 $const_keys BKNG_LICENSE_PUBLIC_KEYS, null when not defined
	 * @param string                $site_host  this site's host (home_url), '' when unknown
	 */
	public static function resolve( string $environment, string $builtin_url, array $builtin_keys, $const_url, $const_keys, string $site_host = '' ): self {
		// A test site is declared so AND lives on a test address (security fix NM-01).
		$test_site = in_array( $environment, array( 'local', 'development' ), true ) && self::is_local_host( $site_host );
		$builtin   = self::parse_keys( $builtin_keys );
		// Release keys win on a real site: wp-config.php cannot swap the trusted server.
		$overridable = $test_site || array() === $builtin;
		$url         = $builtin_url;
		$keys        = $builtin;
		$source      = array() === $builtin ? 'none' : 'release';
		if ( $overridable ) {
			if ( is_string( $const_url ) && '' !== trim( $const_url ) ) {
				$url = $const_url;
			}
			if ( null !== $const_keys ) {
				$keys   = self::parse_keys( $const_keys );
				$source = array() === $keys ? 'none' : 'wp-config';
			}
		}
		return new self( self::clean_url( $url, $test_site ), $keys, $source );
	}

	/**
	 * "k1:BASE64,k2:BASE64", or an array kid => base64. Malformed pairs are dropped.
	 *
	 * @param mixed $value
	 * @return array<string, string>
	 */
	public static function parse_keys( $value ): array {
		$pairs = array();
		if ( is_string( $value ) ) {
			foreach ( explode( ',', $value ) as $part ) {
				$bits = explode( ':', trim( $part ), 2 );
				if ( 2 === count( $bits ) ) {
					$pairs[ trim( $bits[0] ) ] = trim( $bits[1] );
				}
			}
		} elseif ( is_array( $value ) ) {
			$pairs = $value;
		}
		$keys = array();
		foreach ( $pairs as $kid => $key ) {
			if ( is_string( $kid ) && preg_match( '/^[a-z0-9]{1,8}$/', $kid ) && is_string( $key ) && self::is_public_key( $key ) ) {
				$keys[ $kid ] = $key;
			}
		}
		return $keys;
	}

	public static function is_public_key( string $key ): bool {
		$raw = base64_decode( $key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- public key.
		return false !== $raw && 32 === strlen( $raw );
	}

	/** An https address without the final slash; http only on a test site. '' when unusable. */
	private static function clean_url( string $url, bool $allow_http ): string {
		$url    = rtrim( trim( $url ), '/' );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host || ! ( 'https' === $scheme || ( $allow_http && 'http' === $scheme ) ) ) {
			return '';
		}
		return $url;
	}

	public function is_configured(): bool {
		return '' !== $this->url && array() !== $this->public_keys;
	}

	/** Full address of an API path such as /api/v1/license/validate. */
	public function endpoint( string $path ): string {
		return $this->url . $path;
	}

	/** Shown in the Licenza panel: just the host name. */
	public function host(): string {
		return (string) wp_parse_url( $this->url, PHP_URL_HOST );
	}
}
