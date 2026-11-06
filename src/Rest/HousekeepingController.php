<?php
/**
 * Housekeeping: the rooms board and the change of a room's status.
 *
 *   GET  /properties/{property_id}/housekeeping           board of the day (bkng_view_housekeeping)
 *   POST /units/{id}/op-status { from, to, note }          bkng_update_housekeeping
 *   GET  /units/{id}/op-history                            latest changes (bkng_view_housekeeping)
 *
 * Not behind the licence: an expired licence never stops the cleaning of the rooms.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Housekeeping\HousekeepingService;
use BookingManager\Inventory\UnitRepository;

defined( 'ABSPATH' ) || exit;

class HousekeepingController extends RestController {

	public function __construct(
		Policy $policy,
		private HousekeepingService $service,
		private UnitRepository $units
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$ns   = self::REST_NAMESPACE;
		$unit = fn( int $id ) => $this->units->find( $id );

		register_rest_route(
			$ns,
			'/properties/(?P<property_id>\d+)/housekeeping',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->board( (int) $r['property_id'] ) ),
				'permission_callback' => $this->require_cap_for_property( 'bkng_view_housekeeping' ),
			)
		);
		register_rest_route(
			$ns,
			'/units/(?P<id>\d+)/op-status',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $r ) {
					$note = $r->get_param( 'note' );
					return self::respond(
						$this->service->set_status(
							(int) $r['id'],
							sanitize_key( (string) $r->get_param( 'from' ) ),
							sanitize_key( (string) $r->get_param( 'to' ) ),
							null === $note ? null : (string) $note,
							get_current_user_id()
						)
					);
				},
				'permission_callback' => $this->require_cap_for_record( 'bkng_update_housekeeping', $unit ),
			)
		);
		register_rest_route(
			$ns,
			'/units/(?P<id>\d+)/op-history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->history( (int) $r['id'] ) ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_view_housekeeping', $unit ),
			)
		);
	}
}
