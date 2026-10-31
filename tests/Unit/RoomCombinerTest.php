<?php
/**
 * Rooms to book together when no single room hosts the group.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Unit;

use BookingManager\Availability\RoomCombiner;
use PHPUnit\Framework\TestCase;

final class RoomCombinerTest extends TestCase {

	private const DOUBLE = 1;
	private const TRIPLE = 2;

	private static function type( int $id, int $occupancy, int $free, int $adults = 0, int $children = 0 ): array {
		return array(
			'id'            => $id,
			'max_adults'    => $adults ? $adults : $occupancy,
			'max_children'  => $children,
			'max_occupancy' => $occupancy,
			'free'          => $free,
		);
	}

	/** @return string[] "2+2" style signatures */
	private static function shapes( array $combos ): array {
		return array_map(
			static fn( array $c ) => implode( '+', array_map( static fn( array $r ) => $r['adults'] + $r['children'], $c['rooms'] ) ),
			$combos
		);
	}

	public function test_four_guests_get_two_doubles(): void {
		$combos = RoomCombiner::combine( array( self::type( self::DOUBLE, 2, 3 ) ), 4, 0, 4 );
		$this->assertSame( array( '2+2' ), self::shapes( $combos ) );
		$this->assertSame( array( self::DOUBLE, self::DOUBLE ), array_column( $combos[0]['rooms'], 'room_type_id' ) );
	}

	public function test_five_guests_get_a_triple_and_a_double_first(): void {
		$combos = RoomCombiner::combine( array( self::type( self::DOUBLE, 2, 3 ), self::type( self::TRIPLE, 3, 2 ) ), 5, 0, 4 );
		$this->assertSame( '3+2', self::shapes( $combos )[0] );
		$this->assertSame( array( self::TRIPLE, self::DOUBLE ), array_column( $combos[0]['rooms'], 'room_type_id' ) );
		$this->assertSame( 0, $combos[0]['spare'] );
		// Two triples fit too (one bed spare), and three doubles; never four rooms.
		$this->assertContains( '3+2', self::shapes( $combos ) );
		$this->assertContains( '2+2+1', self::shapes( $combos ) );
		foreach ( $combos as $combo ) {
			$this->assertLessThanOrEqual( 3, count( $combo['rooms'] ) );
		}
	}

	public function test_six_guests_get_two_triples_or_three_doubles(): void {
		$combos = RoomCombiner::combine( array( self::type( self::DOUBLE, 2, 3 ), self::type( self::TRIPLE, 3, 2 ) ), 6, 0, 4 );
		$shapes = self::shapes( $combos );
		$this->assertSame( '3+3', $shapes[0] );
		$this->assertContains( '2+2+2', $shapes );
		$this->assertCount( 2, $combos[0]['rooms'] );
	}

	public function test_no_combination_asks_for_a_room_not_needed(): void {
		$combos = RoomCombiner::combine( array( self::type( self::DOUBLE, 2, 5 ) ), 4, 0, 5 );
		$this->assertSame( array( '2+2' ), self::shapes( $combos ) );
	}

	public function test_free_rooms_and_room_limit_are_respected(): void {
		// Only one double free: four guests cannot be split.
		$this->assertSame( array(), RoomCombiner::combine( array( self::type( self::DOUBLE, 2, 1 ) ), 4, 0, 4 ) );
		// Three doubles needed but a booking may hold two rooms only.
		$this->assertSame( array(), RoomCombiner::combine( array( self::type( self::DOUBLE, 2, 5 ) ), 6, 0, 2 ) );
	}

	public function test_every_room_gets_an_adult_and_children_fit_the_limits(): void {
		// Doubles: two adults or one adult and one child.
		$double = self::type( self::DOUBLE, 2, 4, 2, 1 );
		$combos = RoomCombiner::combine( array( $double ), 2, 2, 4 );
		$this->assertCount( 1, $combos );
		foreach ( $combos[0]['rooms'] as $room ) {
			$this->assertSame( 1, $room['adults'] );
			$this->assertSame( 1, $room['children'] );
		}
		// One adult with three children cannot be split: every room needs an adult.
		$this->assertSame( array(), RoomCombiner::combine( array( $double ), 1, 3, 4 ) );
	}

	public function test_split_is_exact(): void {
		$rooms = array( self::type( self::TRIPLE, 3, 1, 3, 0 ), self::type( self::DOUBLE, 2, 1, 2, 2 ) );
		// The two children can only go in the double: the triple takes three adults.
		$split = RoomCombiner::split( $rooms, 3, 2 );
		$this->assertNull( $split );
		$split = RoomCombiner::split( $rooms, 4, 1 );
		$this->assertSame(
			array(
				array( 'room_type_id' => self::TRIPLE, 'adults' => 3, 'children' => 0 ),
				array( 'room_type_id' => self::DOUBLE, 'adults' => 1, 'children' => 1 ),
			),
			$split
		);
	}
}
