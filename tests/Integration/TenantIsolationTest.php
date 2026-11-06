<?php
/**
 * 0.2.1: a customer's staff never reaches another customer's data.
 *
 * Every route of booking-manager/v1 is called with the IDs of customer A's records by an
 * administrator of customer B: the permission check must refuse it (the route's own
 * validation is not enough, so the permission callback is called directly). The few
 * routes that B may call (its own profile, lists, staff) must not return A's data.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Access\Capabilities;
use BookingManager\Access\PropertyScope;
use BookingManager\Audit\AuditLogReader;
use BookingManager\Database\Schema;
use BookingManager\Notifications\NotificationRepository;
use BookingManager\Payments\PaymentService;
use BookingManager\Properties\PropertyService;
use BookingManager\Rates\RateService;
use BookingManager\Inventory\InventoryService;
use BookingManager\Rest\RestController;
use BookingManager\Staff\StaffService;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantAccess;
use BookingManager\Tenants\TenantRepository;
use BookingManager\Tests\TestCase;

final class TenantIsolationTest extends TestCase {

	private \WP_REST_Server $server;

	/** Routes a customer's administrator may call: they act on its own customer only. */
	private const OPEN = array(
		'GET /booking-manager/v1',
		'GET /booking-manager/v1/me',
		'POST /booking-manager/v1/me/preferences',
		'PUT /booking-manager/v1/me/preferences',
		'PATCH /booking-manager/v1/me/preferences',
		'GET /booking-manager/v1/properties',
		'POST /booking-manager/v1/properties',
		'GET /booking-manager/v1/guests',
		'GET /booking-manager/v1/staff',
		'POST /booking-manager/v1/staff',
		'GET /booking-manager/v1/staff/candidates',
		'GET /booking-manager/v1/capabilities',
		// Authenticated by the payment provider's signature, not by a user.
		'POST /booking-manager/v1/webhooks/(?P<provider>[a-z0-9_-]{2,20})/(?P<account>\d+)',
	);

	/** @var array<string, mixed> */
	private array $a = array();

	private int $tenant_b = 0;

	public function set_up(): void {
		parent::set_up();
		Tenancy::flush();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
		remove_all_filters( 'rest_dispatch_request' );
		remove_all_filters( 'rest_post_dispatch' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Customer A (the default one) with a record of every kind, customer B with its own
	 * property, and B's administrator as the current user.
	 */
	private function two_customers(): int {
		global $wpdb;
		$this->assertTrue( Tenancy::migrated() );
		$this->admin();
		$tenants = new TenantRepository();

		$property = $this->property( array( 'name' => 'Villa A' ) );
		$pid      = (int) $property['id'];
		$type     = $this->room_type( $pid, 2 );
		$booking  = $this->book( $pid, (int) $type['type']['id'], 3, 5 );
		$this->assertIsArray( $booking );
		$payment = $this->service( PaymentService::class )->record_manual( (int) $booking['id'], $pid, 5000, 'cash', '', wp_generate_uuid4() );
		$this->assertIsArray( $payment );
		$rates = $this->service( RateService::class );
		$plan  = $rates->create_plan( $pid, array( 'name' => 'Standard' ) );
		$this->assertIsArray( $plan );
		$price = $rates->save_price(
			$pid,
			array(
				'room_type_id' => $type['type']['id'],
				'date_from'    => self::day( 30 ),
				'date_to'      => self::day( 40 ),
				'price_night'  => 9000,
			)
		);
		$this->assertIsArray( $price );
		$adjustment = $rates->save_adjustment(
			$pid,
			array(
				'name'   => 'Pulizia',
				'kind'   => 'supplement',
				'amount' => 1000,
			)
		);
		$this->assertIsArray( $adjustment );
		$restriction = $rates->save_restriction(
			$pid,
			array(
				'date_from' => self::day( 50 ),
				'date_to'   => self::day( 52 ),
				'closed'    => true,
			),
			get_current_user_id()
		);
		$this->assertIsArray( $restriction );
		$block = $this->service( InventoryService::class )->add_block( (int) $type['units'][1]['id'], array( 'date_from' => self::day( 60 ), 'date_to' => self::day( 62 ) ), get_current_user_id() );
		$this->assertIsArray( $block );
		$notification = $this->service( NotificationRepository::class )->insert(
			array(
				'property_id'  => $pid,
				'booking_id'   => (int) $booking['id'],
				'channel'      => 'email',
				'template_key' => 'booking_confirmed',
				'recipient'    => 'mario.rossi@example.org',
			)
		);
		$this->assertGreaterThan( 0, $notification );
		$rooms = Schema::table( 'booking_rooms' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$booking_room = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$rooms} WHERE booking_id = %d LIMIT 1", $booking['id'] ) );
		$this->assertGreaterThan( 0, $booking_room );
		$staff_a = self::factory()->user->create( array( 'role' => Capabilities::ROLE_ADMIN ) );
		// With a single customer, new staff join it (as on a single site).
		$this->assertSame( Tenancy::default_id(), $tenants->tenant_of_user( $staff_a ) );
		$this->tenant_b = $tenants->create( array( 'name' => 'Cliente B' ) );
		$this->assertGreaterThan( 0, $this->tenant_b );

		$this->a = array(
			'property'     => $pid,
			'room_type'    => (int) $type['type']['id'],
			'unit'         => (int) $type['units'][0]['id'],
			'booking'      => (int) $booking['id'],
			'booking_room' => $booking_room,
			'guest'        => (int) $booking['guest_id'],
			'payment'      => (int) $payment['id'],
			'rate_plan'    => (int) $plan['id'],
			'price'        => (int) $price['id'],
			'adjustment'   => (int) $adjustment['id'],
			'restriction'  => (int) $restriction['id'],
			'block'        => (int) $block['id'],
			'notification' => $notification,
			'staff'        => $staff_a,
		);

		// Customer B: its own property, created by the platform administrator.
		$b = $this->service( PropertyService::class )->create(
			array(
				'name'      => 'Villa B',
				'kind'      => 'hotel',
				'timezone'  => 'Europe/Rome',
				'currency'  => 'EUR',
				'tenant_id' => $this->tenant_b,
			),
			get_current_user_id()
		);
		$this->assertIsArray( $b );
		$this->assertSame( $this->tenant_b, Tenancy::of_property( (int) $b['id'] ) );

		$admin_b = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$tenants->add_user( $this->tenant_b, $admin_b );
		( new \WP_User( $admin_b ) )->set_role( Capabilities::ROLE_ADMIN );
		$this->service( PropertyScope::class )->flush();
		wp_set_current_user( $admin_b );
		return (int) $b['id'];
	}

	/** The URL of a route with A's IDs in place of its parameters. */
	private function url_params( string $route ): array {
		preg_match_all( '/\(\?P<(\w+)>[^)]*\)/', $route, $groups );
		$segments = explode( '/', $route );
		$params   = array();
		foreach ( $groups[1] as $name ) {
			$params[ $name ] = $this->value_for( $name, $route, $segments );
		}
		return $params;
	}

	/** @param string[] $segments */
	private function value_for( string $name, string $route, array $segments ): string {
		$map = array(
			'property_id' => 'property',
			'action'      => 'archive',
			'channel'     => 'channex',
			'key'         => 'booking_confirmed',
			'slug'        => 'bkng_front_desk',
			'provider'    => 'stripe',
			'mode'        => 'test',
			'account'     => '1',
			'revision'    => wp_generate_uuid4(),
		);
		if ( 'id' !== $name ) {
			$value = $map[ $name ] ?? '999999';
			return 'property' === $value ? (string) $this->a['property'] : $value;
		}
		// The record is named by the segment before the ID.
		foreach ( $segments as $i => $segment ) {
			if ( str_contains( $segment, '(?P<id>' ) ) {
				$kind = array(
					'properties'    => 'property',
					'room-types'    => 'room_type',
					'units'         => 'unit',
					'blocks'        => 'block',
					'rate-plans'    => 'rate_plan',
					'prices'        => 'price',
					'adjustments'   => 'adjustment',
					'restrictions'  => 'restriction',
					'bookings'      => 'booking',
					'booking-rooms' => 'booking_room',
					'guests'        => 'guest',
					'staff'         => 'staff',
					'payments'      => 'payment',
					'notifications' => 'notification',
					'messages'      => 'notification',
				)[ $segments[ $i - 1 ] ] ?? null;
				$this->assertNotNull( $kind, 'Record ID not mapped for ' . $route );
				return (string) $this->a[ $kind ];
			}
		}
		return '999999';
	}

	private function request( string $method, string $route ): \WP_REST_Request {
		$params  = $this->url_params( $route );
		$path    = (string) preg_replace_callback(
			'/\(\?P<(\w+)>[^)]*\)/',
			static fn( array $m ): string => $params[ $m[1] ],
			$route
		);
		$request = new \WP_REST_Request( $method, $path );
		$request->set_url_params( $params );
		$request->set_query_params(
			array(
				'property_id' => $this->a['property'],
				'from'        => self::day( 0 ),
				'to'          => self::day( 30 ),
			)
		);
		$request->set_body_params( array( 'property_id' => $this->a['property'] ) );
		return $request;
	}

	/** @return array<int, array{0: string, 1: string, 2: callable|null}> method, route, permission */
	private function handlers(): array {
		$list = array();
		foreach ( $this->server->get_routes( RestController::REST_NAMESPACE ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( array_filter( (array) $handler['methods'] ) ) as $method ) {
					$list[] = array( $method, $route, $handler['permission_callback'] ?? null );
				}
			}
		}
		return $list;
	}

	public function test_no_route_lets_a_customer_reach_another_customers_records(): void {
		$this->two_customers();
		$checked = 0;
		$leaks   = array();
		foreach ( $this->handlers() as list( $method, $route, $permission ) ) {
			if ( in_array( $method . ' ' . $route, self::OPEN, true ) ) {
				continue;
			}
			$this->assertIsCallable( $permission, $method . ' ' . $route . ' has no permission check' );
			$allowed = call_user_func( $permission, $this->request( $method, $route ) );
			if ( true === $allowed ) {
				$leaks[] = $method . ' ' . $route;
			}
			++$checked;
		}
		$this->assertSame( array(), $leaks, 'Routes open to another customer' );
		$this->assertGreaterThan( 100, $checked );
	}

	public function test_the_same_routes_still_work_for_the_customers_own_staff(): void {
		$this->two_customers();
		// A's own administrator (not a WordPress administrator) passes the same checks.
		wp_set_current_user( $this->a['staff'] );
		$this->service( PropertyScope::class )->flush();
		$refused = array();
		foreach ( $this->handlers() as list( $method, $route, $permission ) ) {
			// Global settings, licence, roles, WhatsApp and Channex belong to the platform.
			if ( ! str_contains( $route, '(?P<' ) || preg_match( '#/(whatsapp|channex/revisions|roles|webhooks)/#', $route ) ) {
				continue;
			}
			if ( true !== call_user_func( $permission, $this->request( $method, $route ) ) ) {
				$refused[] = $method . ' ' . $route;
			}
		}
		$this->assertSame( array(), $refused );
	}

	public function test_lists_open_to_a_customer_show_only_its_own_data(): void {
		$own = $this->two_customers();

		$properties = $this->dispatch( 'GET', '/properties' );
		$this->assertSame( 200, $properties->get_status() );
		$this->assertSame( array( $own ), array_map( 'intval', array_column( $properties->get_data(), 'id' ) ) );

		$guests = $this->dispatch( 'GET', '/guests', array( 'search' => 'Rossi' ) );
		$this->assertSame( 200, $guests->get_status() );
		$this->assertSame( array(), $guests->get_data() );

		$staff = $this->dispatch( 'GET', '/staff' );
		$this->assertSame( 200, $staff->get_status() );
		$ids = array_map( 'intval', array_column( $staff->get_data()['staff'], 'id' ) );
		$this->assertNotContains( $this->a['staff'], $ids );
		$this->assertSame( array( get_current_user_id() ), $ids, 'WordPress administrators and other customers are hidden' );

		$candidates = $this->dispatch( 'GET', '/staff/candidates' );
		$this->assertSame( array(), (array) $candidates->get_data() );

		$me = $this->dispatch( 'GET', '/me' )->get_data();
		$this->assertFalse( $me['is_platform'] );
		$this->assertSame( $this->tenant_b, $me['tenant']['id'] );

		// Direct calls with A's IDs end in "not found".
		$this->assertSame( 404, $this->dispatch( 'GET', '/bookings/' . $this->a['booking'] )->get_status() );
		$this->assertSame( 404, $this->dispatch( 'GET', '/guests/' . $this->a['guest'] )->get_status() );
		$this->assertContains( $this->dispatch( 'GET', '/properties/' . $this->a['property'] . '/bookings' )->get_status(), array( 403, 404 ) );
	}

	public function test_a_customer_cannot_add_staff_or_properties_to_another_customer(): void {
		$this->two_customers();
		$staff = $this->service( StaffService::class );
		$added = $staff->add(
			wp_get_current_user(),
			array(
				'email'        => 'nuovo@example.org',
				'first_name'   => 'Nuovo',
				'role'         => 'bkng_front_desk',
				'properties'   => array( $this->a['property'] ),
			)
		);
		$this->assertWPError( $added );

		$edit = $staff->update( wp_get_current_user(), $this->a['staff'], array( 'role' => 'bkng_housekeeping' ) );
		$this->assertWPError( $edit );

		$created = $this->service( PropertyService::class )->create(
			array(
				'name'      => 'Villa C',
				'kind'      => 'hotel',
				'timezone'  => 'Europe/Rome',
				'currency'  => 'EUR',
				'tenant_id' => Tenancy::default_id(),
			),
			get_current_user_id()
		);
		$this->assertIsArray( $created );
		$this->assertSame( $this->tenant_b, Tenancy::of_property( (int) $created['id'] ), 'A customer creates properties only for itself' );
	}

	public function test_same_guest_email_makes_separate_cards_per_customer(): void {
		$own = $this->two_customers();
		$this->admin();
		$type    = $this->room_type( $own );
		$booking = $this->book( $own, (int) $type['type']['id'], 3, 5 );
		$this->assertIsArray( $booking );
		$this->assertNotSame( $this->a['guest'], (int) $booking['guest_id'] );
		$this->assertSame( $this->tenant_b, (int) $this->service( \BookingManager\Guests\GuestRepository::class )->find( (int) $booking['guest_id'] )['tenant_id'] );
	}

	public function test_audit_log_and_media_stay_with_their_customer(): void {
		$own = $this->two_customers();
		$reader = $this->service( AuditLogReader::class );
		$mine   = $reader->search( $own, array(), 'Europe/Rome' );
		foreach ( $mine['items'] as $entry ) {
			$this->assertNotSame( $this->a['property'], (int) ( $entry['property_id'] ?? 0 ) );
		}

		// Media: B's upload is marked with B; the picker of B hides A's and old files.
		$admin_b = get_current_user_id();
		wp_set_current_user( $this->a['staff'] );
		$file_a = self::factory()->attachment->create( array( 'post_title' => 'logo-a' ) );
		$old    = self::factory()->attachment->create( array( 'post_title' => 'old' ) );
		delete_post_meta( $old, TenantAccess::ATTACHMENT_META );
		wp_set_current_user( $admin_b );
		$file_b = self::factory()->attachment->create( array( 'post_title' => 'logo-b' ) );
		$this->assertSame( (string) $this->tenant_b, get_post_meta( $file_b, TenantAccess::ATTACHMENT_META, true ) );
		$this->assertFalse( TenantAccess::allows_attachment( $file_b, Tenancy::default_id() ) );
		$this->assertTrue( TenantAccess::allows_attachment( $file_b, $this->tenant_b ) );
		$query = $this->service( TenantAccess::class )->filter_media( array() );
		$found = get_posts( $query + array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => -1 ) );
		$this->assertContains( $file_b, $found );
		$this->assertNotContains( $file_a, $found );
		$this->assertNotContains( $old, $found, 'Files from before 0.2.0 belong to the default customer' );

		// A file B uploaded cannot become A's logo, even by ID.
		$this->admin();
		$refused = $this->service( PropertyService::class )->update_settings( $this->a['property'], array( 'email_logo_id' => $file_b ) );
		$this->assertWPError( $refused );
	}

	/** @param array<string, mixed> $query */
	private function dispatch( string $method, string $path, array $query = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/' . RestController::REST_NAMESPACE . $path );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		return rest_ensure_response( $this->server->dispatch( $request ) );
	}
}
