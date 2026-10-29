<?php
/**
 * The stay as a calendar file: all-day event from arrival to departure included, text
 * escaped, long lines folded, same UID for the same booking.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Unit;

use BookingManager\Bookings\CalendarFile;
use WP_UnitTestCase;

final class CalendarFileTest extends WP_UnitTestCase {

	public function test_the_stay_as_an_event(): void {
		$ics = CalendarFile::build(
			array(
				'code'      => 'BK-7Q2X',
				'arrival'   => '2026-11-28',
				'departure' => '2026-11-30',
			),
			array(
				'name'           => 'Villa Rosa, B&B; centro',
				'address'        => 'Via Roma 1',
				'postcode'       => '55100',
				'city'           => 'Lucca',
				'checkin_from'   => '14:00:00',
				'checkin_to'     => '20:00:00',
				'checkout_until' => '10:30:00',
			),
			'hotel.example',
			1798761600
		);
		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( "UID:bk-7q2x@hotel.example\r\n", $ics );
		$this->assertStringContainsString( "DTSTART;VALUE=DATE:20261128\r\n", $ics );
		$this->assertStringContainsString( "DTEND;VALUE=DATE:20261201\r\n", $ics, 'the departure day is in the event' );
		$this->assertStringContainsString( 'SUMMARY:Villa Rosa\, B&B\; centro', $ics );
		$this->assertStringContainsString( 'LOCATION:Via Roma 1\, 55100 Lucca', $ics );
		$this->assertStringContainsString( 'Check-in dalle 14:00 alle 20:00\nCheck-out entro le 10:30', str_replace( "\r\n ", '', $ics ) );
		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ), 'lines folded at 75 octets' );
		}
		$this->assertSame( 'prenotazione-BK7Q2X.ics', CalendarFile::filename( 'BK-7Q2X' ) );
	}
}
