<?php
/**
 * Activation, deactivation and lazy upgrade.
 *
 * Activation creates the tables (migrations) and the roles. No property, room or demo
 * data is created: the setup wizard starts with the licence and then asks for the
 * property, its rooms and prices.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

use BookingManager\Access\RoleManager;
use BookingManager\Admin\AdminApp;
use BookingManager\Channels\Channex\ChannexSync;
use BookingManager\Database\Migrator;
use BookingManager\Licensing\LicenseScheduler;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Rest\SetupController;

defined( 'ABSPATH' ) || exit;

final class Installer {

	/** Daily tasks: stays completed, holds expired, data retention. */
	public const DAILY_HOOK = 'bkng_daily';

	/** Hourly tasks: options expired. */
	public const HOURLY_HOOK = 'bkng_hourly';

	/** Every minute: notification queue. */
	public const NOTIFICATIONS_HOOK = 'bkng_notifications';

	/** Every hour: reminders before arrival. */
	public const REMINDERS_HOOK = 'bkng_reminders';

	/** Every 15 minutes: online payments left without an answer. */
	public const PAYMENTS_HOOK = 'bkng_payments_reconcile';

	public const MINUTE_SCHEDULE  = 'bkng_every_minute';
	public const QUARTER_SCHEDULE = 'bkng_every_15_minutes';

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function cron_schedules( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::MINUTE_SCHEDULE ]  = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Ogni minuto (Booking Manager)', 'booking-manager' ),
		);
		$schedules[ self::QUARTER_SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Ogni 15 minuti (Booking Manager)', 'booking-manager' ),
		);
		return $schedules;
	}

	/**
	 * @param bool $network_wide Activated network-wide on multisite.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}
		self::install_site();
		// The next admin page opens Booking Manager (AdminApp::after_activation).
		set_transient( AdminApp::REDIRECT, 1, 60 );
	}

	/**
	 * Idempotent: safe to run on every activation and after every update.
	 *
	 * @return array{status: string, applied: string[], error?: string}
	 */
	public static function install_site(): array {
		$result = ( new Migrator() )->run();
		if ( 'ok' !== $result['status'] ) {
			return $result;
		}
		delete_option( 'bkng_migration_error' );

		( new RoleManager() )->install();

		if ( 0 === ( new PropertyRepository() )->count() ) {
			// A brand new installation: the guided setup opens in Booking Manager.
			add_option( SetupController::OPTION, array( 'state' => SetupController::PENDING ), '', false );
		}

		if ( false === get_option( 'bkng_installed_at' ) ) {
			add_option( 'bkng_installed_at', gmdate( 'Y-m-d H:i:s' ), '', false );
		}
		update_option( 'bkng_plugin_version', BKNG_VERSION, true );

		// On activation the plugin has not booted yet: the schedules must exist now.
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
		self::ensure_schedules();

		return $result;
	}

	/** Schedules the recurring tasks that are missing (e.g. cleared by a cron plugin). */
	public static function ensure_schedules(): void {
		$tasks = array(
			self::DAILY_HOOK         => array( HOUR_IN_SECONDS, 'daily' ),
			self::HOURLY_HOOK        => array( 5 * MINUTE_IN_SECONDS, 'hourly' ),
			self::NOTIFICATIONS_HOOK => array( MINUTE_IN_SECONDS, self::MINUTE_SCHEDULE ),
			self::REMINDERS_HOOK     => array( 10 * MINUTE_IN_SECONDS, 'hourly' ),
			self::PAYMENTS_HOOK      => array( 15 * MINUTE_IN_SECONDS, self::QUARTER_SCHEDULE ),
			LicenseScheduler::HOOK   => array( 15 * MINUTE_IN_SECONDS, 'hourly' ),
		);
		$tasks[ ChannexSync::TICK_HOOK ] = array( MINUTE_IN_SECONDS, self::MINUTE_SCHEDULE );
		foreach ( $tasks as $hook => [ $delay, $recurrence ] ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + $delay, $recurrence, $hook );
			}
		}
		// Channex: reconciliation and full sync at night (3:00 of the site), when portals are quiet.
		if ( ! wp_next_scheduled( ChannexSync::NIGHTLY_HOOK ) ) {
			$night = new \DateTimeImmutable( 'tomorrow 03:00', wp_timezone() );
			wp_schedule_event( $night->getTimestamp(), 'daily', ChannexSync::NIGHTLY_HOOK );
		}
	}

	/** Runs migrations when the code is newer than the database (zip uploaded over an old version). */
	public static function maybe_upgrade(): void {
		if ( Migrator::needs_migration() || get_option( 'bkng_plugin_version' ) !== BKNG_VERSION ) {
			self::install_site();
		}
	}

	/**
	 * Deactivation stops the scheduled tasks only: no data is touched.
	 *
	 * @param bool $network_wide Deactivated network-wide on multisite.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}
		self::deactivate_site();
	}

	private static function deactivate_site(): void {
		foreach ( array( self::DAILY_HOOK, self::HOURLY_HOOK, self::NOTIFICATIONS_HOOK, self::REMINDERS_HOOK, self::PAYMENTS_HOOK, ChannexSync::TICK_HOOK, ChannexSync::NIGHTLY_HOOK ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		LicenseScheduler::clear();
	}
}
