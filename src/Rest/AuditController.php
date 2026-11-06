<?php
/**
 * /audit-log?property_id — the operations log (bkng_view_audit_log on that property).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Audit\AuditLogReader;
use BookingManager\Core\Validation;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class AuditController extends RestController {

	public function __construct( Policy $policy, private AuditLogReader $reader, private PropertyRepository $properties ) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/audit-log',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $this->require_cap_for_property( 'bkng_view_audit_log' ),
				'args'                => array(
					'property_id'    => self::property_arg(),
					'entity_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'action'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'user_id'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'from'        => array( 'type' => 'string' ),
					'to'          => array( 'type' => 'string' ),
					'page'        => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 50,
					),
				),
			)
		);
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$filters = array(
			'entity_type' => $request->get_param( 'entity_type' ),
			'action'      => $request->get_param( 'action' ),
			'user_id'     => (int) $request->get_param( 'user_id' ),
			'entity_id'   => (int) $request->get_param( 'entity_id' ),
			'page'        => (int) $request->get_param( 'page' ),
			'per_page'    => (int) $request->get_param( 'per_page' ),
		);
		foreach ( array( 'from', 'to' ) as $key ) {
			$raw = $request->get_param( $key );
			if ( Validation::blank( $raw ) ) {
				continue;
			}
			$filters[ $key ] = Validation::date( $raw );
			if ( null === $filters[ $key ] ) {
				return Validation::error( array( $key => __( 'Data non valida (AAAA-MM-GG).', 'booking-manager' ) ) );
			}
		}
		$property_id = (int) $request->get_param( 'property_id' );
		$tz       = (string) ( $this->properties->find( $property_id )['timezone'] ?? 'UTC' );
		return self::paged( $this->reader->search( $property_id, $filters, $tz ), $filters['per_page'] );
	}
}
