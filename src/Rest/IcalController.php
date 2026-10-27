<?php
/**
 * Booking.com, Airbnb and the other portals through iCal calendars
 * (bkng_manage_integrations).
 *
 *   GET    /properties/{id}/ical               rooms, export addresses, calendars, log
 *   POST   /properties/{id}/ical/feeds         unit_id, channel, url → added and read at once
 *   POST   /properties/{id}/ical/sync          «Sincronizza ora» for the whole property
 *   DELETE /ical/feeds/{id}                    removes a calendar and frees its nights
 *   POST   /ical/feeds/{id}/sync               reads one calendar now
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Channels\Ical\IcalFeedRepository;
use BookingManager\Channels\Ical\IcalService;

defined( 'ABSPATH' ) || exit;

class IcalController extends RestController {

	private const CAP = 'bkng_manage_integrations';

	public function __construct(
		Policy $policy,
		private IcalService $service,
		private IcalFeedRepository $feeds
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$property = $this->require_cap_for_property( self::CAP, 'id' );
		$feed     = $this->require_cap_for_record( self::CAP, fn( int $id ) => $this->feeds->find( $id ) );

		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<id>\d+)/ical',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->overview( (int) $r['id'] ) ),
				'permission_callback' => $property,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<id>\d+)/ical/feeds',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->add_feed( (int) $r['id'], self::body( $r ), get_current_user_id() ), 201 ),
				'permission_callback' => $property,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<id>\d+)/ical/sync',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->sync_property( (int) $r['id'] ) ),
				'permission_callback' => $property,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/ical/feeds/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->delete_feed( (int) $r['id'] ) ),
				'permission_callback' => $feed,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/ical/feeds/(?P<id>\d+)/sync',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->sync_feed( (int) $r['id'] ) ),
				'permission_callback' => $feed,
			)
		);
	}

	/** @return array<string, mixed> */
	private static function body( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		return is_array( $body ) ? $body : array();
	}
}
