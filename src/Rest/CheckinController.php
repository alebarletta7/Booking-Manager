<?php
/**
 * Front desk: check-in with QR or code, check-out, the screen of the day, the booking's QR.
 *
 *   GET  /properties/{property_id}/frontdesk                       arrivals, in house, departures (bkng_checkin)
 *   POST /properties/{property_id}/checkin/scan { text, immediate } what a QR or typed code is
 *   POST /properties/{property_id}/checkin { booking_id, room_ids?, method?, ignore_dirty?, version? }
 *   POST /properties/{property_id}/checkout { booking_id, room_ids?, version? }
 *   GET  /bookings/{id}/qr                                         payload and SVG (bkng_view_bookings)
 *   POST /bookings/{id}/qr/regenerate                              the old QR stops working (bkng_edit_booking)
 *
 * Giving a room not cleaned yet (ignore_dirty) needs bkng_override_restrictions; the
 * balance due is shown only to who may see payments.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Checkin\CheckinService;

defined( 'ABSPATH' ) || exit;

class CheckinController extends RestController {

	public function __construct(
		Policy $policy,
		private CheckinService $service,
		private BookingRepository $bookings
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$ns      = self::REST_NAMESPACE;
		$base    = '/properties/(?P<property_id>\d+)';
		$desk    = $this->require_cap_for_property( 'bkng_checkin' );
		$booking = fn( int $id ) => $this->bookings->find( $id );

		register_rest_route(
			$ns,
			$base . '/frontdesk',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->overview( (int) $r['property_id'] ) ),
				'permission_callback' => $desk,
			)
		);
		register_rest_route(
			$ns,
			$base . '/checkin/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $r ) {
					$property_id = (int) $r['property_id'];
					return self::respond(
						$this->service->scan(
							$property_id,
							mb_substr( (string) $r->get_param( 'text' ), 0, 200 ),
							get_current_user_id(),
							$this->opts( $r, $property_id ) + array( 'immediate' => (bool) $r->get_param( 'immediate' ) )
						)
					);
				},
				'permission_callback' => $desk,
			)
		);
		register_rest_route(
			$ns,
			$base . '/checkin',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $r ) {
					$property_id = (int) $r['property_id'];
					return self::respond( $this->service->check_in( $property_id, (int) $r->get_param( 'booking_id' ), get_current_user_id(), $this->opts( $r, $property_id ) ) );
				},
				'permission_callback' => $desk,
			)
		);
		register_rest_route(
			$ns,
			$base . '/checkout',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $r ) {
					$property_id = (int) $r['property_id'];
					return self::respond( $this->service->check_out( $property_id, (int) $r->get_param( 'booking_id' ), get_current_user_id(), $this->opts( $r, $property_id ) ) );
				},
				'permission_callback' => $desk,
			)
		);
		register_rest_route(
			$ns,
			'/bookings/(?P<id>\d+)/qr',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->qr_for( (array) $this->bookings->find( (int) $r['id'] ) ) ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_view_bookings', $booking ),
			)
		);
		register_rest_route(
			$ns,
			'/bookings/(?P<id>\d+)/qr/regenerate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->regenerate_qr( (int) $r['id'], get_current_user_id() ) ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_edit_booking', $booking ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function opts( \WP_REST_Request $r, int $property_id ): array {
		$opts = array(
			'method'            => sanitize_key( (string) $r->get_param( 'method' ) ),
			'room_ids'          => array_values( array_filter( array_map( 'intval', (array) ( $r->get_param( 'room_ids' ) ?? array() ) ) ) ),
			'ignore_dirty'      => (bool) $r->get_param( 'ignore_dirty' ) && $this->policy->can( 'bkng_override_restrictions', $property_id ),
			'can_view_payments' => $this->policy->can( 'bkng_view_payments', $property_id ),
		);
		if ( null !== $r->get_param( 'version' ) ) {
			$opts['version'] = (int) $r->get_param( 'version' );
		}
		return $opts;
	}
}
