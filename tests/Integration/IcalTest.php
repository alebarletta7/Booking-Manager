<?php
/**
 * Booking.com / Airbnb through iCal, against the real database. The portals are not
 * reached: their calendars are served by the pre_http_request filter.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Availability\Ledger;
use BookingManager\Channels\Ical\IcalFeedRepository;
use BookingManager\Channels\Ical\IcalService;
use BookingManager\Database\Schema;
use BookingManager\Tests\TestCase;

final class IcalTest extends TestCase {

	private const URL = 'https://calendar.portal.example/ical/room.ics';

	/** @var string|int calendar served for URL: text, or an HTTP status (0: no answer) */
	private $served = '';

	/** @var array<string, string|int> calendars served for other addresses */
	private array $others = array();

	public function set_up(): void {
		parent::set_up();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( 0 !== strpos( (string) $url, 'https://calendar.portal.example/' ) ) {
					return $pre;
				}
				$served = $this->others[ (string) $url ] ?? $this->served;
				if ( is_int( $served ) ) {
					return 0 === $served
						? new \WP_Error( 'http_request_failed', 'cURL error 28: timeout' )
						: array(
							'headers'  => array(),
							'body'     => 'error',
							'response' => array(
								'code'    => $served,
								'message' => 'Error',
							),
							'cookies'  => array(),
							'filename' => null,
						);
				}
				return array(
					'headers'  => array( 'content-type' => 'text/calendar' ),
					'body'     => $served,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function ical(): IcalService {
		return $this->service( IcalService::class );
	}

	/** @param array<int, array{0: int, 1: int, 2?: string, 3?: string}> $events [from, to, summary, uid] in days from today */
	private static function calendar( array $events ): string {
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Portal//EN' );
		foreach ( $events as $i => $e ) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . ( $e[3] ?? 'evt-' . $i . '@portal.example' );
			$lines[] = 'DTSTART;VALUE=DATE:' . str_replace( '-', '', self::day( $e[0] ) );
			$lines[] = 'DTEND;VALUE=DATE:' . str_replace( '-', '', self::day( $e[1] ) );
			$lines[] = 'SUMMARY:' . ( $e[2] ?? 'CLOSED - Not available' );
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/** @return array<int, array<string, mixed>> active closures of a room */
	private static function blocks( int $unit_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'unit_blocks' ) . " WHERE unit_id = %d AND status = 'active' ORDER BY date_from", $unit_id ), ARRAY_A );
	}

	private static function sync_rows( string $status ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'sync_log' ) . ' WHERE status = %s', $status ) );
	}

	/** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int} property, type, feed id */
	private function connected( int $units = 1, string $channel = 'booking' ): array {
		$this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], $units );
		$add  = $this->ical()->add_feed(
			$p['id'],
			array(
				'unit_id' => $t['units'][0]['id'],
				'channel' => $channel,
				'url'     => self::URL,
			),
			get_current_user_id()
		);
		$this->assertIsArray( $add );
		return array( $p, $t, (int) $add['feed']['id'] );
	}

	public function test_portal_stays_close_the_room_and_reopen_when_dropped(): void {
		$this->served         = self::calendar( array( array( 5, 8 ), array( 20, 22, 'Reserved', 'res-1@airbnb.com' ) ) );
		[ $p, $t, $feed_id ] = $this->connected();
		$unit                 = $t['units'][0]['id'];

		$blocks = self::blocks( $unit );
		$this->assertCount( 2, $blocks );
		$this->assertSame( array( 'channel', self::day( 5 ), self::day( 8 ), 'Booking.com' ), array( $blocks[0]['kind'], $blocks[0]['date_from'], $blocks[0]['date_to'], $blocks[0]['reason'] ) );
		$this->assertSame( $feed_id, (int) $blocks[0]['feed_id'] );
		$this->assertSame( 0, $this->service( Ledger::class )->free( $t['type']['id'], self::day( 6 ), self::day( 7 ) ) );

		$this->assertWPError( $this->book( $p['id'], $t['type']['id'], 6, 7 ), 'a night closed by the portal cannot be booked here' );
		$this->assertIsArray( $this->book( $p['id'], $t['type']['id'], 10, 12 ) );

		// Same calendar again: nothing changes.
		$again = $this->ical()->sync_feed( $feed_id );
		$this->assertSame( array( 'ok', 0, 0 ), array( $again['status'], $again['created'], $again['removed'] ) );
		$this->assertCount( 2, self::blocks( $unit ) );

		// The portal drops the first stay and moves the second.
		$this->served = self::calendar( array( array( 21, 24, 'Reserved', 'res-1@airbnb.com' ) ) );
		$moved        = $this->ical()->sync_feed( $feed_id );
		$this->assertSame( array( 1, 2 ), array( $moved['created'], $moved['removed'] ) );
		$blocks = self::blocks( $unit );
		$this->assertCount( 1, $blocks );
		$this->assertSame( array( self::day( 21 ), self::day( 24 ) ), array( $blocks[0]['date_from'], $blocks[0]['date_to'] ) );
		$this->assertIsArray( $this->book( $p['id'], $t['type']['id'], 6, 7 ), 'reopened' );

		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'unit_blocks' ) . ' WHERE feed_id IS NOT NULL AND status <> \'active\'' ), 'no row left behind' );
	}

	public function test_a_portal_that_cannot_be_read_keeps_its_closures(): void {
		$this->served         = self::calendar( array( array( 5, 8 ) ) );
		[ , $t, $feed_id ]    = $this->connected();
		$unit                 = $t['units'][0]['id'];
		foreach ( array( 0, 500, 'Bad gateway <html>' ) as $served ) {
			$this->served = $served;
			$r            = $this->ical()->sync_feed( $feed_id );
			$this->assertSame( 'error', $r['status'] );
			$this->assertNotEmpty( $r['error'] );
			$this->assertCount( 1, self::blocks( $unit ), 'the closure stays' );
		}
		$feed = $this->service( IcalFeedRepository::class )->find( $feed_id );
		$this->assertSame( 'error', $feed['last_status'] );
		$this->assertSame( 1, $feed['events'], 'the last good reading is kept' );
		$this->assertSame( 1, self::sync_rows( 'error' ), 'one error row a day' );
	}

	public function test_our_own_dates_coming_back_are_not_errors_but_a_reservation_on_them_is_flagged(): void {
		$this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 1 );
		$unit = $t['units'][0]['id'];
		$this->assertIsArray( $this->book( $p['id'], $t['type']['id'], 5, 8 ) );

		// The portal shows our booking as closed (it read our link), plus one stay of its own.
		$this->served = self::calendar( array( array( 5, 8, 'Airbnb (Not available)' ), array( 10, 12, 'Reserved', 'r2' ) ) );
		$add          = $this->ical()->add_feed(
			$p['id'],
			array(
				'unit_id' => $unit,
				'channel' => 'airbnb',
				'url'     => self::URL,
			),
			1
		);
		$this->assertSame( array( 'ok', 1, 3, 0 ), array( $add['result']['status'], $add['result']['created'], $add['result']['skipped'], $add['result']['conflicts'] ) );
		$this->assertCount( 1, self::blocks( $unit ) );
		$this->assertSame( 'Airbnb · prenotazione', self::blocks( $unit )[0]['reason'] );
		$this->assertSame( 0, self::sync_rows( 'conflict' ) );

		// An Airbnb reservation on nights we sold: possible double booking, logged once.
		$this->served = self::calendar( array( array( 7, 9, 'Reserved', 'r3' ), array( 10, 12, 'Reserved', 'r2' ) ) );
		$r            = $this->ical()->sync_feed( (int) $add['feed']['id'] );
		$this->assertSame( 1, $r['conflicts'] );
		$this->ical()->sync_feed( (int) $add['feed']['id'] );
		$this->assertSame( 1, self::sync_rows( 'conflict' ) );
		$blocks = self::blocks( $unit );
		$this->assertSame( array( self::day( 8 ), self::day( 9 ) ), array( $blocks[0]['date_from'], $blocks[0]['date_to'] ), 'the free night of that stay is closed' );
		$overview = $this->ical()->overview( $p['id'] );
		$this->assertStringContainsString( 'doppia prenotazione', $overview['log'][0]['message'] );
		$this->assertStringNotContainsString( 'Rossi', wp_json_encode( $overview ) );
	}

	public function test_removing_a_calendar_frees_its_nights(): void {
		$this->served         = self::calendar( array( array( 5, 8 ), array( 9, 10 ) ) );
		[ $p, $t, $feed_id ] = $this->connected();
		$this->assertCount( 2, self::blocks( $t['units'][0]['id'] ) );
		$this->assertTrue( $this->ical()->delete_feed( $feed_id ) );
		$this->assertCount( 0, self::blocks( $t['units'][0]['id'] ) );
		$this->assertSame( 1, $this->service( Ledger::class )->free( $t['type']['id'], self::day( 5 ), self::day( 10 ) ) );
		$this->assertNull( $this->service( IcalFeedRepository::class )->find( $feed_id ) );
		$this->assertSame( array(), $this->ical()->overview( $p['id'] )['rooms'][0]['feeds'] );
	}

	public function test_adding_a_calendar_is_validated(): void {
		$this->admin();
		$p     = $this->property();
		$other = $this->property();
		$t     = $this->room_type( $p['id'], 1 );
		$t2    = $this->room_type( $other['id'], 1 );
		$unit  = $t['units'][0]['id'];
		$bad   = array(
			array( 'unit_id' => $t2['units'][0]['id'], 'channel' => 'booking', 'url' => self::URL ),
			array( 'unit_id' => $unit, 'channel' => 'hotels', 'url' => self::URL ),
			array( 'unit_id' => $unit, 'channel' => 'booking', 'url' => 'ftp://x/y.ics' ),
			array( 'unit_id' => $unit, 'channel' => 'booking', 'url' => $this->ical()->export_url( $unit ) ),
		);
		foreach ( $bad as $input ) {
			$this->assertWPError( $this->ical()->add_feed( $p['id'], $input, 1 ), wp_json_encode( $input ) );
		}
		$this->served = self::calendar( array() );
		$this->assertIsArray( $this->ical()->add_feed( $p['id'], array( 'unit_id' => $unit, 'channel' => 'booking', 'url' => self::URL ), 1 ) );
		$this->assertWPError( $this->ical()->add_feed( $p['id'], array( 'unit_id' => $unit, 'channel' => 'airbnb', 'url' => self::URL ), 1 ), 'same calendar twice' );
	}

	public function test_the_link_for_the_portals(): void {
		$this->admin();
		$p     = $this->property( array( 'name' => 'Villa Rosa' ) );
		$t     = $this->room_type( $p['id'], 2 );
		$first = $t['units'][0]['id'];
		$last  = $t['units'][1]['id'];

		$booking = $this->book( $p['id'], $t['type']['id'], 3, 5, array( 'unit_id' => $first ) );
		$this->assertIsArray( $booking );
		$block = $this->service( \BookingManager\Inventory\InventoryService::class )->add_block( $first, array( 'date_from' => self::day( 10 ), 'date_to' => self::day( 11 ) ), 1 );
		$this->assertIsArray( $block );

		$url   = $this->ical()->export_url( $first );
		$token = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$token = substr( $token, strlen( IcalService::QUERY_VAR ) + 1 );
		$ics   = $this->ical()->export_for_token( $token );
		$this->assertIsString( $ics );
		$this->assertStringContainsString( 'DTSTART;VALUE=DATE:' . str_replace( '-', '', self::day( 3 ) ) . "\r\nDTEND;VALUE=DATE:" . str_replace( '-', '', self::day( 5 ) ), $ics );
		$this->assertStringContainsString( 'DTSTART;VALUE=DATE:' . str_replace( '-', '', self::day( 10 ) ), $ics );
		$this->assertStringContainsString( 'X-WR-CALNAME:Villa Rosa - ', $ics );
		foreach ( array( 'Mario', 'Rossi', 'mario.rossi@', $booking['code'] ) as $private ) {
			$this->assertStringNotContainsString( $private, $ics, 'no guest data' );
		}
		$this->assertNull( $this->ical()->export_for_token( $first . '.' . str_repeat( 'a', 32 ) ) );
		$this->assertNull( $this->ical()->export_for_token( $last . substr( $token, strlen( (string) $first ) ) ), 'a token is for one room' );
		$this->assertNull( $this->ical()->export_for_token( '' ) );

		// A stay not assigned to a room yet: the type has one free room for two empty ones,
		// so the last room is closed on the portals, the first stays open.
		$this->assertIsArray( $this->book( $p['id'], $t['type']['id'], 20, 21, array( 'unit_id' => null ) ) );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'booking_rooms' ) . ' SET unit_id = NULL WHERE arrival = %s', self::day( 20 ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'unit_nights' ) . ' WHERE night = %s AND booking_room_id IS NOT NULL', self::day( 20 ) ) );
		$night = str_replace( '-', '', self::day( 20 ) );
		$this->assertStringNotContainsString( 'DTSTART;VALUE=DATE:' . $night, (string) $this->ical()->export( $first ) );
		$this->assertStringContainsString( 'DTSTART;VALUE=DATE:' . $night, (string) $this->ical()->export( $last ) );

		// New addresses: the old ones stop working.
		$this->ical()->rotate_key();
		$this->assertNull( $this->ical()->export_for_token( $token ) );
	}

	/** Adds a calendar to a room. @return int feed id */
	private function feed( int $property_id, int $unit_id, string $url, string $channel = '' ): int {
		$add = $this->ical()->add_feed(
			$property_id,
			array(
				'unit_id' => $unit_id,
				'channel' => $channel,
				'url'     => $url,
			),
			get_current_user_id()
		);
		$this->assertIsArray( $add );
		return (int) $add['feed']['id'];
	}

	private function export_token( int $unit_id ): string {
		$query = (string) wp_parse_url( $this->ical()->export_url( $unit_id ), PHP_URL_QUERY );
		return substr( $query, strlen( IcalService::QUERY_VAR ) + 1 );
	}

	public function test_two_calendars_on_the_same_nights_never_leave_a_gap(): void {
		$this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 1 );
		$unit = $t['units'][0]['id'];
		$a    = 'https://calendar.portal.example/a.ics';
		$b    = 'https://calendar.portal.example/b.ics';

		$this->others[ $a ] = self::calendar( array( array( 5, 9, 'CLOSED - Not available', 'a1' ) ) );
		$this->others[ $b ] = self::calendar( array( array( 7, 11, 'Airbnb (Not available)', 'b1' ) ) );
		$feed_a             = $this->feed( $p['id'], $unit, $a, 'booking' );
		$feed_b             = $this->feed( $p['id'], $unit, $b, 'airbnb' );
		$blocks             = self::blocks( $unit );
		$this->assertSame( array( array( self::day( 5 ), self::day( 9 ), 'booking' ), array( self::day( 9 ), self::day( 11 ), 'airbnb' ) ), array_map( static fn( $x ) => array( $x['date_from'], $x['date_to'], $x['source'] ), $blocks ), 'each night closed once' );

		// Booking.com drops its nights: Airbnb still has 7-9, closed at once (not at its next reading).
		$this->others[ $a ] = self::calendar( array( array( 30, 31, 'CLOSED - Not available', 'a2' ) ) );
		$this->ical()->sync_feed( $feed_a );
		$this->assertSame( 0, $this->service( Ledger::class )->free( $t['type']['id'], self::day( 7 ), self::day( 11 ) ), 'no gap' );
		$this->assertSame( 1, $this->service( Ledger::class )->free( $t['type']['id'], self::day( 5 ), self::day( 7 ) ) );
		$sources = array_column( self::blocks( $unit ), 'source' );
		sort( $sources );
		$this->assertSame( array( 'airbnb', 'booking' ), $sources );
		$this->assertSame( $feed_b, (int) self::blocks( $unit )[0]['feed_id'] );
	}

	public function test_our_dates_coming_back_from_a_portal_do_not_keep_the_room_closed(): void {
		$this->admin();
		$p       = $this->property();
		$t       = $this->room_type( $p['id'], 1 );
		$unit    = $t['units'][0]['id'];
		$booking = 'https://calendar.portal.example/booking.ics';
		$airbnb  = 'https://calendar.portal.example/airbnb.ics';

		// A stay sold on Booking.com closes the room here...
		$this->others[ $booking ] = self::calendar( array( array( 5, 8, 'CLOSED - Not available', 'bk-1' ), array( 40, 41, 'CLOSED - Not available', 'bk-keep' ) ) );
		$feed_booking             = $this->feed( $p['id'], $unit, $booking, 'booking' );
		$this->assertCount( 2, self::blocks( $unit ) );
		// ...Airbnb reads our link (the nights are recorded) and shows them as not available.
		$this->assertIsString( $this->ical()->export_for_token( $this->export_token( $unit ) ) );
		$this->others[ $airbnb ] = self::calendar( array( array( 5, 8, 'Airbnb (Not available)', 'ab-echo' ) ) );
		$feed_airbnb             = $this->feed( $p['id'], $unit, $airbnb, 'airbnb' );
		$this->assertSame( 0, count( array_filter( self::blocks( $unit ), static fn( $b ) => (int) $b['feed_id'] === $feed_airbnb ) ), 'nights already closed by Booking.com: not closed twice' );

		// Booking.com cancels: the room reopens, Airbnb's copy of our dates is an echo.
		$this->others[ $booking ] = self::calendar( array( array( 40, 41, 'CLOSED - Not available', 'bk-keep' ) ) );
		$this->ical()->sync_feed( $feed_booking );
		$r = $this->ical()->sync_feed( $feed_airbnb );
		$this->assertSame( 1, $r['echoes'] );
		$this->assertSame( 1, $this->service( Ledger::class )->free( $t['type']['id'], self::day( 5 ), self::day( 8 ) ), 'reopened, no loop' );
		$this->assertIsArray( $this->book( $p['id'], $t['type']['id'], 5, 8 ), 'direct booking possible again' );

		// A real Airbnb reservation is never an echo.
		$this->others[ $airbnb ] = self::calendar( array( array( 40, 41, 'Reserved', 'ab-res' ) ) );
		$r                       = $this->ical()->sync_feed( $feed_airbnb );
		$this->assertSame( 0, $r['echoes'] );
		$this->assertSame( 1, $r['conflicts'], 'Airbnb sold a night Booking.com holds: flagged' );
	}

	public function test_a_closure_is_not_an_echo_when_the_portal_changes_its_uid(): void {
		$this->served         = self::calendar( array( array( 5, 8, 'CLOSED - Not available', 'uid-1' ) ) );
		[ , $t, $feed_id ]    = $this->connected();
		$unit                 = $t['units'][0]['id'];
		$this->ical()->export_for_token( $this->export_token( $unit ) );
		$this->served = self::calendar( array( array( 5, 8, 'CLOSED - Not available', 'uid-2' ) ) );
		$r            = $this->ical()->sync_feed( $feed_id );
		$this->assertSame( 0, $r['echoes'] );
		$this->assertCount( 1, self::blocks( $unit ) );
		$this->assertSame( 'uid-2', self::blocks( $unit )[0]['external_uid'] );
	}

	public function test_an_empty_or_broken_calendar_never_frees_dates(): void {
		$this->served         = self::calendar( array( array( 5, 8 ) ) );
		[ , $t, $feed_id ]    = $this->connected();
		$unit                 = $t['units'][0]['id'];

		$broken = array(
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART;VALUE=DATE:2026", // cut off
			"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART:tomorrow\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", // unreadable event
		);
		foreach ( $broken as $ics ) {
			$this->served = $ics;
			$r            = $this->ical()->sync_feed( $feed_id );
			$this->assertSame( 'error', $r['status'] );
			$this->assertCount( 1, self::blocks( $unit ) );
		}

		// Suddenly empty: kept once, freed when the next reading confirms it.
		$this->served = self::calendar( array() );
		$this->assertSame( 'warning', $this->ical()->sync_feed( $feed_id )['status'] );
		$this->assertCount( 1, self::blocks( $unit ) );
		$this->assertSame( 'warning', IcalService::feed_state( $this->service( IcalFeedRepository::class )->find( $feed_id ) ) );
		$this->assertSame( 'ok', $this->ical()->sync_feed( $feed_id )['status'] );
		$this->assertCount( 0, self::blocks( $unit ) );
	}

	public function test_failed_readings_wait_longer_and_two_readings_never_overlap(): void {
		$this->served         = self::calendar( array( array( 5, 8 ) ) );
		[ , , $feed_id ]      = $this->connected();
		$repo                 = $this->service( IcalFeedRepository::class );
		$this->assertNotContains( $feed_id, $repo->due_ids( 50 ), 'just read: not due' );

		$this->served = 503;
		$this->ical()->sync_feed( $feed_id );
		$this->ical()->sync_feed( $feed_id );
		$feed = $repo->find( $feed_id );
		$this->assertSame( 2, $feed['failures'] );
		$this->assertGreaterThan( time() + 25 * MINUTE_IN_SECONDS, strtotime( $feed['next_sync_at'] . ' UTC' ), 'second failure: 30 minutes' );
		$this->assertNotNull( $feed['last_success_at'], 'last good reading kept' );
		$this->assertSame( 'error', IcalService::feed_state( $feed ) );

		$this->assertTrue( $repo->claim( $feed_id, 60 ) );
		$this->assertSame( 'busy', $this->ical()->sync_feed( $feed_id )['status'] );
		$repo->unlock( $feed_id );

		$this->served = self::calendar( array( array( 5, 8 ) ) );
		$this->assertSame( 'ok', $this->ical()->sync_feed( $feed_id )['status'] );
		$this->assertSame( 0, $repo->find( $feed_id )['failures'] );

		// The cron reads only what is due, and says when it ran.
		global $wpdb;
		$wpdb->update( Schema::table( 'ical_feeds' ), array( 'next_sync_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $feed_id ) );
		$this->assertContains( $feed_id, $repo->due_ids( 50 ) );
		$this->ical()->sync_all();
		$this->assertNotContains( $feed_id, $repo->due_ids( 50 ) );
		$this->assertTrue( $this->ical()->cron_state()['healthy'] );
	}

	public function test_sources_are_kept_apart_and_addresses_are_secret(): void {
		$this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 1 );
		$unit = $t['units'][0]['id'];
		$this->assertIsArray( $this->book( $p['id'], $t['type']['id'], 2, 3, array( 'unit_id' => $unit ) ) );
		$manual = $this->service( \BookingManager\Inventory\InventoryService::class )->add_block( $unit, array( 'date_from' => self::day( 4 ), 'date_to' => self::day( 5 ) ), 1 );
		$this->assertSame( 'manual', $manual['source'] );
		$url          = 'https://calendar.portal.example/room.ics?s=private-key-123';
		$this->others[ $url ] = self::calendar( array( array( 10, 12, 'Reserved', 'r' ) ) );
		$this->feed( $p['id'], $unit, $url, 'airbnb' );

		$room = $this->ical()->overview( $p['id'] )['rooms'][0];
		$this->assertSame( array( 'direct', 'airbnb', 'manual' ), array_column( $room['sources'], 'source' ) );
		$this->assertSame( array( 1, 2, 1 ), array_column( $room['sources'], 'nights' ) );
		$this->assertSame( 'Prenotazioni dirette', $room['sources'][0]['label'] );

		$json = (string) wp_json_encode( $this->ical()->overview( $p['id'] ) );
		$this->assertStringNotContainsString( 'private-key-123', $json, 'the address is never returned' );
		$this->assertSame( 'calendar.portal.example/room.ics?…', $room['feeds'][0]['url_hint'] );
		global $wpdb;
		$stored = (string) $wpdb->get_var( 'SELECT url FROM ' . Schema::table( 'ical_feeds' ) );
		$this->assertStringStartsWith( 'rm1:', $stored );
		$this->assertStringNotContainsString( 'private-key', $stored, 'encrypted in the database' );
	}

	public function test_all_rooms_at_once_with_the_portal_found_from_the_address(): void {
		$this->admin();
		$p     = $this->property();
		$other = $this->property();
		$t     = $this->room_type( $p['id'], 2 );
		$t2    = $this->room_type( $other['id'], 1 );
		$this->others['https://calendar.portal.example/x.ics'] = self::calendar( array( array( 5, 6 ) ) );
		$r = $this->ical()->add_feeds(
			$p['id'],
			array(
				array( 'unit_id' => $t['units'][0]['id'], 'url' => 'https://calendar.portal.example/x.ics' ),
				array( 'unit_id' => $t['units'][1]['id'], 'url' => 'webcal://calendar.portal.example/y.ics', 'channel' => 'booking' ),
				array( 'unit_id' => $t2['units'][0]['id'], 'url' => 'https://calendar.portal.example/z.ics' ),
				array( 'unit_id' => $t['units'][1]['id'], 'url' => 'http://calendar.portal.example/w.ics' ),
				'garbage',
			),
			1
		);
		$this->assertSame( array( 2, 3 ), array( $r['added'], $r['errors'] ) );
		$this->assertSame( array( true, true, false, false, false ), array_column( $r['items'], 'ok' ) );
		$this->assertSame( 'other', $r['items'][0]['feed']['channel'], 'unknown host: «Altro portale»' );
		$this->assertSame( 'booking', $r['items'][1]['feed']['channel'] );
		$this->assertCount( 0, $this->service( IcalFeedRepository::class )->for_property( $other['id'] ), 'a room of another property is refused' );
	}

	public function test_a_room_gets_a_new_link_and_the_old_one_stops(): void {
		$this->admin();
		$p     = $this->property();
		$t     = $this->room_type( $p['id'], 2 );
		$first = $t['units'][0]['id'];
		$old   = $this->export_token( $first );
		$keep  = $this->export_token( $t['units'][1]['id'] );
		$new   = $this->ical()->rotate_unit( $first );
		$this->assertIsArray( $new );
		$this->assertNull( $this->ical()->export_for_token( $old ) );
		$this->assertIsString( $this->ical()->export_for_token( $this->export_token( $first ) ) );
		$this->assertIsString( $this->ical()->export_for_token( $keep ), 'the other rooms keep their link' );
	}

	public function test_holds_are_not_exported_and_a_cancelled_booking_lets_a_portal_close_the_room(): void {
		$this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 1 );
		$unit = $t['units'][0]['id'];
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'holds' ),
			array(
				'property_id'  => $p['id'],
				'room_type_id' => $t['type']['id'],
				'arrival'      => self::day( 20 ),
				'departure'    => self::day( 21 ),
				'quantity'     => 1,
				'status'       => 'active',
				'uuid'         => wp_generate_uuid4(),
				'token_hash'   => hash( 'sha256', 'test-hold' ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + 600 ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$this->assertStringNotContainsString( 'DTSTART;VALUE=DATE:' . str_replace( '-', '', self::day( 20 ) ), (string) $this->ical()->export( $unit ), 'a hold of the booking form is not exported' );

		$booking = $this->book( $p['id'], $t['type']['id'], 5, 8, array( 'unit_id' => $unit ) );
		$this->served = self::calendar( array( array( 5, 8, 'CLOSED - Not available', 'later' ) ) );
		$feed_id      = $this->feed( $p['id'], $unit, self::URL );
		$this->assertCount( 0, self::blocks( $unit ), 'taken here: skipped' );

		$this->assertIsArray( $this->service( \BookingManager\Bookings\BookingService::class )->set_status( $booking['id'], $booking['version'], 'cancelled', array( 'reason' => 'Test' ) ) );
		$this->assertNotFalse( wp_next_scheduled( IcalService::RECONCILE_HOOK, array( $p['id'] ) ), 'rebuild scheduled after the cancellation' );
		do_action( IcalService::RECONCILE_HOOK, $p['id'] );
		$this->assertCount( 1, self::blocks( $unit ), 'the portal closure now applies, without downloading again' );
		$this->assertSame( $feed_id, (int) self::blocks( $unit )[0]['feed_id'] );
	}

	public function test_staff_of_another_property_cannot_see_or_touch_its_calendars(): void {
		$this->served = self::calendar( array( array( 5, 8 ) ) );
		[ $p, $t, $feed_id ] = $this->connected();
		$mine                 = $this->property( array( 'name' => 'Mia' ) );
		$user                 = self::factory()->user->create( array( 'role' => 'bkng_property_manager' ) );
		( new \WP_User( $user ) )->add_cap( 'bkng_manage_integrations' );
		$this->service( \BookingManager\Access\PropertyScope::class )->assign( $user, $mine['id'] );
		wp_set_current_user( $user );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		remove_all_filters( 'rest_dispatch_request' );
		$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'GET', '/booking-manager/v1/properties/' . $mine['id'] . '/ical' ) )->get_status() );
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'GET', '/booking-manager/v1/properties/' . $p['id'] . '/ical' ) )->get_status() );
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'DELETE', '/booking-manager/v1/ical/feeds/' . $feed_id ) )->get_status() );
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'POST', '/booking-manager/v1/ical/feeds/' . $feed_id . '/sync' ) )->get_status() );
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'POST', '/booking-manager/v1/ical/units/' . $t['units'][0]['id'] . '/link' ) )->get_status() );
		$bulk = new \WP_REST_Request( 'POST', '/booking-manager/v1/properties/' . $mine['id'] . '/ical/feeds/bulk' );
		$bulk->set_body_params( array( 'items' => array( array( 'unit_id' => $t['units'][0]['id'], 'url' => 'https://calendar.portal.example/q.ics' ) ) ) );
		$this->assertSame( 0, rest_do_request( $bulk )->get_data()['added'], 'a room of another property cannot be linked through mine' );
		$this->assertNotNull( $this->service( IcalFeedRepository::class )->find( $feed_id ) );
		$wp_rest_server = null;
	}

	public function test_existing_calendars_are_kept_by_the_upgrade(): void {
		$this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 1 );
		$unit = $t['units'][0]['id'];
		global $wpdb;
		// A calendar as 0.1.11 left it: address in clear, a closure, no events.
		$wpdb->insert(
			Schema::table( 'ical_feeds' ),
			array(
				'property_id' => $p['id'],
				'unit_id'     => $unit,
				'channel'     => 'airbnb',
				'url'         => 'https://calendar.portal.example/old.ics?s=old-key',
				'status'      => 'active',
				'events'      => 1,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$feed_id = (int) $wpdb->insert_id;
		$block   = $this->service( IcalFeedRepository::class )->create_block( array( 'property_id' => $p['id'], 'unit_id' => $unit, 'room_type_id' => $t['type']['id'], 'date_from' => self::day( 5 ), 'date_to' => self::day( 8 ), 'source' => 'manual', 'reason' => 'Airbnb · prenotazione', 'feed_id' => $feed_id, 'external_uid' => 'old-1' ) );
		$this->service( Ledger::class )->occupy( $unit, $p['id'], self::day( 5 ), self::day( 8 ), null, $block );

		( new \BookingManager\Database\Migrations\Migration_20261029_000001_IcalSources() )->up( $wpdb );
		$feed = $this->service( IcalFeedRepository::class )->find( $feed_id );
		$this->assertStringStartsWith( 'rm1:', $feed['url'] );
		$this->assertSame( 'https://calendar.portal.example/old.ics?s=old-key', $this->service( IcalFeedRepository::class )->url( $feed ) );
		$this->assertSame( 'airbnb', self::blocks( $unit )[0]['source'] );
		$this->assertSame( 1, $this->service( \BookingManager\Channels\Ical\IcalEventRepository::class )->count_future( $feed_id, self::day( 0 ) ), 'the closure became an event' );

		// Rebuilding the room from the events keeps the closure.
		$this->ical()->reconcile_property( $p['id'] );
		$this->assertCount( 1, self::blocks( $unit ) );
		$this->assertSame( 0, $this->service( Ledger::class )->free( $t['type']['id'], self::day( 5 ), self::day( 8 ) ) );
	}

	public function test_rest_routes(): void {
		$this->served = self::calendar( array( array( 5, 8 ) ) );
		$this->admin();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		remove_all_filters( 'rest_dispatch_request' );

		$add = new \WP_REST_Request( 'POST', '/booking-manager/v1/properties/' . $p['id'] . '/ical/feeds' );
		$add->set_body_params(
			array(
				'unit_id' => $t['units'][0]['id'],
				'channel' => 'airbnb',
				'url'     => self::URL,
			)
		);
		$res = rest_do_request( $add );
		$this->assertSame( 201, $res->get_status() );
		$feed_id = $res->get_data()['feed']['id'];

		$view = rest_do_request( new \WP_REST_Request( 'GET', '/booking-manager/v1/properties/' . $p['id'] . '/ical' ) );
		$this->assertSame( 200, $view->get_status() );
		$room = $view->get_data()['rooms'][0];
		$this->assertStringContainsString( IcalService::QUERY_VAR . '=', $room['export_url'] );
		$this->assertSame( 1, $room['feeds'][0]['blocks'] );

		$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'POST', '/booking-manager/v1/ical/feeds/' . $feed_id . '/sync' ) )->get_status() );
		$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'POST', '/booking-manager/v1/properties/' . $p['id'] . '/ical/sync' ) )->get_status() );

		// A receptionist without the integrations capability gets nothing.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'GET', '/booking-manager/v1/properties/' . $p['id'] . '/ical' ) )->get_status() );
		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'DELETE', '/booking-manager/v1/ical/feeds/' . $feed_id ) )->get_status() );

		$wp_rest_server = null;
	}
}
