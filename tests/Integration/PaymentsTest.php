<?php
/**
 * Payments against the real database, with a fake provider instead of Stripe/Nexi (their
 * sandboxes are tested by hand: see docs/TEST-REPORT.md): desk payments, refunds, online
 * payment of a public booking confirmed only by the provider's notification, duplicates,
 * wrong amounts, late payments refunded.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingService;
use BookingManager\Frontend\FormToken;
use BookingManager\Frontend\PublicBookingService;
use BookingManager\Payments\PaymentAccountRepository;
use BookingManager\Payments\PaymentAccountService;
use BookingManager\Payments\PaymentRepository;
use BookingManager\Payments\PaymentService;
use BookingManager\Payments\PaymentStatus;
use BookingManager\Tests\FakeProvider;
use BookingManager\Tests\TestCase;

final class PaymentsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		FakeProvider::$status        = array();
		FakeProvider::$refunds       = array();
		FakeProvider::$fail_checkout = false;
		add_filter( 'bkng_payment_providers', array( self::class, 'with_fake' ) );
		$this->reset_services();
	}

	public function tear_down(): void {
		remove_filter( 'bkng_payment_providers', array( self::class, 'with_fake' ) );
		parent::tear_down();
	}

	/** @param array<int, object> $providers */
	public static function with_fake( array $providers ): array {
		$providers[] = new FakeProvider();
		return $providers;
	}

	private function payments(): PaymentService {
		return $this->service( PaymentService::class );
	}

	private function booking( int $id ): array {
		return (array) $this->service( BookingRepository::class )->find( $id );
	}

	/** A property taking online bookings paid in full through the fake provider. */
	private function online_property(): array {
		$p = $this->property(
			array(
				'booking_mode' => 'direct',
				'settings'     => array( 'online_payment' => 'full' ),
			)
		);
		$this->service( PaymentAccountRepository::class )->save( $p['id'], FakeProvider::ID, 'test', 'active', array( 'secret' => 's3cret' ), array( 'webhook' => 'auto', 'webhook_endpoint_id' => 'we_1' ), null, true );
		$this->assertIsArray( $this->service( PaymentAccountService::class )->select( $p['id'], FakeProvider::ID, 'test' ) );
		return $p;
	}

	private function public_booking( array $p, int $type_id, int $from = 30, int $to = 32 ): array|\WP_Error {
		return $this->service( PublicBookingService::class )->create(
			$p['slug'],
			array(
				'arrival'    => self::day( $from ),
				'departure'  => self::day( $to ),
				'rooms'      => array( array( 'room_type_id' => $type_id, 'adults' => 2 ) ),
				'guest'      => array( 'first_name' => 'Giulia', 'last_name' => 'Neri', 'email' => 'giulia@example.org' ),
				'privacy'    => true,
				'form_token' => FormToken::issue( time() - 60 ),
			),
			'203.0.113.' . wp_rand( 1, 250 )
		);
	}

	private function webhook( int $account_id, array $body, string $secret = 's3cret' ): array|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/booking-manager/v1/webhooks/fake/' . $account_id );
		$request->set_header( 'X-Fake-Secret', $secret );
		$request->set_body( (string) wp_json_encode( $body ) );
		return $this->payments()->handle_webhook( FakeProvider::ID, $account_id, $request );
	}

	public function test_desk_payments_update_balance_and_payment_status(): void {
		$this->admin();
		$p = $this->property();
		$t = $this->room_type( $p['id'], 1 );
		$b = $this->book( $p['id'], $t['type']['id'], 5, 8 ); // 30000

		$key  = wp_generate_uuid4();
		$paid = $this->payments()->record_manual( $b['id'], $p['id'], 10000, 'cash', 'Acconto', $key );
		$this->assertIsArray( $paid );
		$again = $this->payments()->record_manual( $b['id'], $p['id'], 10000, 'cash', 'Acconto', $key );
		$this->assertSame( $paid['id'], $again['id'], 'the same request records once' );

		$after = $this->booking( $b['id'] );
		$this->assertSame( 10000, $after['paid'] );
		$this->assertSame( 20000, $after['balance'] );
		$this->assertSame( 'partially_paid', $after['payment_status'] );
		$this->assertSame( $b['version'], $after['version'], 'a payment does not make staff forms stale' );

		$too_much = $this->payments()->record_manual( $b['id'], $p['id'], 20001, 'pos', '', wp_generate_uuid4() );
		$this->assertWPError( $too_much );
		$this->assertWPError( $this->payments()->record_manual( $b['id'], $p['id'], 100, 'bitcoin', '', wp_generate_uuid4() ) );

		$this->assertIsArray( $this->payments()->record_manual( $b['id'], $p['id'], 20000, 'bank_transfer', '', wp_generate_uuid4() ) );
		$this->assertSame( 'paid', $this->booking( $b['id'] )['payment_status'] );

		$refund = $this->payments()->refund( $paid['id'], $p['id'], 4000, 'Sconto concordato', 'refund-' . wp_generate_password( 12, false ) );
		$this->assertIsArray( $refund );
		$this->assertSame( 4000, $refund['refunded'] );
		$after = $this->booking( $b['id'] );
		$this->assertSame( 4000, $after['refunded'] );
		$this->assertSame( 4000, $after['balance'] );
		$this->assertSame( 'partially_paid', $after['payment_status'] );
	}

	public function test_an_online_booking_is_confirmed_only_by_the_provider(): void {
		wp_set_current_user( 0 );
		$p = $this->online_property();
		$t = $this->room_type( $p['id'], 1 );

		$result = $this->public_booking( $p, $t['type']['id'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'option', $result['booking']['status'] );
		$this->assertTrue( $result['booking']['awaiting_payment'] );
		$this->assertSame( 20000, $result['payment']['amount'] );
		$this->assertStringStartsWith( 'https://pay.example.org/', $result['payment']['checkout_url'] );

		$booking = (array) $this->service( BookingRepository::class )->find_by_code( $result['booking']['code'] );
		$payment = $this->service( PaymentRepository::class )->for_booking( (int) $booking['id'] )[0];
		$account = (int) $payment['payment_account_id'];

		// A forged notification changes nothing.
		$this->assertWPError( $this->webhook( $account, array( 'id' => 'ev_x', 'type' => 'paid', 'session' => $payment['external_id'], 'amount' => 20000 ), 'wrong' ) );
		// A notification with another amount does not pay.
		$this->webhook( $account, array( 'id' => 'ev_0', 'type' => 'paid', 'session' => $payment['external_id'], 'amount' => 100 ) );
		$this->assertSame( 'option', $this->booking( (int) $booking['id'] )['status'] );

		$this->assertIsArray( $this->webhook( $account, array( 'id' => 'ev_1', 'type' => 'paid', 'session' => $payment['external_id'], 'amount' => 20000 ) ) );
		$dup = $this->webhook( $account, array( 'id' => 'ev_1', 'type' => 'paid', 'session' => $payment['external_id'], 'amount' => 20000 ) );
		$this->assertTrue( $dup['duplicate'] );

		$after = $this->booking( (int) $booking['id'] );
		$this->assertSame( 'confirmed', $after['status'] );
		$this->assertSame( 'paid', $after['payment_status'] );
		$this->assertSame( 20000, $after['paid'] );
		$this->assertCount( 1, $this->service( PaymentRepository::class )->for_booking( (int) $booking['id'] ) );
		$this->assertSame( array(), FakeProvider::$refunds );
	}

	public function test_a_payment_page_that_cannot_open_frees_the_rooms(): void {
		wp_set_current_user( 0 );
		$p                           = $this->online_property();
		$t                           = $this->room_type( $p['id'], 1 );
		FakeProvider::$fail_checkout = true;
		$this->assertWPError( $this->public_booking( $p, $t['type']['id'] ) );
		FakeProvider::$fail_checkout = false;
		$this->assertIsArray( $this->public_booking( $p, $t['type']['id'] ), 'the room is free again' );
	}

	public function test_a_payment_arriving_after_the_option_expired_is_refunded(): void {
		wp_set_current_user( 0 );
		$p       = $this->online_property();
		$t       = $this->room_type( $p['id'], 1 );
		$result  = $this->public_booking( $p, $t['type']['id'] );
		$booking = (array) $this->service( BookingRepository::class )->find_by_code( $result['booking']['code'] );
		$payment = $this->service( PaymentRepository::class )->for_booking( (int) $booking['id'] )[0];

		// The option expires (the guest left the page open): the booking is cancelled.
		global $wpdb;
		$wpdb->update( \BookingManager\Database\Schema::table( 'bookings' ), array( 'option_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $booking['id'] ) );
		$this->assertSame( 1, $this->service( BookingService::class )->expire_options() );
		$this->assertSame( 'cancelled', $this->booking( (int) $booking['id'] )['status'] );
		$this->assertSame( PaymentStatus::CANCELLED, $this->service( PaymentRepository::class )->find( (int) $payment['id'] )['status'] );

		// ...but the provider took the money a moment before.
		$this->webhook( (int) $payment['payment_account_id'], array( 'id' => 'ev_late', 'type' => 'paid', 'session' => $payment['external_id'], 'amount' => 20000 ) );
		$this->assertCount( 1, FakeProvider::$refunds );
		$this->assertSame( 20000, FakeProvider::$refunds[0]['amount'] );
		$fresh = $this->service( PaymentRepository::class )->find( (int) $payment['id'] );
		$this->assertSame( PaymentStatus::REFUNDED, $fresh['status'] );
		$this->assertSame( 'cancelled', $this->booking( (int) $booking['id'] )['status'] );
		$this->assertSame( 'refunded', $this->booking( (int) $booking['id'] )['payment_status'] );
	}

	public function test_the_guest_returning_from_the_page_sees_the_providers_answer(): void {
		wp_set_current_user( 0 );
		$p       = $this->online_property();
		$t       = $this->room_type( $p['id'], 1 );
		$result  = $this->public_booking( $p, $t['type']['id'] );
		$booking = (array) $this->service( BookingRepository::class )->find_by_code( $result['booking']['code'] );
		$payment = $this->service( PaymentRepository::class )->for_booking( (int) $booking['id'] )[0];

		$public = $this->service( PublicBookingService::class );
		$this->assertSame( 'option', $public->manage( $result['booking']['code'], $result['token'], '198.51.100.1' )['status'] );

		// No notification arrived, but the provider says it is paid.
		FakeProvider::$status[ $payment['external_id'] ] = new \BookingManager\Payments\ProviderEvent( \BookingManager\Payments\ProviderEvent::PAYMENT_SUCCEEDED, null, $payment['external_id'], null, 20000, 'EUR' );
		delete_transient( 'bkng_pay_check_' . $booking['id'] );
		$view = $public->manage( $result['booking']['code'], $result['token'], '198.51.100.1' );
		$this->assertSame( 'confirmed', $view['status'] );
		$this->assertFalse( $view['can_pay'] );
	}
}
