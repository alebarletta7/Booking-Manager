<?php
/**
 * 0.2.2: the platform panel (customers, domains, people, plans, numbers) and what a
 * customer may do in each status (TenantGate).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Access\Capabilities;
use BookingManager\Access\PropertyScope;
use BookingManager\Bookings\BookingService;
use BookingManager\Database\Schema;
use BookingManager\Frontend\PublicBookingService;
use BookingManager\Inventory\InventoryService;
use BookingManager\Properties\PropertyService;
use BookingManager\Rest\RestController;
use BookingManager\Tenants\Plans;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantGate;
use BookingManager\Tenants\TenantRepository;
use BookingManager\Tenants\TenantStatus;
use BookingManager\Tests\TestCase;

final class PlatformTest extends TestCase {

	private \WP_REST_Server $server;

	private int $platform = 0;

	public function set_up(): void {
		parent::set_up();
		Tenancy::flush();
		delete_option( Plans::OPTION );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
		remove_all_filters( 'rest_dispatch_request' );
		remove_all_filters( 'rest_post_dispatch' );
		$this->platform = $this->admin();
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
	private function call( string $method, string $path, array $body = array(), array $query = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/' . RestController::REST_NAMESPACE . $path );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_ensure_response( $this->server->dispatch( $request ) );
	}

	/**
	 * Customer B created from the panel, with its administrator and one property with a
	 * room type of two rooms.
	 *
	 * @return array{tenant: int, admin: int, property: int, type: int}
	 */
	private function customer( array $extra = array() ): array {
		wp_set_current_user( $this->platform );
		$created = $this->call(
			'POST',
			'/platform/tenants',
			$extra + array(
				'name'          => 'Hotel Bianchi Srl',
				'business_type' => 'hotel',
				'admin'         => array(
					'email'      => 'titolare-' . wp_generate_password( 6, false ) . '@example.org',
					'first_name' => 'Giulia',
					'last_name'  => 'Bianchi',
				),
			)
		);
		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$tenant   = (int) $created->get_data()['id'];
		$users    = ( new TenantRepository() )->user_ids( $tenant );
		$property = $this->property(
			array(
				'name'      => 'Villa Bianchi',
				'tenant_id' => $tenant,
			)
		);
		$this->assertSame( $tenant, Tenancy::of_property( (int) $property['id'] ) );
		$type = $this->room_type( (int) $property['id'], 2 );
		return array(
			'tenant'   => $tenant,
			'admin'    => $users[0],
			'property' => (int) $property['id'],
			'type'     => (int) $type['type']['id'],
		);
	}

	private function as_user( int $user_id ): void {
		wp_set_current_user( $user_id );
		$this->service( PropertyScope::class )->flush();
	}

	/** A property as the current user creates it (TestCase::property() passes user 0). */
	protected function property( array $data = array() ): array {
		$result = $this->service( PropertyService::class )->create(
			$data + array(
				'name'     => 'Villa Test',
				'kind'     => 'hotel',
				'timezone' => 'Europe/Rome',
				'currency' => 'EUR',
				'settings' => array(
					'min_stay'         => 1,
					'max_advance_days' => 1095,
					'auto_assign_unit' => true,
				),
			),
			get_current_user_id()
		);
		$this->assertIsArray( $result, (string) wp_json_encode( $result instanceof \WP_Error ? $result->get_error_data() : '' ) );
		return $result;
	}

	public function test_platform_creates_a_customer_with_its_administrator(): void {
		$b = $this->customer();

		$admin = get_userdata( $b['admin'] );
		$this->assertSame( array( Capabilities::ROLE_ADMIN ), array_values( $admin->roles ) );
		$this->assertSame( 'Giulia Bianchi', $admin->display_name );

		$list = $this->call( 'GET', '/platform/tenants', array(), array( 'search' => 'Bianchi' ) )->get_data();
		$this->assertCount( 1, $list );
		$this->assertSame( 1, $list[0]['properties_count'] );
		$this->assertSame( 1, $list[0]['users_count'] );
		$this->assertSame( 'active', $list[0]['status'] );

		$detail = $this->call( 'GET', '/platform/tenants/' . $b['tenant'] )->get_data();
		$this->assertSame( 'unlimited', $detail['plan'] );
		$this->assertSame( 2, $detail['usage']['max_units'] );

		$people = $this->call( 'GET', '/platform/tenants/' . $b['tenant'] . '/users' )->get_data();
		$this->assertSame( array( $b['admin'] ), array_column( $people, 'id' ) );

		$more = $this->call(
			'POST',
			'/platform/tenants/' . $b['tenant'] . '/users',
			array(
				'email'      => 'reception-b@example.org',
				'first_name' => 'Luca',
				'role'       => 'bkng_front_desk',
				'properties' => array( $b['property'] ),
			)
		);
		$this->assertSame( 201, $more->get_status(), (string) wp_json_encode( $more->get_data() ) );
		$this->assertSame( $b['tenant'], ( new TenantRepository() )->tenant_of_user( (int) $more->get_data()['id'] ) );

		// A property of another customer cannot be given to B's people.
		$other = $this->property( array( 'name' => 'Villa A' ) );
		$wrong = $this->call(
			'POST',
			'/platform/tenants/' . $b['tenant'] . '/users',
			array(
				'email'      => 'sbagliato@example.org',
				'first_name' => 'Sara',
				'role'       => 'bkng_front_desk',
				'properties' => array( (int) $other['id'] ),
			)
		);
		$this->assertSame( 400, $wrong->get_status() );

		// The customer's administrator sees its customer read only, not the panel.
		$this->as_user( $b['admin'] );
		$own = $this->call( 'GET', '/tenant' )->get_data();
		$this->assertSame( $b['tenant'], $own['id'] );
		$this->assertSame( array( 'max_properties' => 1, 'max_units' => 2 ), $own['usage'] );
		foreach ( array( '/platform/tenants', '/platform/plans', '/platform/stats', '/platform/tenants/' . $b['tenant'] ) as $path ) {
			$this->assertSame( 403, $this->call( 'GET', $path )->get_status(), $path );
		}
		$this->assertSame( 403, $this->call( 'POST', '/platform/tenants/' . $b['tenant'] . '/activate' )->get_status() );
		$me = $this->call( 'GET', '/me' )->get_data();
		$this->assertTrue( $me['tenant']['can_sell'] );
	}

	public function test_domains_are_checked_and_belong_to_one_customer(): void {
		$b     = $this->customer();
		$added = $this->call( 'POST', '/platform/tenants/' . $b['tenant'] . '/domains', array( 'host' => 'https://www.Hotel-Bianchi.it/' ) );
		$this->assertSame( 200, $added->get_status() );
		$this->assertSame( array( 'www.hotel-bianchi.it' ), $added->get_data() );

		foreach ( array( '192.168.1.10', 'localhost', '*.hotel-bianchi.it', 'https://hotel-bianchi.it/prenota', 'hotel-bianchi.it:8080' ) as $bad ) {
			$this->assertSame( 400, $this->call( 'POST', '/platform/tenants/' . $b['tenant'] . '/domains', array( 'host' => $bad ) )->get_status(), $bad );
		}

		$taken = $this->call( 'POST', '/platform/tenants/' . Tenancy::default_id() . '/domains', array( 'host' => 'www.hotel-bianchi.it' ) );
		$this->assertSame( 400, $taken->get_status() );

		$left = $this->call( 'DELETE', '/platform/tenants/' . $b['tenant'] . '/domains', array(), array( 'host' => 'www.hotel-bianchi.it' ) );
		$this->assertSame( array(), $left->get_data() );
	}

	public function test_a_suspended_customer_keeps_working_on_existing_bookings_only(): void {
		$b       = $this->customer();
		$booking = $this->book( $b['property'], $b['type'], 5, 7 );
		$this->assertIsArray( $booking );

		$no_reason = $this->call( 'POST', '/platform/tenants/' . $b['tenant'] . '/suspend' );
		$this->assertSame( 400, $no_reason->get_status() );
		$suspended = $this->call( 'POST', '/platform/tenants/' . $b['tenant'] . '/suspend', array( 'reason' => 'Fattura non pagata' ) );
		$this->assertSame( 'suspended', $suspended->get_data()['status'] );

		$this->as_user( $b['admin'] );
		$me = $this->call( 'GET', '/me' )->get_data();
		$this->assertFalse( $me['tenant']['can_sell'] );
		$this->assertSame( 'Fattura non pagata', $me['tenant']['reason'] );

		// No new bookings: staff, public form, holds.
		$staff = $this->book( $b['property'], $b['type'], 10, 12 );
		$this->assertWPError( $staff );
		$this->assertSame( TenantGate::ERROR_CODE, $staff->get_error_code() );
		$online = $this->book( $b['property'], $b['type'], 10, 12, array(), array( 'channel' => 'online' ) );
		$this->assertWPError( $online );
		$slug   = (string) $this->service( \BookingManager\Properties\PropertyRepository::class )->find( $b['property'] )['slug'];
		$public = $this->service( PublicBookingService::class );
		$this->assertFalse( $public->property( $slug )['available'] );
		$hold = $public->hold(
			$slug,
			array(
				'arrival'      => self::day( 10 ),
				'departure'    => self::day( 12 ),
				'room_type_id' => $b['type'],
			),
			'203.0.113.9'
		);
		$this->assertWPError( $hold );

		// No new rooms or properties.
		$this->assertWPError( $this->service( InventoryService::class )->create_type( $b['property'], array( 'name' => 'Suite' ) ) );
		$this->assertWPError(
			$this->service( PropertyService::class )->create(
				array(
					'name'     => 'Villa Due',
					'kind'     => 'hotel',
					'timezone' => 'Europe/Rome',
					'currency' => 'EUR',
				),
				$b['admin']
			)
		);

		// Existing bookings: changes and cancellations still work.
		$cancel = $this->call(
			'POST',
			'/bookings/' . $booking['id'] . '/status',
			array(
				'status'  => 'cancelled',
				'version' => $booking['version'],
				'reason'  => 'Richiesta ospite',
			)
		);
		$this->assertSame( 200, $cancel->get_status(), (string) wp_json_encode( $cancel->get_data() ) );

		// Reservations from the portals are saved, with a note in the log.
		$import = $this->book( $b['property'], $b['type'], 20, 22, array( 'external_source' => 'channex', 'external_ref' => 'R-1' ), array( 'channel' => 'import' ) );
		$this->assertIsArray( $import );
		$this->assertSame( 1, $this->log_count( 'tenant.booking_while_inactive' ) );

		// Back to active: everything again.
		wp_set_current_user( $this->platform );
		$this->call( 'POST', '/platform/tenants/' . $b['tenant'] . '/activate' );
		$this->as_user( $b['admin'] );
		$this->assertIsArray( $this->book( $b['property'], $b['type'], 10, 12 ) );
		$this->assertTrue( $public->property( $slug )['available'] );
	}

	public function test_expiry_blocks_like_a_suspension_until_the_date_moves(): void {
		$b = $this->customer( array( 'expires_at' => self::day( 30 ) ) );
		( new TenantRepository() )->update( $b['tenant'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );

		$detail = $this->call( 'GET', '/platform/tenants/' . $b['tenant'] )->get_data();
		$this->assertSame( 'expired', $detail['status'], 'Expired at once, before the daily job' );
		$this->assertWPError( $this->book( $b['property'], $b['type'], 3, 4 ) );

		$this->assertSame( 1, $this->service( TenantGate::class )->expire_due() );
		$this->assertSame( TenantStatus::EXPIRED, ( new TenantRepository() )->find( $b['tenant'] )['status'] );
		$this->assertSame( 1, $this->log_count( 'tenant.expired' ) );

		$this->assertSame( 400, $this->call( 'POST', '/platform/tenants/' . $b['tenant'] . '/activate' )->get_status() );
		$renewed = $this->call( 'PUT', '/platform/tenants/' . $b['tenant'], array( 'expires_at' => self::day( 365 ) ) );
		$this->assertSame( 'active', $renewed->get_data()['status'] );
		$this->assertIsArray( $this->book( $b['property'], $b['type'], 3, 4 ) );
	}

	public function test_plan_limits_on_properties_and_rooms(): void {
		$plans = $this->call(
			'PUT',
			'/platform/plans',
			array(
				'plans' => array(
					array(
						'key'      => 'base',
						'name'     => 'Base',
						'limits'   => array(
							'max_properties' => 1,
							'max_units'      => 3,
						),
						'features' => array( 'online_payment' => true ),
					),
				),
			)
		);
		$this->assertSame( 200, $plans->get_status(), (string) wp_json_encode( $plans->get_data() ) );
		$this->assertSame( array( 'unlimited', 'base' ), array_column( $plans->get_data()['plans'], 'key' ) );

		$b = $this->customer( array( 'plan' => 'base' ) );
		$this->as_user( $b['admin'] );
		$inventory = $this->service( InventoryService::class );
		$this->assertIsArray( $inventory->create_unit( $b['property'], array( 'room_type_id' => $b['type'], 'code' => 'B3' ) ) );
		$fourth = $inventory->create_unit( $b['property'], array( 'room_type_id' => $b['type'], 'code' => 'B4' ) );
		$this->assertWPError( $fourth );
		$this->assertSame( TenantGate::LIMIT_CODE, $fourth->get_error_code() );
		$this->assertWPError( $inventory->create_units( $b['property'], array( 'room_type_id' => $b['type'], 'codes' => array( 'C1', 'C2' ) ) ) );

		$second = $this->service( PropertyService::class )->create(
			array(
				'name'     => 'Villa Due',
				'kind'     => 'hotel',
				'timezone' => 'Europe/Rome',
				'currency' => 'EUR',
			),
			$b['admin']
		);
		$this->assertWPError( $second );
		$this->assertSame( TenantGate::LIMIT_CODE, $second->get_error_code() );

		// The customer's own override wins over the plan.
		wp_set_current_user( $this->platform );
		$this->call( 'PUT', '/platform/tenants/' . $b['tenant'], array( 'limits' => array( 'max_units' => 5 ) ) );
		$this->as_user( $b['admin'] );
		$this->assertIsArray( $inventory->create_unit( $b['property'], array( 'room_type_id' => $b['type'], 'code' => 'B4' ) ) );

		// A plan in use cannot be removed.
		wp_set_current_user( $this->platform );
		$removed = $this->call( 'PUT', '/platform/plans', array( 'plans' => array() ) );
		$this->assertSame( 400, $removed->get_status() );
	}

	public function test_an_archived_customer_disappears_but_keeps_its_data(): void {
		$b       = $this->customer();
		$booking = $this->book( $b['property'], $b['type'], 5, 7 );
		$this->assertIsArray( $booking );
		$slug = (string) $this->service( \BookingManager\Properties\PropertyRepository::class )->find( $b['property'] )['slug'];

		$this->assertSame( 400, $this->call( 'POST', '/platform/tenants/' . Tenancy::default_id() . '/archive' )->get_status(), 'The main customer is never archived' );
		$this->assertSame( 'archived', $this->call( 'POST', '/platform/tenants/' . $b['tenant'] . '/archive' )->get_data()['status'] );

		$this->as_user( $b['admin'] );
		$this->assertSame( array(), $this->call( 'GET', '/me' )->get_data()['properties'] );
		$this->assertSame( 404, $this->call( 'GET', '/bookings/' . $booking['id'] )->get_status() );
		$this->assertWPError( $this->service( PublicBookingService::class )->property( $slug ) );

		wp_set_current_user( $this->platform );
		$this->assertSame( 200, $this->call( 'GET', '/bookings/' . $booking['id'] )->get_status() );
	}

	public function test_platform_numbers_per_customer(): void {
		$b = $this->customer();
		$this->assertIsArray( $this->book( $b['property'], $b['type'], 5, 7 ) );
		$this->assertIsArray( $this->book( $b['property'], $b['type'], 8, 9 ) );

		$stats = $this->call( 'GET', '/platform/stats', array(), array( 'from' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) ) )->get_data();
		$row   = array_values( array_filter( $stats['per_tenant'], static fn( array $r ) => $r['id'] === $b['tenant'] ) );
		$this->assertCount( 1, $row );
		$this->assertSame( 2, $row[0]['bookings'] );
		$this->assertGreaterThan( 0, $row[0]['booked'] );
		$this->assertGreaterThanOrEqual( 2, $stats['by_status']['active'] );
	}

	private function log_count( string $action ): int {
		global $wpdb;
		$table = Schema::table( 'audit_log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action = %s", $action ) );
	}
}
