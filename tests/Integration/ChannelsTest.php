<?php
/**
 * Sales channels (prepared) and guest retention against the real database: a channel's
 * reservation imported once, mapped room types, errors recorded and retried, cancellations
 * from the channel, availability pushed to a connector; old guest cards anonymized.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Bookings\BookingRepository;
use BookingManager\Channels\ChannelConnector;
use BookingManager\Channels\ChannelService;
use BookingManager\Database\Schema;
use BookingManager\Guests\GuestRepository;
use BookingManager\Guests\GuestService;
use BookingManager\Tests\TestCase;

final class ChannelsTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	public static array $pushed = array();

	public function set_up(): void {
		parent::set_up();
		self::$pushed = array();
	}

	public function tear_down(): void {
		remove_all_filters( 'bkng_channel_connectors' );
		parent::tear_down();
	}

	private function channels(): ChannelService {
		return $this->service( ChannelService::class );
	}

	/** @return array<string, mixed> */
	private static function reservation( string $id, int $from, int $to, array $extra = array() ): array {
		return $extra + array(
			'external_id' => $id,
			'modified'    => '1',
			'status'      => 'confirmed',
			'arrival'     => self::day( $from ),
			'departure'   => self::day( $to ),
			'guest'       => array( 'first_name' => 'Hans', 'last_name' => 'Müller' ),
			'rooms'       => array( array( 'external_room_type_id' => 'DBL', 'adults' => 2, 'price_total' => 31000 ) ),
		);
	}

	public function test_a_reservation_is_imported_once_without_e_mails(): void {
		$this->admin();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$this->channels()->map_room_type( $p['id'], 'test_ota', 'DBL', $t['type']['id'] );

		$first = $this->channels()->import_reservation( 'test_ota', $p['id'], self::reservation( 'R-100', 5, 7 ) );
		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );
		$this->assertSame( 'created', $first['result'] );
		$booking = (array) $this->service( BookingRepository::class )->find( (int) $first['booking_id'] );
		$this->assertSame( 'import', $booking['source'] );
		$this->assertSame( 'confirmed', $booking['status'] );
		$this->assertSame( 'R-100', $booking['external_ref'] );
		$this->assertSame( 31000, (int) $booking['total'], 'the channel\'s price' );

		$again = $this->channels()->import_reservation( 'test_ota', $p['id'], self::reservation( 'R-100', 5, 7 ) );
		$this->assertSame( 'duplicate', $again['result'] );
		$this->assertSame( $first['booking_id'], $again['booking_id'] );

		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'notifications' ) . ' WHERE booking_id = %d', $first['booking_id'] ) ), 'the channel writes to its guests' );

		// A change from the channel is shown to the staff, not applied blindly.
		$changed = $this->channels()->import_reservation( 'test_ota', $p['id'], self::reservation( 'R-100', 6, 8, array( 'modified' => '2' ) ) );
		$this->assertSame( 'unchanged', $changed['result'] );
		$this->assertSame( 'review', $this->channels()->overview( $p['id'] )['log'][0]['status'] );

		$cancel = $this->channels()->import_reservation( 'test_ota', $p['id'], self::reservation( 'R-100', 5, 7, array( 'modified' => '3', 'status' => 'cancelled' ) ) );
		$this->assertSame( 'cancelled', $cancel['result'] );
		$this->assertSame( 'cancelled', $this->service( BookingRepository::class )->find( (int) $first['booking_id'] )['status'] );
	}

	public function test_what_does_not_fit_is_recorded_and_tried_again(): void {
		$this->admin();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );

		$unmapped = $this->channels()->import_reservation( 'test_ota', $p['id'], self::reservation( 'R-200', 5, 7 ) );
		$this->assertWPError( $unmapped );
		$this->assertSame( 'bkng_unmapped_room', $unmapped->get_error_code() );
		$this->assertSame( 'error', $this->channels()->overview( $p['id'] )['log'][0]['status'] );

		$this->channels()->map_room_type( $p['id'], 'test_ota', 'DBL', $t['type']['id'] );
		$this->book( $p['id'], $t['type']['id'], 5, 7 );
		$full = $this->channels()->import_reservation( 'test_ota', $p['id'], self::reservation( 'R-200', 5, 7 ) );
		$this->assertWPError( $full, 'no room free: never an overbooking, never silent' );

		$retried = $this->channels()->import_reservation( 'test_ota', $p['id'], self::reservation( 'R-200', 8, 9 ) + array( 'modified' => '1' ) );
		$this->assertIsArray( $retried );
	}

	public function test_connectors_receive_availability_changes(): void {
		add_filter(
			'bkng_channel_connectors',
			static fn( array $list ) => array_merge(
				$list,
				array(
					new class() implements ChannelConnector {
						public function key(): string {
							return 'test_cm';
						}
						public function label(): string {
							return 'Test';
						}
						public function unavailable( array $property ): ?string {
							return null;
						}
						public function push_availability( int $property_id, array $room_type_ids, string $from, string $to ): bool|\WP_Error {
							ChannelsTest::$pushed[] = compact( 'property_id', 'room_type_ids', 'from', 'to' );
							return true;
						}
					},
				)
			)
		);
		$this->reset_services();
		$this->admin();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$this->service( ChannelService::class )->hooks();
		$this->book( $p['id'], $t['type']['id'], 5, 7 );

		$this->assertNotEmpty( self::$pushed );
		$this->assertSame( array( $t['type']['id'] ), self::$pushed[0]['room_type_ids'] );
		$this->assertSame( self::day( 5 ), self::$pushed[0]['from'] );
		$this->assertSame( 'out', $this->channels()->overview( $p['id'] )['log'][0]['direction'] );
	}

	public function test_old_guest_cards_are_anonymized_when_the_site_asks(): void {
		$this->admin();
		$p   = $this->property();
		$t   = $this->room_type( $p['id'], 2 );
		$old = $this->book( $p['id'], $t['type']['id'], 3, 5 );
		$new = $this->book( $p['id'], $t['type']['id'], 3, 5, array( 'guest' => array( 'first_name' => 'Lia', 'last_name' => 'Verdi', 'email' => 'lia@example.org' ) ) );
		global $wpdb;
		// The first guest stayed three years ago.
		$wpdb->update( Schema::table( 'bookings' ), array( 'arrival' => '2023-05-01', 'departure' => '2023-05-03', 'status' => 'completed' ), array( 'id' => $old['id'] ) );
		$wpdb->update( Schema::table( 'guests' ), array( 'created_at' => '2023-04-01 10:00:00' ), array( 'id' => $old['guest_id'] ) );
		$wpdb->update( Schema::table( 'guests' ), array( 'created_at' => '2023-04-01 10:00:00' ), array( 'id' => $new['guest_id'] ) );

		$guests = $this->service( GuestService::class );
		$this->assertSame( 0, $guests->anonymize_inactive( 0, time() ), '0 keeps everything' );
		$this->assertSame( 1, $guests->anonymize_inactive( 24, time() ) );
		$this->assertNotNull( $this->service( GuestRepository::class )->find( (int) $old['guest_id'] )['anonymized_at'] );
		$this->assertNull( $this->service( GuestRepository::class )->find( (int) $new['guest_id'] )['anonymized_at'], 'a stay still to come' );
		$this->assertSame( '', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT recipient FROM ' . Schema::table( 'notifications' ) . ' WHERE booking_id = %d', $old['id'] ) ) );
		$this->assertNull( $this->service( BookingRepository::class )->find( (int) $old['id'] )['email'] );
	}
}
