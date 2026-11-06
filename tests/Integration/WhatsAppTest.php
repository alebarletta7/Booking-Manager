<?php
/**
 * WhatsApp like Night Manager's, against the real database with the test provider (no
 * message leaves): nothing queued until connected and switched on, the approved text
 * goes out, the guest's «no WhatsApp» is respected, versions of the texts, Meta's
 * delivery updates (signature, only forward), the short link of the button, secrets
 * never returned.
 *
 * Meta's real Cloud API is not reached by these tests.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\ManageLink;
use BookingManager\Database\Schema;
use BookingManager\Frontend\FormToken;
use BookingManager\Frontend\PublicBookingService;
use BookingManager\Guests\GuestRepository;
use BookingManager\Notifications\Channels\WhatsAppChannel;
use BookingManager\Notifications\NotificationRepository;
use BookingManager\Notifications\NotificationService;
use BookingManager\Notifications\WhatsApp\WhatsAppService;
use BookingManager\Notifications\WhatsApp\WhatsAppSettings;
use BookingManager\Notifications\WhatsApp\WhatsAppTemplates;
use BookingManager\Notifications\WhatsApp\WhatsAppVariables;
use BookingManager\Tests\TestCase;

final class WhatsAppTest extends TestCase {

	public function tear_down(): void {
		delete_option( WhatsAppSettings::OPTION );
		remove_all_filters( 'pre_wp_mail' );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function wa(): WhatsAppService {
		return $this->service( WhatsAppService::class );
	}

	/** @return array<int, array<string, mixed>> WhatsApp rows of a booking */
	private function rows( int $booking_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'notifications' ) . " WHERE booking_id = %d AND channel = 'whatsapp' ORDER BY id", $booking_id ), ARRAY_A );
	}

	private function connect_and_switch_on(): void {
		$view = $this->wa()->connect( array( 'provider' => 'test' ) );
		$this->assertIsArray( $view );
		$this->assertTrue( $view['settings']['connected'] );
		$this->assertIsArray( $this->wa()->save_settings( array( 'active' => true ) ) );
	}

	private function phone_booking( int $property_id, int $type_id, int $from, array $extra = array() ): array {
		$b = $this->book( $property_id, $type_id, $from, $from + 2, $extra + array( 'guest' => array( 'first_name' => 'Anna', 'last_name' => 'Blu', 'email' => 'anna@example.org', 'phone' => '340 123 4567' ) ) );
		$this->assertIsArray( $b );
		return $b;
	}

	public function test_nothing_is_queued_until_connected_and_switched_on(): void {
		$this->admin();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 2 );
		$b = $this->phone_booking( $p['id'], $t['type']['id'], 10 );
		$this->assertSame( array(), $this->rows( $b['id'] ), 'not connected: no WhatsApp row' );

		$switch = $this->wa()->save_settings( array( 'active' => true ) );
		$this->assertWPError( $switch, 'cannot switch on before connecting' );

		$this->wa()->connect( array( 'provider' => 'test' ) );
		$b2 = $this->phone_booking( $p['id'], $t['type']['id'], 14 );
		$this->assertSame( array(), $this->rows( $b2['id'] ), 'connected but off: no row' );
	}

	public function test_the_approved_text_goes_to_the_guest(): void {
		$this->admin();
		$this->connect_and_switch_on();
		foreach ( $this->wa()->templates() as $template ) {
			$this->assertSame( 'approved', $template['approval']['status'], $template['key'] );
		}
		$config = $this->service( WhatsAppSettings::class )->config();
		$this->assertSame( 'bm_guest_confirmed_v1', $config['templates']['guest_confirmed']['approved']['name'] );

		$p = $this->property( array( 'name' => 'Villa Mare' ) );
		$t = $this->room_type( $p['id'], 2 );
		$b = $this->phone_booking( $p['id'], $t['type']['id'], 10 );
		$this->service( NotificationService::class )->run();
		$rows = $this->rows( $b['id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'guest_confirmed', $rows[0]['template_key'] );
		$this->assertSame( '393401234567', $rows[0]['recipient'] );
		$this->assertSame( 'sent', $rows[0]['status'] );
		$this->assertSame( 'sent', $rows[0]['delivery_status'] );
		$this->assertStringStartsWith( 'prova-', (string) $rows[0]['provider_message_id'] );

		// What the guest reads: the approved text filled with the booking.
		$booking  = (array) $this->service( BookingRepository::class )->find( $b['id'] );
		$channel  = $this->service( WhatsAppChannel::class );
		$values   = $channel->values( $booking, $p, 'guest_confirmed' );
		$approved = $config['templates']['guest_confirmed']['approved'];
		$text     = WhatsAppTemplates::fill( $approved['text'], $values );
		$this->assertStringContainsString( 'Anna', $text );
		$this->assertStringContainsString( 'Villa Mare', $text );
		$this->assertStringContainsString( $b['code'], $text );
		$this->assertNotContains( '', WhatsAppTemplates::params( $approved['params'], $values ), 'WhatsApp refuses empty parameters' );

		// The button carries code and key, and the short link leads to the booking page.
		$suffix = WhatsAppChannel::button_param( $approved, $values );
		$this->assertIsString( $suffix );
		$short = WhatsAppVariables::parse_short( $suffix );
		$this->assertSame( $b['code'], $short[0] );
		$this->assertSame( $this->service( ManageLink::class )->token( (string) $booking['uuid'] ), $short[1] );

		// Switched off for one message: not queued any more.
		$this->wa()->save_template( 'guest_cancelled', array( 'is_active' => false ) );
		$this->service( \BookingManager\Bookings\BookingService::class )->set_status( $b['id'], (int) $b['version'], 'cancelled', array( 'reason' => 'Prova' ) );
		$this->assertSame( array( 'guest_confirmed' ), array_column( $this->rows( $b['id'] ), 'template_key' ) );
	}

	public function test_a_guest_who_said_no_gets_no_whatsapp(): void {
		$this->admin();
		$this->connect_and_switch_on();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 2 );
		$b = $this->phone_booking( $p['id'], $t['type']['id'], 10, array( 'whatsapp_opt_out' => true ) );
		$this->assertSame( array(), $this->rows( $b['id'] ) );
		$booking = (array) $this->service( BookingRepository::class )->find( $b['id'] );
		$this->assertFalse( $this->service( GuestRepository::class )->latest_consent( (int) $booking['guest_id'], 'whatsapp' ) );

		// Said no after the message was queued: skipped when its turn comes, never sent.
		$b2 = $this->phone_booking( $p['id'], $t['type']['id'], 20, array( 'guest' => array( 'first_name' => 'Luca', 'last_name' => 'Verdi', 'email' => 'luca@example.org', 'phone' => '+39 333 000 1111' ) ) );
		$row = $this->rows( $b2['id'] )[0];
		$guest = (int) $this->service( BookingRepository::class )->find( $b2['id'] )['guest_id'];
		$this->service( \BookingManager\Guests\GuestService::class )->update( $guest, array( 'whatsapp_opt_out' => true ) );
		$this->assertSame( 'skipped', $this->service( NotificationService::class )->send( (int) $row['id'] ) );
		$this->assertStringContainsString( 'non vuole ricevere', (string) $this->service( NotificationRepository::class )->find( (int) $row['id'] )['last_error'] );
	}

	public function test_the_public_form_asks_for_the_mobile_when_whatsapp_is_on(): void {
		$this->admin();
		$this->connect_and_switch_on();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		wp_set_current_user( 0 );
		$public = $this->service( PublicBookingService::class );
		$this->assertTrue( $public->property( $p['slug'] )['whatsapp'] );
		$input  = array(
			'arrival'          => self::day( 20 ),
			'departure'        => self::day( 22 ),
			'rooms'            => array( array( 'room_type_id' => $t['type']['id'], 'adults' => 2 ) ),
			'guest'            => array( 'first_name' => 'Giulia', 'last_name' => 'Neri', 'email' => 'giulia@example.org' ),
			'privacy'          => true,
			'whatsapp_opt_out' => true,
			'form_token'       => FormToken::issue( time() - 60 ),
		);
		$missing = $public->create( $p['slug'], $input, '203.0.113.20' );
		$this->assertWPError( $missing );
		$this->assertArrayHasKey( 'phone', $missing->get_error_data()['fields'] );

		$input['guest']['phone'] = '333 111 2222';
		$result                  = $public->create( $p['slug'], $input, '203.0.113.21' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$booking = (array) $this->service( BookingRepository::class )->find_by_code( $result['booking']['code'] );
		$this->assertSame( array(), $this->rows( (int) $booking['id'] ), 'ticked «Non voglio ricevere messaggi WhatsApp»' );
	}

	public function test_a_new_text_waits_for_approval_and_the_old_one_stays_in_use(): void {
		$approved = array(
			'name'   => 'bm_guest_confirmed_v1',
			'text'   => 'Vecchio {nome} testo',
			'params' => array( 'nome' ),
			'status' => 'approved',
			'reason' => '',
		);
		$state    = array(
			'version'  => 2,
			'latest'   => array( 'name' => 'bm_guest_confirmed_v2', 'text' => 'Nuovo {nome} testo', 'params' => array( 'nome' ), 'status' => 'pending', 'reason' => '' ),
			'approved' => $approved,
		);
		$this->assertSame( $state, WhatsAppService::apply_status( $state, array( 'status' => 'pending', 'reason' => '' ), '2026-10-27 10:00:00' ) );

		$now = WhatsAppService::apply_status( $state, array( 'status' => 'approved', 'reason' => '' ), '2026-10-27 10:00:00' );
		$this->assertSame( 'bm_guest_confirmed_v2', $now['approved']['name'], 'the new version is used once approved' );

		$rejected = WhatsAppService::apply_status( $state, array( 'status' => 'rejected', 'reason' => 'INVALID_FORMAT' ), '2026-10-27 10:00:00' );
		$this->assertSame( 'bm_guest_confirmed_v1', $rejected['approved']['name'], 'a rejected text leaves the old one in use' );

		$paused = WhatsAppService::apply_status( array( 'latest' => $approved, 'approved' => $approved ), array( 'status' => 'paused', 'reason' => '' ), '2026-10-27 10:00:00' );
		$this->assertArrayNotHasKey( 'approved', $paused, 'a paused text is not usable' );

		$this->assertSame( 'bm_guest_pre_arrival_v3', WhatsAppService::template_name( 'guest_pre_arrival', 3 ) );
	}

	public function test_texts_are_checked_like_meta_does(): void {
		$this->assertSame( array(), WhatsAppTemplates::problems( 'Ciao {nome}, a presto.' ) );
		$this->assertNotEmpty( WhatsAppTemplates::problems( '{nome}, ciao' ), 'starts with a variable' );
		$this->assertNotEmpty( WhatsAppTemplates::problems( 'Ciao {nome}.' ), 'ends with a variable' );
		$this->assertNotEmpty( WhatsAppTemplates::problems( 'Ciao {nome} {cognome} a presto' ), 'two variables together' );
		$this->assertNotEmpty( WhatsAppTemplates::problems( 'Ciao {nmoe} a presto' ), 'unknown variable' );
		$this->assertSame(
			array(
				'body'   => 'Ciao {{1}}, codice {{2}}, ancora {{3}} grazie',
				'params' => array( 'nome', 'codice', 'nome' ),
			),
			WhatsAppTemplates::numbered( 'Ciao {nome}, codice {codice}, ancora {nome} grazie' )
		);
		foreach ( WhatsAppTemplates::all() as $key => $default ) {
			$this->assertSame( array(), WhatsAppTemplates::problems( $default['body'] ), $key );
		}
		$this->assertSame( '393401234567', WhatsAppSettings::wa_phone( '340 123 4567', '39' ) );
		$this->assertSame( '393401234567', WhatsAppSettings::wa_phone( '+39 340 123 4567', '39' ) );
		$this->assertSame( '4915112345678', WhatsAppSettings::wa_phone( '0049 151 12345678', '39' ) );
		$this->assertNull( WhatsAppSettings::wa_phone( '123', '39' ) );
		$this->assertSame( '-', WhatsAppVariables::param( "  \n " ) );
	}

	public function test_delivery_updates_are_signed_and_only_move_forward(): void {
		$this->admin();
		$this->connect_and_switch_on();
		$settings = $this->service( WhatsAppSettings::class );
		$config   = $settings->config();
		$creds    = (array) $settings->credentials( $config );
		$this->assertNotEmpty( $creds['verify_token'] );
		$this->assertTrue( $this->wa()->verify_webhook( $creds['verify_token'] ) );
		$this->assertFalse( $this->wa()->verify_webhook( 'sbagliato' ) );
		// The app secret Meta signs with (the test provider has no fields of its own).
		$config['credentials'] = $settings->seal( $creds + array( 'app_secret' => 'segreto' ) );
		$settings->save( $config );

		$p  = $this->property();
		$t  = $this->room_type( $p['id'], 1 );
		$b  = $this->phone_booking( $p['id'], $t['type']['id'], 10 );
		$this->service( NotificationService::class )->run();
		$row = $this->rows( $b['id'] )[0];
		$id  = (string) $row['provider_message_id'];

		$payload = static fn( string $status ) => (string) wp_json_encode(
			array( 'entry' => array( array( 'changes' => array( array( 'value' => array( 'statuses' => array( array( 'id' => $id, 'status' => $status, 'timestamp' => (string) time() ) ) ) ) ) ) ) )
		);
		$sign    = static fn( string $raw ) => 'sha256=' . hash_hmac( 'sha256', $raw, 'segreto' );

		$raw = $payload( 'read' );
		$this->assertWPError( $this->wa()->webhook( $raw, 'sha256=00' ), 'bad signature' );
		$this->assertSame( 1, $this->wa()->webhook( $raw, $sign( $raw ) ) );
		$late = $payload( 'delivered' );
		$this->assertSame( 0, $this->wa()->webhook( $late, $sign( $late ) ), 'a late «delivered» never undoes «read»' );
		$this->assertSame( 'read', $this->rows( $b['id'] )[0]['delivery_status'] );

		$log = $this->wa()->log( array() );
		$this->assertSame( 'read', $log['items'][0]['delivery_status'] );
		$this->assertSame( 'Anna Blu', $log['items'][0]['guest'] );
		$this->assertSame( $b['code'], $log['items'][0]['booking_code'] );

		$again = $this->wa()->resend( (int) $row['id'] );
		$this->assertIsArray( $again );
		$this->assertSame( 'sent', $again['status'] );
		$this->assertCount( 2, $this->rows( $b['id'] ), 'the old row stays in the log' );
	}

	public function test_secrets_never_come_back(): void {
		$this->admin();
		$this->wa()->connect( array( 'provider' => 'test' ) );
		$settings = $this->service( WhatsAppSettings::class );
		$config   = $settings->config();
		$config['provider']    = 'meta';
		$config['credentials'] = $settings->seal(
			array(
				'phone_number_id'     => '123',
				'business_account_id' => '456',
				'access_token'        => 'EAAG-segretissimo',
				'app_secret'          => 'segreto-app',
				'verify_token'        => 'tok',
			)
		);
		$settings->save( $config );
		$json = (string) wp_json_encode( $this->wa()->view() );
		$this->assertStringNotContainsString( 'segretissimo', $json );
		$this->assertStringNotContainsString( 'segreto-app', $json );
		$this->assertStringNotContainsString( 'segretissimo', (string) get_option( WhatsAppSettings::OPTION )['credentials'], 'encrypted at rest' );
		$view = $this->wa()->settings_view();
		$this->assertTrue( $view['credentials']['access_token'] );
		$this->assertSame( '123', $view['credentials']['phone_number_id'] );
	}

	public function test_only_administrators_open_the_section(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$server         = $wp_rest_server;
		do_action( 'rest_api_init', $server );
		// The licence gate is tested on its own (RestApiTest).
		remove_all_filters( 'rest_dispatch_request' );
		$staff  = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $staff );
		$this->assertSame( 403, $server->dispatch( new \WP_REST_Request( 'GET', '/booking-manager/v1/whatsapp' ) )->get_status() );
		$this->admin();
		$response = $server->dispatch( new \WP_REST_Request( 'GET', '/booking-manager/v1/whatsapp' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( count( WhatsAppTemplates::KEYS ), $response->get_data()['templates'] );
		$this->assertStringEndsWith( '/booking-manager/public/v1/whatsapp/webhook', $response->get_data()['settings']['webhook_url'] );
	}
}
