<?php
/**
 * Channex, the pure parts: webhook signature, amounts in cents, ranges, values of a
 * night, answers of the API, rooms and guest of a revision.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Unit;

use BookingManager\Channels\Channex\AriProjector;
use BookingManager\Channels\Channex\ChannexBookings;
use BookingManager\Channels\Channex\ChannexClient;
use PHPUnit\Framework\TestCase;

final class ChannexTest extends TestCase {

	private const SECRET = 'whsec_0123456789abcdef0123456789';

	private static function sign( string $ts, string $raw, string $secret = self::SECRET ): string {
		return hash_hmac( 'sha256', $ts . '.' . $raw, $secret );
	}

	public function test_the_webhook_signature_is_checked_on_the_raw_body(): void {
		$raw = '{"event":"booking","property_id":"x"}';
		$now = 1_800_000_000;
		$ts  = (string) $now;
		$this->assertTrue( ChannexBookings::verify( $raw, $ts, self::sign( $ts, $raw ), self::SECRET, $now ) );
		// Body changed by one byte, other secret, empty secret.
		$this->assertFalse( ChannexBookings::verify( $raw . ' ', $ts, self::sign( $ts, $raw ), self::SECRET, $now ) );
		$this->assertFalse( ChannexBookings::verify( $raw, $ts, self::sign( $ts, $raw, 'another_secret_0123456789' ), self::SECRET, $now ) );
		$this->assertFalse( ChannexBookings::verify( $raw, $ts, self::sign( $ts, $raw ), '', $now ) );
		// Older than 5 minutes, or from the future.
		$this->assertFalse( ChannexBookings::verify( $raw, $ts, self::sign( $ts, $raw ), self::SECRET, $now + 301 ) );
		$this->assertFalse( ChannexBookings::verify( $raw, $ts, self::sign( $ts, $raw ), self::SECRET, $now - 301 ) );
		$this->assertTrue( ChannexBookings::verify( $raw, $ts, self::sign( $ts, $raw ), self::SECRET, $now + 299 ) );
		// Key rotation: two signatures, one is ours; prefix "sha256=" accepted.
		$this->assertTrue( ChannexBookings::verify( $raw, $ts, str_repeat( 'a', 64 ) . ', sha256=' . self::sign( $ts, $raw ), self::SECRET, $now ) );
		// Timestamp in milliseconds.
		$ms = (string) ( $now * 1000 );
		$this->assertTrue( ChannexBookings::verify( $raw, $ms, self::sign( $ms, $raw ), self::SECRET, $now ) );
		$this->assertFalse( ChannexBookings::verify( $raw, 'abc', self::sign( 'abc', $raw ), self::SECRET, $now ) );
	}

	public function test_amounts_become_cents_without_floats(): void {
		$this->assertSame( 7650, ChannexBookings::cents( '76.50' ) );
		$this->assertSame( 7650, ChannexBookings::cents( '76.5' ) );
		$this->assertSame( 7600, ChannexBookings::cents( '76' ) );
		$this->assertSame( 7600, ChannexBookings::cents( 76 ) );
		$this->assertSame( 12050, ChannexBookings::cents( 120.5 ) );
		$this->assertSame( 1, ChannexBookings::cents( '0.005' ) );
		$this->assertSame( 0, ChannexBookings::cents( '0.004' ) );
		$this->assertSame( 2999999999999, ChannexBookings::cents( '29999999999.99' ) );
		$this->assertSame( -1050, ChannexBookings::cents( '-10.50' ) );
		$this->assertNull( ChannexBookings::cents( '10,50' ) );
		$this->assertNull( ChannexBookings::cents( '' ) );
		$this->assertNull( ChannexBookings::cents( null ) );
	}

	public function test_consecutive_equal_nights_become_one_range(): void {
		$nights = array(
			'2026-11-12' => array( 'availability' => 2 ),
			'2026-11-10' => array( 'availability' => 3 ),
			'2026-11-11' => array( 'availability' => 3 ),
			'2026-11-13' => array( 'availability' => 2 ),
			'2026-11-15' => array( 'availability' => 2 ),
		);
		$this->assertSame(
			array(
				array(
					'property_id'  => 'P',
					'room_type_id' => 'R',
					'date_from'    => '2026-11-10',
					'date_to'      => '2026-11-11',
					'availability' => 3,
				),
				array(
					'property_id'  => 'P',
					'room_type_id' => 'R',
					'date_from'    => '2026-11-12',
					'date_to'      => '2026-11-13',
					'availability' => 2,
				),
				// A hole in the dates breaks the range even with the same value.
				array(
					'property_id'  => 'P',
					'room_type_id' => 'R',
					'date'         => '2026-11-15',
					'availability' => 2,
				),
			),
			AriProjector::compress(
				array(
					'property_id'  => 'P',
					'room_type_id' => 'R',
				),
				$nights
			)
		);
		$this->assertSame( array(), AriProjector::compress( array(), array() ) );
		// 500 equal nights: one item.
		$many = array();
		foreach ( AriProjector::horizon( '2026-11-01' ) as $date ) {
			$many[ $date ] = array( 'availability' => 1 );
		}
		$this->assertCount( 500, $many );
		$this->assertCount( 1, AriProjector::compress( array(), $many ) );
	}

	private static function type(): array {
		return array(
			'id'                => 7,
			'base_price'        => 10000,
			'extra_adult_price' => 2000,
			'extra_child_price' => 1000,
			'base_occupancy'    => 2,
			'max_adults'        => 3,
			'max_occupancy'     => 3,
		);
	}

	private static function rule( array $r ): array {
		return $r + array(
			'date_from'           => '2026-11-01',
			'date_to'             => '2026-11-30',
			'min_stay'            => null,
			'max_stay'            => null,
			'arrival_weekdays'    => null,
			'departure_weekdays'  => null,
			'closed'              => false,
			'closed_to_arrival'   => false,
			'closed_to_departure' => false,
		);
	}

	public function test_a_night_carries_price_and_rules_as_the_booking_form_reads_them(): void {
		$settings = array(
			'min_stay' => 1,
			'max_stay' => 30,
		);
		$plan     = array(
			'id'          => 3,
			'modifier_bp' => -1000, // −10% non rimborsabile.
		);
		$prices   = array(
			array(
				'rate_plan_id'      => null,
				'date_from'         => '2026-11-01',
				'date_to'           => '2026-11-30',
				'weekdays'          => '6,7',
				'price_night'       => 15000,
				'extra_adult_price' => null,
				'extra_child_price' => null,
				'priority'          => 0,
				'id'                => 1,
			),
		);
		// 2026-11-14 is a Saturday: weekend price 150 €, −10%.
		$saturday = AriProjector::night( self::type(), $plan, $prices, array(), $settings, false, '2026-11-14' );
		$this->assertSame( 13500, $saturday['rate'] );
		$this->assertSame( 1, $saturday['min_stay_arrival'] );
		$this->assertSame( 30, $saturday['max_stay'] );
		$this->assertFalse( $saturday['stop_sell'] );
		// Weekday: base price of the type.
		$this->assertSame( 9000, AriProjector::night( self::type(), $plan, $prices, array(), $settings, false, '2026-11-11' )['rate'] );

		$rules = array(
			self::rule( array( 'min_stay' => 3 ) ),
			self::rule( array( 'arrival_weekdays' => '6' ) ),
			self::rule(
				array(
					'date_from' => '2026-11-20',
					'date_to'   => '2026-11-20',
					'closed'    => true,
				)
			),
			self::rule(
				array(
					'date_from'           => '2026-11-25',
					'date_to'             => '2026-11-25',
					'closed_to_departure' => true,
					'max_stay'            => 7,
				)
			),
		);
		$wed = AriProjector::night( self::type(), null, array(), $rules, $settings, false, '2026-11-11' );
		$this->assertSame( 3, $wed['min_stay_arrival'] );
		$this->assertTrue( $wed['closed_to_arrival'], 'arrivals only on Saturday' );
		$this->assertFalse( $wed['closed_to_departure'] );
		$sat = AriProjector::night( self::type(), null, array(), $rules, $settings, false, '2026-11-14' );
		$this->assertFalse( $sat['closed_to_arrival'] );
		$this->assertTrue( AriProjector::night( self::type(), null, array(), $rules, $settings, false, '2026-11-20' )['stop_sell'] );
		$on25 = AriProjector::night( self::type(), null, array(), $rules, $settings, false, '2026-11-25' );
		$this->assertTrue( $on25['closed_to_departure'] );
		$this->assertSame( 7, $on25['max_stay'] );
		// No upper limit: 0.
		$this->assertSame( 0, AriProjector::night( self::type(), null, array(), array(), array( 'max_stay' => 365 ), false, '2026-11-11' )['max_stay'] );

		// Per occupancy: 1, 2 (base), 3 adults (+20 €).
		$per = AriProjector::night( self::type(), null, array(), array(), $settings, true, '2026-11-11' );
		$this->assertSame(
			array(
				array(
					'occupancy' => 1,
					'rate'      => 10000,
				),
				array(
					'occupancy' => 2,
					'rate'      => 10000,
				),
				array(
					'occupancy' => 3,
					'rate'      => 12000,
				),
			),
			$per['rates']
		);
		$this->assertArrayNotHasKey( 'rate', $per );

		// Price 0: not sold (Channex wants a rate above 0).
		$free = AriProjector::night( array( 'base_price' => 0 ) + self::type(), null, array(), array(), $settings, false, '2026-11-11' );
		$this->assertArrayNotHasKey( 'rate', $free );
		$this->assertTrue( $free['stop_sell'] );
	}

	public function test_answers_of_the_api_are_read(): void {
		$ok = array(
			'data' => array(
				array(
					'id'   => '11111111-2222-3333-4444-555555555555',
					'type' => 'task',
				),
			),
			'meta' => array(
				'message'  => 'Success',
				'warnings' => array(
					array(
						'date'    => '2026-01-01',
						'warning' => 'date in past',
					),
				),
			),
		);
		$this->assertSame( '11111111-2222-3333-4444-555555555555', ChannexClient::task_id( $ok ) );
		$this->assertCount( 1, ChannexClient::warnings( $ok ) );
		$this->assertSame( array(), ChannexClient::warnings( array( 'data' => array() ) ) );
		$this->assertNull( ChannexClient::task_id( null ) );

		$this->assertStringContainsString( 'HTTP 422', ChannexClient::error_message( 422, array( 'errors' => array( 'title' => 'Validation Error', 'details' => array( 'rate' => array( 'must be greater than 0' ) ) ) ) ) );
		$this->assertStringContainsString( 'rate: must be greater than 0', ChannexClient::error_message( 422, array( 'errors' => array( 'title' => 'Validation Error', 'details' => array( 'rate' => array( 'must be greater than 0' ) ) ) ) ) );
		$this->assertStringContainsString( 'HTTP 429', ChannexClient::error_message( 429, null ) );

		$options = ChannexClient::options(
			array(
				'data' => array(
					array(
						'id'         => 'a1',
						'attributes' => array(
							'title'        => 'Double',
							'room_type_id' => 'r1',
							'secret'       => 'no',
						),
					),
					array( 'attributes' => array( 'title' => 'no id' ) ),
				),
			)
		);
		$this->assertSame(
			array(
				array(
					'id'           => 'a1',
					'title'        => 'Double',
					'room_type_id' => 'r1',
				),
			),
			$options
		);
	}

	public function test_rooms_of_a_revision_are_mapped_and_capped(): void {
		$rt  = '0b9a4c1e-2f3d-4e5f-8a7b-6c5d4e3f2a1b';
		$rp  = '1b9a4c1e-2f3d-4e5f-8a7b-6c5d4e3f2a1b';
		$par = '2b9a4c1e-2f3d-4e5f-8a7b-6c5d4e3f2a1b';
		$a   = array(
			'arrival_date'   => '2026-11-10',
			'departure_date' => '2026-11-12',
			'rooms'          => array(
				array(
					'room_type_id'  => $rt,
					'rate_plan_id'  => $rp,
					'checkin_date'  => '2026-11-10',
					'checkout_date' => '2026-11-12',
					'amount'        => '210.00',
					'occupancy'     => array(
						'adults'   => 4,
						'children' => 1,
						'infants'  => 0,
					),
				),
				array(
					// Derived rate: the parent is ours.
					'room_type_id' => $rt,
					'rate_plan_id' => '9b9a4c1e-2f3d-4e5f-8a7b-6c5d4e3f2a1b',
					'meta'         => array( 'parent_rate_plan_id' => $par ),
					'amount'       => '99.9',
					'occupancy'    => array( 'adults' => 1 ),
				),
			),
		);
		$types = array(
			5 => array(
				'name'          => 'Doppia',
				'max_adults'    => 2,
				'max_children'  => 1,
				'max_occupancy' => 3,
			),
		);
		$out   = ChannexBookings::rooms(
			$a,
			static fn( string $uuid ) => $rt === $uuid ? 5 : null,
			static fn( string $uuid ) => match ( $uuid ) {
				$rp => array(
					'room_type_id' => 5,
					'rate_plan_id' => 8,
				),
				$par => array(
					'room_type_id' => 5,
					'rate_plan_id' => 0,
				),
				default => null,
			},
			static fn( int $id ) => $types[ $id ] ?? null
		);
		$this->assertFalse( $out['unmapped'] );
		$this->assertSame(
			array(
				'room_type_id' => 5,
				'arrival'      => '2026-11-10',
				'departure'    => '2026-11-12',
				'adults'       => 2,
				'children'     => 1,
				'rate_plan_id' => 8,
				'price_total'  => 21000,
			),
			$out['lines'][0]
		);
		$this->assertCount( 1, $out['notes'], 'guests above capacity are written in the notes' );
		$this->assertArrayNotHasKey( 'rate_plan_id', $out['lines'][1], 'base price (no plan)' );
		$this->assertSame( 9990, $out['lines'][1]['price_total'] );
		$this->assertSame( '2026-11-10', $out['lines'][1]['arrival'], 'dates of the reservation when the room has none' );

		$unmapped = ChannexBookings::rooms(
			array( 'rooms' => array( array( 'room_type_id' => '3b9a4c1e-2f3d-4e5f-8a7b-6c5d4e3f2a1b' ) ) ),
			static fn() => null,
			static fn() => null,
			static fn() => null
		);
		$this->assertTrue( $unmapped['unmapped'] );
		$this->assertTrue( ChannexBookings::rooms( array(), static fn() => null, static fn() => null, static fn() => null )['unmapped'] );
	}

	public function test_the_guest_keeps_only_valid_contacts(): void {
		$g = ChannexBookings::guest(
			array(
				'name'     => 'Hans',
				'surname'  => 'Müller',
				'mail'     => 'hans.123@guest.booking.com',
				'phone'    => '+49 (30) 1234-567',
				'country'  => 'de',
				'language' => 'de-DE',
			)
		);
		$this->assertSame( 'Hans', $g['first_name'] );
		$this->assertSame( 'Müller', $g['last_name'] );
		$this->assertSame( 'hans.123@guest.booking.com', $g['email'] );
		$this->assertSame( '+49 30 1234567', $g['phone'] );
		$this->assertSame( 'DE', $g['country'] );
		$this->assertSame( 'de', $g['language'] );

		$bad = ChannexBookings::guest(
			array(
				'name'  => 'Solo',
				'mail'  => 'not-an-email',
				'phone' => '12',
			)
		);
		$this->assertSame( 'Solo', $bad['last_name'], 'a single name becomes the surname' );
		$this->assertNull( $bad['email'] );
		$this->assertNull( $bad['phone'] );
		$this->assertSame( 'Ospite', ChannexBookings::guest( array() )['last_name'] );
		$this->assertSame( 'Booking.com', ChannexBookings::ota_label( array( 'ota_name' => 'BookingCom' ) ) );
		$this->assertSame( 'Channex', ChannexBookings::ota_label( array() ) );
	}
}
