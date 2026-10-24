<?php
/**
 * The first-installation wizard (Configurazione guidata), for WordPress administrators.
 *
 * The first step is the licence: until a licence is activated the app shows only that
 * step (AdminApp / LicenseManager::is_locked). Then:
 *
 *   GET  /setup          what is already there: the first property, its room types and
 *                        rooms, the default rate, the booking page
 *   POST /setup/page     creates and publishes the booking page with the shortcode
 *   POST /setup/finish   the wizard is done (or skipped): it is not offered again
 *
 * The wizard saves the property, rooms and rates through the usual routes (/properties,
 * /properties/{id}/room-types, /properties/{id}/units, /properties/{id}/rate-plans),
 * with their checks. It opens by itself only after a first activation (state());
 * Impostazioni can reopen it.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Audit\AuditLogger;
use BookingManager\Frontend\PublicFrontend;
use BookingManager\Inventory\InventoryService;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Inventory\UnitRepository;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Properties\PropertyService;
use BookingManager\Rates\RateRepository;

defined( 'ABSPATH' ) || exit;

class SetupController extends RestController {

	public const OPTION = 'bkng_setup';

	/** The wizard is still to do. */
	public const PENDING = 'pending';

	/** Done or skipped. */
	public const DONE = 'done';

	public function __construct(
		Policy $policy,
		private PropertyRepository $properties,
		private RoomTypeRepository $types,
		private UnitRepository $units,
		private RateRepository $rates,
		private AuditLogger $audit
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/setup',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => $this->admin(),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/setup/page',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_page' ),
				'permission_callback' => $this->admin(),
				'args'                => array(
					'property_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'title'       => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/setup/finish',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'finish' ),
				'permission_callback' => $this->admin(),
				'args'                => array(
					'skipped' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/** WordPress administrators only, as for the licence. */
	private function admin(): callable {
		return function () {
			$basic = $this->policy->authorize( 'bkng_manage_settings' );
			if ( true !== $basic ) {
				return $basic;
			}
			return current_user_can( 'manage_options' )
				? true
				: new \WP_Error( 'bkng_forbidden', __( 'Solo un amministratore di WordPress può usare la configurazione guidata.', 'booking-manager' ), array( 'status' => 403 ) );
		};
	}

	/**
	 * pending | done. Activation on a site with no property yet stores "pending"
	 * (Installer).
	 */
	public function state(): string {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) && self::PENDING === ( $stored['state'] ?? '' ) ? self::PENDING : self::DONE;
	}

	public function show(): \WP_REST_Response {
		$first = null;
		foreach ( $this->properties->all( null, false ) as $property ) {
			$first = $property;
			break;
		}
		$id = $first ? (int) $first['id'] : 0;
		return rest_ensure_response(
			array(
				'state'          => $this->state(),
				'property'       => $first ? PropertyService::payload( $first ) : null,
				'room_types'     => $id ? array_map( array( InventoryService::class, 'type_payload' ), $this->types->for_property( $id ) ) : array(),
				'units'          => $id ? array_map( array( InventoryService::class, 'unit_payload' ), $this->units->for_property( $id ) ) : array(),
				'rate_plans'     => $id ? $this->rates->plans( $id ) : array(),
				'page'           => $first ? $this->page_of( (string) $first['slug'] ) : null,
				'site_timezone'  => wp_timezone_string(),
				'admin_email'    => (string) get_option( 'admin_email' ),
				'permalinks_set' => '' !== (string) get_option( 'permalink_structure' ),
				'kinds'          => PropertyService::kind_labels(),
				'check_url'      => $first ? PublicController::url( 'properties/' . $first['slug'] ) : null,
			)
		);
	}

	public function create_page( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$property = $this->properties->find( (int) $request['property_id'] );
		if ( ! $property || 'active' !== $property['status'] ) {
			return PropertyService::not_found();
		}
		$existing = $this->page_of( (string) $property['slug'] );
		if ( $existing ) {
			return rest_ensure_response( $existing + array( 'created' => false ) );
		}
		if ( ! current_user_can( 'publish_pages' ) ) {
			return new \WP_Error( 'bkng_forbidden', __( 'Il tuo account non può pubblicare pagine.', 'booking-manager' ), array( 'status' => 403 ) );
		}
		$title   = trim( sanitize_text_field( (string) $request['title'] ) );
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => '' !== $title ? $title : __( 'Prenota il tuo soggiorno', 'booking-manager' ),
				'post_content' => PublicFrontend::shortcode_for( (string) $property['slug'] ),
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return new \WP_Error( 'bkng_page_failed', __( 'Non è stato possibile creare la pagina.', 'booking-manager' ), array( 'status' => 500 ) );
		}
		PublicFrontend::remember_page( (string) $property['slug'], (int) $page_id );
		$this->audit->log(
			array(
				'action'      => 'setup.page_created',
				'entity_type' => 'property',
				'entity_id'   => (int) $property['id'],
				'property_id' => (int) $property['id'],
				'new'         => array( 'page_id' => (int) $page_id ),
			)
		);
		return rest_ensure_response( (array) $this->page_of( (string) $property['slug'] ) + array( 'created' => true ) );
	}

	public function finish( \WP_REST_Request $request ): \WP_REST_Response {
		update_option(
			self::OPTION,
			array(
				'state'   => self::DONE,
				'at'      => gmdate( 'c' ),
				'skipped' => (bool) $request['skipped'],
			),
			false
		);
		$this->audit->log(
			array(
				'action'      => $request['skipped'] ? 'setup.skipped' : 'setup.finished',
				'entity_type' => 'settings',
			)
		);
		return rest_ensure_response( array( 'state' => self::DONE ) );
	}

	/**
	 * The published page with the property's form.
	 *
	 * @return array{id: int, url: string, edit_url: string, title: string}|null
	 */
	private function page_of( string $slug ): ?array {
		$page_id = PublicFrontend::page_for( $slug );
		if ( ! $page_id ) {
			return null;
		}
		return array(
			'id'       => $page_id,
			'url'      => (string) get_permalink( $page_id ),
			'edit_url' => (string) get_edit_post_link( $page_id, 'raw' ),
			'title'    => (string) get_the_title( $page_id ),
		);
	}
}
