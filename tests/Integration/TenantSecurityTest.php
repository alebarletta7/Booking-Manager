<?php
/**
 * 0.2.7: hourly cap of online bookings per customer, failed logins limited per account
 * and per address, https required for the public API, the platform's customer selector
 * (what the customer sees, every entry in the log).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Access\LoginGuard;
use BookingManager\Access\PropertyScope;
use BookingManager\Core\Https;
use BookingManager\Database\Schema;
use BookingManager\Frontend\FormToken;
use BookingManager\Frontend\PublicBookingService;
use BookingManager\Properties\PropertyService;
use BookingManager\Rest\RestController;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantGate;
use BookingManager\Tenants\TenantRepository;
use BookingManager\Tests\TestCase;

final class TenantSecurityTest extends TestCase {

	private \WP_REST_Server $server;

	private int $platform = 0;

	public function set_up(): void {
		parent::set_up();
		Tenancy::flush();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
		remove_all_filters( 'rest_dispatch_request' );
		remove_all_filters( 'rest_post_dispatch' );
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->platform = $this->admin();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_filter( 'pre_wp_mail', '__return_true' );
		remove_all_filters( 'bkng_force_https' );
		remove_all_filters( 'bkng_client_ip' );
		unset( $_SERVER['HTTPS'] );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function call( string $method, string $path, array $body = array(), string $ns = RestController::REST_NAMESPACE ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/' . $ns . $path );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_ensure_response( $this->server->dispatch( $request ) );
	}

	private function as_user( int $user_id ): void {
		wp_set_current_user( $user_id );
		$this->service( PropertyScope::class )->flush();
	}

	/** @return array{tenant: int, admin: int, property: array<string, mixed>, type: int} */
	private function customer( string $name ): array {
		$this->as_user( $this->platform );
		$created = $this->call(
			'POST',
			'/platform/tenants',
			array(
				'name'          => $name,
				'business_type' => 'hotel',
				'admin'         => array(
					'email'      => sanitize_title( $name ) . '-' . wp_generate_password( 6, false ) . '@example.org',
					'first_name' => 'Titolare',
				),
			)
		);
		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$tenant = (int) $created->get_data()['id'];
		$prop   = $this->service( PropertyService::class )->create(
			array(
				'name'      => 'Villa ' . $name,
				'kind'      => 'hotel',
				'timezone'  => 'Europe/Rome',
				'currency'  => 'EUR',
				'email'     => 'info@' . sanitize_title( $name ) . '.example',
				'tenant_id' => $tenant,
				'settings'  => array( 'auto_assign_unit' => true ),
			),
			get_current_user_id()
		);
		$this->assertIsArray( $prop );
		$type = $this->room_type( (int) $prop['id'], 5 );
		Tenancy::flush();
		return array(
			'tenant'   => $tenant,
			'admin'    => ( new TenantRepository() )->user_ids( $tenant )[0],
			'property' => $prop,
			'type'     => (int) $type['type']['id'],
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	private function online_booking( array $customer, int $from, string $ip ) {
		return $this->service( PublicBookingService::class )->create(
			(string) $customer['property']['slug'],
			array(
				'arrival'    => self::day( $from ),
				'departure'  => self::day( $from + 1 ),
				'rooms'      => array(
					array(
						'room_type_id' => $customer['type'],
						'adults'       => 2,
					),
				),
				'guest'      => array( 'first_name' => 'Giulia', 'last_name' => 'Neri', 'email' => 'giulia@example.org' ),
				'privacy'    => true,
				'form_token' => FormToken::issue( time() - 60 ),
			),
			$ip
		);
	}

	/* ===================================================== bookings per hour */

	public function test_a_customer_has_an_hourly_cap_of_online_bookings(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$c = $this->customer( 'Le Rose' );
		( new TenantRepository() )->update( $b['tenant'], array( 'limits' => array( 'max_bookings_hour' => 2 ) ) );
		wp_set_current_user( 0 );

		// Different visitors: the customer's cap is what stops the third.
		$this->assertIsArray( $this->online_booking( $b, 10, '203.0.113.1' ) );
		$this->assertIsArray( $this->online_booking( $b, 11, '203.0.113.2' ) );
		$third = $this->online_booking( $b, 12, '203.0.113.3' );
		$this->assertWPError( $third );
		$this->assertSame( 'bkng_rate_limited', $third->get_error_code() );
		$this->assertSame( 429, $third->get_error_data()['status'] );

		// Another customer is not affected, nor is the staff app.
		$this->assertIsArray( $this->online_booking( $c, 10, '203.0.113.4' ) );
		$this->as_user( $b['admin'] );
		$this->assertIsArray( $this->book( (int) $b['property']['id'], $b['type'], 20, 21 ) );

		$gate = $this->service( TenantGate::class );
		$this->assertSame( 2, $gate->usage( $b['tenant'] )['max_bookings_hour'] );
		$this->assertSame( 1, $gate->usage( $c['tenant'] )['max_bookings_hour'] );
		$this->assertNull( $gate->online_quota( $c['tenant'] ), 'no cap in the plan: never stopped' );
		$this->assertSame( 2, $gate->usage( $c['tenant'] )['max_bookings_hour'] );
	}

	/* ================================================================ login */

	public function test_failed_logins_lock_the_account_even_with_the_right_password(): void {
		$this->assertTrue( LoginGuard::enabled() );
		$user  = self::factory()->user->create(
			array(
				'user_login' => 'giulia',
				'user_email' => 'giulia@hotel-bianchi.it',
				'user_pass'  => 'giusta-123',
			)
		);
		$other = self::factory()->user->create(
			array(
				'user_login' => 'marco',
				'user_pass'  => 'giusta-456',
			)
		);
		$guard = $this->service( LoginGuard::class );
		add_filter( 'bkng_client_ip', static fn() => '198.51.100.7' );

		// Four mistakes, then the right password: in, and the counter starts again.
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertWPError( wp_authenticate( 'giulia', 'sbagliata' ) );
		}
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( 'giulia', 'giusta-123' ) );
		$guard->succeeded( 'giulia' );
		$this->assertFalse( $guard->locked( 'giulia' ) );

		// Five mistakes, by login or by e-mail: locked.
		for ( $i = 0; $i < 3; $i++ ) {
			wp_authenticate( 'giulia', 'sbagliata' );
		}
		wp_authenticate( 'giulia@hotel-bianchi.it', 'sbagliata' );
		wp_authenticate( 'GIULIA', 'sbagliata' );
		$locked = wp_authenticate( 'giulia', 'giusta-123' );
		$this->assertWPError( $locked );
		$this->assertSame( LoginGuard::ERROR_CODE, $locked->get_error_code() );
		$this->assertWPError( wp_authenticate( 'giulia@hotel-bianchi.it', 'giusta-123' ) );

		// Another account from the same address still logs in.
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( 'marco', 'giusta-456' ) );
		$this->assertSame( $other, wp_authenticate( 'marco', 'giusta-456' )->ID );

		// One line in the log, without the password.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'audit_log' ) . " WHERE action = 'security.login_locked' AND entity_id = %d", $user ), ARRAY_A );
		$this->assertNotNull( $row );
		$this->assertStringNotContainsString( 'giusta', (string) wp_json_encode( $row ) );
		$this->assertStringNotContainsString( 'sbagliata', (string) wp_json_encode( $row ) );
	}

	public function test_failed_logins_lock_the_address(): void {
		self::factory()->user->create(
			array(
				'user_login' => 'anna',
				'user_pass'  => 'giusta-789',
			)
		);
		$ip = '198.51.100.9';
		add_filter( 'bkng_client_ip', static function () use ( &$ip ) {
			return $ip;
		} );
		// Twenty different names from one address (someone trying many accounts).
		for ( $i = 0; $i < LoginGuard::limits()['address']; $i++ ) {
			wp_authenticate( 'nessuno' . $i, 'x' );
		}
		$this->assertSame( LoginGuard::ERROR_CODE, wp_authenticate( 'anna', 'giusta-789' )->get_error_code() );
		$ip = '198.51.100.10';
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( 'anna', 'giusta-789' ), 'another address is not locked' );
	}

	/* ================================================================ https */

	public function test_the_public_api_requires_https_when_the_site_is_on_https(): void {
		$p = $this->property();
		add_filter( 'bkng_force_https', '__return_true' );
		unset( $_SERVER['HTTPS'] );
		$plain = $this->call( 'GET', '/properties/' . $p['slug'], array(), 'booking-manager/public/v1' );
		$this->assertSame( 403, $plain->get_status() );
		$this->assertSame( Https::ERROR_CODE, $plain->get_data()['code'] );

		$_SERVER['HTTPS'] = 'on';
		$this->assertSame( 200, $this->call( 'GET', '/properties/' . $p['slug'], array(), 'booking-manager/public/v1' )->get_status() );

		// A site still on http (development) is left alone, and the staff API is not concerned.
		unset( $_SERVER['HTTPS'] );
		remove_all_filters( 'bkng_force_https' );
		$this->assertFalse( Https::required(), 'http://example.org' );
		$this->assertSame( 200, $this->call( 'GET', '/properties/' . $p['slug'], array(), 'booking-manager/public/v1' )->get_status() );
		$this->assertSame( 'https://example.org/booking/villa/?bkng_code=X', Https::upgrade( '/booking/villa/?bkng_code=X' ) );
	}

	/* ===================================================== customer selector */

	public function test_the_platform_enters_a_customer_and_it_is_logged(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$c = $this->customer( 'Le Rose' );
		$this->as_user( $this->platform );
		$this->assertCount( 0, array_diff( array( (int) $b['property']['id'], (int) $c['property']['id'] ), array_column( $this->call( 'GET', '/properties' )->get_data(), 'id' ) ), 'all customers before' );

		$entered = $this->call( 'PUT', '/platform/viewing', array( 'tenant_id' => $b['tenant'] ) );
		$this->assertSame( 200, $entered->get_status(), (string) wp_json_encode( $entered->get_data() ) );
		$this->service( PropertyScope::class )->flush();

		$me = $this->call( 'GET', '/me' )->get_data();
		$this->assertSame( $b['tenant'], $me['viewing'] );
		$this->assertSame( $b['tenant'], $me['tenant']['id'] );
		$this->assertSame( array( (int) $b['property']['id'] ), array_column( $me['properties'], 'id' ) );
		$this->assertSame( array( (int) $b['property']['id'] ), array_map( 'intval', array_column( $this->call( 'GET', '/properties' )->get_data(), 'id' ) ) );
		$this->assertSame( 404, $this->call( 'GET', '/properties/' . $c['property']['id'] )->get_status(), 'Le Rose is not in Bianchi\'s view' );
		$this->assertSame( $b['tenant'], $this->call( 'GET', '/tenant' )->get_data()['id'] );

		global $wpdb;
		$log = $wpdb->get_row( "SELECT * FROM " . Schema::table( 'audit_log' ) . " WHERE action = 'platform.tenant_entered' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( $b['tenant'], (int) $log['tenant_id'], 'visible to the customer too' );
		$this->assertSame( $this->platform, (int) $log['user_id'] );

		// Back to all.
		$this->assertSame( 200, $this->call( 'PUT', '/platform/viewing', array( 'tenant_id' => 0 ) )->get_status() );
		$this->service( PropertyScope::class )->flush();
		$this->assertSame( 0, $this->call( 'GET', '/me' )->get_data()['viewing'] );
		$this->assertSame( 200, $this->call( 'GET', '/properties/' . $c['property']['id'] )->get_status() );
		$this->assertNotNull( $wpdb->get_var( "SELECT id FROM " . Schema::table( 'audit_log' ) . " WHERE action = 'platform.tenant_left'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 404, $this->call( 'PUT', '/platform/viewing', array( 'tenant_id' => 999999 ) )->get_status() );
	}

	public function test_a_customer_cannot_use_the_selector(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$c = $this->customer( 'Le Rose' );
		$this->as_user( $b['admin'] );
		$this->assertSame( 403, $this->call( 'PUT', '/platform/viewing', array( 'tenant_id' => $c['tenant'] ) )->get_status() );
		// Even with the meta written by hand, a customer's user stays in its customer.
		update_user_meta( $b['admin'], PropertyScope::VIEWING_META, $c['tenant'] );
		$this->service( PropertyScope::class )->flush();
		$this->assertSame( 0, $this->service( PropertyScope::class )->viewing( $b['admin'] ) );
		$this->assertSame( array( (int) $b['property']['id'] ), $this->service( PropertyScope::class )->allowed_ids( $b['admin'] ) );
		$this->assertSame( 404, $this->call( 'GET', '/properties/' . $c['property']['id'] )->get_status() );
	}

	/* ============================================================ operational mode */

	public function test_only_wordpress_administrators_may_leave_operational_mode(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$this->assertFalse( \BookingManager\Rest\MeController::operational_by_default( $this->platform ) );
		$this->assertTrue( \BookingManager\Rest\MeController::operational_by_default( $b['admin'] ), 'the customer\'s administrator too' );

		$this->as_user( $b['admin'] );
		$me = $this->call( 'GET', '/me' )->get_data();
		$this->assertTrue( $me['preferences']['operational_mode'] );
		$this->assertTrue( $me['preferences']['operational_locked'] );
		$this->assertSame( 403, $this->call( 'PUT', '/me/preferences', array( 'operational_mode' => false ) )->get_status() );

		$this->as_user( $this->platform );
		$this->assertFalse( $this->call( 'GET', '/me' )->get_data()['preferences']['operational_locked'] );
		$this->assertSame( 200, $this->call( 'PUT', '/me/preferences', array( 'operational_mode' => true ) )->get_status() );
		$this->assertSame( 200, $this->call( 'PUT', '/me/preferences', array( 'operational_mode' => false ) )->get_status() );
	}
}
