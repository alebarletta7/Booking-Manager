<?php
/**
 * Asks the licence server on its own (WP-Cron).
 *
 * An hourly tick checks whether a validate is due: revalidate_hours (from the server's
 * policy, 12 by default) after the last answer. After a failed check it tries again
 * sooner, but less and less often so a server back online is not flooded: 15 minutes,
 * 30 minutes, 1 hour, 2 hours, then every 6 hours, until an answer arrives (a single
 * retry event, so the short delays do not wait for the hourly tick).
 *
 * When a new version of the plugin is installed between two validates, a heartbeat
 * (same answer as validate, without a new seq) tells the server the versions now in use.
 *
 * Nothing happens on a copy without the licence server's keys or without an activation.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

defined( 'ABSPATH' ) || exit;

class LicenseScheduler {

	public const HOOK       = 'bkng_license_check';
	public const RETRY_HOOK = 'bkng_license_retry';

	/** Delay before the n-th retry (the last one repeats). */
	public const RETRIES = array( 900, 1800, 3600, 7200, 21600 );

	public function __construct( private LicenseManager $license ) {}

	public function hooks(): void {
		add_action( self::HOOK, array( $this, 'tick' ) );
		add_action( self::RETRY_HOOK, array( $this, 'retry' ) );
		add_action( 'init', array( self::class, 'ensure' ), 20 );
	}

	/** Schedules the hourly tick if it is missing (e.g. cleared by a cron plugin). */
	public static function ensure(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 15 * 60, 'hourly', self::HOOK );
		}
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::RETRY_HOOK );
	}

	/** What the hourly tick does now: validate, heartbeat or nothing (tested). */
	public static function due( ?int $next_check_at, bool $version_unreported, int $now ): string {
		if ( null === $next_check_at ) {
			return 'none';
		}
		if ( $now >= $next_check_at ) {
			return 'validate';
		}
		return $version_unreported ? 'heartbeat' : 'none';
	}

	public function tick(): void {
		$what = self::due( $this->license->next_check_at(), $this->license->version_unreported(), time() );
		if ( 'none' !== $what ) {
			$this->run( $what );
		}
	}

	public function retry(): void {
		$this->run( 'validate' );
	}

	private function run( string $endpoint ): void {
		$summary = $this->license->refresh( $endpoint );
		wp_clear_scheduled_hook( self::RETRY_HOOK );
		if ( '' === $summary['last_error'] || ! $summary['activated'] ) {
			return;
		}
		// One retry waiting at a time.
		wp_schedule_single_event( time() + self::delay( $this->license->fail_count() ), self::RETRY_HOOK );
	}

	/** Seconds before the next try after $failures checks failed in a row. */
	public static function delay( int $failures ): int {
		return self::RETRIES[ min( max( $failures, 1 ), count( self::RETRIES ) ) - 1 ];
	}
}
