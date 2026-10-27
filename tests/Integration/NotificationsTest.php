<?php
/**
 * Messages against the real database and WordPress's test mailer: which e-mails leave
 * after which event, the QR inside, texts of the property, reminders before arrival,
 * retries, messages no longer relevant (WhatsApp: WhatsAppTest).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingService;
use BookingManager\Database\Schema;
use BookingManager\Frontend\FormToken;
use BookingManager\Frontend\PublicBookingService;
use BookingManager\Notifications\NotificationRepository;
use BookingManager\Notifications\NotificationService;
use BookingManager\Notifications\TemplateRepository;
use BookingManager\Properties\PropertyService;
use BookingManager\Tests\TestCase;

final class NotificationsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		parent::tear_down();
	}

	private function notifications(): NotificationService {
		return $this->service( NotificationService::class );
	}

	/** @return array<int, array<string, mixed>> rows of a booking, oldest first */
	private function rows( int $booking_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'notifications' ) . ' WHERE booking_id = %d ORDER BY id', $booking_id ), ARRAY_A );
	}

	/** @return array<int, array<string, mixed>> e-mails handed to the mailer */
	private function sent(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	private function guest_property( array $settings = array() ): array {
		return $this->property(
			array(
				'email'    => 'info@villa.example',
				'address'  => 'Via Roma 1',
				'city'     => 'Lucca',
				'settings' => $settings + array( 'auto_assign_unit' => true ),
			)
		);
	}

	public function test_a_request_from_the_site_reaches_guest_and_staff(): void {
		wp_set_current_user( 0 );
		$p      = $this->guest_property();
		$t      = $this->room_type( $p['id'], 1 );
		$result = $this->service( PublicBookingService::class )->create(
			$p['slug'],
			array(
				'arrival'    => self::day( 20 ),
				'departure'  => self::day( 22 ),
				'rooms'      => array( array( 'room_type_id' => $t['type']['id'], 'adults' => 2 ) ),
				'guest'      => array( 'first_name' => 'Giulia', 'last_name' => 'Neri', 'email' => 'giulia@example.org' ),
				'privacy'    => true,
				'form_token' => FormToken::issue( time() - 60 ),
			),
			'203.0.113.9'
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'pending', $result['booking']['status'] );
		$booking = (array) $this->service( BookingRepository::class )->find_by_code( $result['booking']['code'] );

		$rows = $this->rows( (int) $booking['id'] );
		$this->assertSame( array( 'staff_new', 'guest_received' ), array_column( $rows, 'template_key' ) );
		$this->assertSame( array( 'info@villa.example', 'giulia@example.org' ), array_column( $rows, 'recipient' ) );

		$this->assertSame( 2, $this->notifications()->run()['sent'] );
		$sent = $this->sent();
		$this->assertCount( 2, $sent );
		$this->assertStringContainsString( 'Nuova prenotazione online', $sent[0]['subject'] );
		$this->assertStringContainsString( 'Giulia', $sent[0]['body'] );
		$this->assertStringContainsString( 'Villa Test', $sent[1]['subject'] );
		$this->assertStringContainsString( 'Abbiamo ricevuto la tua richiesta', $sent[1]['body'] );
		$this->assertStringContainsString( 'bkng_token', $sent[1]['body'], 'the link to manage the booking' );
		$this->assertSame( array( 'sent', 'sent' ), array_column( $this->rows( (int) $booking['id'] ), 'status' ) );
		$this->assertSame( 0, array_sum( $this->notifications()->run() ), 'nothing sent twice' );
	}

	public function test_the_confirmation_carries_the_qr_and_imports_stay_silent(): void {
		$this->admin();
		$p = $this->guest_property();
		$t = $this->room_type( $p['id'], 2 );
		$b = $this->book( $p['id'], $t['type']['id'], 10, 12 );

		$rows = $this->rows( $b['id'] );
		$this->assertSame( array( 'guest_confirmed' ), array_column( $rows, 'template_key' ), 'staff bookings: no e-mail to the staff' );
		$this->notifications()->send( (int) $rows[0]['id'] );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertStringContainsString( 'La tua prenotazione è confermata', $mailer->Body );
		$this->assertStringContainsString( 'cid:bkng-qr-' . $b['code'], $mailer->Body );
		$this->assertStringContainsString( 'Via Roma 1, Lucca', $mailer->Body );
		$images = array_filter( $mailer->getAttachments(), static fn( array $a ) => 'inline' === $a[6] );
		$cids   = array_column( $images, 7 );
		$this->assertContains( 'bkng-qr-' . $b['code'], $cids, 'the QR is embedded, not a remote image' );
		preg_match_all( '/src="([^"]+)"/', $mailer->Body, $sources );
		foreach ( $sources[1] as $src ) {
			$this->assertStringStartsWith( 'cid:', $src, 'no remote image in the e-mail' );
			$this->assertContains( substr( $src, 4 ), $cids, 'every image travels with the e-mail' );
		}

		$imported = $this->book( $p['id'], $t['type']['id'], 10, 12, array(), array( 'channel' => 'import' ) );
		$this->assertIsArray( $imported );
		$this->assertSame( array(), $this->rows( $imported['id'] ) );

		$quiet = $this->book( $p['id'], $t['type']['id'], 14, 15, array( 'notify_guest' => false ) );
		$this->assertSame( array(), $this->rows( $quiet['id'] ) );
	}

	public function test_a_message_no_longer_true_is_not_sent(): void {
		$this->admin();
		$p = $this->guest_property();
		$t = $this->room_type( $p['id'], 1 );
		$b = $this->book( $p['id'], $t['type']['id'], 10, 12 );
		$this->assertIsArray( $this->service( BookingService::class )->set_status( $b['id'], $b['version'], 'cancelled', array( 'reason' => 'Richiesta ospite' ) ) );

		$this->notifications()->run();
		$rows = array_column( $this->rows( $b['id'] ), null, 'template_key' );
		$this->assertSame( 'skipped', $rows['guest_confirmed']['status'] );
		$this->assertStringContainsString( 'Non più attuale', $rows['guest_confirmed']['last_error'] );
		$this->assertSame( 'sent', $rows['guest_cancelled']['status'] );
		$this->assertStringContainsString( 'Motivo: Richiesta ospite', $this->sent()[0]['body'] );
	}

	public function test_reminders_before_arrival_go_once(): void {
		$this->admin();
		$p = $this->guest_property( array( 'reminder_days' => 3, 'send_pre_arrival' => true ) );
		$t = $this->room_type( $p['id'], 2 );
		$soon  = $this->book( $p['id'], $t['type']['id'], 2, 4 );
		$later = $this->book( $p['id'], $t['type']['id'], 9, 10 );
		$fresh = $this->book( $p['id'], $t['type']['id'], 1, 2 );
		global $wpdb;
		foreach ( array( $soon['id'], $later['id'] ) as $id ) {
			$wpdb->update( Schema::table( 'bookings' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) ), array( 'id' => $id ) );
		}

		$this->assertSame( 1, $this->notifications()->queue_reminders() );
		$this->assertSame( 0, $this->notifications()->queue_reminders() );
		$this->assertContains( 'guest_pre_arrival', array_column( $this->rows( $soon['id'] ), 'template_key' ) );
		$this->assertNotContains( 'guest_pre_arrival', array_column( $this->rows( $fresh['id'] ), 'template_key' ), 'booked a moment ago' );
	}

	public function test_the_property_writes_its_own_texts(): void {
		$this->admin();
		$p = $this->guest_property();
		$t = $this->room_type( $p['id'], 1 );
		$this->service( TemplateRepository::class )->save( $p['id'], 'email', 'guest_confirmed', 'it', 'Ci vediamo il {arrivo}, {nome}!', "Gentile {nome},\nla camera è pronta per {notti} notti.", 0 );
		$b = $this->book( $p['id'], $t['type']['id'], 10, 12 );
		$this->notifications()->run();
		$mail = $this->sent()[0];
		$this->assertStringStartsWith( 'Ci vediamo il ', $mail['subject'] );
		$this->assertStringContainsString( 'Mario!', $mail['subject'] );
		$this->assertStringContainsString( 'la camera è pronta per 2 notti.', $mail['body'] );

		$preview = $this->notifications()->preview( $p['id'], 'guest_confirmed', 'Prova {codice}', '' );
		$this->assertSame( 'Prova ' . $b['code'], $preview['subject'] );
		$this->assertFalse( $preview['example'] );
	}

	public function test_the_look_of_the_emails_is_the_propertys(): void {
		$this->admin();
		$p = $this->guest_property(
			array(
				'email_subtitle'  => 'B&B & Hotel',
				'email_tagline'   => 'La tua ospitalità ci sta a cuore',
				'email_signoff'   => 'A presto!',
				'email_color'     => '#7A2E3A',
				'email_header_bg' => '#efe6d8',
				'email_page_bg'   => 'rosso',
				'email_banner_id' => -1,
			)
		);
		$this->assertSame( '#7a2e3a', $p['settings']['email_color'] );
		$this->assertSame( '', $p['settings']['email_page_bg'], 'only #rrggbb colours are kept' );
		$t = $this->room_type( $p['id'], 1 );
		$b = $this->book( $p['id'], $t['type']['id'], 10, 12 );
		$this->notifications()->run();
		$body = tests_retrieve_phpmailer_instance()->Body;
		foreach ( array( 'B&amp;B &amp; Hotel', 'La tua ospitalità ci sta a cuore', 'A presto!', '#7a2e3a', '#efe6d8', 'Riepilogo prenotazione', $b['code'] ) as $text ) {
			$this->assertStringContainsString( $text, $body );
		}

		// The preview shows what is being chosen, not saved, with the images inside.
		$preview = $this->notifications()->preview( $p['id'], 'guest_received', null, null, array( 'email_color' => '#1d3557', 'email_subtitle' => 'Guest house' ) );
		$this->assertStringContainsString( '#1d3557', $preview['html'] );
		$this->assertStringContainsString( 'Guest house', $preview['html'] );
		$this->assertStringNotContainsString( 'cid:', $preview['html'] );
		$this->assertStringContainsString( 'data:image/png;base64,', $preview['html'] );
	}

	public function test_a_failing_mail_server_is_retried_then_reported(): void {
		$this->admin();
		$p = $this->guest_property();
		$t = $this->room_type( $p['id'], 1 );
		$b = $this->book( $p['id'], $t['type']['id'], 10, 12 );
		add_filter( 'pre_wp_mail', '__return_false' );

		$repo = $this->service( NotificationRepository::class );
		$id   = (int) $this->rows( $b['id'] )[0]['id'];
		for ( $i = 1; $i <= 5; $i++ ) {
			$repo->update( $id, array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
			$status = $this->notifications()->send( $id );
			$this->assertSame( $i < 5 ? 'queued' : 'failed', $status );
		}
		$row = $repo->find( $id );
		$this->assertSame( 5, (int) $row['attempts'] );
		$this->assertNotEmpty( $row['last_error'] );

		remove_all_filters( 'pre_wp_mail' );
		$again = $this->notifications()->resend( $id );
		$this->assertSame( 'sent', $again['status'] );
		$this->assertSame( 'failed', $repo->find( $id )['status'], 'the old row stays in the log' );
	}
}
