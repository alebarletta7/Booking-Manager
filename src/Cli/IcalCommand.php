<?php
/**
 * wp booking-manager ical sync [--all] [--property=<id>] | status
 *
 * For production sites with a system cron: «sync» reads the calendars that are due, as the
 * WordPress cron does every 15 minutes.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Cli;

use BookingManager\Channels\Ical\IcalFeedRepository;
use BookingManager\Channels\Ical\IcalService;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Calendars of Booking.com, Airbnb and the other portals.
 */
class IcalCommand {

	public function __construct(
		private IcalService $service,
		private IcalFeedRepository $feeds,
		private PropertyRepository $properties
	) {}

	/**
	 * Reads the portals' calendars.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Every calendar, not only the ones due.
	 *
	 * [--property=<id>]
	 * : Only the calendars of one property (all of them).
	 *
	 * ## EXAMPLES
	 *
	 *     wp booking-manager ical sync
	 *     wp booking-manager ical sync --property=3
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public function sync( array $args, array $assoc ): void {
		unset( $args );
		if ( isset( $assoc['property'] ) ) {
			$result = $this->service->sync_property( (int) $assoc['property'] );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::success( sprintf( 'Calendari letti: %d, con errori: %d.', $result['feeds'], $result['errors'] ) );
			return;
		}
		if ( isset( $assoc['all'] ) ) {
			$n = 0;
			foreach ( $this->properties->all() as $property ) {
				$r = $this->service->sync_property( (int) $property['id'] );
				$n += is_array( $r ) ? (int) $r['feeds'] : 0;
			}
			update_option( IcalService::CRON_OPTION, time(), false );
			\WP_CLI::success( sprintf( 'Calendari letti: %d.', $n ) );
			return;
		}
		$this->service->sync_all();
		\WP_CLI::success( 'Calendari in scadenza letti.' );
	}

	/**
	 * Shows every calendar with its state (the addresses are not shown).
	 *
	 * ## EXAMPLES
	 *
	 *     wp booking-manager ical status
	 */
	public function status(): void {
		$rows = array();
		foreach ( $this->properties->all() as $property ) {
			foreach ( $this->feeds->for_property( (int) $property['id'] ) as $feed ) {
				$rows[] = array(
					'id'           => $feed['id'],
					'property'     => $property['id'],
					'unit'         => $feed['unit_id'],
					'portal'       => $feed['channel'],
					'state'        => IcalService::feed_state( $feed ),
					'last_success' => (string) $feed['last_success_at'],
					'next'         => (string) $feed['next_sync_at'],
					'error'        => (string) $feed['last_error'],
				);
			}
		}
		$cron = $this->service->cron_state();
		\WP_CLI::log( 'Ultimo giro automatico: ' . ( $cron['last_run'] ?? 'mai' ) . ( $cron['wp_cron_disabled'] ? ' (DISABLE_WP_CRON attivo: serve un cron di sistema)' : '' ) );
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'property', 'unit', 'portal', 'state', 'last_success', 'next', 'error' ) );
	}
}
