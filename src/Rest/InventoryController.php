<?php
/**
 * Room types and rooms (units), and their out-of-service periods:
 *
 *   GET  /properties/{property_id}/room-types        POST (new type)
 *   PUT  /room-types/{id}                            POST /room-types/{id}/archive
 *   GET  /properties/{property_id}/units             POST (new room)
 *   POST /properties/{property_id}/units/batch       (several rooms of one type)
 *   PUT  /units/{id}                                 POST /units/{id}/archive|restore
 *   GET  /properties/{property_id}/blocks?from&to    POST /units/{id}/blocks
 *   DELETE /blocks/{id}
 *
 * Reading needs bkng_access; changes need bkng_manage_rooms, blocks bkng_update_housekeeping
 * or bkng_manage_rooms.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Core\Dates;
use BookingManager\Core\Validation;
use BookingManager\Inventory\InventoryService;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Inventory\UnitRepository;

defined( 'ABSPATH' ) || exit;

class InventoryController extends RestController {

	public function __construct( Policy $policy, private InventoryService $service, private RoomTypeRepository $types, private UnitRepository $units ) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$type  = fn( int $id ) => $this->types->find( $id );
		$unit  = fn( int $id ) => $this->units->find( $id );
		$block = fn( int $id ) => $this->units->find_block( $id );
		$rooms = $this->require_cap_for_property( 'bkng_manage_rooms' );

		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<property_id>\d+)/room-types',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => rest_ensure_response( array_map( array( InventoryService::class, 'type_payload' ), $this->types->for_property( (int) $r['property_id'], rest_sanitize_boolean( $r['include_archived'] ) ) ) ),
					'permission_callback' => $this->require_cap_for_property( 'bkng_access' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->create_type( (int) $r['property_id'], (array) $r->get_json_params() ), 201 ),
					'permission_callback' => $rooms,
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/room-types/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->update_type( (int) $r['id'], (array) $r->get_json_params() ) ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_manage_rooms', $type ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/room-types/(?P<id>\d+)/archive',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->archive_type( (int) $r['id'] ) ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_manage_rooms', $type ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<property_id>\d+)/units',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => rest_ensure_response( array_map( array( InventoryService::class, 'unit_payload' ), $this->units->for_property( (int) $r['property_id'], rest_sanitize_boolean( $r['include_archived'] ) ) ) ),
					'permission_callback' => $this->require_cap_for_property( 'bkng_access' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->create_unit( (int) $r['property_id'], (array) $r->get_json_params() ), 201 ),
					'permission_callback' => $rooms,
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<property_id>\d+)/units/batch',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->create_units( (int) $r['property_id'], (array) $r->get_json_params() ), 201 ),
				'permission_callback' => $rooms,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/units/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->update_unit( (int) $r['id'], (array) $r->get_json_params() ) ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_manage_rooms', $unit ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/units/(?P<id>\d+)/(?P<action>archive|restore)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( 'archive' === $r['action'] ? $this->service->archive_unit( (int) $r['id'] ) : $this->service->restore_unit( (int) $r['id'] ) ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_manage_rooms', $unit ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<property_id>\d+)/blocks',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'blocks' ),
				'permission_callback' => $this->require_cap_for_property( 'bkng_access' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/units/(?P<id>\d+)/blocks',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->add_block( (int) $r['id'], (array) $r->get_json_params(), get_current_user_id() ), 201 ),
				'permission_callback' => $this->require_any( $this->require_cap_for_record( 'bkng_manage_rooms', $unit ), $this->require_cap_for_record( 'bkng_update_housekeeping', $unit ) ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/blocks/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->remove_block( (int) $r['id'] ) ),
				'permission_callback' => $this->require_any( $this->require_cap_for_record( 'bkng_manage_rooms', $block ), $this->require_cap_for_record( 'bkng_update_housekeeping', $block ) ),
			)
		);
	}

	public function blocks( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$from = Validation::date( $request['from'] );
		$to   = Validation::date( $request['to'] );
		if ( null === $from || null === $to || Dates::count( $from, $to ) < 1 || Dates::count( $from, $to ) > 400 ) {
			return Validation::error( array( 'to' => __( 'Periodo non valido.', 'booking-manager' ) ) );
		}
		return rest_ensure_response( $this->units->blocks_between( (int) $request['property_id'], $from, $to ) );
	}
}
