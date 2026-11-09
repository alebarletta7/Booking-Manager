<?php
/**
 * FASE 4: real concurrency with two customers. Eight PHP processes (each with its own
 * database connection) start at the same instant: four try to take the last room of
 * customer A, four the last room of customer B. Each customer must sell its room exactly
 * once, the two must not block each other, and every guest card must belong to the
 * customer of its booking. Setup data is committed (the workers cannot see an open
 * transaction) and deleted at the end.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Database\Schema;
use BookingManager\Database\Transaction;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantRepository;
use BookingManager\Tests\TestCase;

final class TenantConcurrencyTest extends TestCase {

	private const PER_TENANT = 4;

	/** @var int[] */
	private array $properties = array();

	/** @var int[] */
	private array $tenants = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		Transaction::$use_savepoints = false;
	}

	public function tear_down(): void {
		global $wpdb;
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Schema::table( '' ) ) . '%' ) );
		foreach ( $this->properties as $property_id ) {
			foreach ( $tables as $table ) {
				$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'property_id' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from SHOW TABLES.
				if ( $column ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE property_id = %d", $property_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			$wpdb->delete( Schema::table( 'properties' ), array( 'id' => $property_id ) );
		}
		foreach ( $this->tenants as $tenant_id ) {
			foreach ( $tables as $table ) {
				$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'tenant_id' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $column ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE tenant_id = %d", $tenant_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			$wpdb->delete( Schema::table( 'tenants' ), array( 'id' => $tenant_id ) );
		}
		Transaction::$use_savepoints = true;
		Tenancy::flush();
		parent::tear_down();
	}

	public function test_two_customers_each_sell_their_last_room_once_at_the_same_instant(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open non disponibile.' );
		}
		Tenancy::flush();
		$this->assertTrue( Tenancy::ready() );
		$repo  = new TenantRepository();
		$setup = array();
		foreach ( array( 'Concorrenza A', 'Concorrenza B' ) as $name ) {
			$tenant          = $repo->create(
				array(
					'name'   => $name,
					'status' => 'active',
					'plan'   => 'unlimited',
				)
			);
			$this->tenants[] = $tenant;
			$p = $this->property( array( 'name' => 'Villa ' . $name ) );
			global $wpdb;
			$wpdb->update( Schema::table( 'properties' ), array( 'tenant_id' => $tenant ), array( 'id' => (int) $p['id'] ) );
			$this->properties[] = (int) $p['id'];
			$t                  = $this->room_type( (int) $p['id'], 1 );
			$setup[]            = array(
				'tenant'   => $tenant,
				'property' => (int) $p['id'],
				'type'     => (int) $t['type']['id'],
			);
		}
		Tenancy::flush();
		$this->assertSame( $setup[0]['tenant'], Tenancy::of_property( $setup[0]['property'] ) );
		$this->assertSame( $setup[1]['tenant'], Tenancy::of_property( $setup[1]['property'] ) );

		$arrival   = self::day( 40 );
		$departure = self::day( 42 );
		$start     = microtime( true ) + 3.0;
		$worker    = dirname( __DIR__ ) . '/concurrency/worker.php';
		$procs     = array();
		for ( $i = 0; $i < self::PER_TENANT * 2; $i++ ) {
			$s     = $setup[ $i % 2 ]; // A and B interleaved: they leave together.
			$cmd   = array( PHP_BINARY, $worker, (string) $s['property'], (string) $s['type'], $arrival, $departure, sprintf( '%.6F', $start ) );
			$pipes = array();
			$proc  = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			$this->assertIsResource( $proc );
			$procs[] = array( $proc, $pipes, $i % 2 );
		}

		$results = array( array(), array() );
		foreach ( $procs as list( $proc, $pipes, $which ) ) {
			$out = stream_get_contents( $pipes[1] );
			$err = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $proc );
			$data = json_decode( (string) $out, true );
			$this->assertIsArray( $data, 'Il processo concorrente non ha risposto: ' . $out . $err );
			$results[ $which ][] = $data;
		}

		global $wpdb;
		foreach ( array( 0, 1 ) as $which ) {
			$won = array_values( array_filter( $results[ $which ], static fn( array $r ) => $r['ok'] ) );
			$this->assertCount( 1, $won, 'Cliente ' . ( $which ? 'B' : 'A' ) . ': una sola prenotazione deve riuscire: ' . wp_json_encode( $results[ $which ] ) );
			foreach ( $results[ $which ] as $r ) {
				if ( ! $r['ok'] ) {
					$this->assertSame( 'bkng_unavailable', $r['code'] );
				}
			}
			$s = $setup[ $which ];
			$this->assertSame(
				1,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'booking_rooms' ) . ' WHERE room_type_id = %d AND occupies = 1', $s['type'] ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
			// The winner's guest card is in the winner's customer.
			$guest_tenant = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT g.tenant_id FROM ' . Schema::table( 'bookings' ) . ' b JOIN ' . Schema::table( 'guests' ) . ' g ON g.id = b.guest_id WHERE b.id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$won[0]['id']
				)
			);
			$this->assertSame( $s['tenant'], $guest_tenant );
			$this->assertSame( $s['property'], (int) $wpdb->get_var( $wpdb->prepare( 'SELECT property_id FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $won[0]['id'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
