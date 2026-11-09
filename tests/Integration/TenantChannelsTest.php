<?php
/**
 * 0.2.6: WhatsApp per customer and Channex per property. Each customer's WhatsApp lives in
 * its own settings and answers on its own webhook address; each property of a customer
 * other than the default one has its own Channex key, secret and webhook. The plan
 * decides whether a customer may use them; nobody reaches another customer's settings.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Access\PropertyScope;
use BookingManager\Channels\Channex\ChannexAdmin;
use BookingManager\Channels\Channex\ChannexBookings;
use BookingManager\Channels\Channex\ChannexMap;
use BookingManager\Channels\Channex\ChannexSettings;
use BookingManager\Database\Schema;
use BookingManager\Notifications\NotificationService;
use BookingManager\Notifications\WhatsApp\WhatsAppSettings;
use BookingManager\Properties\PropertyService;
use BookingManager\Rest\PublicController;
use BookingManager\Rest\RestController;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantRepository;
use BookingManager\Tests\TestCase;

final class TenantChannelsTest extends TestCase {

	private const KEY_B  = 'chiave_bianchi_0123456789';
	private const KEY_S  = 'chiave_sito_0123456789';
	private const CX_B   = 'bbbbbbbb-0000-4000-8000-00000000000b';
	private const CX_C   = 'cccccccc-0000-4000-8000-00000000000c';

	private \WP_REST_Server $server;

	private int $platform = 0;

	/** @var array<int, array{key: string, path: string}> calls to Channex */
	private array $calls = array();

	public function set_up(): void {
		parent::set_up();
		Tenancy::flush();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
		remove_all_filters( 'rest_dispatch_request' );
		remove_all_filters( 'rest_post_dispatch' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->platform = $this->admin();
		$this->calls    = array();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'pre_wp_mail', '__return_true' );
		delete_option( WhatsAppSettings::OPTION );
		delete_option( ChannexSettings::OPTION );
		parent::tear_down();
	}

	/** Channex's API, simulated: records which key each call used. */
	public function fake_http( $pre, array $args, string $url ) {
		if ( ! str_starts_with( $url, ChannexSettings::STAGING_URL ) ) {
			return $pre;
		}
		$this->calls[] = array(
			'key'  => (string) ( $args['headers']['user-api-key'] ?? '' ),
			'path' => trim( (string) wp_parse_url( substr( $url, strlen( ChannexSettings::STAGING_URL ) ), PHP_URL_PATH ), '/' ),
		);
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( array( 'data' => array() ) ),
			'response' => array(
				'code'    => 200,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * @param array<string, mixed> $body
	 * @param array<string, mixed> $query
	 * @param array<string, string> $headers
	 */
	private function call( string $method, string $path, array $body = array(), array $query = array(), string $ns = RestController::REST_NAMESPACE, array $headers = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/' . $ns . $path );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
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
	 * A customer with its administrator and a property; WhatsApp and Channex in its plan.
	 *
	 * @return array{tenant: int, uuid: string, admin: int, property: array<string, mixed>, type: int}
	 */
	private function customer( string $name ): array {
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
		$repo   = new TenantRepository();
		$repo->update(
			$tenant,
			array(
				'features' => array(
					'whatsapp_meta' => true,
					'channex'       => true,
				),
			)
		);
		$prop = $this->service( PropertyService::class )->create(
			array(
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
			'uuid'     => (string) $repo->find( $tenant )['uuid'],
			'admin'    => $repo->user_ids( $tenant )[0],
			'property' => $prop,
			'type'     => (int) $type['type']['id'],
		);
	}

	/** @return array<int, array<string, mixed>> */
	private function whatsapp_rows( int $booking_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'notifications' ) . " WHERE booking_id = %d AND channel = 'whatsapp'", $booking_id ), ARRAY_A );
	}

	/** @return array<string, mixed> */
	private function phone_booking( int $property_id, int $type_id, int $from ): array {
		$b = $this->book( $property_id, $type_id, $from, $from + 2, array( 'guest' => array( 'first_name' => 'Anna', 'last_name' => 'Blu', 'email' => 'anna@example.org', 'phone' => '340 123 4567' ) ) );
		$this->assertIsArray( $b );
		return $b;
	}

	/* ============================================================ WhatsApp */

	public function test_each_customer_has_its_own_whatsapp(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$c = $this->customer( 'Le Rose' );

		$this->as_user( $b['admin'] );
		$connected = $this->call( 'PUT', '/whatsapp/connection', array( 'provider' => 'test' ) );
		$this->assertSame( 200, $connected->get_status(), (string) wp_json_encode( $connected->get_data() ) );
		$this->assertSame( 200, $this->call( 'PUT', '/whatsapp/settings', array( 'active' => true ) )->get_status() );
		$this->assertStringEndsWith( '/whatsapp/webhook/' . $b['uuid'], (string) $this->call( 'GET', '/whatsapp' )->get_data()['settings']['webhook_url'] );

		// Stored with the customer, sealed; the site's WhatsApp stays untouched.
		$this->assertFalse( get_option( WhatsAppSettings::OPTION ) );
		$stored = ( new TenantRepository() )->find( $b['tenant'] )['settings']['whatsapp'];
		$this->assertSame( 'test', $stored['provider'] );
		$this->assertTrue( $stored['active'] );

		$this->as_user( $this->platform );
		$at_b = $this->phone_booking( (int) $b['property']['id'], $b['type'], 10 );
		$at_c = $this->phone_booking( (int) $c['property']['id'], $c['type'], 10 );
		$this->service( NotificationService::class )->run();
		$this->assertCount( 1, $this->whatsapp_rows( (int) $at_b['id'] ), 'Bianchi\'s guest gets the WhatsApp' );
		$this->assertSame( array(), $this->whatsapp_rows( (int) $at_c['id'] ), 'Le Rose has no WhatsApp: never Bianchi\'s' );

		// Each customer sees its own log only.
		$this->as_user( $c['admin'] );
		$this->assertSame( array(), $this->call( 'GET', '/whatsapp/messages' )->get_data()['items'] );
		$this->assertFalse( $this->call( 'GET', '/whatsapp' )->get_data()['settings']['connected'] );
		$this->as_user( $b['admin'] );
		$this->assertCount( 1, $this->call( 'GET', '/whatsapp/messages' )->get_data()['items'] );
	}

	public function test_a_customer_cannot_reach_another_customers_whatsapp(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$c = $this->customer( 'Le Rose' );
		$this->as_user( $c['admin'] );
		$other = array( 'tenant' => $b['tenant'] );
		$this->assertSame( 403, $this->call( 'GET', '/whatsapp', array(), $other )->get_status() );
		$this->assertSame( 403, $this->call( 'PUT', '/whatsapp/connection', array( 'provider' => 'test' ), $other )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', '/whatsapp/messages', array(), $other )->get_status() );
		$this->assertSame( array(), ( new TenantRepository() )->find( $b['tenant'] )['settings']['whatsapp'] ?? array() );

		// The platform may look at any customer's.
		$this->as_user( $this->platform );
		$this->assertSame( 200, $this->call( 'PUT', '/whatsapp/connection', array( 'provider' => 'test' ), $other )->get_status() );
		$this->assertSame( 'test', ( new TenantRepository() )->find( $b['tenant'] )['settings']['whatsapp']['provider'] );
		$this->assertFalse( get_option( WhatsAppSettings::OPTION ) );
	}

	public function test_whatsapp_needs_the_plan(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		( new TenantRepository() )->update( $b['tenant'], array( 'features' => array( 'whatsapp_meta' => false ) ) );
		$this->as_user( $b['admin'] );
		$refused = $this->call( 'PUT', '/whatsapp/connection', array( 'provider' => 'test' ) );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertFalse( $this->call( 'GET', '/whatsapp' )->get_data()['settings']['allowed'] );
	}

	public function test_meta_reaches_each_customer_on_its_own_address(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$this->as_user( $b['admin'] );
		$this->call( 'PUT', '/whatsapp/connection', array( 'provider' => 'test' ) );
		$settings = $this->service( WhatsAppSettings::class );
		$token    = (string) $settings->scoped( $b['tenant'], fn() => $settings->credentials( $settings->config() )['verify_token'] ?? '' );
		$this->assertNotSame( '', $token );

		$verify = array(
			'hub_mode'         => 'subscribe',
			'hub_verify_token' => $token,
			'hub_challenge'    => 'sfida123',
		);
		$ok = $this->call( 'GET', '/whatsapp/webhook/' . $b['uuid'], array(), $verify, PublicController::REST_NAMESPACE );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'sfida123', $ok->get_data() );
		remove_all_filters( 'rest_pre_serve_request' );
		$this->assertSame( 403, $this->call( 'GET', '/whatsapp/webhook', array(), $verify, PublicController::REST_NAMESPACE )->get_status(), 'the site\'s address knows nothing of Bianchi\'s token' );
		$this->assertSame( 404, $this->call( 'GET', '/whatsapp/webhook/' . wp_generate_uuid4(), array(), $verify, PublicController::REST_NAMESPACE )->get_status() );
	}

	/* ============================================================= Channex */

	public function test_each_property_of_a_customer_has_its_own_channex(): void {
		$b = $this->customer( 'Hotel Bianchi' );
		$c = $this->customer( 'Le Rose' );
		$pb = (int) $b['property']['id'];
		$pc = (int) $c['property']['id'];

		// The site's connection is on: it serves the default customer's properties only.
		$settings = $this->service( ChannexSettings::class );
		$settings->save_credentials( array( 'api_key' => self::KEY_S ) );
		$settings->save( array( 'active' => true ) );
		$this->assertFalse( $settings->scoped( $pc, fn() => $settings->active() ), 'Le Rose never runs on the site\'s key' );
		$this->assertSame( '', $settings->scoped( $pc, fn() => $settings->api_key() ) );
		$own_default = $this->property();
		$this->assertSame( self::KEY_S, $settings->scoped( (int) $own_default['id'], fn() => $settings->api_key() ), 'the default customer keeps the site\'s' );

		$this->as_user( $b['admin'] );
		$saved = $this->call( 'PUT', "/properties/{$pb}/channex/connection", array( 'api_key' => self::KEY_B, 'active' => true ) );
		$this->assertSame( 200, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
		$view = $saved->get_data()['settings'];
		$this->assertSame( 'property', $view['scope'] );
		$this->assertTrue( $view['active'] );
		$this->assertTrue( $view['has_api_key'] );
		$this->assertStringNotContainsString( self::KEY_B, (string) wp_json_encode( $saved->get_data() ), 'the key never comes back' );
		$this->assertStringEndsWith( '/channex/webhook/' . $b['property']['uuid'], (string) $saved->get_data()['webhook_url'] );

		// Its own option; the site's key is unchanged.
		$this->assertSame( self::KEY_B, $settings->scoped( $pb, fn() => $settings->api_key() ) );
		$this->assertTrue( $settings->scoped( $pb, fn() => $settings->active() ) );
		$this->assertSame( self::KEY_S, $settings->api_key() );
		$this->assertIsArray( get_option( ChannexSettings::PROPERTY_OPTION . $pb ) );
		$this->assertStringNotContainsString( self::KEY_B, (string) wp_json_encode( get_option( ChannexSettings::PROPERTY_OPTION . $pb ) ), 'sealed at rest' );

		// The key test goes out with Bianchi's key.
		$this->calls = array();
		$tested      = $this->call( 'POST', "/properties/{$pb}/channex/connection/test" );
		$this->assertSame( 200, $tested->get_status(), (string) wp_json_encode( $tested->get_data() ) );
		$this->assertNotEmpty( $this->calls );
		foreach ( $this->calls as $call ) {
			$this->assertSame( self::KEY_B, $call['key'] );
		}

		// Removed: back to nothing (Bianchi cannot share the site's).
		$this->assertSame( 200, $this->call( 'DELETE', "/properties/{$pb}/channex/connection" )->get_status() );
		$this->assertFalse( get_option( ChannexSettings::PROPERTY_OPTION . $pb ) );
		$this->assertSame( '', $settings->scoped( $pb, fn() => $settings->api_key() ) );
	}

	public function test_a_customer_cannot_reach_another_propertys_channex(): void {
		$b  = $this->customer( 'Hotel Bianchi' );
		$c  = $this->customer( 'Le Rose' );
		$pc = (int) $c['property']['id'];
		$this->as_user( $b['admin'] );
		$this->assertContains( $this->call( 'GET', "/properties/{$pc}/channex/connection" )->get_status(), array( 403, 404 ) );
		$this->assertContains( $this->call( 'PUT', "/properties/{$pc}/channex/connection", array( 'api_key' => self::KEY_B ) )->get_status(), array( 403, 404 ) );
		$this->assertContains( $this->call( 'POST', "/properties/{$pc}/channex/connection/test" )->get_status(), array( 403, 404 ) );
		$this->assertContains( $this->call( 'DELETE', "/properties/{$pc}/channex/connection" )->get_status(), array( 403, 404 ) );
		$this->assertFalse( get_option( ChannexSettings::PROPERTY_OPTION . $pc ) );
		// The site's connection is the platform's.
		$this->assertSame( 403, $this->call( 'GET', '/channex' )->get_status() );
		$this->assertSame( 403, $this->call( 'PUT', '/channex/settings', array( 'api_key' => self::KEY_B ) )->get_status() );
	}

	public function test_channex_needs_the_plan(): void {
		$b  = $this->customer( 'Hotel Bianchi' );
		$pb = (int) $b['property']['id'];
		( new TenantRepository() )->update( $b['tenant'], array( 'features' => array( 'channex' => false ) ) );
		$this->as_user( $b['admin'] );
		$this->assertSame( 403, $this->call( 'PUT', "/properties/{$pb}/channex/connection", array( 'api_key' => self::KEY_B ) )->get_status() );
		$this->assertFalse( $this->call( 'GET', "/properties/{$pb}/channex/connection" )->get_data()['settings']['allowed'] );
		$this->assertFalse( get_option( ChannexSettings::PROPERTY_OPTION . $pb ) );
	}

	public function test_a_propertys_webhook_acts_on_that_property_only(): void {
		$b  = $this->customer( 'Hotel Bianchi' );
		$c  = $this->customer( 'Le Rose' );
		$pb = (int) $b['property']['id'];
		$pc = (int) $c['property']['id'];
		$admin = $this->service( ChannexAdmin::class );
		foreach ( array( $pb => self::CX_B, $pc => self::CX_C ) as $pid => $cx ) {
			$this->assertIsArray( $admin->on_property( $pid, 'save_settings', array( 'api_key' => 'chiave_' . $pid . '_0123456789', 'active' => true ) ) );
			$this->service( ChannexMap::class )->save( $pid, $cx, array(), array() );
		}
		$settings = $this->service( ChannexSettings::class );
		$admin->on_property( $pb, 'create_signing_key' );
		$admin->on_property( $pc, 'create_signing_key' );
		$secret_b = (string) $settings->scoped( $pb, fn() => $settings->webhook_secret(), true );
		$secret_c = (string) $settings->scoped( $pc, fn() => $settings->webhook_secret(), true );
		$this->assertNotSame( '', $secret_b );
		$this->assertNotSame( $secret_b, $secret_c );

		$hook = function ( string $path, string $token, string $cx ) {
			return $this->call(
				'POST',
				$path,
				array(
					'event'       => 'booking',
					'property_id' => $cx,
				),
				array(),
				PublicController::REST_NAMESPACE,
				array( ChannexBookings::TOKEN_HEADER => $token )
			);
		};
		$clear = static function () use ( $pb, $pc ) {
			wp_clear_scheduled_hook( ChannexBookings::PULL_HOOK, array( $pb ) );
			wp_clear_scheduled_hook( ChannexBookings::PULL_HOOK, array( $pc ) );
		};
		$path_b = '/channex/webhook/' . $b['property']['uuid'];

		$clear();
		$this->assertSame( 200, $hook( $path_b, $secret_b, self::CX_B )->get_status() );
		$this->assertNotFalse( wp_next_scheduled( ChannexBookings::PULL_HOOK, array( $pb ) ), 'Bianchi\'s reservations are read' );

		$clear();
		$this->assertSame( 200, $hook( $path_b, $secret_b, self::CX_C )->get_status() );
		$this->assertFalse( wp_next_scheduled( ChannexBookings::PULL_HOOK, array( $pc ) ), 'Bianchi\'s address never acts on Le Rose' );

		$this->assertSame( 401, $hook( $path_b, $secret_c, self::CX_B )->get_status(), 'Le Rose\'s secret is not Bianchi\'s' );

		$clear();
		$this->assertSame( 401, $hook( '/channex/webhook', $secret_b, self::CX_B )->get_status(), 'the site\'s address has its own secret' );
		$this->assertFalse( wp_next_scheduled( ChannexBookings::PULL_HOOK, array( $pb ) ) );

		$this->assertSame( 404, $hook( '/channex/webhook/' . wp_generate_uuid4(), $secret_b, self::CX_B )->get_status() );
	}
}
