<?php
/**
 * What each licence state allows: the one table the rest of the plugin relies on.
 *
 * The server also sends access {read, write, update}; LicenseState applies the more
 * restrictive of the two, so the server can narrow a case but never widen this table.
 * Reading is always allowed: no state ever hides or deletes data.
 *
 * not_configured is Booking Manager's own state: a copy without usable licence server
 * keys blocks nothing and offers no updates from the server. The release carries the
 * keys (ServerConfig::PUBLIC_KEYS), so this happens only on a local/development site
 * whose wp-config.php sets BKNG_LICENSE_PUBLIC_KEYS without a valid key.
 *
 * inactive is not only «no new bookings»: with no activation on this site Booking Manager is
 * locked altogether (LicenseManager::is_locked, WriteGate full mode).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

defined( 'ABSPATH' ) || exit;

final class AccessMatrix {

	public const NOT_CONFIGURED    = 'not_configured';
	public const ACTIVE            = 'active';
	public const LIFETIME          = 'lifetime';
	public const TRIAL             = 'trial';
	public const EXPIRED           = 'expired';
	public const SUSPENDED         = 'suspended';
	public const REVOKED           = 'revoked';
	public const SITE_CHANGED      = 'site_changed';
	public const RECOVERY_REQUIRED = 'recovery_required';
	public const INVALID           = 'invalid';
	public const INACTIVE          = 'inactive';
	public const OFFLINE_GRACE     = 'offline_grace';
	public const UNVERIFIED        = 'unverified';

	/** [read, write, update]; «server» = what the server says about updates (updates_until). */
	private const TABLE = array(
		self::NOT_CONFIGURED    => array( true, true, false ),
		self::ACTIVE            => array( true, true, 'server' ),
		self::LIFETIME          => array( true, true, 'server' ),
		self::TRIAL             => array( true, true, 'server' ),
		self::OFFLINE_GRACE     => array( true, true, false ),
		self::UNVERIFIED        => array( true, false, false ),
		self::EXPIRED           => array( true, false, 'server' ),
		self::SUSPENDED         => array( true, false, false ),
		self::REVOKED           => array( true, false, false ),
		self::RECOVERY_REQUIRED => array( true, false, false ),
		self::SITE_CHANGED      => array( true, false, false ),
		self::INACTIVE          => array( true, false, false ),
		self::INVALID           => array( true, false, false ),
	);

	/** @return string[] */
	public static function states(): array {
		return array_keys( self::TABLE );
	}

	/** The statuses a server can send in license_status. */
	public static function is_server_status( string $status ): bool {
		return in_array( $status, array( self::ACTIVE, self::LIFETIME, self::TRIAL, self::EXPIRED, self::SUSPENDED, self::REVOKED ), true );
	}

	/** A licence that lets the site work normally. */
	public static function is_good( string $status ): bool {
		return in_array( $status, array( self::ACTIVE, self::LIFETIME, self::TRIAL ), true );
	}

	/**
	 * @param array{read?: bool, write?: bool, update?: bool}|null $server access from the last authorization
	 * @return array{read: bool, write: bool, update: bool}
	 */
	public static function access( string $state, ?array $server ): array {
		[ $read, $write, $update ] = self::TABLE[ $state ] ?? self::TABLE[ self::INACTIVE ];
		$server_update             = (bool) ( $server['update'] ?? false );
		$update                    = 'server' === $update ? $server_update : $update;
		if ( null !== $server ) {
			$write  = $write && (bool) ( $server['write'] ?? false );
			$update = $update && $server_update;
		}
		return array(
			'read'   => $read,
			'write'  => $write,
			'update' => $update,
		);
	}

	/** Italian name of a state, for the Licenza panel and WP-CLI. */
	public static function label( string $state ): string {
		$labels = array(
			self::NOT_CONFIGURED    => __( 'Non configurato', 'booking-manager' ),
			self::ACTIVE            => __( 'Attiva', 'booking-manager' ),
			self::LIFETIME          => __( 'Attiva (a vita)', 'booking-manager' ),
			self::TRIAL             => __( 'In prova', 'booking-manager' ),
			self::EXPIRED           => __( 'Scaduta', 'booking-manager' ),
			self::SUSPENDED         => __( 'Sospesa', 'booking-manager' ),
			self::REVOKED           => __( 'Revocata', 'booking-manager' ),
			self::SITE_CHANGED      => __( 'Da ricollegare (indirizzo del sito cambiato)', 'booking-manager' ),
			self::RECOVERY_REQUIRED => __( 'Da ricollegare (attivazione sostituita)', 'booking-manager' ),
			self::INVALID           => __( 'Risposta non valida', 'booking-manager' ),
			self::INACTIVE          => __( 'Nessuna licenza attiva', 'booking-manager' ),
			self::OFFLINE_GRACE     => __( 'Server licenze non raggiungibile (tolleranza)', 'booking-manager' ),
			self::UNVERIFIED        => __( 'Non verificabile', 'booking-manager' ),
		);
		return $labels[ $state ] ?? $state;
	}
}
