<?php
/**
 * New versions of Booking Manager, from the licence server.
 *
 * The plugin is not on wordpress.org. Its header says
 * "Update URI: https://licenze.alessandrobarletta.it/booking-manager": WordPress never asks
 * wordpress.org about it (somebody else's plugin with the same folder name is never
 * offered) and leaves the offer to the filters below. With the licence module
 * configured and this site activated, update/check (channel stable) says the newest
 * version this site may install and the newest one published. It is offered through the
 * usual Plugins screen, with the changelog in «Visualizza dettagli».
 *
 * The package is fetched only at «Aggiorna ora», with a one-time permit
 * (update/download-token), from the licence server's own address, and handed to
 * WordPress only if its SHA-256 and size are the ones the server signed. A newer version
 * the licence does not cover is shown with «rinnova la licenza» and no package.
 *
 * Any failure (server down, bad signature, licence to reconnect) offers nothing: no
 * error is shown, the next check tries again (12 hours, 1 hour after a failure).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Updates;

use BookingManager\Licensing\LicenseManager;
use BookingManager\Licensing\ServerAnswer;

defined( 'ABSPATH' ) || exit;

class UpdateChecker {

	public const TRANSIENT = 'bkng_update_server';
	public const TTL       = 12 * 3600;
	/** A server that did not answer is asked again sooner. */
	public const RETRY = 3600;
	/** The package of a version from the licence server: «bkng-license:1.2.3». */
	public const PACKAGE = 'bkng-license:';
	public const CHANNEL = 'stable';
	/** The «Update URI» of the plugin header. */
	public const UPDATE_URI = 'https://licenze.alessandrobarletta.it/booking-manager';

	private const VERSION = '/^\d+(\.\d+){0,3}(-[0-9A-Za-z.]+)?$/';

	/** «Controlla di nuovo» in Bacheca → Aggiornamenti already asked again in this request. */
	private bool $refreshed = false;

	/** The version in the plugin file, read once per request (see installed()). */
	private static ?string $installed = null;

	public function __construct( private LicenseManager $licenses ) {}

	public function hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'offer' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'offer' ) );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 4 );
		add_action( 'in_plugin_update_message-' . self::basename(), array( $this, 'renew_message' ), 10, 2 );
		// Before WordPress asks again (wp_update_plugins, priority 10), so it sees the new version.
		add_action( 'upgrader_process_complete', array( $this, 'forget' ), 1, 2 );
	}

	/** @return array<string, string> the logo, for the WordPress Updates screen */
	public static function icons(): array {
		return array(
			'1x'  => plugins_url( 'assets/images/icon-128x128.png', BKNG_FILE ),
			'2x'  => plugins_url( 'assets/images/icon-256x256.png', BKNG_FILE ),
			'svg' => plugins_url( 'assets/images/logo.svg', BKNG_FILE ),
		);
	}

	public static function basename(): string {
		return plugin_basename( BKNG_FILE );
	}

	/**
	 * The version installed now. Not BKNG_VERSION: in the request that installs an update
	 * the constant still holds the old version.
	 */
	public static function installed(): string {
		if ( null === self::$installed ) {
			$version         = defined( 'BKNG_FILE' ) && function_exists( 'get_file_data' ) ? (string) ( get_file_data( BKNG_FILE, array( 'version' => 'Version' ) )['version'] ?? '' ) : '';
			self::$installed = '' !== $version ? $version : ( defined( 'BKNG_VERSION' ) ? BKNG_VERSION : '' );
		}
		return self::$installed;
	}

	/** Updates come from the licence server only on a configured, activated site. */
	public function enabled(): bool {
		return $this->licenses->is_configured() && '' !== $this->licenses->activation_id() && ! $this->licenses->requires_recovery();
	}

	/**
	 * Filter (pre_set_)site_transient_update_plugins: Booking Manager is always in what
	 * WordPress knows about updates. With a newer version on the licence server it is in
	 * «response»; otherwise (nothing newer, licence not activated yet, server not
	 * answering) it is in «no_update» with the installed version. Only plugins in one of
	 * the two get the «Abilita gli aggiornamenti automatici» toggle and «Visualizza i
	 * dettagli» in the Plugins screen.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function offer( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$file  = self::basename();
		$found = $this->enabled() ? $this->found() : null;
		$item  = $found['update'] ?? $found['renew'] ?? null;
		if ( null === $item ) {
			// An offer kept from before (e.g. the version just installed) goes away.
			if ( isset( $transient->response ) && is_array( $transient->response ) ) {
				unset( $transient->response[ $file ] );
			}
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $file ] = self::entry( $file, self::installed(), '', $found['current'] ?? null );
			return $transient;
		}
		$entry               = self::entry( $file, $item['version'], null === $found['update'] ? '' : $item['package'], $item );
		// No package: WordPress shows «aggiornamento automatico non disponibile» and
		// renew_message() says why.
		$entry->bkng_renew   = null === $found['update'] ? $item['updates_until'] : null;
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $file ] = $entry;
		if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
			unset( $transient->no_update[ $file ] );
		}
		return $transient;
	}

	/**
	 * One plugin in the update_plugins transient, as wordpress.org would describe it (pure).
	 *
	 * @param array<string, mixed>|null $item what the server says about that version, if known
	 */
	public static function entry( string $file, string $version, string $package, ?array $item ): object {
		return (object) array(
			'id'            => self::UPDATE_URI,
			'slug'          => dirname( $file ),
			'plugin'        => $file,
			'new_version'   => $version,
			'url'           => '',
			'package'       => $package,
			'requires'      => (string) ( $item['requires'] ?? '' ),
			'requires_php'  => (string) ( $item['requires_php'] ?? '' ),
			'tested'        => (string) ( $item['tested'] ?? '' ),
			'icons'         => self::icons(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'compatibility' => new \stdClass(),
		);
	}

	/**
	 * «Verifica aggiornamenti» in Licenza: asks the licence server now and writes the
	 * answer into what WordPress knows, so Plugin and Bacheca → Aggiornamenti show it at once.
	 *
	 * @return array<string, mixed> state()
	 */
	public function check_now(): array {
		$state     = $this->state( true );
		$transient = get_site_transient( 'update_plugins' );
		if ( is_object( $transient ) ) {
			// set_site_transient() runs offer() again, on the answer just kept.
			set_site_transient( 'update_plugins', $transient );
		}
		return $state;
	}

	/**
	 * Action in_plugin_update_message-{plugin}: under the «new version» line of a version
	 * the licence does not cover.
	 *
	 * @param mixed $plugin_data
	 * @param mixed $response
	 */
	public function renew_message( $plugin_data = null, $response = null ): void {
		unset( $plugin_data );
		if ( ! is_object( $response ) || ! property_exists( $response, 'bkng_renew' ) || null === $response->bkng_renew ) {
			return;
		}
		echo ' <strong>' . esc_html( self::renew_text( (string) $response->bkng_renew ) ) . '</strong>';
	}

	/** «Rinnova la licenza…», with the end of the updates period when known. */
	public static function renew_text( string $updates_until ): string {
		$time = '' === $updates_until ? false : strtotime( $updates_until );
		return false === $time
			? __( 'La tua licenza non comprende questa versione: rinnova la licenza per riceverla.', 'booking-manager' )
			/* translators: %s: date the updates period ended. */
			: sprintf( __( 'Il periodo di aggiornamenti della tua licenza è finito il %s: rinnova la licenza per ricevere questa versione.', 'booking-manager' ), wp_date( (string) ( get_option( 'date_format' ) ?: 'j F Y' ), $time ) );
	}

	/**
	 * Filter plugins_api: the «Visualizza dettagli» window of the Plugins screen.
	 *
	 * @param mixed $result
	 * @param mixed $args
	 * @return mixed
	 */
	public function details( $result, string $action = '', $args = null ) {
		$slug = is_object( $args ) ? (string) ( $args->slug ?? '' ) : '';
		if ( 'plugin_information' !== $action || dirname( self::basename() ) !== $slug ) {
			return $result;
		}
		$found = $this->enabled() ? $this->found() : null;
		// Nothing from the server (licence not activated, server not answering): the
		// installed version and its notes from CHANGELOG.md.
		$item = $found['update'] ?? $found['renew'] ?? $found['current'] ?? array(
			'version'      => self::installed(),
			'requires'     => '',
			'requires_php' => '',
			'tested'       => '',
			'changelog'    => Changelog::to_html( Changelog::local( self::installed() ) ),
		);
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( BKNG_FILE, false, false );
		if ( '' === $item['requires'] ) {
			$item['requires']     = (string) ( $data['RequiresWP'] ?? '' );
			$item['requires_php'] = (string) ( $data['RequiresPHP'] ?? '' );
		}
		$description = wp_kses_post( $data['Description'] );
		if ( ! $this->enabled() ) {
			$description .= '<p><strong>' . esc_html__( 'Gli aggiornamenti arrivano dal server licenze: attiva la licenza nella sezione Licenza di Booking Manager per riceverli.', 'booking-manager' ) . '</strong></p>';
		}
		return (object) array(
			'name'          => $data['Name'],
			'slug'          => $slug,
			'version'       => $item['version'],
			'author'        => $data['Author'],
			'homepage'      => $data['PluginURI'],
			'requires'      => $item['requires'],
			'requires_php'  => $item['requires_php'],
			'tested'        => $item['tested'],
			'download_link' => '', // The licence server's package needs a one-time permit.
			'icons'         => self::icons(),
			'sections'      => array(
				'description' => $description,
				'changelog'   => $item['changelog'],
			),
		);
	}

	/**
	 * Filter upgrader_pre_download: a «bkng-license:1.2.3» package is fetched from the
	 * licence server with a one-time permit and handed to WordPress only if it is exactly
	 * the file the server signed (sha256 and size). Any other package is left alone.
	 *
	 * @param mixed $reply
	 * @param mixed $package
	 * @param mixed $upgrader
	 * @param mixed $hook_extra
	 * @return mixed the path of the checked zip, a WP_Error, or $reply untouched
	 */
	public function download( $reply, $package = '', $upgrader = null, $hook_extra = null ) {
		unset( $upgrader, $hook_extra );
		if ( ! is_string( $package ) || ! str_starts_with( $package, self::PACKAGE ) ) {
			return $reply;
		}
		$version = substr( $package, strlen( self::PACKAGE ) );
		if ( ! preg_match( self::VERSION, $version ) ) {
			return new \WP_Error( 'bkng_update_package', __( 'Versione da scaricare non valida.', 'booking-manager' ) );
		}
		$answer = $this->enabled() ? $this->licenses->update_call( 'update/download-token', array( 'version' => $version ) ) : null;
		if ( null === $answer ) {
			return new \WP_Error( 'bkng_update_no_license', __( 'Questo sito non ha una licenza attiva: attivala nella sezione Licenza di Booking Manager, poi riprova.', 'booking-manager' ) );
		}
		if ( ! $answer->is_authorization() ) {
			return new \WP_Error( 'bkng_update_refused', self::refusal( $answer ) );
		}
		$permit = $answer->data->download ?? null;
		$url    = is_string( $permit->url ?? null ) ? $permit->url : '';
		$sha256 = is_string( $permit->sha256 ?? null ) ? strtolower( $permit->sha256 ) : '';
		$size   = is_int( $permit->size ?? null ) ? $permit->size : 0;
		if ( ( $permit->version ?? null ) !== $version || ! preg_match( '/^[0-9a-f]{64}$/', $sha256 ) || $size <= 0 || ! self::own_download( $url, $this->licenses->server()->url ) ) {
			return new \WP_Error( 'bkng_update_permit', __( 'Il server licenze ha dato un permesso di download non valido: aggiornamento annullato.', 'booking-manager' ) );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file     = wp_tempnam( 'booking-manager-' . $version . '.zip' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 300,
				'redirection' => 0,
				'stream'      => true,
				'filename'    => $file,
				'user-agent'  => 'BookingManager/' . BKNG_VERSION,
			)
		);
		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			wp_delete_file( $file );
			return new \WP_Error(
				'bkng_update_download',
				0 === $status
					? __( 'Download non riuscito: il server licenze non risponde. Riprova più tardi.', 'booking-manager' )
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'Download non riuscito (errore %d): riprova più tardi.', 'booking-manager' ), $status )
			);
		}
		if ( ! self::matches( $file, $sha256, $size ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'bkng_update_mismatch', __( 'Il file scaricato non è quello firmato dal server licenze: aggiornamento annullato, niente è stato cambiato.', 'booking-manager' ) );
		}
		return $file;
	}

	/** Is this file exactly the one the server signed (size and SHA-256)? */
	public static function matches( string $file, string $sha256, int $size ): bool {
		clearstatcache( true, $file );
		if ( ! is_file( $file ) || (int) filesize( $file ) !== $size ) {
			return false;
		}
		$hash = hash_file( 'sha256', $file );
		return is_string( $hash ) && hash_equals( strtolower( $sha256 ), $hash );
	}

	/**
	 * Action upgrader_process_complete: after an update the answer is read again, so the
	 * Plugins screen does not keep offering the version just installed.
	 *
	 * @param mixed                $upgrader
	 * @param array<string, mixed> $extra
	 */
	public function forget( $upgrader, array $extra = array() ): void {
		unset( $upgrader );
		if ( 'plugin' === ( $extra['type'] ?? '' ) ) {
			self::$installed = null;
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * What the Licenza panel shows about updates.
	 *
	 * @return array<string, mixed>
	 */
	public function state( bool $fresh = false ): array {
		$enabled = $this->enabled();
		$found   = $enabled ? $this->found( $fresh ) : null;
		$renew   = $found['renew'] ?? null;
		return array(
			'enabled'     => $enabled,
			'version'     => self::installed(),
			'reachable'   => (bool) ( $found['reachable'] ?? false ),
			'available'   => null !== ( $found['update'] ?? null ),
			'latest'      => $found['update']['version'] ?? '',
			'security'    => (bool) ( $found['update']['security'] ?? false ),
			'renew'       => null === $renew ? null : array(
				'version' => $renew['version'],
				'text'    => self::renew_text( (string) $renew['updates_until'] ),
			),
			'checked_at'  => $enabled ? $this->checked_at() : '',
			'plugins_url' => admin_url( 'plugins.php' ),
			'updates_url' => admin_url( 'update-core.php' ),
		);
	}

	// ------------------------------------------------------------------ inside

	/** When the licence server was last asked about updates (ISO 8601, UTC), or ''. */
	private function checked_at(): string {
		$kept = get_transient( self::TRANSIENT );
		return is_array( $kept ) && is_string( $kept['checked'] ?? null ) ? $kept['checked'] : '';
	}

	/**
	 * update:  a newer version this site may install (with its package);
	 * renew:   a newer version the licence does not cover, when there is nothing to install;
	 * current: what to show in «Visualizza dettagli» when there is nothing newer.
	 *
	 * @return array{reachable: bool, update: ?array<string, mixed>, renew: ?array<string, mixed>, current: ?array<string, mixed>}
	 */
	private function found( bool $fresh = false ): array {
		if ( ! $this->refreshed && self::forced() ) {
			$fresh           = true;
			$this->refreshed = true;
		}
		$server = $this->server( $fresh );
		return self::pick( $server, self::installed() );
	}

	/**
	 * The choice between installable and latest (pure, tested).
	 *
	 * @param array{reachable: bool, latest: ?array<string, mixed>, installable: ?array<string, mixed>, updates_until: string}|null $server
	 * @return array{reachable: bool, update: ?array<string, mixed>, renew: ?array<string, mixed>, current: ?array<string, mixed>}
	 */
	public static function pick( ?array $server, string $installed ): array {
		$found = array(
			'reachable' => (bool) ( $server['reachable'] ?? false ),
			'update'    => null,
			'renew'     => null,
			'current'   => null,
		);
		if ( null === $server || ! $server['reachable'] ) {
			return $found;
		}
		$installable = $server['installable'];
		$latest      = $server['latest'];
		if ( null !== $installable && version_compare( $installable['version'], $installed, '>' ) ) {
			$found['update'] = self::item( $installable );
		} elseif ( null !== $latest && ! $latest['entitled'] && version_compare( $latest['version'], $installed, '>' ) ) {
			$found['renew'] = self::item( $latest ) + array( 'updates_until' => $server['updates_until'] );
		}
		$found['current'] = null === $latest ? null : self::item( $latest );
		return $found;
	}

	/**
	 * @param array<string, mixed> $release one cleaned by release()
	 * @return array<string, mixed>
	 */
	private static function item( array $release ): array {
		return array(
			'version'      => $release['version'],
			'package'      => self::PACKAGE . $release['version'],
			'requires'     => $release['requires_wp'],
			'requires_php' => $release['requires_php'],
			'tested'       => $release['tested_wp'],
			'changelog'    => Changelog::to_html( $release['changelog'] ),
			'security'     => $release['security'],
		);
	}

	/**
	 * What the licence server says, kept for twelve hours (one hour when it did not
	 * answer). null when this site has no activation to ask with.
	 *
	 * @return array{reachable: bool, latest: ?array<string, mixed>, installable: ?array<string, mixed>, updates_until: string}|null
	 */
	private function server( bool $fresh = false ): ?array {
		$activation = $this->licenses->activation_id();
		if ( '' === $activation ) {
			return null;
		}
		$key = md5( $activation . '|' . self::installed() );
		if ( ! $fresh ) {
			$kept = get_transient( self::TRANSIENT );
			if ( is_array( $kept ) && ( $kept['key'] ?? '' ) === $key && is_array( $kept['data'] ?? null ) ) {
				return $kept['data'];
			}
		}
		$answer = $this->licenses->update_call( 'update/check', array( 'channel' => self::CHANNEL ) );
		if ( null === $answer ) {
			return null;
		}
		$data = self::parse_check( $answer );
		set_transient(
			self::TRANSIENT,
			array(
				'key'     => $key,
				'data'    => $data,
				'checked' => gmdate( 'c' ),
			),
			$data['reachable'] ? self::TTL : self::RETRY
		);
		return $data;
	}

	/**
	 * An update/check answer, reduced to what is needed (pure, tested).
	 *
	 * @return array{reachable: bool, latest: ?array<string, mixed>, installable: ?array<string, mixed>, updates_until: string}
	 */
	public static function parse_check( ServerAnswer $answer ): array {
		$data = array(
			'reachable'     => $answer->is_authorization(),
			'latest'        => null,
			'installable'   => null,
			'updates_until' => '',
		);
		if ( $answer->is_authorization() ) {
			$update                = $answer->data->update ?? null;
			$data['latest']        = self::release( $update->latest ?? null );
			$data['installable']   = self::release( $update->installable ?? null );
			$until                 = $answer->data->license->updates_until ?? null;
			$data['updates_until'] = is_string( $until ) ? $until : '';
		}
		return $data;
	}

	/**
	 * A release as update/check describes it, only with what is needed and well formed.
	 *
	 * @param mixed $raw
	 * @return array{version: string, sha256: string, size: int, requires_php: string, requires_wp: string, tested_wp: string, security: bool, changelog: string, entitled: bool}|null
	 */
	public static function release( $raw ): ?array {
		if ( ! $raw instanceof \stdClass || ! is_string( $raw->version ?? null ) || ! preg_match( self::VERSION, $raw->version ) ) {
			return null;
		}
		$text = static fn( $value ): string => is_string( $value ) ? sanitize_text_field( $value ) : '';
		return array(
			'version'      => $raw->version,
			'sha256'       => $text( $raw->sha256 ?? null ),
			'size'         => is_int( $raw->size ?? null ) ? $raw->size : 0,
			'requires_php' => $text( $raw->requires_php ?? null ),
			'requires_wp'  => $text( $raw->requires_wp ?? null ),
			'tested_wp'    => $text( $raw->tested_wp ?? null ),
			'security'     => true === ( $raw->security ?? false ),
			'changelog'    => is_string( $raw->changelog ?? null ) ? $raw->changelog : '',
			'entitled'     => true === ( $raw->entitled ?? true ),
		);
	}

	/** «Controlla di nuovo» in Bacheca → Aggiornamenti: WordPress asks again, so do we. */
	private static function forced(): bool {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads whether to check now, as WordPress does.
		return is_admin() && 'update-core.php' === ( $pagenow ?? '' ) && ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' );
	}

	/** The download address in a permit must be the licence server's own (pure, tested). */
	public static function own_download( string $url, string $server_url ): bool {
		if ( '' === $url || '' === $server_url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( $server_url );
		return is_array( $parts ) && is_array( $home )
			&& strtolower( (string) ( $parts['host'] ?? '' ) ) === strtolower( (string) ( $home['host'] ?? '' ) )
			&& ( $parts['scheme'] ?? '' ) === ( $home['scheme'] ?? '' )
			&& ( $parts['port'] ?? null ) === ( $home['port'] ?? null )
			&& str_ends_with( (string) ( $parts['path'] ?? '' ), '/api/v1/update/download' );
	}

	private static function refusal( ServerAnswer $answer ): string {
		return match ( $answer->code ) {
			'not_entitled'    => __( 'La tua licenza non comprende questa versione: rinnova la licenza per riceverla.', 'booking-manager' ),
			'unknown_release' => __( 'Questa versione non è più disponibile sul server licenze.', 'booking-manager' ),
			'rate_limited'    => __( 'Troppi download per questa licenza: riprova più tardi.', 'booking-manager' ),
			default           => ServerAnswer::UNAVAILABLE === $answer->kind
				? __( 'Il server licenze non risponde: riprova più tardi.', 'booking-manager' )
				/* translators: %s: error code from the licence server. */
				: sprintf( __( 'Il server licenze ha rifiutato il download (%s).', 'booking-manager' ), $answer->code ),
		};
	}
}
