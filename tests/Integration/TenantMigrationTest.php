<?php
/**
 * 0.2.0: first customer (tenant) and the migration of the data that existed before.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Database\Schema;
use BookingManager\Payments\PaymentService;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantMigration;
use BookingManager\Tenants\TenantRepository;
use BookingManager\Tests\TestCase;

final class TenantMigrationTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Tenancy::flush();
	}

	private function migration(): TenantMigration {
		return $this->service( TenantMigration::class );
	}

	private static function column( string $short, string $column, int $id ) {
		global $wpdb;
		$table = Schema::table( $short );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE id = %d", $id ) );
	}

	public function test_a_new_installation_has_its_first_tenant_and_stamps_new_rows(): void {
		$this->assertTrue( Tenancy::migrated() );
		$default = Tenancy::default_id();
		$this->assertGreaterThan( 0, $default );
		$this->assertTrue( ( new TenantRepository() )->find( $default )['is_default'] );

		$this->admin();
		$property = $this->property();
		$type     = $this->room_type( (int) $property['id'] );
		$booking  = $this->book( (int) $property['id'], (int) $type['type']['id'], 3, 5 );
		$this->assertIsArray( $booking );

		$this->assertSame( (string) $default, self::column( 'properties', 'tenant_id', (int) $property['id'] ) );
		$this->assertSame( (string) $default, self::column( 'guests', 'tenant_id', (int) $booking['guest_id'] ) );
		$this->assertSame( 0, $this->count_null( 'audit_log', 'property_id IS NOT NULL' ) );
		$this->assertSame( 0, $this->count_null( 'notifications' ) );
	}

	public function test_existing_data_is_migrated_without_changing_anything(): void {
		$admin = $this->admin();
		// Back to a site from before 0.2.0: no default tenant, tenant_id NULL everywhere.
		$tenant_before = Tenancy::default_id();
		$this->migration()->rollback();
		$this->assertSame( 0, Tenancy::default_id() );

		$property = $this->property();
		$type     = $this->room_type( (int) $property['id'], 2 );
		$one      = $this->book( (int) $property['id'], (int) $type['type']['id'], 3, 6 );
		$two      = $this->book( (int) $property['id'], (int) $type['type']['id'], 10, 12, array( 'guest' => array( 'first_name' => 'Anna', 'last_name' => 'Bianchi', 'email' => 'anna@example.org' ) ) );
		$this->assertIsArray( $one );
		$this->assertIsArray( $two );
		$paid = $this->service( PaymentService::class )->record_manual( (int) $one['id'], (int) $property['id'], 5000, 'cash', '', wp_generate_uuid4() );
		$this->assertIsArray( $paid );
		$staff = self::factory()->user->create( array( 'role' => 'bkng_front_desk' ) );

		$this->assertNull( self::column( 'properties', 'tenant_id', (int) $property['id'] ) );
		$this->assertNull( self::column( 'guests', 'tenant_id', (int) $one['guest_id'] ) );

		// Without the backup confirmation nothing happens.
		$refused = $this->migration()->migrate( false );
		$this->assertWPError( $refused );
		$this->assertSame( 'bkng_backup_required', $refused->get_error_code() );
		$this->assertNull( self::column( 'properties', 'tenant_id', (int) $property['id'] ) );

		$plan = $this->migration()->plan();
		$this->assertTrue( $plan['schema_ready'] );
		$this->assertGreaterThanOrEqual( 1, $plan['rows']['properties'] );
		$this->assertGreaterThanOrEqual( 2, $plan['rows']['guests'] );
		$this->assertContains( $staff, $plan['users'] );
		$this->assertNotContains( $admin, $plan['users'] );

		global $wpdb;
		$bookings = Schema::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$before = $wpdb->get_results( "SELECT * FROM {$bookings} ORDER BY id", ARRAY_A );

		$result = $this->migration()->migrate( true, array( 'user_id' => $admin ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['verify']['ok'], implode( "\n", $result['verify']['problems'] ) );
		// The tenant marked as default is reused, not duplicated.
		$this->assertSame( $tenant_before, (int) $result['tenant']['id'] );
		$this->assertSame( $tenant_before, Tenancy::default_id() );
		$this->assertTrue( Tenancy::migrated() );

		// Same rows, same ids, same amounts and states: only the bookings are compared
		// column by column, the rest by the snapshot in verify().
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( $before, $wpdb->get_results( "SELECT * FROM {$bookings} ORDER BY id", ARRAY_A ) );
		$this->assertSame( (string) $tenant_before, self::column( 'properties', 'tenant_id', (int) $property['id'] ) );
		$this->assertSame( (string) $tenant_before, self::column( 'guests', 'tenant_id', (int) $two['guest_id'] ) );
		foreach ( TenantMigration::tables() as $short ) {
			$this->assertSame( 0, $this->count_null( $short, 'audit_log' === $short ? 'property_id IS NOT NULL' : '' ), $short );
		}
		$repo = new TenantRepository();
		$this->assertSame( $tenant_before, $repo->tenant_of_user( $staff ) );
		$this->assertSame( 0, $repo->tenant_of_user( $admin ), 'WordPress administrators run the platform, outside tenants' );

		// A second run changes nothing.
		$again = $this->migration()->migrate( true );
		$this->assertSame( array_fill_keys( TenantMigration::tables(), 0 ), $again['rows'] );
		$this->assertSame( array(), $again['users'] );
		$this->assertTrue( $again['verify']['ok'] );
	}

	public function test_verify_finds_a_booking_whose_guest_belongs_to_another_tenant(): void {
		$this->admin();
		$property = $this->property();
		$type     = $this->room_type( (int) $property['id'] );
		$booking  = $this->book( (int) $property['id'], (int) $type['type']['id'], 3, 5 );
		$this->migration()->migrate( true );

		$other = ( new TenantRepository() )->create( array( 'name' => 'Altro cliente' ) );
		global $wpdb;
		$wpdb->update( Schema::table( 'guests' ), array( 'tenant_id' => $other ), array( 'id' => (int) $booking['guest_id'] ) );

		$check = $this->migration()->verify();
		$this->assertFalse( $check['ok'] );
		$this->assertSame( 1, $check['checks']['bookings_guest_other_tenant'] );
	}

	public function test_rollback_sets_tenant_back_to_null_and_keeps_everything(): void {
		$this->admin();
		$property = $this->property();
		$tenants  = count( ( new TenantRepository() )->all() );

		$changed = $this->migration()->rollback();
		$this->assertGreaterThanOrEqual( 1, $changed['properties'] );
		$this->assertNull( self::column( 'properties', 'tenant_id', (int) $property['id'] ) );
		$this->assertFalse( Tenancy::migrated() );
		$this->assertSame( $tenants, count( ( new TenantRepository() )->all() ), 'no tenant is deleted' );
		$this->assertSame( 'Villa Test', self::column( 'properties', 'name', (int) $property['id'] ) );
	}

	private function count_null( string $short, string $and = '' ): int {
		global $wpdb;
		$table = Schema::table( $short );
		$and   = '' !== $and ? ' AND ' . $and : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE tenant_id IS NULL{$and}" );
	}
}
