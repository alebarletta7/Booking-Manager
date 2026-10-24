<?php
/**
 * Commercial status transitions and the helpers around dates.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Unit;

use BookingManager\Bookings\BookingStatus;
use BookingManager\Core\Dates;
use PHPUnit\Framework\TestCase;

final class BookingStatusTest extends TestCase {

	public function test_transitions(): void {
		$this->assertTrue( BookingStatus::can( 'pending', 'confirmed' ) );
		$this->assertTrue( BookingStatus::can( 'option', 'cancelled' ) );
		$this->assertTrue( BookingStatus::can( 'confirmed', 'in_house' ) );
		$this->assertTrue( BookingStatus::can( 'no_show', 'confirmed' ) );
		$this->assertFalse( BookingStatus::can( 'cancelled', 'confirmed' ) );
		$this->assertFalse( BookingStatus::can( 'completed', 'in_house' ) );
		$this->assertFalse( BookingStatus::can( 'pending', 'in_house' ) );
	}

	public function test_which_statuses_occupy_rooms(): void {
		foreach ( array( 'pending', 'option', 'confirmed', 'in_house' ) as $status ) {
			$this->assertTrue( BookingStatus::occupies( $status ), $status );
		}
		foreach ( array( 'completed', 'cancelled', 'no_show' ) as $status ) {
			$this->assertFalse( BookingStatus::occupies( $status ), $status );
		}
	}

	public function test_nights_exclude_the_departure_day(): void {
		$this->assertSame( array( '2026-02-27', '2026-02-28' ), Dates::nights( '2026-02-27', '2026-03-01' ) );
		$this->assertSame( 0, Dates::count( '2026-03-01', '2026-03-01' ) );
		$this->assertSame( array(), Dates::nights( '2026-03-02', '2026-03-01' ) );
		$this->assertSame( 365, Dates::count( '2026-01-01', '2027-01-01' ) );
		$this->assertSame( -3, Dates::diff_days( '2026-03-04', '2026-03-01' ) );
		$this->assertSame( '1,3,7', Dates::weekdays( array( 7, '3', 1, 9, 'x' ) ) );
		$this->assertNull( Dates::weekdays( '' ) );
	}
}
