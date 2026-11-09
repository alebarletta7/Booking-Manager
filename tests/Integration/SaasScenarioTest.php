<?php
/**
 * FASE 4: the whole platform in one scenario. Customer A has two properties and a
 * receptionist assigned to one of them; customer B has one. The same guest books at
 * both customers. Every role sees exactly its share: properties, bookings, guests,
 * payments, messages, activity log, plan usage.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Access\PropertyScope;
use BookingManager\Database\Schema;
use BookingManager\Properties\PropertyService;
use BookingManager\Rest\RestController;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantRepository;
use BookingManager\Tests\TestCase;

final class SaasScenarioTest extends TestCase {

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
		parent::tear_down();
	}

	/** @param array<string, mixed> $body */
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

	private function as_user( int $user_id ): void {
		wp_set_current_user( $user_id );
		$this->service( PropertyScope::class )->flush();
	}

	private function tenant( string $name ): int {
		$this->as_user( $this->platform );
		$created = $this->call(
			'POST',
			'/platform/tenants',
			array(
				'name'          => $name,
				'business_type' => 'hotel',
				'admin'         => array(
					'email'      => sanitize_title( $name ) . '@example.org',
					'first_name' => 'Titolare',
				),
			)
		);
		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		return (int) $created->get_data()['id'];
	}

	/** @return array{id: int, type: int} */
	private function property_of( int $tenant, string $name ): array {
		$this->as_user( $this->platform );
		$p = $this->service( PropertyService::class )->create(
			array(
				'name'      => $name,
				'kind'      => 'hotel',
				'timezone'  => 'Europe/Rome',
				'currency'  => 'EUR',
				'email'     => 'info@' . sanitize_title( $name ) . '.example',
				'tenant_id' => $tenant,
				'settings'  => array( 'auto_assign_unit' => true ),
			),
			$this->platform
		);
		$this->assertIsArray( $p );
		$t = $this->room_type( (int) $p['id'], 3 );
		Tenancy::flush();
		return array(
			'id'   => (int) $p['id'],
			'type' => (int) $t['type']['id'],
		);
	}

	/** @return array<string, mixed> */
	private function booking( array $property, int $from ): array {
		$b = $this->book(
			$property['id'],
			$property['type'],
			$from,
			$from + 2,
			array(
				'guest' => array(
					'first_name' => 'Mario',
					'last_name'  => 'Rossi',
					'email'      => 'mario.rossi@example.org',
				),
			)
		);
		$this->assertIsArray( $b );
		return $b;
	}

	/** @return int[] */
	private static function ids( \WP_REST_Response $response, ?string $key = null ): array {
		$data = $response->get_data();
		$rows = null === $key ? $data : ( $data[ $key ] ?? array() );
		return array_map( 'intval', array_column( (array) $rows, 'id' ) );
	}

	public function test_each_role_sees_exactly_its_share_of_the_platform(): void {
		$tenant_a = $this->tenant( 'Gruppo Alfa' );
		$tenant_b = $this->tenant( 'Hotel Beta' );
		$a1       = $this->property_of( $tenant_a, 'Alfa Mare' );
		$a2       = $this->property_of( $tenant_a, 'Alfa Monti' );
		$b1       = $this->property_of( $tenant_b, 'Beta Centro' );
		$repo     = new TenantRepository();
		$admin_a  = $repo->user_ids( $tenant_a )[0];
		$admin_b  = $repo->user_ids( $tenant_b )[0];

		// The same guest at the three properties.
		$this->as_user( $this->platform );
		$at_a1 = $this->booking( $a1, 10 );
		$at_a2 = $this->booking( $a2, 12 );
		$at_b1 = $this->booking( $b1, 14 );

		// A's administrator: both its properties, its bookings, one guest card for both.
		$this->as_user( $admin_a );
		$this->assertEqualsCanonicalizing( array( $a1['id'], $a2['id'] ), self::ids( $this->call( 'GET', '/properties' ) ) );
		$this->assertSame( 200, $this->call( 'GET', '/bookings/' . $at_a1['id'] )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/bookings/' . $at_a2['id'] )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/bookings/' . $at_b1['id'] )->get_status() );
		$this->assertSame( (int) $at_a1['guest_id'], (int) $at_a2['guest_id'], 'one card for the customer\'s two properties' );
		$this->assertNotSame( (int) $at_a1['guest_id'], (int) $at_b1['guest_id'], 'another customer has its own card' );
		$this->assertSame( array( (int) $at_a1['guest_id'] ), self::ids( $this->call( 'GET', '/guests', array(), array( 'search' => 'Rossi' ) ) ) );

		// A payment at A1, taken by A.
		$paid = $this->call(
			'POST',
			'/bookings/' . $at_a1['id'] . '/payments',
			array(
				'amount'     => 5000,
				'method'     => 'cash',
				'request_id' => wp_generate_uuid4(),
			)
		);
		$this->assertSame( 201, $paid->get_status(), (string) wp_json_encode( $paid->get_data() ) );

		// A receptionist of A at A2 only.
		$added = $this->call(
			'POST',
			'/staff',
			array(
				'email'      => 'reception@alfa-monti.example',
				'first_name' => 'Lucia',
				'role'       => 'bkng_front_desk',
				'properties' => array( $a2['id'] ),
			)
		);
		$this->assertSame( 201, $added->get_status(), (string) wp_json_encode( $added->get_data() ) );
		$reception = (int) $added->get_data()['id'];
		$this->assertSame( $tenant_a, $repo->tenant_of_user( $reception ) );

		$this->as_user( $reception );
		$this->assertSame( array( $a2['id'] ), self::ids( $this->call( 'GET', '/properties' ) ) );
		$this->assertSame( 200, $this->call( 'GET', '/bookings/' . $at_a2['id'] )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/bookings/' . $at_a1['id'] )->get_status(), 'the other property of the same customer' );
		$this->assertContains( $this->call( 'GET', '/bookings/' . $at_a1['id'] . '/payments' )->get_status(), array( 403, 404 ) );
		$this->assertSame( 404, $this->call( 'GET', '/bookings/' . $at_b1['id'] )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', '/platform/tenants' )->get_status() );

		// B's administrator: only B.
		$this->as_user( $admin_b );
		$this->assertSame( array( $b1['id'] ), self::ids( $this->call( 'GET', '/properties' ) ) );
		$this->assertContains( $this->call( 'GET', '/bookings/' . $at_a1['id'] . '/payments' )->get_status(), array( 403, 404 ) );
		$this->assertSame( array( (int) $at_b1['guest_id'] ), self::ids( $this->call( 'GET', '/guests', array(), array( 'search' => 'Rossi' ) ) ) );
		$this->assertSame( $tenant_b, $this->call( 'GET', '/tenant' )->get_data()['id'] );
		$this->assertSame( 1, $this->call( 'GET', '/tenant' )->get_data()['usage']['max_properties'] );
		$this->assertContains( $this->call( 'GET', '/audit-log', array(), array( 'property_id' => $a1['id'] ) )->get_status(), array( 403, 404 ) );
		$log = $this->call( 'GET', '/audit-log', array(), array( 'property_id' => $b1['id'] ) );
		$this->assertSame( 200, $log->get_status() );
		foreach ( (array) ( $log->get_data()['items'] ?? $log->get_data() ) as $entry ) {
			$this->assertNotContains( (int) ( $entry['property_id'] ?? 0 ), array( $a1['id'], $a2['id'] ), 'no line of A in B\'s log' );
		}

		// Messages and rows carry the customer of their booking.
		global $wpdb;
		foreach ( array( array( $at_a1, $tenant_a ), array( $at_a2, $tenant_a ), array( $at_b1, $tenant_b ) ) as list( $booking, $tenant ) ) {
			$tenants = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT tenant_id FROM ' . Schema::table( 'notifications' ) . ' WHERE booking_id = %d', $booking['id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->assertSame( array( $tenant ), $tenants );
			$this->assertSame( $tenant, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT tenant_id FROM ' . Schema::table( 'guests' ) . ' WHERE id = %d', $booking['guest_id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// The platform sees everything, and one customer when it enters it.
		$this->as_user( $this->platform );
		$this->assertCount( 0, array_diff( array( $a1['id'], $a2['id'], $b1['id'] ), self::ids( $this->call( 'GET', '/properties' ) ) ) );
		$this->call( 'PUT', '/platform/viewing', array( 'tenant_id' => $tenant_a ) );
		$this->service( PropertyScope::class )->flush();
		$this->assertEqualsCanonicalizing( array( $a1['id'], $a2['id'] ), self::ids( $this->call( 'GET', '/properties' ) ) );
		$this->call( 'PUT', '/platform/viewing', array( 'tenant_id' => 0 ) );
	}
}
