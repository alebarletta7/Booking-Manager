<?php
/**
 * Real concurrency: several PHP processes, each with its own database connection, try to
 * book the last room for the same nights at the same instant. Exactly one must succeed;
 * the others get "not available". Unlike the other integration tests, the setup data is
 * committed (the processes cannot see an open transaction) and deleted at the end.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Database\Schema;
use BookingManager\Database\Transaction;
use BookingManager\Tests\TestCase;

final class ConcurrencyTest extends TestCase {

	private const WORKERS = 8;

	private int $property_id = 0;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// Out of the test's transaction: what is created now is visible to the workers.
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		Transaction::$use_savepoints = false;
	}

	public function tear_down(): void {
		global $wpdb;
		if ( $this->property_id ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Schema::table( '' ) ) . '%' ) ) as $table ) {
				$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'property_id' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from SHOW TABLES.
				if ( $column ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE property_id = %d", $this->property_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			$wpdb->delete( Schema::table( 'properties' ), array( 'id' => $this->property_id ) );
		}
		Transaction::$use_savepoints = true;
		parent::tear_down();
	}

	public function test_the_last_room_is_sold_once_when_many_book_together(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open non disponibile.' );
		}
		$p                 = $this->property();
		$this->property_id = (int) $p['id'];
		$t                 = $this->room_type( $p['id'], 1 );
		$arrival           = self::day( 30 );
		$departure         = self::day( 33 );
		$start             = microtime( true ) + 3.0;
		$worker            = dirname( __DIR__ ) . '/concurrency/worker.php';

		$procs = array();
		for ( $i = 0; $i < self::WORKERS; $i++ ) {
			$cmd   = array( PHP_BINARY, $worker, (string) $p['id'], (string) $t['type']['id'], $arrival, $departure, sprintf( '%.6F', $start ) );
			$pipes = array();
			$proc  = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			$this->assertIsResource( $proc );
			$procs[] = array( $proc, $pipes );
		}

		$results = array();
		foreach ( $procs as list( $proc, $pipes ) ) {
			$out = stream_get_contents( $pipes[1] );
			$err = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $proc );
			$data = json_decode( (string) $out, true );
			$this->assertIsArray( $data, 'Il processo concorrente non ha risposto: ' . $out . $err );
			$results[] = $data;
		}

		$won  = array_filter( $results, static fn( array $r ) => $r['ok'] );
		$lost = array_filter( $results, static fn( array $r ) => ! $r['ok'] );
		$this->assertCount( 1, $won, 'Una sola prenotazione deve riuscire: ' . wp_json_encode( $results ) );
		foreach ( $lost as $r ) {
			$this->assertSame( 'bkng_unavailable', $r['code'] );
		}

		global $wpdb;
		$rooms = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table( 'booking_rooms' ) . " WHERE room_type_id = %d AND occupies = 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$t['type']['id']
			)
		);
		$this->assertSame( 1, $rooms );
	}
}
