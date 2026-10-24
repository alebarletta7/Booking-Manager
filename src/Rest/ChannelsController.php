<?php
/**
 * Sales channels (prepared for add-on connectors):
 *
 *   GET /properties/{property_id}/channels                                   connectors and latest exchanges
 *   PUT /properties/{property_id}/channels/{channel}/mappings { external_room_type_id, room_type_id }
 *
 * Both need bkng_manage_integrations.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Channels\ChannelService;
use BookingManager\Inventory\RoomTypeRepository;

defined( 'ABSPATH' ) || exit;

class ChannelsController extends RestController {

	public function __construct(
		Policy $policy,
		private ChannelService $service,
		private RoomTypeRepository $types
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$ns   = self::REST_NAMESPACE;
		$base = '/properties/(?P<property_id>\d+)/channels';
		$cap  = $this->require_cap_for_property( 'bkng_manage_integrations' );

		register_rest_route(
			$ns,
			$base,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => rest_ensure_response( $this->service->overview( (int) $r['property_id'] ) ),
				'permission_callback' => $cap,
			)
		);
		register_rest_route(
			$ns,
			$base . '/(?P<channel>[a-z0-9_-]{2,40})/mappings',
			array(
				'methods'             => 'PUT',
				'callback'            => function ( \WP_REST_Request $r ) {
					$property_id = (int) $r['property_id'];
					$type        = $this->types->find( (int) $r->get_param( 'room_type_id' ) );
					$external    = substr( trim( sanitize_text_field( (string) $r->get_param( 'external_room_type_id' ) ) ), 0, 190 );
					if ( ! $type || (int) $type['property_id'] !== $property_id || '' === $external ) {
						return new \WP_Error( 'bkng_invalid', __( 'Tipologia o codice del canale non validi.', 'booking-manager' ), array( 'status' => 400 ) );
					}
					$this->service->map_room_type( $property_id, (string) $r['channel'], $external, (int) $type['id'] );
					return rest_ensure_response( $this->service->overview( $property_id ) );
				},
				'permission_callback' => $cap,
			)
		);
	}
}
