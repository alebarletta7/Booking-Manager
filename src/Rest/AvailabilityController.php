<?php
/**
 * Availability and the planning for the staff app:
 *
 *   GET /properties/{property_id}/availability?from&to          free rooms per type and night
 *   GET /properties/{property_id}/availability/search?arrival&departure&adults&children
 *   GET /properties/{property_id}/planning?from&to              rooms, stays, blocks
 *   GET /properties/{property_id}/day?date                      arrivals, departures, in house
 *   GET /properties/{property_id}/dashboard?date                KPIs
 *
 * Read-only. Housekeeping staff see the planning (rooms and stays, no prices); the
 * search with prices needs bkng_create_booking.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Availability\AvailabilityService;
use BookingManager\Calendar\PlanningService;
use BookingManager\Core\Clock;
use BookingManager\Core\Validation;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Properties\PropertyService;
use BookingManager\Reports\DashboardService;

defined( 'ABSPATH' ) || exit;

class AvailabilityController extends RestController {

	public function __construct(
		Policy $policy,
		private AvailabilityService $availability,
		private PlanningService $planning,
		private DashboardService $dashboard,
		private PropertyRepository $properties,
		private Clock $clock
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$routes = array(
			'availability'        => array( 'grid', 'bkng_view_bookings' ),
			'availability/search' => array( 'search', 'bkng_create_booking' ),
			'planning'            => array( 'planning', 'bkng_view_housekeeping' ),
			'day'                 => array( 'day', 'bkng_view_housekeeping' ),
			'dashboard'           => array( 'dashboard', 'bkng_view_dashboard' ),
		);
		foreach ( $routes as $path => $route ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/properties/(?P<property_id>\d+)/' . $path,
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, $route[0] ),
					'permission_callback' => $this->require_cap_for_property( $route[1] ),
				)
			);
		}
	}

	public function grid( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$range = self::range( $request, 'from', 'to', 120 );
		if ( $range instanceof \WP_Error ) {
			return $range;
		}
		return rest_ensure_response( $this->availability->grid( (int) $request['property_id'], $range[0], $range[1] ) );
	}

	public function search( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$range = self::range( $request, 'arrival', 'departure', 365 );
		if ( $range instanceof \WP_Error ) {
			return $range;
		}
		$property = $this->properties->find( (int) $request['property_id'] );
		if ( ! $property ) {
			return PropertyService::not_found();
		}
		$adults   = max( 1, min( 50, (int) ( $request['adults'] ?? 2 ) ) );
		$children = max( 0, min( 50, (int) ( $request['children'] ?? 0 ) ) );
		$extras   = array_values( array_filter( array_map( 'intval', (array) ( $request['extras'] ?? array() ) ) ) );
		return rest_ensure_response( $this->availability->search( $property, $range[0], $range[1], $adults, $children, false, $extras ) );
	}

	public function planning( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$range = self::range( $request, 'from', 'to', 62 );
		if ( $range instanceof \WP_Error ) {
			return $range;
		}
		$data = $this->planning->planning( (int) $request['property_id'], $range[0], $range[1] );
		if ( ! $this->policy->can( 'bkng_view_bookings', (int) $request['property_id'] ) ) {
			$data = self::without_commercial_data( $data );
		}
		return rest_ensure_response( $data );
	}

	public function day( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$date = self::optional_date( $request['date'] );
		if ( $date instanceof \WP_Error ) {
			return $date;
		}
		$property = $this->properties->find( (int) $request['property_id'] );
		if ( ! $property ) {
			return PropertyService::not_found();
		}
		$data = $this->planning->day( (int) $property['id'], $date ?? $this->clock->today_in( (string) $property['timezone'] ) );
		if ( ! $this->policy->can( 'bkng_view_bookings', (int) $property['id'] ) ) {
			unset( $data['pending'] );
			$data = self::without_commercial_data( $data );
		}
		return rest_ensure_response( $data );
	}

	public function dashboard( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$date = self::optional_date( $request['date'] );
		if ( $date instanceof \WP_Error ) {
			return $date;
		}
		$summary = $this->dashboard->summary( (int) $request['property_id'], $date );
		if ( null === $summary ) {
			return PropertyService::not_found();
		}
		if ( ! $this->policy->can( 'bkng_view_payments', (int) $request['property_id'] ) ) {
			unset( $summary['balance_due'], $summary['balance_due_7_days'], $summary['deposits_overdue'] );
		}
		return rest_ensure_response( $summary );
	}

	/**
	 * Housekeeping sees rooms and who arrives or leaves, never prices, contacts or notes.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function without_commercial_data( array $data ): array {
		$hidden = array( 'total', 'balance', 'paid', 'price_total', 'payment_status', 'email', 'phone', 'internal_notes', 'customer_notes', 'source', 'code' );
		$walk   = static function ( $value ) use ( &$walk, $hidden ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			foreach ( $hidden as $key ) {
				unset( $value[ $key ] );
			}
			return array_map( $walk, $value );
		};
		return $walk( $data );
	}

	/** @return array{0: string, 1: string}|\WP_Error */
	private static function range( \WP_REST_Request $request, string $from_key, string $to_key, int $max ): array|\WP_Error {
		$from = Validation::date( $request[ $from_key ] );
		$to   = Validation::date( $request[ $to_key ] );
		if ( null === $from || null === $to || ! AvailabilityService::valid_range( $from, $to, $max ) ) {
			return Validation::error(
				array(
					/* translators: %d: maximum number of nights */
					$to_key => sprintf( __( 'Periodo non valido (massimo %d notti).', 'booking-manager' ), $max ),
				)
			);
		}
		return array( $from, $to );
	}

	/** @param mixed $value */
	private static function optional_date( $value ): string|\WP_Error|null {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$date = Validation::date( $value );
		return null === $date ? Validation::error( array( 'date' => __( 'Data non valida.', 'booking-manager' ) ) ) : $date;
	}
}
