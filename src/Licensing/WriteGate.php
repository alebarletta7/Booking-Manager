<?php
/**
 * What the licence lets the plugin's API do, in one of three modes (mode()), ported from
 * Night Manager's WriteGate:
 *
 * - FULL (LicenseManager::is_locked: this site has no activation of its own, because it
 *   was never activated or an administrator deactivated it here): Booking Manager is
 *   locked until the licence is entered (the wizard shows only the Licenza step). On
 *   booking-manager/v1 only the licence routes and /me (+ /me/preferences, the app shell)
 *   answer; everything else, reading included, is 403 bkng_license_required. On the
 *   public namespace the booking form shows that online bookings are closed
 *   (available: false) and a new booking is refused, while the guest's page and
 *   cancellation of bookings already taken still work.
 *
 * - BOOKINGS (activated once, but no licence that allows it: expired, suspended, revoked,
 *   site_changed, recovery_required, invalid, unverified after the offline grace…):
 *   - BLOCKED: new bookings (staff app and public form, holds included) and the
 *     management of rooms (room types and rooms: create, change, archive, restore);
 *   - ALLOWED: everything that serves guests already booked: changes, confirmations,
 *     cancellations, check-in, room assignment, out-of-service periods, guests (privacy
 *     is never blocked), rates, properties, staff, settings, Cloudflare, the Licenza
 *     panel, the guest's page and cancellation.
 *   - A write route in neither list is blocked: in doubt, the gate closes. Reading is
 *     never blocked in this mode.
 *
 * - NONE: a licence that allows new bookings (or a copy without the licence server's
 *   keys, development only).
 *
 * Every API call goes through here (rest_dispatch_request, after the permission check).
 * Tests list every route of the plugin and fail when a write route is not in exactly one
 * of BLOCKED / ALLOWED, and check which routes stay open in the full lock. Nothing is
 * deleted or changed. BookingService also asks the licence (ServerGate), so no other path
 * creates a booking.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

use BookingManager\Rest\PublicController;
use BookingManager\Rest\RestController;

defined( 'ABSPATH' ) || exit;

class WriteGate {

	public const ERROR_CODE = 'bkng_license_required';

	/** Locked: no activation on this site. */
	public const MODE_FULL = 'full';

	/** Activated, but the licence does not allow new bookings now. */
	public const MODE_BOOKINGS = 'bookings';

	/** Nothing is blocked. */
	public const MODE_NONE = 'none';

	/** What decide() says about a call. */
	public const PASS     = 'pass';
	public const LOCKED   = 'locked';
	public const BOOKINGS = 'bookings';

	/**
	 * Full lock: the only paths that answer, whatever the method (as local_path() writes
	 * them). The public property pages answer with available: false (public_property()).
	 */
	public const LOCK_ALLOWED = array(
		'#^/me(/preferences)?$#',
		'#^/license(/(activate|refresh|deactivate|updates/check))?$#',
		'#^/public/properties(/[a-z0-9-]+)?$#',
		'#^/public/manage/[A-Za-z0-9]{6,16}(/(cancel|pay))?$#',
		// Money already taken must still be settled (confirmed or refunded).
		'#^/webhooks/[a-z0-9_-]{2,20}/\d+$#',
	);

	/**
	 * Paths inside booking-manager/v1; the public namespace is written as /public/….
	 * Blocked without a licence: new bookings (staff and online, holds included) and the
	 * management of rooms (types and rooms: create, change, archive).
	 */
	public const BLOCKED = array(
		'#^/properties/\d+/bookings$#',
		'#^/public/properties/[a-z0-9-]+/(bookings|holds)$#',
		'#^/properties/\d+/(room-types|units)$#',
		'#^/room-types/\d+(/archive)?$#',
		'#^/units/\d+(/(archive|restore))?$#',
	);

	public const ALLOWED = array(
		'#^/me/preferences$#',
		'#^/properties(/\d+)?$#',
		'#^/properties/\d+/(settings|archive|restore)$#',
		'#^/properties/\d+/(rate-plans|adjustments|restrictions|prices)$#',
		'#^/(rate-plans|adjustments|restrictions|prices)/\d+(/archive)?$#',
		'#^/units/\d+/blocks$#',
		'#^/blocks/\d+$#',
		'#^/bookings/\d+(/(stay|status))?$#',
		'#^/booking-rooms/\d+/(assign|move)$#',
		'#^/guests/\d+(/anonymize)?$#',
		'#^/staff(/\d+)?$#',
		'#^/roles/[a-z_]+$#',
		'#^/settings$#',
		'#^/cloudflare(/(verify|rules))?$#',
		'#^/license/(activate|refresh|deactivate|updates/check)$#',
		'#^/setup/(page|finish)$#',
		'#^/public/holds/release$#',
		'#^/public/manage/[A-Za-z0-9]{6,16}/(cancel|pay)$#',
		'#^/properties/\d+/payment-accounts(/select|/[a-z0-9_-]{2,20}/(test|live)(/verify)?)?$#',
		'#^/bookings/\d+/(payments|payment-link)$#',
		'#^/payments/\d+/(refund|sync)$#',
		'#^/webhooks/[a-z0-9_-]{2,20}/\d+$#',
	);

	public function __construct( private LicenseManager $license ) {}

	public function hooks(): void {
		add_filter( 'rest_dispatch_request', array( $this, 'guard' ), 10, 2 );
		add_filter( 'rest_post_dispatch', array( $this, 'public_property' ), 10, 3 );
	}

	/**
	 * A route of the plugin as the lists write it ("/bookings/12", "/public/properties/x"),
	 * null for any other namespace.
	 */
	public static function local_path( string $route ): ?string {
		foreach ( array( PublicController::REST_NAMESPACE => '/public', RestController::REST_NAMESPACE => '' ) as $namespace => $prefix ) {
			$base = '/' . $namespace;
			if ( $route === $base ) {
				return '' === $prefix ? '/' : $prefix;
			}
			if ( str_starts_with( $route, $base . '/' ) ) {
				return $prefix . substr( $route, strlen( $base ) );
			}
		}
		return null;
	}

	/**
	 * blocked | allowed for a path as local_path() writes it. A path in neither list, or
	 * in both, is blocked.
	 */
	public static function classify( string $path ): string {
		$match = static fn( array $patterns ) => (bool) array_filter( $patterns, static fn( string $p ) => (bool) preg_match( $p, $path ) );
		return ! $match( self::BLOCKED ) && $match( self::ALLOWED ) ? 'allowed' : 'blocked';
	}

	public static function is_read( string $method ): bool {
		return in_array( strtoupper( $method ), array( 'GET', 'HEAD', 'OPTIONS' ), true );
	}

	/** full | bookings | none for a licence. */
	public static function mode_for( bool $locked, bool $can_write ): string {
		if ( $locked ) {
			return self::MODE_FULL;
		}
		return $can_write ? self::MODE_NONE : self::MODE_BOOKINGS;
	}

	/** The mode of this site now. */
	public function mode(): string {
		return self::mode_for( $this->license->is_locked(), $this->license->can_write() );
	}

	/** In the full lock allow-list (a path as local_path() writes it). */
	public static function lock_allows( string $method, string $path ): bool {
		$open = (bool) array_filter( self::LOCK_ALLOWED, static fn( string $p ) => (bool) preg_match( $p, $path ) );
		// The public property pages are read-only; what completes a booking may write.
		return $open && ( self::is_read( $method ) || ! str_starts_with( $path, '/public/properties' ) );
	}

	/**
	 * The rule (tested): pass, locked (full lock) or bookings (a new booking without a
	 * licence that allows it) for a call in a mode. Routes of other plugins always pass.
	 */
	public static function decide( string $mode, string $method, string $route ): string {
		if ( self::MODE_NONE === $mode ) {
			return self::PASS;
		}
		$path = self::local_path( $route );
		if ( null === $path ) {
			return self::PASS;
		}
		if ( self::MODE_FULL === $mode ) {
			return self::lock_allows( $method, $path ) ? self::PASS : self::LOCKED;
		}
		return self::is_read( $method ) || 'allowed' === self::classify( $path ) ? self::PASS : self::BOOKINGS;
	}

	/**
	 * Filter rest_dispatch_request: runs after the permission check, so only who may make
	 * the call reads why it is blocked.
	 *
	 * @param mixed $response null, or what another filter already answered
	 * @return mixed
	 */
	public function guard( $response, \WP_REST_Request $request ) {
		if ( null !== $response ) {
			return $response;
		}
		$route = (string) $request->get_route();
		if ( null === self::local_path( $route ) ) {
			return $response; // Not ours: the licence is not even read.
		}
		$decision = self::decide( $this->mode(), $request->get_method(), $route );
		if ( self::PASS === $decision ) {
			return $response;
		}
		$path = (string) self::local_path( $route );
		if ( str_starts_with( $path, '/public' ) ) {
			// Visitors never read about licences.
			$message = __( 'Le prenotazioni online non sono disponibili in questo momento. Contatta la struttura.', 'booking-manager' );
		} elseif ( self::LOCKED === $decision ) {
			$message = $this->license->locked_text( current_user_can( 'manage_options' ) );
		} else {
			$message = $this->license->read_only_reason();
		}
		return new \WP_Error( self::ERROR_CODE, $message, array( 'status' => 403 ) );
	}

	/**
	 * Filter rest_post_dispatch: the public form says at once that online bookings are
	 * closed (available: false), instead of failing at the end of the form.
	 *
	 * @param mixed $response
	 * @param mixed $server
	 * @return mixed
	 */
	public function public_property( $response, $server, \WP_REST_Request $request ) {
		unset( $server );
		if ( ! $response instanceof \WP_REST_Response || 'GET' !== $request->get_method() || 200 !== $response->get_status()
			|| ! preg_match( '#^/public/properties(/[a-z0-9-]+)?$#', (string) self::local_path( (string) $request->get_route() ) ) ) {
			return $response;
		}
		if ( self::MODE_NONE === $this->mode() ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$response->set_data( self::closed( $data ) );
		}
		return $response;
	}

	/**
	 * A property (or a list of them) with online bookings closed.
	 *
	 * @param array<int|string, mixed> $data
	 * @return array<int|string, mixed>
	 */
	public static function closed( array $data ): array {
		if ( array_key_exists( 'available', $data ) ) {
			$data['available'] = false;
			return $data;
		}
		foreach ( $data as $i => $item ) {
			if ( is_array( $item ) && array_key_exists( 'available', $item ) ) {
				$data[ $i ]['available'] = false;
			}
		}
		return $data;
	}
}
