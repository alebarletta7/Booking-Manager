<?php
/**
 * 0.2.5: the e-mails of each customer: sender and SMTP, its texts for all its properties,
 * formatted texts, the new placeholders, «Pagamento non riuscito», custom messages and
 * the subject in the log.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Access\PropertyScope;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Core\Hooks;
use BookingManager\Database\Schema;
use BookingManager\Notifications\NotificationService;
use BookingManager\Notifications\NotificationTemplates;
use BookingManager\Notifications\TemplateRepository;
use BookingManager\Properties\PropertyService;
use BookingManager\Rest\RestController;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantMail;
use BookingManager\Tenants\TenantProfile;
use BookingManager\Tenants\TenantRepository;
use BookingManager\Tests\TestCase;

final class TenantMailTest extends TestCase {

	private \WP_REST_Server $server;

	private int $platform = 0;

	/** @var array<int, array<string, mixed>> what PHPMailer held for each e-mail */
	private array $outgoing = array();

	public function set_up(): void {
		parent::set_up();
		Tenancy::flush();
		reset_phpmailer_instance();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
		remove_all_filters( 'rest_dispatch_request' );
		remove_all_filters( 'rest_post_dispatch' );
		$this->platform = $this->admin();
		$this->outgoing = array();
		// After every other hook (TenantMail is at 999): what leaves, message by message.
		add_action(
			'phpmailer_init',
			function ( $mailer ) {
				$this->outgoing[] = array(
					'to'       => $mailer->getToAddresses()[0][0] ?? '',
					'subject'  => $mailer->Subject,
					'mailer'   => $mailer->Mailer,
					'host'     => $mailer->Host,
					'user'     => $mailer->Username,
					'password' => $mailer->Password,
					'from'     => $mailer->From,
					'reply_to' => array_keys( $mailer->getReplyToAddresses() ),
				);
			},
			1000
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_actions( 'phpmailer_init' );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $body
	 * @param array<string, mixed> $query
	 */
	private function call( string $method, string $path, array $body = array(), array $query = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/' . RestController::REST_NAMESPACE . $path );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_ensure_response( $this->server->dispatch( $request ) );
	}

	private function as_user( int $user_id ): void {
		wp_set_current_user( $user_id );
		$this->service( PropertyScope::class )->flush();
	}

	/**
	 * A customer with its administrator and a property.
	 *
	 * @return array{tenant: int, admin: int, property: array<string, mixed>, type: int}
	 */
	private function customer( string $name, array $property = array() ): array {
		$this->as_user( $this->platform );
		$created = $this->call(
			'POST',
			'/platform/tenants',
			array(
				'name'          => $name,
				'business_type' => 'hotel',
				'admin'         => array(
					'email'      => sanitize_title( $name ) . '-' . wp_generate_password( 6, false ) . '@example.org',
					'first_name' => 'Titolare',
				),
			)
		);
		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$tenant = (int) $created->get_data()['id'];
		$prop   = $this->service( PropertyService::class )->create(
			$property + array(
				'name'      => 'Villa ' . $name,
				'kind'      => 'hotel',
				'timezone'  => 'Europe/Rome',
				'currency'  => 'EUR',
				'tenant_id' => $tenant,
			),
			get_current_user_id()
		);
		$this->assertIsArray( $prop );
		$type = $this->room_type( (int) $prop['id'], 3 );
		Tenancy::flush();
		return array(
			'tenant'   => $tenant,
			'admin'    => ( new TenantRepository() )->user_ids( $tenant )[0],
			'property' => $prop,
			'type'     => (int) $type['type']['id'],
		);
	}

	/** @return array<string, mixed> SMTP settings of a customer, as the screen sends them */
	private static function smtp( string $host, string $from, string $password = 'segreta-123' ): array {
		return array(
			'mode'       => 'smtp',
			'host'       => $host,
			'port'       => 587,
			'secure'     => 'tls',
			'user'       => $from,
			'password'   => $password,
			'from_email' => $from,
			'from_name'  => 'Hotel Bianchi',
		);
	}

	private function notifications(): NotificationService {
		return $this->service( NotificationService::class );
	}

	/** @return array<int, array<string, mixed>> */
	private function rows( int $booking_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'notifications' ) . ' WHERE booking_id = %d ORDER BY id', $booking_id ), ARRAY_A );
	}

	public function test_each_customers_emails_leave_with_its_own_settings(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$c = $this->customer( 'Le Rose' );
		// Le Rose stays on the platform's service, replies to its booking address.
		$this->service( TenantProfile::class )->save( $c['tenant'], array( 'contacts' => array( 'booking_email' => 'prenotazioni@lerose.it' ) ) );

		$this->as_user( $b['admin'] );
		$saved = $this->call( 'PUT', '/tenant/mail', self::smtp( 'smtp.hotel-bianchi.it', 'noreply@hotel-bianchi.it' ) );
		$this->assertSame( 200, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
		$test = $this->call( 'POST', '/tenant/mail/test', array( 'to' => 'giulia@hotel-bianchi.it' ) );
		$this->assertTrue( $test->get_data()['ok'], (string) wp_json_encode( $test->get_data() ) );
		$this->assertTrue( $test->get_data()['settings']['active'] );
		$this->outgoing = array();

		$this->as_user( $this->platform );
		$this->book( (int) $b['property']['id'], $b['type'], 10, 12 );
		$this->book( (int) $c['property']['id'], $c['type'], 10, 12 );
		$this->book( (int) $b['property']['id'], $b['type'], 20, 22 );
		$this->notifications()->run();

		$this->assertCount( 3, $this->outgoing );
		$name = static fn( array $m ) => $m['subject'];
		foreach ( $this->outgoing as $mail ) {
			if ( str_contains( $mail['subject'], 'Villa Hotel Bianchi' ) ) {
				$this->assertSame( 'smtp', $mail['mailer'], $name( $mail ) );
				$this->assertSame( 'smtp.hotel-bianchi.it', $mail['host'] );
				$this->assertSame( 'noreply@hotel-bianchi.it', $mail['from'] );
				$this->assertSame( 'segreta-123', $mail['password'], 'the sealed password is opened only to send' );
			} else {
				$this->assertStringContainsString( 'Villa Le Rose', $mail['subject'] );
				$this->assertSame( 'mail', $mail['mailer'], 'Le Rose never goes through Bianchi\'s server' );
				$this->assertSame( TenantMail::platform_from(), $mail['from'] );
				$this->assertSame( '', $mail['password'] );
				$this->assertSame( array( 'prenotazioni@lerose.it' ), $mail['reply_to'] );
			}
		}
		// Nothing of Bianchi's server is left on the shared mailer.
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'mail', $mailer->Mailer );
		$this->assertSame( '', $mailer->Password );
	}

	public function test_the_server_is_used_only_after_a_test_and_the_password_stays_secret(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$this->as_user( $b['admin'] );
		$mail = $this->service( TenantMail::class );

		$saved = $this->call( 'PUT', '/tenant/mail', self::smtp( 'smtp.hotel-bianchi.it', 'noreply@hotel-bianchi.it' ) );
		$data  = $saved->get_data();
		$this->assertTrue( $data['password_set'] );
		$this->assertFalse( $data['verified'] );
		$this->assertFalse( $data['active'] );
		$this->assertNull( $mail->smtp_for( ( new TenantRepository() )->find( $b['tenant'] ) ), 'not used before the test' );

		// A sender of another domain is refused.
		$wrong = $this->call( 'PUT', '/tenant/mail', array( 'from_email' => 'info@altro.it' ) + self::smtp( 'smtp.hotel-bianchi.it', 'noreply@hotel-bianchi.it' ) );
		$this->assertSame( 400, $wrong->get_status() );
		$this->assertArrayHasKey( 'from_email', $wrong->get_data()['data']['fields'] );

		$this->assertTrue( $this->call( 'POST', '/tenant/mail/test', array( 'to' => 'giulia@hotel-bianchi.it' ) )->get_data()['ok'] );
		$this->assertNotNull( $mail->smtp_for( ( new TenantRepository() )->find( $b['tenant'] ) ) );

		// Saved again without the password: kept, still verified.
		$same = $this->call( 'PUT', '/tenant/mail', array( 'password' => '' ) + self::smtp( 'smtp.hotel-bianchi.it', 'noreply@hotel-bianchi.it' ) )->get_data();
		$this->assertTrue( $same['password_set'] );
		$this->assertTrue( $same['active'] );

		// Another server: to be tested again.
		$moved = $this->call( 'PUT', '/tenant/mail', array( 'password' => '' ) + self::smtp( 'mail.hotel-bianchi.it', 'noreply@hotel-bianchi.it' ) )->get_data();
		$this->assertFalse( $moved['verified'] );
		$this->assertFalse( $moved['active'] );

		// The password never comes back, nor is it in the settings or the log in clear.
		global $wpdb;
		$this->assertStringNotContainsString( 'segreta-123', (string) wp_json_encode( $this->call( 'GET', '/tenant/mail' )->get_data() ) );
		$this->assertStringNotContainsString( 'segreta-123', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT settings FROM ' . Schema::table( 'tenants' ) . ' WHERE id = %d', $b['tenant'] ) ) );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit_log' ) . ' WHERE tenant_id = %d AND ( old_values LIKE %s OR new_values LIKE %s )', $b['tenant'], '%segreta%', '%segreta%' ) ) );
	}

	public function test_a_plan_without_its_own_server_stays_on_the_platform(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		( new TenantRepository() )->update( $b['tenant'], array( 'features' => array( 'custom_smtp' => false ) ) );
		$this->as_user( $b['admin'] );
		$this->assertFalse( $this->call( 'GET', '/tenant/mail' )->get_data()['allowed'] );
		$refused = $this->call( 'PUT', '/tenant/mail', self::smtp( 'smtp.hotel-bianchi.it', 'noreply@hotel-bianchi.it' ) );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertArrayHasKey( 'mode', $refused->get_data()['data']['fields'] );
	}

	public function test_a_customer_reaches_only_its_own_mail_and_texts(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$c = $this->customer( 'Le Rose' );
		$this->as_user( $b['admin'] );
		$other = array( 'tenant' => $c['tenant'] );
		$this->assertSame( 403, $this->call( 'GET', '/tenant/mail', array(), $other )->get_status() );
		$this->assertSame( 403, $this->call( 'PUT', '/tenant/mail', self::smtp( 'smtp.x.it', 'a@x.it' ), $other )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/tenant/mail/test', array( 'to' => 'a@x.it' ), $other )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', '/tenant/templates', array(), $other )->get_status() );
		$this->assertSame( 403, $this->call( 'PUT', '/tenant/templates/guest_confirmed', array( 'subject' => 'X' ), $other )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', '/tenant/templates/guest_confirmed', array(), $other + array( 'language' => 'it' ) )->get_status() );
		// Nor the property texts of another customer's property.
		$this->assertContains( $this->call( 'GET', '/properties/' . $c['property']['id'] . '/templates' )->get_status(), array( 403, 404 ) );
		$this->assertContains( $this->call( 'POST', '/properties/' . $c['property']['id'] . '/templates/custom', array( 'subject' => 'X', 'body' => 'Y' ) )->get_status(), array( 403, 404 ) );
		$this->assertSame( array(), $this->service( TemplateRepository::class )->for_property( (int) $c['property']['id'] ) );
		$this->assertSame( array(), ( new TenantRepository() )->find( $c['tenant'] )['settings']['templates'] ?? array() );
	}

	public function test_the_customers_texts_serve_every_property_without_its_own(): void {
		$b      = $this->customer( 'Hotel Bianchi' );
		$second = $this->service( PropertyService::class )->create(
			array(
				'name'      => 'Bianchi Mare',
				'kind'      => 'hotel',
				'timezone'  => 'Europe/Rome',
				'currency'  => 'EUR',
				'tenant_id' => $b['tenant'],
			),
			$this->platform
		);
		$second_type = (int) $this->room_type( (int) $second['id'], 2 )['type']['id'];
		$c           = $this->customer( 'Le Rose' );
		Tenancy::flush();

		$this->as_user( $b['admin'] );
		$saved = $this->call(
			'PUT',
			'/tenant/templates/guest_confirmed',
			array(
				'language' => 'it',
				'subject'  => 'Bianchi ti aspetta, {nome_cliente}',
				'body'     => '<p>Ciao <strong>{nome}</strong>,</p><script>alert(1)</script><ul><li>Codice {codice_prenotazione}</li></ul><p><a href="{link_prenotazione}" onclick="x()">La tua prenotazione</a></p>',
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$stored = ( new TenantRepository() )->find( $b['tenant'] )['settings']['templates']['guest_confirmed']['it'];
		$this->assertStringNotContainsString( 'script', $stored['body'], 'cleaned with wp_kses' );
		$this->assertStringNotContainsString( 'onclick', $stored['body'] );
		// The property's own text wins over the customer's.
		$this->service( TemplateRepository::class )->save( (int) $second['id'], 'email', 'guest_confirmed', 'it', 'Solo per Bianchi Mare', '', 0 );

		$this->as_user( $this->platform );
		$one   = $this->book( (int) $b['property']['id'], $b['type'], 10, 12 );
		$two   = $this->book( (int) $second['id'], $second_type, 10, 12 );
		$three = $this->book( (int) $c['property']['id'], $c['type'], 10, 12 );
		reset_phpmailer_instance();
		$this->notifications()->run();
		$sent = array_column( tests_retrieve_phpmailer_instance()->mock_sent, null, 'subject' );

		$this->assertArrayHasKey( 'Bianchi ti aspetta, Mario', $sent );
		$body = $sent['Bianchi ti aspetta, Mario']['body'];
		$this->assertStringContainsString( '<strong>Mario</strong>', $body );
		$this->assertStringContainsString( 'Codice ' . $one['code'], $body );
		$this->assertStringNotContainsString( '{link_prenotazione}', $body );
		$this->assertStringNotContainsString( 'alert(1)', $body );
		$this->assertArrayHasKey( 'Solo per Bianchi Mare', $sent, 'the property\'s own subject' );
		$this->assertStringContainsString( 'Ciao <strong>Mario</strong>', $sent['Solo per Bianchi Mare']['body'], 'its empty text falls back to the customer\'s' );
		$this->assertArrayHasKey( 'Prenotazione confermata – Villa Le Rose', $sent, 'another customer keeps the default' );

		// The log keeps the subject as it went out.
		$this->assertSame( 'Bianchi ti aspetta, Mario', $this->rows( (int) $one['id'] )[0]['subject'] );
		$this->assertSame( 'Bianchi ti aspetta, Mario', $this->notifications()->payload( $this->rows( (int) $one['id'] )[0] )['subject'] );
		unset( $two, $three );
	}

	public function test_the_new_placeholders_take_the_public_contacts(): void {
		$b = $this->customer( 'Hotel Bianchi', array( 'phone' => '+39 06 7654321' ) );
		$this->service( TenantProfile::class )->save(
			$b['tenant'],
			array(
				'contacts' => array(
					'whatsapp'      => '+39 333 1234567',
					'website'       => 'https://www.hotel-bianchi.it',
					'booking_email' => 'prenotazioni@hotel-bianchi.it',
				),
				'public'   => array(
					'whatsapp'      => true,
					'website'       => true,
					'booking_email' => true,
				),
			)
		);
		$c       = $this->customer( 'Le Rose' );
		$booking = $this->book( (int) $b['property']['id'], $b['type'], 10, 13 );
		$tpl     = $this->service( NotificationTemplates::class );
		$all     = '{nome_cliente} {cognome_cliente} {email_cliente} {nome_struttura} {codice_prenotazione} {numero_notti} {stato_prenotazione} {link_prenotazione} {telefono_struttura} {email_struttura} {whatsapp_struttura} {sito_struttura}';
		$texts   = $tpl->texts( 'guest_confirmed', ( new BookingRepository() )->find( (int) $booking['id'] ), $b['property'], array( 'body' => $all ), 'https://booking.example/m/1' );
		$this->assertSame( 'Mario Rossi mario.rossi@example.org Villa Hotel Bianchi ' . $booking['code'] . ' 3 Confermata https://booking.example/m/1 +39 06 7654321 prenotazioni@hotel-bianchi.it +393331234567 https://www.hotel-bianchi.it', $texts['intro'] );
		$this->assertFalse( $texts['html'] );

		// Le Rose has no WhatsApp: the placeholder becomes empty, never another customer's number.
		$other = $this->book( (int) $c['property']['id'], $c['type'], 10, 12 );
		$texts = $tpl->texts( 'guest_confirmed', ( new BookingRepository() )->find( (int) $other['id'] ), $c['property'], array( 'body' => '[{whatsapp_struttura}] [{sito_struttura}]' ) );
		$this->assertSame( '[] []', $texts['intro'] );
		$this->assertContains( '{whatsapp_struttura}', NotificationTemplates::PLACEHOLDERS );
		$this->assertContains( '{nome}', NotificationTemplates::PLACEHOLDERS, 'the old names keep working' );
	}

	public function test_a_refused_online_payment_asks_the_guest_to_try_again(): void {
		$b       = $this->customer( 'Hotel Bianchi' );
		$booking = $this->book( (int) $b['property']['id'], $b['type'], 10, 12 );
		global $wpdb;
		$wpdb->update( Schema::table( 'bookings' ), array( 'status' => 'option' ), array( 'id' => $booking['id'] ) );
		$this->notifications()->run();
		$before = count( $this->rows( (int) $booking['id'] ) );

		Hooks::dispatch( Hooks::PAYMENT_FAILED, (int) $booking['id'], array( 'payment_id' => 7, 'provider' => 'stripe' ) );
		Hooks::dispatch( Hooks::PAYMENT_FAILED, (int) $booking['id'], array( 'payment_id' => 7, 'provider' => 'stripe' ) );
		Hooks::dispatch( Hooks::PAYMENT_FAILED, (int) $booking['id'], array( 'payment_id' => 8, 'provider' => 'manual' ) );
		$rows = array_slice( $this->rows( (int) $booking['id'] ), $before );
		$this->assertCount( 1, array_filter( $rows, static fn( array $r ) => 'guest_payment_failed' === $r['template_key'] && 'email' === $r['channel'] ), 'once per payment, never for desk payments' );

		reset_phpmailer_instance();
		$this->notifications()->run();
		$mail = tests_retrieve_phpmailer_instance()->mock_sent[0];
		$this->assertSame( 'Pagamento non riuscito – Villa Hotel Bianchi', $mail['subject'] );
		$this->assertStringContainsString( 'Riprova il pagamento', $mail['body'] );

		// Paid meanwhile: the message is no longer true and is not sent.
		$wpdb->update( Schema::table( 'bookings' ), array( 'status' => 'confirmed' ), array( 'id' => $booking['id'] ) );
		Hooks::dispatch( Hooks::PAYMENT_FAILED, (int) $booking['id'], array( 'payment_id' => 9, 'provider' => 'nexi' ) );
		$this->assertSame( array(), array_filter( array_slice( $this->rows( (int) $booking['id'] ), $before + 1 ), static fn( array $r ) => 'guest_payment_failed' === $r['template_key'] ) );
	}

	public function test_a_custom_message_is_written_once_and_sent_by_hand(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$c = $this->customer( 'Le Rose' );
		$this->as_user( $b['admin'] );
		$base = '/properties/' . $b['property']['id'] . '/templates';

		$empty = $this->call( 'POST', $base . '/custom', array( 'subject' => '' ) );
		$this->assertSame( 400, $empty->get_status() );

		$created = $this->call(
			'POST',
			$base . '/custom',
			array(
				'language' => 'it',
				'subject'  => 'Parcheggio per {nome}',
				'body'     => '<p>Il parcheggio è in <em>Via Verdi 3</em>.</p>',
			)
		);
		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$this->assertSame( 'custom_1', $created->get_data()['created'] );
		$this->assertSame( 'custom_2', $this->call( 'POST', $base . '/custom', array( 'subject' => 'Altro', 'body' => 'Testo' ) )->get_data()['created'] );
		$names = $this->call( 'GET', $base . '/custom' )->get_data();
		$this->assertSame( array( 'custom_1', 'custom_2' ), array_column( $names, 'key' ) );

		$this->as_user( $this->platform );
		$booking = $this->book( (int) $b['property']['id'], $b['type'], 10, 12 );
		$this->notifications()->run();
		reset_phpmailer_instance();
		$this->as_user( $b['admin'] );
		$sent = $this->call( 'POST', '/bookings/' . $booking['id'] . '/notify', array( 'template' => 'custom_1' ) );
		$this->assertSame( 201, $sent->get_status(), (string) wp_json_encode( $sent->get_data() ) );
		$this->assertSame( 'sent', $sent->get_data()['status'] );
		$this->assertSame( 'Parcheggio per Mario', $sent->get_data()['subject'] );
		$mail = tests_retrieve_phpmailer_instance()->mock_sent[0];
		$this->assertSame( 'Parcheggio per Mario', $mail['subject'] );
		$this->assertStringContainsString( '<em>Via Verdi 3</em>', $mail['body'] );

		// Another customer's property has no custom_1: nothing is sent.
		$this->as_user( $this->platform );
		$other = $this->book( (int) $c['property']['id'], $c['type'], 10, 12 );
		$this->assertSame( 404, $this->call( 'POST', '/bookings/' . $other['id'] . '/notify', array( 'template' => 'custom_1' ) )->get_status() );

		// Removed in every language.
		$this->as_user( $b['admin'] );
		$this->call( 'DELETE', $base . '/custom_1' );
		$this->assertSame( array( 'custom_2' ), array_column( $this->call( 'GET', $base . '/custom' )->get_data(), 'key' ) );
	}
}
