<?php
/**
 * Guest cards:
 *
 *   GET  /guests?search                 guests with bookings in the caller's properties
 *   GET  /guests/{id}                   card, bookings, possible duplicates
 *   PUT  /guests/{id}                   contacts and notes (bkng_edit_guest)
 *   GET  /guests/{id}/export            everything stored (bkng_export_guest_data)
 *   POST /guests/{id}/anonymize         right to erasure (bkng_anonymize_guest)
 *
 * A guest card is shared by the properties where the guest stayed: the staff of one of
 * them sees it, anybody else gets 404. Export and anonymization touch every property,
 * so they also need access to all properties.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Guests\GuestRepository;
use BookingManager\Guests\GuestService;

defined( 'ABSPATH' ) || exit;

class GuestsController extends RestController {

	public function __construct( Policy $policy, private GuestService $service, private GuestRepository $repo, private BookingRepository $bookings ) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/guests',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $this->require_cap( 'bkng_view_guests' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/guests/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $this->require_guest( 'bkng_view_guests' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, "update" ),
					'permission_callback' => $this->require_guest( 'bkng_edit_guest' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/guests/(?P<id>\d+)/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->export( (int) $r['id'] ) ),
				'permission_callback' => $this->require_guest( 'bkng_export_guest_data', true ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/guests/(?P<id>\d+)/anonymize',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->anonymize( (int) $r['id'] ) ),
				'permission_callback' => $this->require_guest( 'bkng_anonymize_guest', true ),
			)
		);
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = max( 1, min( 100, (int) ( $request['per_page'] ?? 30 ) ) );
		$page     = max( 1, (int) ( $request['page'] ?? 1 ) );
		$allowed  = $this->policy->scope()->allowed_ids( get_current_user_id() );
		$rows     = $this->repo->search( mb_substr( sanitize_text_field( (string) $request['search'] ), 0, 100 ), $allowed, $per_page, ( $page - 1 ) * $per_page );
		return rest_ensure_response( array_map( array( self::class, 'card' ), $rows ) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = (int) $request['id'];
		$allowed = $this->policy->scope()->allowed_ids( get_current_user_id() );
		$history = array();
		foreach ( $this->visible_properties( $id ) as $property_id ) {
			$found = $this->bookings->search(
				array(
					'property_id' => $property_id,
					'guest_id'    => $id,
					'limit'       => 100,
				)
			);
			$history = array_merge( $history, array_map( array( BookingsController::class, 'summary' ), $found['items'] ) );
		}
		usort( $history, static fn( array $a, array $b ) => strcmp( $b['arrival'], $a['arrival'] ) );
		// Possible duplicates: only cards the caller could open anyway.
		$duplicates = array_values(
			array_filter(
				$this->service->possible_duplicates( $id ),
				fn( array $d ) => null === $allowed || array_intersect( $allowed, $this->repo->property_ids( $d['id'] ) )
			)
		);
		return rest_ensure_response(
			self::card( (array) $this->repo->find( $id ) ) + array(
				'bookings'   => $history,
				'duplicates' => $duplicates,
				'consents'   => $this->repo->consents( $id ),
				// WhatsApp messages unless the guest said no.
				'whatsapp'   => false !== $this->repo->latest_consent( $id, 'whatsapp' ),
			)
		);
	}

	/**
	 * permission_callback: capability + a booking of the guest in a property of the caller.
	 * Missing and not visible answer the same 404.
	 */
	private function require_guest( string $cap, bool $all_properties = false ): callable {
		return function ( \WP_REST_Request $request ) use ( $cap, $all_properties ) {
			$basic = $this->policy->authorize( $cap );
			if ( true !== $basic ) {
				return $basic;
			}
			$id    = (int) $request['id'];
			$guest = $this->repo->find( $id );
			if ( ! $guest || ( null !== $guest['anonymized_at'] && ! $all_properties ) || ! $this->visible_properties( $id ) ) {
				return GuestService::not_found();
			}
			if ( $all_properties && ! $this->policy->can( 'bkng_access_all_properties' ) ) {
				return new \WP_Error( 'rest_forbidden', __( 'Serve l\'accesso a tutte le strutture.', 'booking-manager' ), array( 'status' => 403 ) );
			}
			return true;
		};
	}

	/** @return int[] properties of the guest's bookings that the caller can open with this capability */
	private function visible_properties( int $guest_id ): array {
		$allowed = $this->policy->scope()->allowed_ids( get_current_user_id() );
		$ids     = $this->repo->property_ids( $guest_id );
		return null === $allowed ? $ids : array_values( array_intersect( $ids, $allowed ) );
	}

	/**
	 * @param array<string, mixed> $g
	 * @return array<string, mixed>
	 */
	public static function card( array $g ): array {
		return array(
			'id'            => (int) $g['id'],
			'uuid'          => (string) $g['uuid'],
			'first_name'    => (string) $g['first_name'],
			'last_name'     => (string) $g['last_name'],
			'email'         => $g['email'],
			'phone'         => $g['phone'],
			'country'       => $g['country'],
			'language'      => $g['language'],
			'notes'         => $g['notes'],
			'stays_count'   => (int) ( $g['stays_count'] ?? 0 ),
			'anonymized'    => null !== ( $g['anonymized_at'] ?? null ),
			'version'       => (int) ( $g['version'] ?? 0 ),
		);
	}
}
