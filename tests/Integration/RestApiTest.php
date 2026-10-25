<?php
/**
 * The REST API through the real WordPress REST server: permissions per role and per
 * property, the staff booking flow, the public form flow, and the licence gate's
 * classification of every write route.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Access\PropertyScope;
use BookingManager\Bookings\ManageLink;
use BookingManager\Frontend\FormToken;
use BookingManager\Licensing\LicenseManager;
use BookingManager\Licensing\WriteGate;
use BookingManager\Rest\PublicController;
use BookingManager\Rest\RestController;
use BookingManager\Tests\TestCase;

final class RestApiTest extends TestCase {

	private \WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
		// The licence server's keys are built in, so the real gate would lock this
		// unactivated test site: the gate is tested on its own (last tests).
		remove_all_filters( 'rest_dispatch_request' );
		remove_all_filters( 'rest_post_dispatch' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $body
	 * @param array<string, mixed> $query
	 */
	private function call( string $method, string $path, array $body = array(), array $query = array(), bool $public = false ): \WP_REST_Response {
		$ns      = $public ? PublicController::REST_NAMESPACE : RestController::REST_NAMESPACE;
		$request = new \WP_REST_Request( $method, '/' . $ns . $path );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		// As WP_REST_Server::serve_request() does after dispatching.
		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $this->server->dispatch( $request ) ), $this->server, $request );
	}

	private function user( string $role, ?int $property_id = null ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		if ( null !== $property_id ) {
			$this->service( PropertyScope::class )->assign( $id, $property_id );
		}
		wp_set_current_user( $id );
		return $id;
	}

	public function test_anonymous_callers_get_401_on_staff_routes(): void {
		wp_set_current_user( 0 );
		$p = $this->property();
		foreach ( array( '/properties', '/properties/' . $p['id'] . '/bookings', '/properties/' . $p['id'] . '/planning', '/guests' ) as $path ) {
			$this->assertSame( 401, $this->call( 'GET', $path, array(), array( 'from' => self::day( 0 ), 'to' => self::day( 7 ) ) )->get_status(), $path );
		}
	}

	public function test_staff_create_and_manage_a_booking(): void {
		$this->admin();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 2 );

		$search = $this->call( 'GET', '/properties/' . $p['id'] . '/availability/search', array(), array( 'arrival' => self::day( 5 ), 'departure' => self::day( 8 ), 'adults' => 2 ) );
		$this->assertSame( 200, $search->get_status() );
		$this->assertSame( $t['type']['id'], $search->get_data()['offers'][0]['room_type_id'] );

		$created = $this->call(
			'POST',
			'/properties/' . $p['id'] . '/bookings',
			array(
				'arrival' => self::day( 5 ),
				'departure' => self::day( 8 ),
				'rooms'   => array( array( 'room_type_id' => $t['type']['id'], 'adults' => 2 ) ),
				'guest'   => array( 'first_name' => 'Anna', 'last_name' => 'Bianchi', 'phone' => '+39 333 1234567' ),
			)
		);
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$b = $created->get_data();
		$this->assertSame( 'confirmed', $b['status'] );

		$list = $this->call( 'GET', '/properties/' . $p['id'] . '/bookings', array(), array( 'search' => 'Bianchi' ) );
		$this->assertSame( '1', $list->get_headers()['X-WP-Total'] );
		$this->assertArrayNotHasKey( 'internal_notes', $list->get_data()[0] );

		$stale = $this->call( 'POST', '/bookings/' . $b['id'] . '/status', array( 'status' => 'cancelled', 'version' => $b['version'] + 5, 'reason' => 'Test' ) );
		$this->assertSame( 409, $stale->get_status() );

		$no_reason = $this->call( 'POST', '/bookings/' . $b['id'] . '/status', array( 'status' => 'cancelled', 'version' => $b['version'] ) );
		$this->assertSame( 400, $no_reason->get_status() );

		$cancel = $this->call( 'POST', '/bookings/' . $b['id'] . '/status', array( 'status' => 'cancelled', 'version' => $b['version'], 'reason' => 'Richiesta ospite' ) );
		$this->assertSame( 200, $cancel->get_status() );
		$this->assertSame( 'cancelled', $cancel->get_data()['status'] );

		$history = $this->call( 'GET', '/bookings/' . $b['id'] . '/history' );
		$this->assertContains( 'booking.status_changed', array_column( $history->get_data(), 'action' ) );
	}

	public function test_staff_of_another_property_get_404_on_its_records(): void {
		$this->admin();
		$mine  = $this->property( array( 'name' => 'Mia' ) );
		$other = $this->property( array( 'name' => 'Altra' ) );
		$t     = $this->room_type( $other['id'], 1 );
		$b     = $this->book( $other['id'], $t['type']['id'], 3, 5 );

		$this->user( 'bkng_property_manager', $mine['id'] );
		$this->assertSame( 404, $this->call( 'GET', '/properties/' . $other['id'] . '/bookings' )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/bookings/' . $b['id'] )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/guests/' . $b['guest_id'] )->get_status() );
		$this->assertSame( 404, $this->call( 'PUT', '/units/' . $t['units'][0]['id'], array( 'name' => 'X' ) )->get_status() );
		$ids = array_column( $this->call( 'GET', '/properties' )->get_data(), 'id' );
		$this->assertSame( array( $mine['id'] ), $ids );
	}

	public function test_housekeeping_sees_rooms_but_not_money_or_contacts(): void {
		$this->admin();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$this->book( $p['id'], $t['type']['id'], 0, 2, array( 'internal_notes' => 'VIP' ) );

		$this->user( 'bkng_housekeeping', $p['id'] );
		$this->assertSame( 403, $this->call( 'GET', '/properties/' . $p['id'] . '/bookings' )->get_status() );
		$planning = $this->call( 'GET', '/properties/' . $p['id'] . '/planning', array(), array( 'from' => self::day( 0 ), 'to' => self::day( 7 ) ) );
		$this->assertSame( 200, $planning->get_status() );
		$stay = $planning->get_data()['stays'][0];
		$this->assertArrayNotHasKey( 'balance', $stay );
		$this->assertArrayNotHasKey( 'code', $stay );
		$day = $this->call( 'GET', '/properties/' . $p['id'] . '/day' )->get_data();
		$this->assertArrayNotHasKey( 'pending', $day );
		$this->assertArrayNotHasKey( 'balance', $day['arrivals'][0] );
		$dash = $this->call( 'GET', '/properties/' . $p['id'] . '/dashboard' )->get_data();
		$this->assertArrayNotHasKey( 'balance_due', $dash );
		// Housekeeping can put a room out of service, not change rooms.
		$this->assertSame( 201, $this->call( 'POST', '/units/' . $t['units'][0]['id'] . '/blocks', array( 'date_from' => self::day( 10 ), 'date_to' => self::day( 12 ), 'reason' => 'Manutenzione' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'PUT', '/units/' . $t['units'][0]['id'], array( 'name' => 'X' ) )->get_status() );
	}

	public function test_front_desk_cannot_confirm_or_cancel_but_can_check_in(): void {
		$this->admin();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$b = $this->book( $p['id'], $t['type']['id'], 0, 2 );

		$this->user( 'bkng_front_desk', $p['id'] );
		$this->assertSame( 403, $this->call( 'POST', '/bookings/' . $b['id'] . '/status', array( 'status' => 'cancelled', 'version' => $b['version'], 'reason' => 'x' ) )->get_status() );
		$in = $this->call( 'POST', '/bookings/' . $b['id'] . '/status', array( 'status' => 'in_house', 'version' => $b['version'] ) );
		$this->assertSame( 200, $in->get_status(), wp_json_encode( $in->get_data() ) );

		// A booking registered by the front desk waits for confirmation.
		$created = $this->call(
			'POST',
			'/properties/' . $p['id'] . '/bookings',
			array(
				'arrival'   => self::day( 20 ),
				'departure' => self::day( 22 ),
				'rooms'     => array( array( 'room_type_id' => $t['type']['id'] ) ),
				'guest'     => array( 'first_name' => 'Luca', 'last_name' => 'Verdi' ),
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'pending', $created->get_data()['status'] );
	}

	public function test_public_form_books_and_the_guest_cancels_from_the_link(): void {
		wp_set_current_user( 0 );
		$p = $this->property( array( 'booking_mode' => 'direct' ) );
		$t = $this->room_type( $p['id'], 1 );

		$view = $this->call( 'GET', '/properties/' . $p['slug'], array(), array(), true );
		$this->assertSame( 200, $view->get_status() );
		$this->assertTrue( $view->get_data()['available'] );
		$this->assertArrayNotHasKey( 'base_price', $view->get_data()['room_types'][0] );
		// Colour of the form: only #rrggbb is kept (it ends up in a style attribute).
		$this->assertSame( '', $view->get_data()['color'] );
		$this->assertSame( '#a5523a', \BookingManager\Properties\PropertySettings::merge( array(), array( 'form_color' => '#A5523A' ) )['form_color'] );
		$this->assertSame( '', \BookingManager\Properties\PropertySettings::merge( array(), array( 'form_color' => 'red;background:url(x)' ) )['form_color'] );
		$this->assertStringContainsString( 'no-store', (string) $view->get_headers()['Cache-Control'] );

		$search = $this->call( 'GET', '/properties/' . $p['slug'] . '/search', array(), array( 'arrival' => self::day( 30 ), 'departure' => self::day( 32 ), 'adults' => 2 ), true );
		$this->assertSame( 20000, $search->get_data()['offers'][0]['rates'][0]['total'] );
		// The "free cancellation" filter of the form reads this flag, worked out by the server.
		$this->assertIsBool( $search->get_data()['offers'][0]['rates'][0]['free_cancel'] );

		$hold = $this->call( 'POST', '/properties/' . $p['slug'] . '/holds', array( 'room_type_id' => $t['type']['id'], 'arrival' => self::day( 30 ), 'departure' => self::day( 32 ) ), array(), true );
		$this->assertSame( 201, $hold->get_status() );

		$body = array(
			'arrival'    => self::day( 30 ),
			'departure'  => self::day( 32 ),
			'rooms'      => array( array( 'room_type_id' => $t['type']['id'], 'adults' => 2, 'unit_id' => 999, 'price_total' => 1 ) ),
			'guest'      => array( 'first_name' => 'Giulia', 'last_name' => 'Neri', 'email' => 'giulia@example.org', 'phone' => '+39 347 0000000' ),
			'privacy'    => true,
			'hold_token' => $hold->get_data()['token'],
			'form_token' => FormToken::issue( time() - 60 ),
			'request_id' => 'abc123',
		);
		$bot = $this->call( 'POST', '/properties/' . $p['slug'] . '/bookings', $body + array( 'website' => 'spam' ), array(), true );
		$this->assertSame( 400, $bot->get_status() );

		$created = $this->call( 'POST', '/properties/' . $p['slug'] . '/bookings', $body, array(), true );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$data = $created->get_data();
		$this->assertSame( 'confirmed', $data['booking']['status'] );
		$this->assertSame( 20000, $data['booking']['total'], 'a price sent by the visitor is ignored' );
		$this->assertArrayNotHasKey( 'internal_notes', $data['booking'] );

		// The same request again (double click): the same booking.
		$again = $this->call( 'POST', '/properties/' . $p['slug'] . '/bookings', $body, array(), true );
		$this->assertSame( $data['booking']['code'], $again->get_data()['booking']['code'] );

		$code = $data['booking']['code'];
		$this->assertSame( 404, $this->call( 'GET', '/manage/' . $code, array(), array( 'token' => str_repeat( 'A', 32 ) ), true )->get_status() );
		$manage = $this->call( 'GET', '/manage/' . $code, array(), array( 'token' => $data['token'] ), true );
		$this->assertSame( 200, $manage->get_status() );
		$this->assertTrue( $manage->get_data()['can_cancel'] );
		$this->assertStringContainsString( ManageLink::ARG_TOKEN . '=' . $data['token'], $data['manage_url'] );

		$cancel = $this->call( 'POST', '/manage/' . $code . '/cancel', array(), array( 'token' => $data['token'] ), true );
		$this->assertSame( 200, $cancel->get_status() );
		$this->assertSame( 'cancelled', $cancel->get_data()['status'] );
	}

	public function test_public_form_refuses_a_closed_property_and_a_late_form(): void {
		wp_set_current_user( 0 );
		$p = $this->property( array( 'settings' => array( 'online_enabled' => false ) ) );
		$this->assertSame( 404, $this->call( 'GET', '/properties/' . $p['slug'], array(), array(), true )->get_status() );

		$open = $this->property( array( 'name' => 'Aperta' ) );
		$t    = $this->room_type( $open['id'], 1 );
		$late = $this->call(
			'POST',
			'/properties/' . $open['slug'] . '/bookings',
			array(
				'arrival'    => self::day( 30 ),
				'departure'  => self::day( 32 ),
				'rooms'      => array( array( 'room_type_id' => $t['type']['id'] ) ),
				'guest'      => array( 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.org' ),
				'privacy'    => true,
				'form_token' => FormToken::issue( time() - 3 * HOUR_IN_SECONDS ),
			),
			array(),
			true
		);
		$this->assertSame( 400, $late->get_status() );
	}

	/**
	 * Every write route of the plugin, with a sample path for each.
	 *
	 * @return array<int, array{0: string, 1: string}> method, path as local_path() writes it
	 */
	private function write_routes(): array {
		$out = array();
		foreach ( $this->server->get_routes() as $route => $handlers ) {
			$path = WriteGate::local_path( $route );
			if ( null === $path || '/' === $path || '/public' === $path ) {
				continue;
			}
			foreach ( self::samples( $path ) as $sample ) {
				foreach ( $handlers as $handler ) {
					foreach ( array_keys( array_filter( (array) $handler['methods'] ) ) as $method ) {
						if ( ! WriteGate::is_read( $method ) ) {
							$out[] = array( $method, $sample );
						}
					}
				}
			}
		}
		return $out;
	}

	/** @return string[] concrete paths for a route regex */
	private static function samples( string $route ): array {
		if ( preg_match( '/\(\?P<\w+>([a-z_]+(?:\|[a-z_]+)+)\)/', $route, $m ) ) {
			$out = array();
			foreach ( explode( '|', $m[1] ) as $alt ) {
				$out = array_merge( $out, self::samples( str_replace( $m[0], $alt, $route ) ) );
			}
			return $out;
		}
		$path = preg_replace(
			array( '/\(\?P<\w+>\\\\d\+\)/', '/\(\?P<\w+>\[a-z0-9\\\\-\]\+\)/', '/\(\?P<\w+>\[A-Za-z0-9\]\{6,16\}\)/', '/\(\?P<\w+>\[a-z_\]\+\)/', '/\(\?P<\w+>\[a-z0-9_-\]\{2,20\}\)/', '/\(\?P<\w+>\[a-z0-9_-\]\{2,40\}\)/' ),
			array( '12', 'villa-test', 'ABCDEFGH', 'bkng_admin', 'stripe', 'channel_x' ),
			$route
		);
		return array( (string) $path );
	}

	public function test_every_write_route_is_classified_by_the_licence_gate(): void {
		$routes = $this->write_routes();
		$this->assertGreaterThan( 30, count( $routes ) );
		$blocked = array();
		foreach ( $routes as list( $method, $path ) ) {
			$this->assertDoesNotMatchRegularExpression( '/[()?<>\\\\]/', $path, 'sample not expanded: ' . $path );
			$matches = (int) (bool) array_filter( WriteGate::BLOCKED, static fn( $p ) => preg_match( $p, $path ) )
				+ (int) (bool) array_filter( WriteGate::ALLOWED, static fn( $p ) => preg_match( $p, $path ) );
			$this->assertSame( 1, $matches, "$method $path must be in exactly one list" );
			if ( 'blocked' === WriteGate::classify( $path ) ) {
				$blocked[] = "$method $path";
			}
		}
		sort( $blocked );
		$this->assertSame(
			array(
				'POST /properties/12/bookings',
				'POST /properties/12/room-types',
				'POST /properties/12/units',
				'POST /properties/12/units/batch',
				'POST /public/properties/villa-test/bookings',
				'POST /public/properties/villa-test/holds',
				'POST /room-types/12',
				'POST /room-types/12/archive',
				'POST /units/12',
				'POST /units/12/archive',
				'POST /units/12/restore',
				'PUT /room-types/12',
				'PUT /units/12',
			),
			array_values( array_unique( array_filter( $blocked, static fn( $r ) => ! str_starts_with( $r, 'PATCH' ) ) ) )
		);
	}

	/** The real gate on the REST server, in a forced mode. */
	private function gate_in( string $mode ): void {
		$gate = new class( $mode, $this->service( LicenseManager::class ) ) extends WriteGate {
			public function __construct( private string $forced, LicenseManager $license ) {
				parent::__construct( $license );
			}
			public function mode(): string {
				return $this->forced;
			}
		};
		$gate->hooks();
	}

	public function test_an_expired_licence_stops_new_bookings_and_rooms_but_not_the_rest(): void {
		$this->admin();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$b = $this->book( $p['id'], $t['type']['id'], 1, 3 );
		$this->gate_in( WriteGate::MODE_BOOKINGS );

		$new = $this->call( 'POST', '/properties/' . $p['id'] . '/bookings', array( 'arrival' => self::day( 5 ), 'departure' => self::day( 6 ), 'rooms' => array( array( 'room_type_id' => $t['type']['id'] ) ), 'guest' => array( 'first_name' => 'A', 'last_name' => 'B' ) ) );
		$this->assertSame( 403, $new->get_status() );
		$this->assertSame( WriteGate::ERROR_CODE, $new->get_data()['code'] );
		$this->assertSame( 403, $this->call( 'POST', '/properties/' . $p['id'] . '/units', array( 'room_type_id' => $t['type']['id'], 'code' => '99' ) )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/properties/' . $p['id'] . '/bookings' )->get_status() );
		$this->assertSame( 200, $this->call( 'POST', '/bookings/' . $b['id'] . '/status', array( 'status' => 'cancelled', 'version' => $b['version'], 'reason' => 'x' ) )->get_status() );

		wp_set_current_user( 0 );
		$view = $this->call( 'GET', '/properties/' . $p['slug'], array(), array(), true );
		$this->assertFalse( $view->get_data()['available'] );
	}

	public function test_the_full_lock_leaves_only_licence_and_guest_pages(): void {
		$this->assertSame( WriteGate::LOCKED, WriteGate::decide( WriteGate::MODE_FULL, 'GET', '/' . RestController::REST_NAMESPACE . '/properties' ) );
		$this->assertSame( WriteGate::LOCKED, WriteGate::decide( WriteGate::MODE_FULL, 'POST', '/' . RestController::REST_NAMESPACE . '/setup/finish' ) );
		$this->assertSame( WriteGate::PASS, WriteGate::decide( WriteGate::MODE_FULL, 'POST', '/' . RestController::REST_NAMESPACE . '/license/activate' ) );
		$this->assertSame( WriteGate::PASS, WriteGate::decide( WriteGate::MODE_FULL, 'GET', '/' . PublicController::REST_NAMESPACE . '/properties/villa' ) );
		$this->assertSame( WriteGate::LOCKED, WriteGate::decide( WriteGate::MODE_FULL, 'POST', '/' . PublicController::REST_NAMESPACE . '/properties/villa/bookings' ) );
		$this->assertSame( WriteGate::PASS, WriteGate::decide( WriteGate::MODE_FULL, 'POST', '/' . PublicController::REST_NAMESPACE . '/manage/ABCDEFGH/cancel' ) );
		// Licence expired: rooms and new bookings stop, the rest goes on.
		$this->assertSame( WriteGate::BOOKINGS, WriteGate::decide( WriteGate::MODE_BOOKINGS, 'PUT', '/' . RestController::REST_NAMESPACE . '/units/4' ) );
		$this->assertSame( WriteGate::PASS, WriteGate::decide( WriteGate::MODE_BOOKINGS, 'POST', '/' . RestController::REST_NAMESPACE . '/bookings/4/status' ) );
		$this->assertSame( WriteGate::PASS, WriteGate::decide( WriteGate::MODE_BOOKINGS, 'GET', '/' . RestController::REST_NAMESPACE . '/properties/4/planning' ) );
	}
}
