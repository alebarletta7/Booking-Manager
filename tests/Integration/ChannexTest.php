<?php
/**
 * Channex against the real database, with Channex's API simulated (pre_http_request):
 * changes queued and sent in one call, spacing and pause after 429, nights changed while
 * sending stay queued, full sync in two calls, reconciliation; reservations from the feed
 * (new, modified, cancelled) saved once and acknowledged after saving, overbooking
 * accepted, unmapped rooms kept and imported again, no ack when saving fails; the signed
 * webhook.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Availability\HoldService;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Channels\Channex\ChannexAlerts;
use BookingManager\Channels\Channex\ChannexBookings;
use BookingManager\Channels\Channex\ChannexLog;
use BookingManager\Channels\Channex\ChannexMap;
use BookingManager\Channels\Channex\ChannexQueue;
use BookingManager\Channels\Channex\ChannexRevisions;
use BookingManager\Channels\Channex\ChannexSettings;
use BookingManager\Channels\Channex\ChannexSync;
use BookingManager\Database\Schema;
use BookingManager\Inventory\InventoryService;
use BookingManager\Rates\RateService;
use BookingManager\Tests\TestCase;

final class ChannexTest extends TestCase {

	private const P_UUID   = 'aaaaaaaa-0000-4000-8000-000000000001';
	private const TWIN     = 'aaaaaaaa-0000-4000-8000-000000000011';
	private const DOUBLE   = 'aaaaaaaa-0000-4000-8000-000000000012';
	private const TWIN_BAR = 'aaaaaaaa-0000-4000-8000-000000000021';
	private const DBL_BAR  = 'aaaaaaaa-0000-4000-8000-000000000022';
	private const SECRET   = 'whsec_test_0123456789abcdef';

	/** @var array<int, array{method: string, path: string, query: array<string, mixed>, body: mixed}> */
	private array $calls = array();

	/** @var callable(string, string, array, mixed): array{0: int, 1: mixed} */
	private $responder;

	private array $p;
	private array $twin;
	private array $double;
	private int $plan;

	public function set_up(): void {
		parent::set_up();
		delete_option( ChannexSettings::OPTION );
		delete_option( ChannexAlerts::OPTION );
		$this->calls     = array();
		$this->responder = static fn() => array( 200, array( 'data' => array( array( 'id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'type' => 'task' ) ), 'meta' => array( 'message' => 'Success' ) ) );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'pre_wp_mail', '__return_true' );

		$this->admin();
		$this->p      = $this->property();
		$this->twin   = $this->room_type( $this->p['id'], 2, array( 'name' => 'Twin' ) );
		$this->double = $this->room_type( $this->p['id'], 1, array( 'name' => 'Double' ) );
		$plan         = $this->service( RateService::class )->create_plan( $this->p['id'], array( 'name' => 'BAR' ) );
		$this->plan   = (int) $plan['id'];

		$settings = $this->service( ChannexSettings::class );
		$settings->save_credentials(
			array(
				'api_key'        => 'test_api_key_0123456789',
				'webhook_secret' => self::SECRET,
			)
		);
		$settings->save( array( 'active' => true ) );
		$this->service( ChannexMap::class )->save(
			$this->p['id'],
			self::P_UUID,
			array(
				$this->twin['type']['id']   => self::TWIN,
				$this->double['type']['id'] => self::DOUBLE,
			),
			array(
				array(
					'room_type_id' => $this->twin['type']['id'],
					'rate_plan_id' => $this->plan,
					'channex_id'   => self::TWIN_BAR,
				),
				array(
					'room_type_id' => $this->double['type']['id'],
					'rate_plan_id' => $this->plan,
					'channex_id'   => self::DBL_BAR,
				),
			)
		);
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'pre_wp_mail', '__return_true' );
		parent::tear_down();
	}

	/** Channex's API, simulated. */
	public function fake_http( $pre, array $args, string $url ) {
		if ( ! str_starts_with( $url, ChannexSettings::STAGING_URL ) ) {
			return $pre;
		}
		$this->assertSame( 'test_api_key_0123456789', $args['headers']['user-api-key'] );
		$parts = wp_parse_url( substr( $url, strlen( ChannexSettings::STAGING_URL ) ) );
		$query = array();
		wp_parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$call          = array(
			'method' => (string) $args['method'],
			'path'   => trim( (string) $parts['path'], '/' ),
			'query'  => $query,
			'body'   => isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null,
		);
		$this->calls[] = $call;
		[ $status, $body ] = ( $this->responder )( $call['method'], $call['path'], $call['query'], $call['body'] );
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function sync(): ChannexSync {
		return $this->service( ChannexSync::class );
	}

	private function inbound(): ChannexBookings {
		return $this->service( ChannexBookings::class );
	}

	/** @return array<int, array<string, mixed>> */
	private function calls_to( string $method, string $path ): array {
		return array_values( array_filter( $this->calls, static fn( array $c ) => $c['method'] === $method && $c['path'] === $path ) );
	}

	private function dirty( string $kind ): int {
		return $this->service( ChannexQueue::class )->counts( $this->p['id'] )[ $kind ];
	}

	/** Spacing between calls is real time: tests move the last call back. */
	private function rewind(): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'cx_state' ),
			array(
				'last_avail_call_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'last_restr_call_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'paused_until'       => null,
			),
			array( 'property_id' => $this->p['id'] )
		);
	}

	public function test_changes_are_queued_and_sent_as_deltas_in_one_call(): void {
		$this->assertIsArray( $this->book( $this->p['id'], $this->twin['type']['id'], 10, 13 ) );
		$this->assertSame( 3, $this->dirty( ChannexQueue::AVAILABILITY ), 'three nights of the Twin' );
		// Out of service: the Double on other nights.
		$block = $this->service( InventoryService::class )->add_block( $this->double['units'][0]['id'], array( 'date_from' => self::day( 20 ), 'date_to' => self::day( 22 ) ), 0 );
		$this->assertIsArray( $block );
		$this->assertSame( 5, $this->dirty( ChannexQueue::AVAILABILITY ) );
		// A weekend price for the Twin on a week: rates of the Twin.
		$price = $this->service( RateService::class )->save_price(
			$this->p['id'],
			array(
				'room_type_id' => $this->twin['type']['id'],
				'date_from'    => self::day( 30 ),
				'date_to'      => self::day( 36 ),
				'price_night'  => 15000,
				'weekdays'     => '6,7',
			)
		);
		$this->assertIsArray( $price );
		$this->assertSame( 7, $this->dirty( ChannexQueue::RESTRICTIONS ) );

		$this->sync()->push_all( $this->p['id'] );

		$avail = $this->calls_to( 'POST', 'availability' );
		$this->assertCount( 1, $avail, 'all availability changes in ONE call' );
		$values = $avail[0]['body']['values'];
		$this->assertCount( 2, $values, 'consecutive nights with the same value become one range' );
		$twin = array_values( array_filter( $values, static fn( $v ) => self::TWIN === $v['room_type_id'] ) )[0];
		$this->assertSame(
			array(
				'property_id'  => self::P_UUID,
				'room_type_id' => self::TWIN,
				'date_from'    => self::day( 10 ),
				'date_to'      => self::day( 12 ),
				'availability' => 1,
			),
			$twin
		);
		$dbl = array_values( array_filter( $values, static fn( $v ) => self::DOUBLE === $v['room_type_id'] ) )[0];
		$this->assertSame( 0, $dbl['availability'] );

		$restr = $this->calls_to( 'POST', 'restrictions' );
		$this->assertCount( 1, $restr );
		foreach ( $restr[0]['body']['values'] as $value ) {
			$this->assertSame( self::TWIN_BAR, $value['rate_plan_id'] );
			$this->assertIsInt( $value['rate'], 'integer cents' );
			$this->assertGreaterThanOrEqual( self::day( 30 ), $value['date'] ?? $value['date_from'] );
		}
		$rates = array_map( static fn( $v ) => $v['rate'], $restr[0]['body']['values'] );
		$this->assertContains( 15000, $rates );
		$this->assertContains( 10000, $rates );

		$this->assertSame( 0, $this->dirty( ChannexQueue::AVAILABILITY ) );
		$this->assertSame( 0, $this->dirty( ChannexQueue::RESTRICTIONS ) );
		$tasks = $this->service( ChannexLog::class )->tasks( $this->p['id'] );
		$this->assertSame( 'bbbbbbbb-0000-4000-8000-000000000001', $tasks[0]['task_id'], 'task id kept for the certification' );

		// Nothing new: no call.
		$this->rewind();
		$this->calls = array();
		$this->sync()->push_all( $this->p['id'] );
		$this->assertSame( array(), $this->calls );
	}

	public function test_calls_are_spaced_and_paused_after_429(): void {
		$this->book( $this->p['id'], $this->twin['type']['id'], 5, 6 );
		$this->responder = static fn() => array( 429, array( 'errors' => array( 'title' => 'Too Many Requests' ) ) );
		$this->assertSame( 'error', $this->sync()->push( $this->p['id'], ChannexQueue::AVAILABILITY ) );
		$this->assertSame( 1, $this->dirty( ChannexQueue::AVAILABILITY ), 'the night stays queued' );
		$state = $this->service( ChannexLog::class )->state( $this->p['id'] );
		$this->assertSame( 1, $state['consecutive_failures'] );
		$this->assertGreaterThanOrEqual( time() + 55, strtotime( $state['paused_until'] . ' UTC' ) );

		$this->responder = static fn() => array( 200, array( 'data' => array(), 'meta' => array() ) );
		$this->assertSame( 'paused', $this->sync()->push( $this->p['id'], ChannexQueue::AVAILABILITY ) );
		$this->assertCount( 1, $this->calls, 'no call while paused' );

		$this->rewind();
		// Just sent: the next call waits 6 seconds (10 a minute).
		global $wpdb;
		$wpdb->update( Schema::table( 'cx_state' ), array( 'last_avail_call_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'property_id' => $this->p['id'] ) );
		$this->assertSame( 'wait', $this->sync()->push( $this->p['id'], ChannexQueue::AVAILABILITY ) );

		$this->rewind();
		$this->assertSame( 'sent', $this->sync()->push( $this->p['id'], ChannexQueue::AVAILABILITY ) );
		$this->assertSame( 0, $this->service( ChannexLog::class )->state( $this->p['id'] )['consecutive_failures'] );
		$this->assertSame( 0, $this->dirty( ChannexQueue::AVAILABILITY ) );
	}

	public function test_a_401_suspends_the_sync_and_warns(): void {
		$this->book( $this->p['id'], $this->twin['type']['id'], 5, 6 );
		$this->responder = static fn() => array( 401, array( 'errors' => array( 'title' => 'Unauthorized' ) ) );
		$this->sync()->push( $this->p['id'], ChannexQueue::AVAILABILITY );
		$settings = $this->service( ChannexSettings::class );
		$this->assertFalse( $settings->active() );
		$this->assertNotSame( '', $settings->config()['suspended'] );
		$this->assertSame( 'unauthorized', $this->service( ChannexAlerts::class )->all()[0]['code'] );
		// Saving the key again restarts it.
		$this->service( \BookingManager\Channels\Channex\ChannexAdmin::class )->save_settings( array( 'api_key' => 'test_api_key_0123456789' ) );
		$this->assertTrue( $settings->active() );
	}

	public function test_a_night_changed_while_sending_stays_queued(): void {
		$this->book( $this->p['id'], $this->twin['type']['id'], 5, 6 );
		$queue           = $this->service( ChannexQueue::class );
		$p               = $this->p['id'];
		$type            = $this->twin['type']['id'];
		$this->responder = static function ( $method, $path ) use ( $queue, $p, $type ) {
			if ( 'availability' === $path ) {
				// Another booking arrives during the call.
				$queue->mark( $p, ChannexQueue::AVAILABILITY, $type, 0, array( self::day( 5 ) ) );
			}
			return array( 200, array( 'data' => array(), 'meta' => array() ) );
		};
		$this->sync()->push( $p, ChannexQueue::AVAILABILITY );
		$this->assertSame( 1, $this->dirty( ChannexQueue::AVAILABILITY ), 'dirty_seq moved: sent again next time' );
	}

	public function test_a_full_sync_is_two_calls_of_500_days(): void {
		$this->book( $this->p['id'], $this->twin['type']['id'], 10, 12 );
		$this->assertTrue( $this->sync()->full_sync( $this->p['id'] ) );
		$this->assertCount( 2, $this->calls );
		$this->assertSame( 'availability', $this->calls[0]['path'] );
		$this->assertSame( 'restrictions', $this->calls[1]['path'] );
		$twin = array_values( array_filter( $this->calls[0]['body']['values'], static fn( $v ) => self::TWIN === $v['room_type_id'] ) );
		$this->assertCount( 3, $twin, 'before, during and after the booking' );
		$this->assertSame( self::day( 0 ), $twin[0]['date_from'] );
		$this->assertSame( self::day( 499 ), $twin[2]['date_to'] );
		$this->assertSame( 0, $this->dirty( ChannexQueue::AVAILABILITY ), 'what was queued went with the full sync' );
		$this->assertNotNull( $this->service( ChannexLog::class )->state( $this->p['id'] )['last_full_sync_at'] );
		$kinds = array_column( $this->service( ChannexLog::class )->tasks( $this->p['id'] ), 'kind' );
		$this->assertSame( array( 'full_restrictions', 'full_availability' ), $kinds );
	}

	public function test_the_reconciliation_queues_what_differs(): void {
		$this->responder = function ( $method, $path, $query ) {
			$this->assertSame( self::P_UUID, $query['filter']['property_id'] );
			return array(
				200,
				array(
					'data' => array(
						self::TWIN   => array_fill_keys( \BookingManager\Channels\Channex\AriProjector::horizon( self::day( 0 ) ), 2 ),
						self::DOUBLE => array( self::day( 3 ) => 0 ) + array_fill_keys( \BookingManager\Channels\Channex\AriProjector::horizon( self::day( 0 ) ), 1 ),
					),
				),
			);
		};
		$this->assertSame( 1, $this->sync()->reconcile( $this->p['id'] ) );
		$this->assertSame( 1, $this->dirty( ChannexQueue::AVAILABILITY ) );
	}

	public function test_holds_of_the_public_form_are_sent_and_given_back(): void {
		$hold = $this->service( HoldService::class )->create( $this->p['id'], $this->double['type']['id'], self::day( 40 ), self::day( 42 ), 1, 15 );
		$this->assertIsArray( $hold );
		$this->assertSame( 2, $this->dirty( ChannexQueue::AVAILABILITY ) );
		$this->sync()->push( $this->p['id'], ChannexQueue::AVAILABILITY );
		$this->assertSame( 0, $this->calls[0]['body']['values'][0]['availability'], 'Booking.com sees the room as sold during the payment' );

		global $wpdb;
		$wpdb->update( Schema::table( 'holds' ), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $hold['id'] ) );
		$this->rewind();
		$this->sync()->tick();
		$last = end( $this->calls );
		$this->assertSame( 1, $last['body']['values'][0]['availability'], 'expired hold: room back on sale' );
	}

	/* ---------------------------------------------------------------- reservations */

	/** @return array<string, mixed> a feed item */
	private function revision( string $id, string $status, int $from, int $to, array $extra = array() ): array {
		return array(
			'id'         => $id,
			'type'       => 'booking_revision',
			'attributes' => $extra + array(
				'id'                   => $id,
				'booking_id'           => 'cccccccc-0000-4000-8000-000000000001',
				'property_id'          => self::P_UUID,
				'status'               => $status,
				'ota_name'             => 'BookingCom',
				'ota_reservation_code' => '4012345678',
				'arrival_date'         => self::day( $from ),
				'departure_date'       => self::day( $to ),
				'amount'               => '230.00',
				'currency'             => 'EUR',
				'payment_collect'      => 'property',
				'customer'             => array(
					'name'    => 'Hans',
					'surname' => 'Müller',
					'mail'    => 'hans@guest.booking.com',
					'country' => 'DE',
				),
				'rooms'                => array(
					array(
						'room_type_id'  => self::DOUBLE,
						'rate_plan_id'  => self::DBL_BAR,
						'checkin_date'  => self::day( $from ),
						'checkout_date' => self::day( $to ),
						'amount'        => '230.00',
						'occupancy'     => array( 'adults' => 2 ),
					),
				),
				'guarantee'            => array(
					'card_number' => '4111111111111111',
					'cvv'         => '123',
				),
				'inserted_at'          => gmdate( 'Y-m-d\TH:i:s' ),
			),
		);
	}

	/** The feed gives $items until they are acknowledged. */
	private function feed( array $items ): void {
		$acked           = array();
		$this->responder = function ( $method, $path ) use ( $items, &$acked ) {
			if ( 'GET' === $method && 'booking_revisions/feed' === $path ) {
				return array( 200, array( 'data' => array_values( array_filter( $items, static fn( $i ) => ! isset( $acked[ $i['id'] ] ) ) ) ) );
			}
			if ( preg_match( '#^booking_revisions/([0-9a-f-]{36})/ack$#', $path, $m ) ) {
				$acked[ $m[1] ] = true;
				return array( 200, array( 'meta' => array( 'message' => 'Success' ) ) );
			}
			return array( 200, array( 'data' => array(), 'meta' => array() ) );
		};
	}

	public function test_new_modified_and_cancelled_reservations_are_applied_once_and_acknowledged(): void {
		$new = $this->revision( 'dddddddd-0000-4000-8000-000000000001', 'new', 10, 12 );
		$this->feed( array( $new ) );
		$result = $this->inbound()->pull( $this->p['id'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertCount( 1, $this->calls_to( 'POST', 'booking_revisions/dddddddd-0000-4000-8000-000000000001/ack' ) );

		$rev = $this->service( ChannexRevisions::class )->find( 'dddddddd-0000-4000-8000-000000000001' );
		$this->assertSame( 'created', $rev['outcome'] );
		$this->assertNotNull( $rev['acked_at'] );
		$this->assertStringNotContainsString( '4111111111111111', (string) $rev['payload'], 'never the card' );
		$this->assertStringNotContainsString( 'guarantee', (string) $rev['payload'] );

		$repo    = $this->service( BookingRepository::class );
		$booking = $repo->find( (int) $rev['local_booking_id'] );
		$this->assertSame( 'confirmed', $booking['status'] );
		$this->assertSame( 'import', $booking['source'] );
		$this->assertSame( 'Booking.com', $booking['external_source'] );
		$this->assertSame( '4012345678', $booking['external_ref'] );
		$this->assertSame( 23000, (int) $booking['total'], 'price of the portal, in cents' );
		$this->assertSame( 'Müller', $booking['last_name'] );
		$this->assertStringContainsString( 'in struttura', (string) $booking['internal_notes'] );
		$this->assertSame( 2, $this->dirty( ChannexQueue::AVAILABILITY ), 'the Double is sold on Booking.com nights too' );

		// The same revision again (webhook and poll): nothing new.
		$this->assertSame( 'duplicate', $this->inbound()->handle( $this->p['id'], $new )['outcome'] );
		$this->assertSame( 1, $repo->search( array( 'property_id' => $this->p['id'] ) )['total'] );

		// Modified: two nights later, three nights.
		$this->calls = array();
		$mod         = $this->revision( 'dddddddd-0000-4000-8000-000000000002', 'modified', 14, 17, array( 'amount' => '345.00' ) );
		$mod['attributes']['rooms'][0]['amount'] = '345.00';
		$this->feed( array( $mod ) );
		$this->inbound()->pull( $this->p['id'] );
		$booking = $repo->find( (int) $rev['local_booking_id'] );
		$this->assertSame( self::day( 14 ), $booking['arrival'] );
		$this->assertSame( self::day( 17 ), $booking['departure'] );
		$this->assertSame( 34500, (int) $booking['total'] );
		$this->assertSame( 'modified', $this->service( ChannexRevisions::class )->find( 'dddddddd-0000-4000-8000-000000000002' )['outcome'] );
		$this->assertSame( 5, $this->dirty( ChannexQueue::AVAILABILITY ), 'old nights freed and new nights taken' );

		// Cancelled.
		$this->feed( array( $this->revision( 'dddddddd-0000-4000-8000-000000000003', 'cancelled', 14, 17 ) ) );
		$this->inbound()->pull( $this->p['id'] );
		$this->assertSame( 'cancelled', $repo->find( (int) $rev['local_booking_id'] )['status'] );
		$this->assertCount( 1, $this->calls_to( 'POST', 'booking_revisions/dddddddd-0000-4000-8000-000000000003/ack' ) );
	}

	public function test_a_reservation_without_free_rooms_is_saved_as_overbooking(): void {
		// The only Double is sold at the desk.
		$this->assertIsArray( $this->book( $this->p['id'], $this->double['type']['id'], 10, 12 ) );
		$this->feed( array( $this->revision( 'dddddddd-0000-4000-8000-000000000010', 'new', 10, 12 ) ) );
		$this->inbound()->pull( $this->p['id'] );
		$rev = $this->service( ChannexRevisions::class )->find( 'dddddddd-0000-4000-8000-000000000010' );
		$this->assertSame( 'created', $rev['outcome'] );
		$this->assertSame( '1', (string) $rev['overbooked'] );
		$this->assertSame( '1', (string) $rev['needs_attention'] );
		$this->assertNotNull( $rev['acked_at'], 'confirmed by the portal: accepted and acknowledged' );
		$rooms = $this->service( BookingRepository::class )->rooms( (int) $rev['local_booking_id'] );
		$this->assertNull( $rooms[0]['unit_id'], 'no room to give: the staff decides' );
		$this->assertSame( 'overbooking', $this->service( ChannexAlerts::class )->all()[0]['code'] );
		// Availability sent stays 0.
		$this->rewind();
		$this->sync()->push( $this->p['id'], ChannexQueue::AVAILABILITY );
		$last = end( $this->calls );
		$this->assertSame( 0, $last['body']['values'][0]['availability'] );
	}

	public function test_an_unmapped_room_is_kept_and_imported_after_the_mapping(): void {
		$other = $this->room_type( $this->p['id'], 1, array( 'name' => 'Suite' ) );
		$item  = $this->revision( 'dddddddd-0000-4000-8000-000000000020', 'new', 10, 12 );
		$item['attributes']['rooms'][0]['room_type_id'] = 'aaaaaaaa-0000-4000-8000-000000000099';
		$item['attributes']['rooms'][0]['rate_plan_id'] = 'aaaaaaaa-0000-4000-8000-000000000098';
		$this->feed( array( $item ) );
		$this->inbound()->pull( $this->p['id'] );
		$rev = $this->service( ChannexRevisions::class )->find( 'dddddddd-0000-4000-8000-000000000020' );
		$this->assertSame( 'unmapped', $rev['outcome'] );
		$this->assertNull( $rev['local_booking_id'] );
		$this->assertSame( '1', (string) $rev['needs_attention'] );
		$this->assertNotNull( $rev['acked_at'], 'kept here, with its data' );
		$this->assertSame( 'unmapped', $this->service( ChannexAlerts::class )->all()[0]['code'] );

		$map = $this->service( ChannexMap::class );
		$map->save(
			$this->p['id'],
			self::P_UUID,
			$map->room_types( $this->p['id'] ) + array( $other['type']['id'] => 'aaaaaaaa-0000-4000-8000-000000000099' ),
			$map->rate_plans( $this->p['id'] )
		);
		$this->calls = array();
		$done        = $this->inbound()->retry( 'dddddddd-0000-4000-8000-000000000020' );
		$this->assertSame( 'created', $done['outcome'] );
		$this->assertSame( array(), $this->calls_to( 'POST', 'booking_revisions/dddddddd-0000-4000-8000-000000000020/ack' ), 'already acknowledged' );
		$booking = $this->service( BookingRepository::class )->find( (int) $done['local_booking_id'] );
		$this->assertSame( (int) $other['type']['id'], (int) $this->service( BookingRepository::class )->rooms( (int) $booking['id'] )[0]['room_type_id'] );
	}

	public function test_a_reservation_not_saved_is_not_acknowledged(): void {
		$this->gate->allow = false;
		$this->feed( array( $this->revision( 'dddddddd-0000-4000-8000-000000000030', 'new', 10, 12 ) ) );
		$result = $this->inbound()->pull( $this->p['id'] );
		$this->assertSame( 1, $result['errors'] );
		$this->assertSame( array(), $this->calls_to( 'POST', 'booking_revisions/dddddddd-0000-4000-8000-000000000030/ack' ), 'Channex will propose it again' );
		$rev = $this->service( ChannexRevisions::class )->find( 'dddddddd-0000-4000-8000-000000000030' );
		$this->assertNull( $rev['processed_at'] );
		$this->assertNotNull( $rev['error'] );
		$this->assertSame( 0, (int) $this->service( BookingRepository::class )->search( array( 'property_id' => $this->p['id'] ) )['total'] );

		$this->gate->allow = true;
		$this->inbound()->pull( $this->p['id'] );
		$rev = $this->service( ChannexRevisions::class )->find( 'dddddddd-0000-4000-8000-000000000030' );
		$this->assertSame( 'created', $rev['outcome'] );
		$this->assertSame( '2', (string) $rev['attempts'] );
		$this->assertNotNull( $rev['acked_at'] );
	}

	public function test_the_webhook_needs_a_valid_signature_and_only_schedules_the_feed(): void {
		$server = rest_get_server();
		$body   = (string) wp_json_encode(
			array(
				'event'       => 'booking',
				'property_id' => self::P_UUID,
				'payload'     => array( 'booking_id' => 'cccccccc-0000-4000-8000-000000000001' ),
			)
		);
		$send   = static function ( ?string $signature, string $ts ) use ( $server, $body ) {
			$request = new \WP_REST_Request( 'POST', '/booking-manager/public/v1/channex/webhook' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_header( 'x-hmac-timestamp', $ts );
			if ( null !== $signature ) {
				$request->set_header( 'x-hmac-signature-256', $signature );
			}
			$request->set_body( $body );
			return $server->dispatch( $request );
		};
		$ts = (string) time();
		$this->assertSame( 401, $send( null, $ts )->get_status() );
		$this->assertSame( 401, $send( hash_hmac( 'sha256', $ts . '.' . $body, 'wrong_secret_0123456789' ), $ts )->get_status() );
		$old = (string) ( time() - 600 );
		$this->assertSame( 401, $send( hash_hmac( 'sha256', $old . '.' . $body, self::SECRET ), $old )->get_status() );
		wp_clear_scheduled_hook( ChannexBookings::PULL_HOOK, array( $this->p['id'] ) );
		$ok = $send( hash_hmac( 'sha256', $ts . '.' . $body, self::SECRET ), $ts );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( array(), $this->calls, 'no call to Channex inside the webhook' );
		$this->assertNotFalse( wp_next_scheduled( ChannexBookings::PULL_HOOK, array( $this->p['id'] ) ), 'feed read by a job' );
		$log = $this->service( ChannexLog::class )->webhooks( $this->p['id'] );
		$this->assertCount( 4, $log );
		$this->assertTrue( $log[0]['valid'] );
		$this->assertFalse( $log[1]['valid'] );
	}

	public function test_nothing_is_queued_when_the_sync_is_off(): void {
		$this->service( ChannexSettings::class )->save( array( 'active' => false ) );
		$this->book( $this->p['id'], $this->twin['type']['id'], 10, 13 );
		$this->assertSame( 0, $this->dirty( ChannexQueue::AVAILABILITY ) );
		$this->assertSame( 'inactive', $this->sync()->push( $this->p['id'], ChannexQueue::AVAILABILITY ) );
		$this->assertSame( array(), $this->calls );
	}
}
