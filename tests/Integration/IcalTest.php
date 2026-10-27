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

	/** @var string|int calendar served for URL: text, or an HTTP status */
	private $served = '';

	public function set_up(): void {
		parent::set_up();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( 0 !== strpos( (string) $url, 'https://calendar.portal.example/' ) ) {
					return $pre;
				}
				if ( is_int( $this->served ) ) {
					return 0 === $this->served
						? new \WP_Error( 'http_request_failed', 'cURL error 28: timeout' )
						: array(
							'headers'  => array(),
							'body'     => 'error',
							'response' => array(
								'code'    => $this->served,
								'message' => 'Error',
							),
							'cookies'  => array(),
							'filename' => null,
						);
				}
				return array(
					'headers'  => array( 'content-type' => 'text/calendar' ),
					'body'     => $this->served,
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
