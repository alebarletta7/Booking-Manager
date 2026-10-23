<?php
/**
 * Availability and bookings against the real database: no double booking, departure
 * day free, holds, restrictions, changes, statuses, idempotency, optimistic locking.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Availability\HoldService;
use BookingManager\Availability\Ledger;
use BookingManager\Bookings\BookingService;
use BookingManager\Rates\RateService;
use BookingManager\Tests\TestCase;

final class BookingEngineTest extends TestCase {

	private function bookings(): BookingService {
		return $this->service( BookingService::class );
	}

	public function test_a_booking_occupies_its_room_and_is_priced(): void {
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$b = $this->book( $p['id'], $t['type']['id'], 10, 13 );
		$this->assertIsArray( $b );
		$this->assertSame( 'confirmed', $b['status'] );
		$this->assertSame( 'unpaid', $b['payment_status'] );
		$this->assertSame( 3, $b['nights'] );
		$this->assertSame( 30000, $b['total'] );
		$this->assertSame( 30000, $b['balance'] );
		$this->assertSame( $t['units'][0]['id'], $b['rooms'][0]['unit_id'] );
		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{8}$/', $b['code'] );
		$this->assertSame( 0, $this->service( Ledger::class )->free( $t['type']['id'], self::day( 10 ), self::day( 13 ) ) );
	}

	public function test_the_last_room_cannot_be_sold_twice(): void {
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$this->assertIsArray( $this->book( $p['id'], $t['type']['id'], 10, 13 ) );
		$second = $this->book( $p['id'], $t['type']['id'], 12, 14 );
		$this->assertWPError( $second );
		$this->assertSame( 'bkng_unavailable', $second->get_error_code() );
		$this->assertSame( 409, $second->get_error_data()['status'] );
	}

	public function test_departure_day_is_free_for_the_next_arrival(): void {
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$this->assertIsArray( $this->book( $p['id'], $t['type']['id'], 10, 13 ) );
		$next = $this->book( $p['id'], $t['type']['id'], 13, 15 );
		$this->assertIsArray( $next );
		$this->assertSame( $t['units'][0]['id'], $next['rooms'][0]['unit_id'] );
	}

	public function test_the_database_refuses_the_same_room_twice_even_if_counts_were_wrong(): void {
		$p      = $this->property();
		$t      = $this->room_type( $p['id'], 2 );
		$ledger = $this->service( Ledger::class );
		$this->assertTrue( $ledger->occupy( $t['units'][0]['id'], $p['id'], self::day( 5 ), self::day( 7 ), 999001 ) );
		$this->assertFalse( $ledger->occupy( $t['units'][0]['id'], $p['id'], self::day( 6 ), self::day( 8 ), 999002 ) );
		// Nothing of the refused stay was written, not even its free night.
		global $wpdb;
		$table = \BookingManager\Database\Schema::table( 'unit_nights' );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE booking_room_id = 999002" ) );
	}

	public function test_a_specific_room_already_taken_is_refused_and_nothing_is_written(): void {
		$p     = $this->property();
		$t     = $this->room_type( $p['id'], 2 );
		$first = $this->book( $p['id'], $t['type']['id'], 10, 12, array( 'unit_id' => $t['units'][1]['id'] ) );
		$this->assertSame( $t['units'][1]['id'], $first['rooms'][0]['unit_id'] );
		$again = $this->book( $p['id'], $t['type']['id'], 11, 12, array( 'unit_id' => $t['units'][1]['id'] ) );
		$this->assertWPError( $again );
		$this->assertSame( 'bkng_unit_taken', $again->get_error_code() );
		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \BookingManager\Database\Schema::table( 'bookings' ) ) );
	}

	public function test_hotel_bookings_without_room_count_against_the_type(): void {
		$p = $this->property( array( 'settings' => array( 'auto_assign_unit' => false ) ) );
		$t = $this->room_type( $p['id'], 2 );
		$a = $this->book( $p['id'], $t['type']['id'], 10, 12 );
		$b = $this->book( $p['id'], $t['type']['id'], 10, 12 );
		$this->assertNull( $a['rooms'][0]['unit_id'] );
		$this->assertNull( $b['rooms'][0]['unit_id'] );
		$this->assertWPError( $this->book( $p['id'], $t['type']['id'], 11, 12 ) );
		// Assigning later: one room each, the same room twice is refused.
		$ok = $this->bookings()->assign_unit( $a['rooms'][0]['id'], $t['units'][0]['id'], $a['version'], 0 );
		$this->assertIsArray( $ok );
		$ko = $this->bookings()->assign_unit( $b['rooms'][0]['id'], $t['units'][0]['id'], $b['version'], 0 );
		$this->assertWPError( $ko );
		$this->assertIsArray( $this->bookings()->assign_unit( $b['rooms'][0]['id'], $t['units'][1]['id'], $b['version'], 0 ) );
	}

	public function test_cancellation_frees_the_room_and_no_show_can_be_undone_only_if_free(): void {
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$a = $this->book( $p['id'], $t['type']['id'], 0, 2 );
		$c = $this->bookings()->set_status( $a['id'], $a['version'], 'no_show' );
		$this->assertSame( 'no_show', $c['status'] );
		$b = $this->book( $p['id'], $t['type']['id'], 0, 2 );
		$this->assertIsArray( $b );
		$back = $this->bookings()->set_status( $c['id'], $c['version'], 'confirmed' );
		$this->assertWPError( $back );
		$this->assertSame( 'bkng_unavailable', $back->get_error_code() );
		$cancelled = $this->bookings()->set_status( $b['id'], $b['version'], 'cancelled', array( 'reason' => 'Test' ) );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertIsArray( $this->bookings()->set_status( $c['id'], $c['version'], 'confirmed' ) );
	}

	public function test_invalid_transitions_and_early_no_show_are_refused(): void {
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$a = $this->book( $p['id'], $t['type']['id'], 5, 6 );
		$this->assertSame( 'bkng_too_early', $this->bookings()->set_status( $a['id'], $a['version'], 'no_show' )->get_error_code() );
		$this->assertSame( 'bkng_invalid_transition', $this->bookings()->set_status( $a['id'], $a['version'], 'completed' )->get_error_code() );
	}

	public function test_stale_version_is_a_conflict(): void {
		$p = $this->property();
		$t = $this->room_type( $p['id'], 2 );
		$a = $this->book( $p['id'], $t['type']['id'], 5, 6 );
		$this->assertIsArray( $this->bookings()->update_details( $a['id'], array( 'version' => $a['version'], 'internal_notes' => 'Primo' ), 0 ) );
		$late = $this->bookings()->update_details( $a['id'], array( 'version' => $a['version'], 'internal_notes' => 'Secondo' ), 0 );
		$this->assertWPError( $late );
		$this->assertSame( 409, $late->get_error_data()['status'] );
	}

	public function test_same_idempotency_key_returns_the_same_booking(): void {
		$p     = $this->property();
		$t     = $this->room_type( $p['id'], 2 );
		$first = $this->book( $p['id'], $t['type']['id'], 5, 6, array( 'idempotency_key' => 'abc-123' ) );
		$again = $this->book( $p['id'], $t['type']['id'], 5, 6, array( 'idempotency_key' => 'abc-123' ) );
		$this->assertSame( $first['id'], $again['id'] );
		$this->assertSame( 1, $this->service( Ledger::class )->free( $t['type']['id'], self::day( 5 ), self::day( 6 ) ) );
	}

	public function test_changing_dates_rechecks_availability_and_reprices(): void {
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$a = $this->book( $p['id'], $t['type']['id'], 10, 12 );
		$this->book( $p['id'], $t['type']['id'], 14, 16 );
		$room  = $a['rooms'][0];
		$input = static fn( int $to ) => array(
			'version' => $a['version'],
			'rooms'   => array(
				array(
					'id'           => $room['id'],
					'room_type_id' => $room['room_type_id'],
					'arrival'      => $room['arrival'],
					'departure'    => self::day( $to ),
					'adults'       => 2,
				),
			),
		);
		$this->assertWPError( $this->bookings()->change_stay( $a['id'], $input( 15 ), array() ) );
		$changed = $this->bookings()->change_stay( $a['id'], $input( 14 ), array() );
		$this->assertIsArray( $changed );
		$this->assertSame( 4, $changed['nights'] );
		$this->assertSame( 40000, $changed['total'] );
		$this->assertSame( $t['units'][0]['id'], $changed['rooms'][0]['unit_id'] );
	}

	public function test_a_hold_keeps_the_room_for_its_owner_only(): void {
		$p    = $this->property();
		$t    = $this->room_type( $p['id'], 1 );
		$hold = $this->service( HoldService::class )->create( $p['id'], $t['type']['id'], self::day( 20 ), self::day( 22 ), 1, 15 );
		$this->assertIsArray( $hold );
		$this->assertWPError( $this->book( $p['id'], $t['type']['id'], 20, 22 ) );
		$mine = $this->book( $p['id'], $t['type']['id'], 20, 22, array( 'hold_token' => $hold['token'] ) );
		$this->assertIsArray( $mine );
	}

	public function test_stay_rules_and_staff_override(): void {
		$p = $this->property( array( 'settings' => array( 'min_stay' => 3 ) ) );
		$t = $this->room_type( $p['id'], 1 );
		$short = $this->book( $p['id'], $t['type']['id'], 10, 11 );
		$this->assertWPError( $short );
		$this->assertArrayHasKey( 'min_stay', $short->get_error_data()['fields'] );
		$forced = $this->book(
			$p['id'],
			$t['type']['id'],
			10,
			11,
			array( 'override_restrictions' => true ),
			array(
				'channel'      => 'staff',
				'can_override' => true,
			)
		);
		$this->assertIsArray( $forced );
	}

	public function test_closures_and_out_of_service(): void {
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$this->service( RateService::class )->save_restriction( $p['id'], array( 'date_from' => self::day( 30 ), 'date_to' => self::day( 31 ), 'closed' => true ), 0 );
		$this->assertArrayHasKey( 'closed', $this->book( $p['id'], $t['type']['id'], 29, 31 )->get_error_data()['fields'] );

		$inventory = $this->service( \BookingManager\Inventory\InventoryService::class );
		$block     = $inventory->add_block( $t['units'][0]['id'], array( 'date_from' => self::day( 40 ), 'date_to' => self::day( 42 ) ), 0 );
		$this->assertIsArray( $block );
		$this->assertWPError( $this->book( $p['id'], $t['type']['id'], 41, 43 ) );
		$this->assertTrue( $inventory->remove_block( $block['id'] ) );
		$this->assertIsArray( $this->book( $p['id'], $t['type']['id'], 41, 43 ) );
	}

	public function test_price_already_sold_does_not_change_with_the_rates(): void {
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$a = $this->book( $p['id'], $t['type']['id'], 10, 12 );
		$this->service( \BookingManager\Inventory\InventoryService::class )->update_type( $t['type']['id'], array( 'base_price' => 99900 ) );
		$after = $this->bookings()->payload( (array) $this->service( \BookingManager\Bookings\BookingRepository::class )->find( $a['id'] ) );
		$this->assertSame( 20000, $after['total'] );
		$this->assertSame( 10000, $after['price_snapshot']['rooms'][0]['nightly'][0]['price'] );
	}

	public function test_licence_blocks_new_bookings_and_room_management_but_not_existing_bookings(): void {
		$p = $this->property();
		$t = $this->room_type( $p['id'], 2 );
		$a = $this->book( $p['id'], $t['type']['id'], 0, 2 );

		$this->gate->allow = false;
		$staff             = $this->book( $p['id'], $t['type']['id'], 5, 6 );
		$this->assertSame( 'bkng_license_required', $staff->get_error_code() );
		$online = $this->book( $p['id'], $t['type']['id'], 5, 6, array( 'privacy_accepted' => true ), array( 'channel' => 'online' ) );
		$this->assertSame( 'bkng_license_required', $online->get_error_code() );
		$inventory = $this->service( \BookingManager\Inventory\InventoryService::class );
		$this->assertSame( 'bkng_license_required', $inventory->create_unit( $p['id'], array( 'code' => 'X1' ) )->get_error_code() );
		$this->assertSame( 'bkng_license_required', $inventory->update_type( $t['type']['id'], array( 'name' => 'Nuovo' ) )->get_error_code() );

		// A guest already booked is never stopped: check-in and cancellation still work.
		$this->assertSame( 'in_house', $this->bookings()->set_status( $a['id'], $a['version'], 'in_house' )['status'] );
		$this->assertIsArray( $inventory->add_block( $t['units'][1]['id'], array( 'date_from' => self::day( 50 ), 'date_to' => self::day( 51 ) ), 0 ) );
	}

	public function test_online_bookings_follow_the_property_mode(): void {
		$p = $this->property( array( 'booking_mode' => 'request' ) );
		$t = $this->room_type( $p['id'], 2 );
		$missing_privacy = $this->book( $p['id'], $t['type']['id'], 5, 6, array(), array( 'channel' => 'online' ) );
		$this->assertArrayHasKey( 'privacy_accepted', $missing_privacy->get_error_data()['fields'] );
		$request = $this->book( $p['id'], $t['type']['id'], 5, 6, array( 'privacy_accepted' => true, 'internal_notes' => 'no' ), array( 'channel' => 'online' ) );
		$this->assertSame( 'pending', $request['status'] );
		$this->assertSame( 'public_form', $request['source'] );
		$this->assertNull( $request['internal_notes'] );

		$this->service( \BookingManager\Properties\PropertyService::class )->update( $p['id'], array( 'booking_mode' => 'direct' ) );
		$this->reset_services();
		$direct = $this->book( $p['id'], $t['type']['id'], 5, 6, array( 'privacy_accepted' => true ), array( 'channel' => 'online' ) );
		$this->assertSame( 'confirmed', $direct['status'] );
	}

	public function test_guest_cards_are_linked_only_with_same_email_and_surname(): void {
		$p = $this->property();
		$t = $this->room_type( $p['id'], 3 );
		$a = $this->book( $p['id'], $t['type']['id'], 5, 6 );
		$b = $this->book( $p['id'], $t['type']['id'], 7, 8 );
		$this->assertSame( $a['guest_id'], $b['guest_id'] );
		$c = $this->book(
			$p['id'],
			$t['type']['id'],
			9,
			10,
			array(
				'guest' => array(
					'first_name' => 'Anna',
					'last_name'  => 'Bianchi',
					'email'      => 'mario.rossi@example.org',
				),
			)
		);
		$this->assertNotSame( $a['guest_id'], $c['guest_id'] );
		$dupes = $this->service( \BookingManager\Guests\GuestService::class )->possible_duplicates( $c['guest_id'] );
		$this->assertSame( array( $a['guest_id'] ), array_column( $dupes, 'id' ) );
	}

	public function test_archiving_a_room_with_future_stays_is_refused(): void {
		$p         = $this->property();
		$t         = $this->room_type( $p['id'], 2 );
		$inventory = $this->service( \BookingManager\Inventory\InventoryService::class );
		$this->book( $p['id'], $t['type']['id'], 5, 6, array( 'unit_id' => $t['units'][0]['id'] ) );
		$this->assertSame( 'bkng_unit_in_use', $inventory->archive_unit( $t['units'][0]['id'] )->get_error_code() );
		$this->assertSame( 'archived', $inventory->archive_unit( $t['units'][1]['id'] )['status'] );
	}

	public function test_bnb_room_gets_its_own_hidden_type(): void {
		$p    = $this->property( array( 'kind' => 'bnb' ) );
		$unit = $this->service( \BookingManager\Inventory\InventoryService::class )->create_unit(
			$p['id'],
			array(
				'code'          => '1',
				'name'          => 'Camera Glicine',
				'max_adults'    => 2,
				'max_occupancy' => 2,
				'base_price'    => 8500,
			)
		);
		$type = $this->service( \BookingManager\Inventory\RoomTypeRepository::class )->find( $unit['room_type_id'] );
		$this->assertTrue( $type['is_hidden'] );
		$this->assertSame( 'Camera Glicine', $type['name'] );
		$this->assertSame( 8500, $type['base_price'] );
		$b = $this->book( $p['id'], $type['id'], 3, 5 );
		$this->assertSame( 17000, $b['total'] );
		$this->assertSame( $unit['id'], $b['rooms'][0]['unit_id'] );
	}
}
