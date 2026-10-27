<?php
/**
 * The portals' calendars: reading Booking.com / Airbnb events (folded lines, dates with
 * or without time, cancelled events, no UID) and writing a room's closed periods.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Unit;

use BookingManager\Channels\Ical\IcalCalendar;
use BookingManager\Channels\Ical\IcalService;
use WP_UnitTestCase;

final class IcalCalendarTest extends WP_UnitTestCase {

	public function test_reads_airbnb_and_booking_events(): void {
		$ics = implode(
			"\r\n",
			array(
				'BEGIN:VCALENDAR',
				'PRODID:-//Airbnb Inc//Hosting Calendar 1.0//EN',
				'BEGIN:VEVENT',
				'DTEND;VALUE=DATE:20261114',
				'DTSTART;VALUE=DATE:20261110',
				'UID:1418fb94e984-2d3a1b6f0e7c2a4b@airbnb.com',
				'DESCRIPTION:Reservation URL: https://www.airbnb.com/hosting/reservations/details/HMABCDEF\n',
				' Phone Number (Last 4 Digits): 1234',
				'SUMMARY:Reserved',
				'END:VEVENT',
				'BEGIN:VEVENT',
				'DTSTART:20261201T140000Z',
				'DTEND:20261203T100000Z',
				'UID:abc@booking.com',
				'SUMMARY:CLOSED - Not available',
				'END:VEVENT',
				'BEGIN:VEVENT',
				'DTSTART;VALUE=DATE:20261220',
				'SUMMARY:Airbnb (Not available)',
				'END:VEVENT',
				'BEGIN:VEVENT',
				'DTSTART;VALUE=DATE:20261224',
				'DTEND;VALUE=DATE:20261226',
				'STATUS:CANCELLED',
				'UID:gone',
				'END:VEVENT',
				'BEGIN:VEVENT',
				'DTSTART;VALUE=DATE:20261230',
				'DTEND;VALUE=DATE:20261230',
				'UID:empty',
				'END:VEVENT',
				'END:VCALENDAR',
			)
		);
		$events = IcalCalendar::parse( $ics );
		$this->assertCount( 3, $events, 'cancelled and empty events are skipped' );
		$this->assertSame( '1418fb94e984-2d3a1b6f0e7c2a4b@airbnb.com', $events[0]['uid'] );
		$this->assertSame( array( '2026-11-10', '2026-11-14', 'Reserved' ), array( $events[0]['from'], $events[0]['to'], $events[0]['summary'] ) );
		$this->assertTrue( IcalCalendar::is_reservation( $events[0]['summary'] ) );
		$this->assertSame( array( '2026-12-01', '2026-12-03' ), array( $events[1]['from'], $events[1]['to'] ), 'date-times are read as dates' );
		$this->assertFalse( IcalCalendar::is_reservation( $events[1]['summary'] ) );
		$this->assertSame( array( '2026-12-20', '2026-12-21' ), array( $events[2]['from'], $events[2]['to'] ), 'no end: one night' );
		$this->assertStringStartsWith( 'senza-uid-2026-12-20-2026-12-21', $events[2]['uid'] );
		$this->assertFalse( IcalCalendar::is_reservation( $events[2]['summary'] ) );
		$this->assertSame( array(), IcalCalendar::parse( '<html>not a calendar</html>' ) );
	}

	public function test_ranges_and_calendar_for_the_portals(): void {
		$ranges = IcalCalendar::ranges( array( '2026-11-10', '2026-11-11', '2026-11-13', '2026-11-30', '2026-12-01' ) );
		$this->assertSame( array( array( '2026-11-10', '2026-11-12' ), array( '2026-11-13', '2026-11-14' ), array( '2026-11-30', '2026-12-02' ) ), $ranges );

		$ics = IcalCalendar::build( $ranges, 'bkng-7', 'Villa Rosa, B&B - 101', 1798761600 );
		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\n", $ics );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
		$this->assertSame( 3, substr_count( $ics, 'BEGIN:VEVENT' ) );
		$this->assertStringContainsString( "DTSTART;VALUE=DATE:20261130\r\nDTEND;VALUE=DATE:20261202\r\n", $ics );
		$this->assertStringContainsString( 'X-WR-CALNAME:Villa Rosa\, B&B - 101', $ics );
		$this->assertStringContainsString( 'UID:bkng-7-20261110-20261112@', $ics );
		$this->assertStringContainsString( 'SUMMARY:Non disponibile', $ics );

		// What we write, we read back the same.
		$back = IcalCalendar::parse( $ics );
		$this->assertSame( array( '2026-11-13', '2026-11-14' ), array( $back[1]['from'], $back[1]['to'] ) );
	}

	public function test_calendar_addresses(): void {
		$this->assertSame( 'https://www.airbnb.it/calendar/ical/123.ics?s=abc', IcalService::clean_url( ' webcal://www.airbnb.it/calendar/ical/123.ics?s=abc ' ) );
		$this->assertSame( 'https://admin.booking.com/hotel/hoteladmin/ical.html?t=x-y', IcalService::clean_url( 'https://admin.booking.com/hotel/hoteladmin/ical.html?t=x-y' ) );
		$this->assertNull( IcalService::clean_url( 'ftp://example.org/cal.ics' ) );
		$this->assertNull( IcalService::clean_url( 'javascript:alert(1)' ) );
		$this->assertNull( IcalService::clean_url( 'file:///etc/passwd' ) );
		$this->assertNull( IcalService::clean_url( '' ) );
	}
}
