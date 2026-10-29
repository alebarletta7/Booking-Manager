<?php
/**
 * Front desk and housekeeping against the real database: QR and typed codes, check-in
 * and check-out per room, rooms made dirty by a check-out, rooms not ready refused, the
 * board of the day and the permissions of the REST routes.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Access\PropertyScope;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingService;
use BookingManager\Checkin\CheckinService;
use BookingManager\Checkin\QrToken;
use BookingManager\Housekeeping\HousekeepingService;
use BookingManager\Housekeeping\OpStatus;
use BookingManager\Inventory\UnitRepository;
use BookingManager\Properties\PropertyService;
use BookingManager\Rest\RestController;
use BookingManager\Tests\TestCase;

final class CheckinTest extends TestCase {

	private function desk(): CheckinService {
		return $this->service( CheckinService::class );
	}

	private function booking( int $id ): array {
		return (array) $this->service( BookingRepository::class )->find( $id );
	}

	/** @return array<int, array<string, mixed>> */
	private function rooms( int $booking_id ): array {
		return $this->service( BookingRepository::class )->rooms( $booking_id, true );
	}

	private function qr_of( int $booking_id ): string {
		return (string) $this->desk()->qr_for( $this->booking( $booking_id ) )['qr'];
	}

	private function unit_status( int $unit_id ): string {
		return (string) $this->service( UnitRepository::class )->find( $unit_id )['op_status'];
	}

	private function set_settings( int $property_id, array $settings ): void {
		$this->assertIsArray( $this->service( PropertyService::class )->update_settings( $property_id, $settings ) );
		$this->reset_services();
	}

	public function test_the_qr_is_signed_and_reveals_nothing(): void {
		$qr      = new QrToken( str_repeat( 'k', 64 ) );
		$payload = $qr->payload( 'ABCDEFGHJKMNPQRS' );
		$this->assertMatchesRegularExpression( '/^BK1:ABCDEFGHJKMNPQRS\.[a-f0-9]{20}$/', $payload );
		$this->assertSame( 'ABCDEFGHJKMNPQRS', $qr->verify( $payload ) );
		$this->assertSame( 'ABCDEFGHJKMNPQRS', $qr->verify( "  {$payload}\n" ) );
		$this->assertNull( $qr->verify( substr( $payload, 0, -1 ) . ( str_ends_with( $payload, 'a' ) ? 'b' : 'a' ) ), 'a forged signature' );
		$this->assertNull( ( new QrToken( str_repeat( 'x', 64 ) ) )->verify( $payload ), 'another site\'s key' );
		$this->assertNull( $qr->verify( 'RM1:ABCDEFGHJKMNPQRS.' . substr( $payload, -20 ) ) );
		$this->assertTrue( QrToken::looks_like( 'bk1:xyz' ) );
	}

	public function test_scan_and_check_in_once_even_from_two_phones(): void {
		$user = $this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 1 );
		$b    = $this->book( $p['id'], $t['type']['id'], 0, 2 );

		$scan = $this->desk()->scan( $p['id'], $this->qr_of( $b['id'] ), $user );
		$this->assertSame( CheckinService::READY, $scan['result'] );
		$this->assertTrue( $scan['can_checkin'] );
		$this->assertSame( $b['code'], $scan['booking']['code'] );
		$this->assertArrayNotHasKey( 'balance', $scan['booking'], 'money only for who may see payments' );

		$in = $this->desk()->check_in( $p['id'], $b['id'], $user, array( 'method' => 'qr' ) );
		$this->assertSame( CheckinService::OK, $in['result'] );
		$this->assertSame( 'in_house', $this->booking( $b['id'] )['status'] );
		$this->assertSame( 'in_house', $this->rooms( $b['id'] )[0]['stay_status'] );
		$this->assertNotNull( $this->rooms( $b['id'] )[0]['checked_in_at'] );

		// The second phone, with the version it read before.
		$again = $this->desk()->check_in( $p['id'], $b['id'], $user, array( 'version' => $b['version'] ) );
		$this->assertSame( CheckinService::ALREADY, $again['result'] );
		$this->assertStringContainsString( 'già registrato', $again['message'] );
		$this->assertTrue( $again['can_checkout'] );

		global $wpdb;
		$ok = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \BookingManager\Database\Schema::table( 'checkins' ) . " WHERE booking_id = %d AND kind = 'in' AND result = 'ok'", $b['id'] ) );
		$this->assertSame( 1, $ok );

		// Typed code works too (spaces and lower case are fine).
		$typed = $this->desk()->scan( $p['id'], strtolower( substr( $b['code'], 0, 4 ) . ' ' . substr( $b['code'], 4 ) ), $user );
		$this->assertSame( CheckinService::ALREADY, $typed['result'] );
	}

	public function test_bad_codes_other_days_and_other_properties(): void {
		$user  = $this->admin();
		$p     = $this->property();
		$other = $this->property( array( 'name' => 'Altra' ) );
		$t     = $this->room_type( $p['id'], 2 );
		$later = $this->book( $p['id'], $t['type']['id'], 3, 5 );

		$this->assertSame( CheckinService::INVALID, $this->desk()->scan( $p['id'], 'BK1:ABCDEFGHJKMNPQRS.0123456789abcdef0123', $user )['result'] );
		$this->assertSame( CheckinService::INVALID, $this->desk()->scan( $p['id'], 'ZZZZZZZZ', $user )['result'] );

		$early = $this->desk()->scan( $p['id'], $this->qr_of( $later['id'] ), $user );
		$this->assertSame( CheckinService::TOO_EARLY, $early['result'] );
		$this->assertFalse( $early['can_checkin'] );
		$this->assertSame( CheckinService::TOO_EARLY, $this->desk()->check_in( $p['id'], $later['id'], $user )['result'] );
		$this->assertSame( 'confirmed', $this->booking( $later['id'] )['status'] );

		$elsewhere = $this->desk()->scan( $other['id'], $this->qr_of( $later['id'] ), $user );
		$this->assertSame( CheckinService::WRONG_PROPERTY, $elsewhere['result'] );
		$this->assertNull( $elsewhere['booking'], 'nothing about another property\'s booking' );
		$this->assertSame( CheckinService::INVALID, $this->desk()->check_in( $other['id'], $later['id'], $user )['result'] );

		// A regenerated QR replaces the old one at once.
		$old = $this->qr_of( $later['id'] );
		$this->assertIsArray( $this->desk()->regenerate_qr( $later['id'], $user ) );
		$this->assertSame( CheckinService::INVALID, $this->desk()->scan( $p['id'], $old, $user )['result'] );
		$this->assertSame( CheckinService::TOO_EARLY, $this->desk()->scan( $p['id'], $this->qr_of( $later['id'] ), $user )['result'] );

		$pending = $this->book( $p['id'], $t['type']['id'], 0, 1, array( 'status' => 'pending' ) );
		$this->assertSame( CheckinService::PENDING, $this->desk()->scan( $p['id'], $pending['code'], $user )['result'] );
	}

	public function test_check_out_completes_the_stay_and_the_room_needs_cleaning(): void {
		$user = $this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 1 );
		$unit = $t['units'][0]['id'];
		$b    = $this->book( $p['id'], $t['type']['id'], 0, 2 );
		$this->assertSame( OpStatus::READY, $this->unit_status( $unit ) );

		$this->assertSame( CheckinService::OK, $this->desk()->check_in( $p['id'], $b['id'], $user )['result'] );
		$out = $this->desk()->check_out( $p['id'], $b['id'], $user, array( 'can_view_payments' => true ) );
		$this->assertSame( CheckinService::OK, $out['result'] );
		$this->assertSame( (int) $this->booking( $b['id'] )['balance'], $out['booking']['balance'], 'the balance is shown at check-out' );
		$this->assertSame( 'completed', $this->booking( $b['id'] )['status'] );
		$this->assertSame( 'departed', $this->rooms( $b['id'] )[0]['stay_status'] );
		$this->assertSame( OpStatus::DIRTY, $this->unit_status( $unit ) );

		$board = $this->service( HousekeepingService::class )->board( $p['id'] );
		$this->assertSame( 1, $board['counts']['dirty'] );
		$this->assertArrayNotHasKey( 'name', $board['rooms'][0]['today'] );

		global $wpdb;
		$timing = $wpdb->get_var( $wpdb->prepare( 'SELECT timing FROM ' . \BookingManager\Database\Schema::table( 'checkins' ) . " WHERE booking_id = %d AND kind = 'out'", $b['id'] ) );
		$this->assertSame( 'early', $timing );
		$this->assertSame( CheckinService::COMPLETED, $this->desk()->check_out( $p['id'], $b['id'], $user )['result'] );
	}

	public function test_a_room_not_cleaned_is_refused_when_the_property_asks_so(): void {
		$user = $this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 1 );
		$unit = (int) $t['units'][0]['id'];
		$b    = $this->book( $p['id'], $t['type']['id'], 0, 2 );
		$hk   = $this->service( HousekeepingService::class );
		$this->assertIsArray( $hk->set_status( $unit, OpStatus::READY, OpStatus::DIRTY, null, $user ) );

		// The property asks for clean rooms at check-in.
		$this->set_settings( $p['id'], array( 'block_dirty_checkin' => true ) );
		$refused = $this->desk()->check_in( $p['id'], $b['id'], $user );
		$this->assertWPError( $refused );
		$this->assertSame( 'bkng_room_not_ready', $refused->get_error_code() );
		$this->assertSame( 'confirmed', $this->booking( $b['id'] )['status'] );

		// The status route checks it as well.
		$this->assertWPError( $this->service( BookingService::class )->set_status( $b['id'], $b['version'], 'in_house', array( 'user_id' => $user ) ) );

		$this->assertSame( CheckinService::OK, $this->desk()->check_in( $p['id'], $b['id'], $user, array( 'ignore_dirty' => true ) )['result'] );
	}

	public function test_rooms_arrive_and_leave_separately(): void {
		$user = $this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 2 );
		$b    = $this->service( BookingService::class )->create(
			array(
				'property_id' => $p['id'],
				'arrival'     => self::day( 0 ),
				'departure'   => self::day( 2 ),
				'rooms'       => array(
					array( 'room_type_id' => $t['type']['id'], 'adults' => 2 ),
					array( 'room_type_id' => $t['type']['id'], 'adults' => 1 ),
				),
				'guest'       => array( 'first_name' => 'Anna', 'last_name' => 'Bianchi' ),
			),
			array( 'channel' => 'staff' )
		);
		$this->assertIsArray( $b );
		$rooms = $this->rooms( $b['id'] );

		$this->assertSame( CheckinService::OK, $this->desk()->check_in( $p['id'], $b['id'], $user, array( 'room_ids' => array( $rooms[0]['id'] ) ) )['result'] );
		$this->assertSame( array( 'in_house', 'expected' ), array_column( $this->rooms( $b['id'] ), 'stay_status' ) );
		$this->assertSame( CheckinService::READY, $this->desk()->scan( $p['id'], $this->qr_of( $b['id'] ), $user )['result'], 'the second room can still check in' );

		$this->assertSame( CheckinService::OK, $this->desk()->check_in( $p['id'], $b['id'], $user )['result'] );
		$this->assertSame( array( 'in_house', 'in_house' ), array_column( $this->rooms( $b['id'] ), 'stay_status' ) );

		$this->assertSame( CheckinService::OK, $this->desk()->check_out( $p['id'], $b['id'], $user, array( 'room_ids' => array( $rooms[1]['id'] ) ) )['result'] );
		$this->assertSame( 'in_house', $this->booking( $b['id'] )['status'], 'one room still in house' );
		$this->assertSame( OpStatus::DIRTY, $this->unit_status( (int) $rooms[1]['unit_id'] ) );
		$this->assertSame( OpStatus::READY, $this->unit_status( (int) $rooms[0]['unit_id'] ) );

		$overview = $this->desk()->overview( $p['id'] );
		$this->assertSame( 1, $overview['counts']['in_house'] );
		$row = $overview['in_house'][0];
		$this->assertSame( $t['type']['name'], $row['room_type'], 'the desk list says which room type' );
		$this->assertGreaterThan( 0, $row['nights'] );
		$this->assertArrayNotHasKey( 'balance', $row, 'amounts only for who sees payments' );
		$this->assertArrayHasKey( 'balance_label', $this->desk()->overview( $p['id'], true )['in_house'][0] );

		$this->assertSame( CheckinService::OK, $this->desk()->check_out( $p['id'], $b['id'], $user )['result'] );
		$this->assertSame( 'completed', $this->booking( $b['id'] )['status'] );
	}

	public function test_undoing_a_check_in_sets_the_rooms_back(): void {
		$user = $this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 1 );
		$b    = $this->book( $p['id'], $t['type']['id'], 0, 2 );
		$this->desk()->check_in( $p['id'], $b['id'], $user );
		$fresh = $this->booking( $b['id'] );
		$this->assertIsArray( $this->service( BookingService::class )->set_status( $b['id'], (int) $fresh['version'], 'confirmed', array( 'user_id' => $user ) ) );
		$room = $this->rooms( $b['id'] )[0];
		$this->assertSame( 'expected', $room['stay_status'] );
		$this->assertNull( $room['checked_in_at'] );
	}

	public function test_housekeeping_changes_only_what_the_operator_saw(): void {
		$user = $this->admin();
		$p    = $this->property();
		$unit = (int) $this->room_type( $p['id'], 1 )['units'][0]['id'];
		$hk   = $this->service( HousekeepingService::class );

		$this->assertSame( OpStatus::CLEANING, $hk->set_status( $unit, OpStatus::READY, OpStatus::CLEANING, 'Piano 1', $user )['op_status'] );
		$stale = $hk->set_status( $unit, OpStatus::READY, OpStatus::MAINTENANCE, null, $user );
		$this->assertWPError( $stale );
		$this->assertSame( 409, $stale->get_error_data()['status'] );
		$this->assertSame( OpStatus::CLEANING, $stale->get_error_data()['current'] );
		$this->assertWPError( $hk->set_status( $unit, OpStatus::CLEANING, 'sparkling', null, $user ) );

		$history = $hk->history( $unit );
		$this->assertCount( 1, $history );
		$this->assertSame( 'Piano 1', $history[0]['note'] );
	}

	public function test_rest_routes_follow_the_roles(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		remove_all_filters( 'rest_dispatch_request' );
		remove_all_filters( 'rest_post_dispatch' );
		$call = static function ( string $method, string $path, array $body = array() ): \WP_REST_Response {
			global $wp_rest_server;
			$request = new \WP_REST_Request( $method, '/' . RestController::REST_NAMESPACE . $path );
			if ( $body ) {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( $body ) );
			}
			return rest_ensure_response( $wp_rest_server->dispatch( $request ) );
		};

		$this->admin();
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 1 );
		$unit = (int) $t['units'][0]['id'];
		$b    = $this->book( $p['id'], $t['type']['id'], 0, 2 );

		$hk_user = self::factory()->user->create( array( 'role' => 'bkng_housekeeping' ) );
		$this->service( PropertyScope::class )->assign( $hk_user, $p['id'] );
		wp_set_current_user( $hk_user );
		$board = $call( 'GET', '/properties/' . $p['id'] . '/housekeeping' );
		$this->assertSame( 200, $board->get_status() );
		$this->assertStringNotContainsString( 'Rossi', (string) wp_json_encode( $board->get_data() ) );
		$this->assertSame( 200, $call( 'POST', '/units/' . $unit . '/op-status', array( 'from' => 'ready', 'to' => 'cleaning' ) )->get_status() );
		$this->assertSame( 403, $call( 'POST', '/properties/' . $p['id'] . '/checkin', array( 'booking_id' => $b['id'] ) )->get_status() );
		$this->assertSame( 403, $call( 'GET', '/properties/' . $p['id'] . '/frontdesk' )->get_status() );

		$desk_user = self::factory()->user->create( array( 'role' => 'bkng_front_desk' ) );
		$this->service( PropertyScope::class )->assign( $desk_user, $p['id'] );
		wp_set_current_user( $desk_user );
		$scan = $call( 'POST', '/properties/' . $p['id'] . '/checkin/scan', array( 'text' => $b['code'] ) );
		$this->assertSame( 200, $scan->get_status() );
		$this->assertSame( 'ready', $scan->get_data()['result'] );
		$this->assertArrayHasKey( 'balance', $scan->get_data()['booking'], 'the front desk sees the balance' );
		// Front desk cannot give a room in cleaning by forcing it: the flag needs a manager.
		$this->set_settings( $p['id'], array( 'block_dirty_checkin' => true ) );
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		remove_all_filters( 'rest_dispatch_request' );
		remove_all_filters( 'rest_post_dispatch' );
		$forced = $call( 'POST', '/properties/' . $p['id'] . '/checkin', array( 'booking_id' => $b['id'], 'ignore_dirty' => true ) );
		$this->assertSame( 409, $forced->get_status() );
		$this->assertSame( 200, $call( 'GET', '/bookings/' . $b['id'] . '/qr' )->get_status() );
		$this->assertSame( 200, $call( 'GET', '/properties/' . $p['id'] . '/frontdesk' )->get_status() );

		$wp_rest_server = null;
	}
}
