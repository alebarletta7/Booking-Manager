<?php
/**
 * Bookings for the staff app:
 *
 *   GET   /properties/{property_id}/bookings       list with filters (paged)
 *   POST  /properties/{property_id}/bookings       new booking (manual, phone, walk-in)
 *   GET   /bookings/{id}
 *   PATCH /bookings/{id}                           holder contacts, notes, arrival time, names
 *   PUT   /bookings/{id}/stay                      dates, rooms, guests per room, extras
 *   POST  /bookings/{id}/status                    commercial status (confirm, cancel, no-show…)
 *   POST  /booking-rooms/{id}/assign               room assigned to a stay (or none)
 *   POST  /booking-rooms/{id}/move                 drag in the planning
 *   GET   /bookings/{id}/history                   audit entries of the booking
 *
 * Every write sends the "version" read with the booking: a stale one answers 409.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Audit\AuditLogReader;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingService;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Core\Validation;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class BookingsController extends RestController {

	/** Capability needed to move a booking INTO each status. */
	private const STATUS_CAPS = array(
		BookingStatus::PENDING   => 'bkng_edit_booking',
		BookingStatus::OPTION    => 'bkng_edit_booking',
		BookingStatus::CONFIRMED => 'bkng_confirm_booking',
		BookingStatus::CANCELLED => 'bkng_cancel_booking',
		BookingStatus::NO_SHOW   => 'bkng_mark_no_show',
		BookingStatus::IN_HOUSE  => 'bkng_checkin',
		BookingStatus::COMPLETED => 'bkng_checkin',
	);

	public function __construct(
		Policy $policy,
		private BookingService $service,
		private BookingRepository $repo,
		private PropertyRepository $properties,
		private AuditLogReader $audit
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$booking = fn( int $id ) => $this->repo->find( $id );
		$room    = fn( int $id ) => $this->repo->find_room( $id );

		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<property_id>\d+)/bookings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->require_cap_for_property( 'bkng_view_bookings' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->require_cap_for_property( 'bkng_create_booking' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/bookings/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->payload( (array) $this->repo->find( (int) $r['id'] ) ) ),
					'permission_callback' => $this->require_cap_for_record( 'bkng_view_bookings', $booking ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->update_details( (int) $r['id'], (array) $r->get_json_params(), get_current_user_id() ) ),
					'permission_callback' => $this->require_cap_for_record( 'bkng_edit_booking', $booking ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/bookings/(?P<id>\d+)/stay',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'change_stay' ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_edit_booking', $booking ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/bookings/(?P<id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'status' ),
				// The capability depends on the target status: checked in the callback.
				'permission_callback' => $this->require_cap_for_record( 'bkng_view_bookings', $booking ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/booking-rooms/(?P<id>\d+)/assign',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'assign' ),
				'permission_callback' => $this->require_any( $this->require_cap_for_record( 'bkng_edit_booking', $room ), $this->require_cap_for_record( 'bkng_checkin', $room ) ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/booking-rooms/(?P<id>\d+)/move',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'move' ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_edit_booking', $room ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/bookings/(?P<id>\d+)/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'history' ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_view_bookings', $booking ),
			)
		);
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$filters = array( 'property_id' => (int) $request['property_id'] );
		foreach ( array( 'from', 'to', 'arrival', 'departure' ) as $key ) {
			if ( null !== $request[ $key ] && '' !== $request[ $key ] ) {
				$date = Validation::date( $request[ $key ] );
				if ( null === $date ) {
					return Validation::error( array( $key => __( 'Data non valida.', 'booking-manager' ) ) );
				}
				$filters[ $key ] = $date;
			}
		}
		if ( null !== $request['status'] ) {
			$filters['status'] = is_array( $request['status'] ) ? array_map( 'strval', $request['status'] ) : explode( ',', (string) $request['status'] );
		}
		foreach ( array( 'source', 'payment_status', 'order' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$filters[ $key ] = sanitize_key( (string) $request[ $key ] );
			}
		}
		if ( null !== $request['search'] ) {
			$filters['search'] = mb_substr( sanitize_text_field( (string) $request['search'] ), 0, 100 );
		}
		if ( null !== $request['guest_id'] ) {
			$filters['guest_id'] = absint( $request['guest_id'] );
		}
		$per_page           = max( 1, min( 200, (int) ( $request['per_page'] ?? 50 ) ) );
		$page               = max( 1, (int) ( $request['page'] ?? 1 ) );
		$filters['limit']   = $per_page;
		$filters['offset']  = ( $page - 1 ) * $per_page;
		$result             = $this->repo->search( $filters );
		$result['items']    = array_map( array( self::class, 'summary' ), $result['items'] );
		return self::paged( $result, $per_page );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$property_id = (int) $request['property_id'];
		$input       = array( 'property_id' => $property_id ) + (array) $request->get_json_params();
		$input['property_id'] = $property_id;
		$status = (string) ( $input['status'] ?? BookingStatus::CONFIRMED );
		// Registering a booking directly as confirmed is a confirmation.
		if ( BookingStatus::CONFIRMED === $status && ! $this->policy->can( 'bkng_confirm_booking', $property_id ) ) {
			$input['status'] = BookingStatus::PENDING;
		}
		return self::respond( $this->service->create( $input, $this->ctx( $property_id ) + array( 'channel' => 'staff' ) ), 201 );
	}

	public function change_stay( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$booking = (array) $this->repo->find( (int) $request['id'] );
		return self::respond( $this->service->change_stay( (int) $request['id'], (array) $request->get_json_params(), $this->ctx( (int) $booking['property_id'] ) ) );
	}

	public function status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$booking = (array) $this->repo->find( (int) $request['id'] );
		$body    = (array) $request->get_json_params();
		$to      = (string) ( $body['status'] ?? '' );
		if ( ! isset( self::STATUS_CAPS[ $to ] ) ) {
			return Validation::error( array( 'status' => __( 'Stato non valido.', 'booking-manager' ) ) );
		}
		// Undoing a check-in (in house → confirmed) is a front-desk operation.
		$cap = BookingStatus::IN_HOUSE === $booking['status'] && BookingStatus::CONFIRMED === $to ? 'bkng_checkin' : self::STATUS_CAPS[ $to ];
		$ok  = $this->policy->authorize( $cap, (int) $booking['property_id'] );
		if ( true !== $ok ) {
			return $ok;
		}
		if ( BookingStatus::CANCELLED === $to && '' === trim( (string) ( $body['reason'] ?? '' ) ) ) {
			return Validation::error( array( 'reason' => __( 'Indica il motivo dell\'annullamento.', 'booking-manager' ) ) );
		}
		return self::respond(
			$this->service->set_status(
				(int) $request['id'],
				(int) ( $body['version'] ?? 0 ),
				$to,
				array(
					'reason'  => $body['reason'] ?? null,
					'user_id' => get_current_user_id(),
				)
			)
		);
	}

	public function assign( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body    = (array) $request->get_json_params();
		$unit_id = isset( $body['unit_id'] ) && null !== $body['unit_id'] ? (int) $body['unit_id'] : null;
		return self::respond( $this->service->assign_unit( (int) $request['id'], $unit_id, (int) ( $body['version'] ?? 0 ), get_current_user_id() ) );
	}

	public function move( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$room = (array) $this->repo->find_room( (int) $request['id'] );
		return self::respond( $this->service->move_room( (int) $request['id'], (array) $request->get_json_params(), $this->ctx( (int) $room['property_id'] ) ) );
	}

	public function history( \WP_REST_Request $request ): \WP_REST_Response {
		$booking  = (array) $this->repo->find( (int) $request['id'] );
		$property = (array) $this->properties->find( (int) $booking['property_id'] );
		$result   = $this->audit->search(
			(int) $booking['property_id'],
			array(
				'entity_type' => 'booking',
				'entity_id'   => (int) $booking['id'],
				'per_page'    => 100,
			),
			(string) ( $property['timezone'] ?? 'UTC' )
		);
		return rest_ensure_response( $result['items'] );
	}

	/**
	 * What the caller may do beyond the basic operation.
	 *
	 * @return array{user_id: int, can_override: bool, can_price: bool}
	 */
	private function ctx( int $property_id ): array {
		return array(
			'user_id'      => get_current_user_id(),
			'can_override' => $this->policy->can( 'bkng_override_restrictions', $property_id ),
			// A price agreed by hand is a commercial decision: same people who set the rates.
			'can_price'    => $this->policy->can( 'bkng_manage_rates', $property_id ),
		);
	}

	/**
	 * A row of the bookings list: no notes, no price breakdown.
	 *
	 * @param array<string, mixed> $b
	 * @return array<string, mixed>
	 */
	public static function summary( array $b ): array {
		return array(
			'id'             => (int) $b['id'],
			'uuid'           => (string) $b['uuid'],
			'code'           => (string) $b['code'],
			'status'         => (string) $b['status'],
			'payment_status' => (string) $b['payment_status'],
			'source'         => (string) $b['source'],
			'first_name'     => (string) $b['first_name'],
			'last_name'      => (string) $b['last_name'],
			'arrival'        => (string) $b['arrival'],
			'departure'      => (string) $b['departure'],
			'nights'         => (int) $b['nights'],
			'adults'         => (int) $b['adults'],
			'children'       => (int) $b['children'],
			'total'          => (int) $b['total'],
			'balance'        => (int) $b['balance'],
			'currency'       => (string) $b['currency'],
			'version'        => (int) $b['version'],
		);
	}
}
