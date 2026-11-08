<?php
/**
 * 0.2.4: the customer's contacts, WhatsApp click-to-chat, logo, font and return page.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Access\PropertyScope;
use BookingManager\Frontend\PublicBookingService;
use BookingManager\Properties\PropertyService;
use BookingManager\Rest\RestController;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantAccess;
use BookingManager\Tenants\TenantProfile;
use BookingManager\Tenants\TenantRepository;
use BookingManager\Tests\TestCase;

final class TenantProfileTest extends TestCase {

	private \WP_REST_Server $server;

	private int $platform = 0;

	public function set_up(): void {
		parent::set_up();
		Tenancy::flush();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
		remove_all_filters( 'rest_dispatch_request' );
		remove_all_filters( 'rest_post_dispatch' );
		$this->platform = $this->admin();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
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
	 * A customer created from the panel, with its administrator, a domain and a property.
	 *
	 * @return array{tenant: int, admin: int, property: array<string, mixed>}
	 */
	private function customer( string $name, string $domain, array $property = array() ): array {
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
		$this->assertSame( 200, $this->call( 'POST', '/platform/tenants/' . $tenant . '/domains', array( 'host' => $domain ) )->get_status() );
		$prop = $this->service( PropertyService::class )->create(
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
		$this->room_type( (int) $prop['id'], 1 );
		Tenancy::flush();
		return array(
			'tenant'   => $tenant,
			'admin'    => ( new TenantRepository() )->user_ids( $tenant )[0],
			'property' => $prop,
		);
	}

	/** @return array<string, mixed> */
	private function profile( array $contacts, array $public, array $extra = array() ): array {
		return $extra + array(
			'contacts' => $contacts,
			'public'   => $public,
		);
	}

	public function test_whatsapp_numbers_need_the_country_code(): void {
		$this->assertSame( '393331234567', TenantProfile::whatsapp_number( '+39 333 123 4567' ) );
		$this->assertSame( '393331234567', TenantProfile::whatsapp_number( '0039 333-1234567' ) );
		$this->assertSame( '', TenantProfile::whatsapp_number( '333 1234567' ) );
		$this->assertSame( '', TenantProfile::whatsapp_number( '+39 12' ) );
		$this->assertSame( '', TenantProfile::whatsapp_number( '+0 3331234567' ) );
	}

	public function test_each_administrator_edits_only_its_own_customer(): void {
		$b = $this->customer( 'Hotel Bianchi', 'www.hotel-bianchi.it' );
		$c = $this->customer( 'Le Rose', 'www.lerose.it' );

		$this->as_user( $b['admin'] );
		$own = $this->call( 'GET', '/tenant/profile' );
		$this->assertSame( 200, $own->get_status() );
		$this->assertSame( $b['tenant'], $own->get_data()['tenant']['id'] );
		$this->assertSame( array( 'www.hotel-bianchi.it' ), $own->get_data()['domains'] );

		// Another customer's id in the request is refused, for reading and writing.
		$this->assertSame( 403, $this->call( 'GET', '/tenant/profile', array(), array( 'tenant' => $c['tenant'] ) )->get_status() );
		$this->assertSame( 403, $this->call( 'PUT', '/tenant/profile', $this->profile( array( 'phone' => '06 1' ), array() ), array( 'tenant' => $c['tenant'] ) )->get_status() );

		$saved = $this->call(
			'PUT',
			'/tenant/profile',
			$this->profile(
				array(
					'trade_name' => 'Hotel Bianchi',
					'email'      => 'info@hotel-bianchi.it',
					'phone'      => '+39 06 1234567',
					'whatsapp'   => '+39 333 1234567',
				),
				array(
					'trade_name' => true,
					'email'      => true,
					'whatsapp'   => true,
				),
				array(
					'same_email'    => true,
					'whatsapp_text' => 'Ciao, prenotazione {codice_prenotazione}',
					'version'       => $own->get_data()['tenant']['version'],
				)
			)
		);
		$this->assertSame( 200, $saved->get_status(), (string) wp_json_encode( $saved->get_data() ) );
		$profile = $saved->get_data()['profile'];
		$this->assertSame( '393331234567', $profile['contacts']['whatsapp'] );
		$this->assertSame( 'info@hotel-bianchi.it', $profile['contacts']['booking_email'] );
		$this->assertSame( 'info@hotel-bianchi.it', $profile['contacts']['info_email'] );

		// C's profile is untouched; the platform reads and writes any customer.
		$this->as_user( $this->platform );
		$other = $this->call( 'GET', '/tenant/profile', array(), array( 'tenant' => $c['tenant'] ) );
		$this->assertSame( 200, $other->get_status() );
		$this->assertSame( '', $other->get_data()['profile']['contacts']['phone'] );
		$put = $this->call( 'PUT', '/tenant/profile', $this->profile( array( 'phone' => '+39 0835 1' ), array( 'phone' => true ) ), array( 'tenant' => $c['tenant'] ) );
		$this->assertSame( 200, $put->get_status() );
		$this->assertSame( '+39 0835 1', $put->get_data()['profile']['contacts']['phone'] );

		// A receptionist of B cannot edit the business.
		$this->as_user( $b['admin'] );
		$staff = $this->call(
			'POST',
			'/staff',
			array(
				'email'      => 'reception-' . wp_generate_password( 4, false ) . '@example.org',
				'first_name' => 'Luca',
				'role'       => 'bkng_front_desk',
				'properties' => array( (int) $b['property']['id'] ),
			)
		);
		$this->assertSame( 201, $staff->get_status(), (string) wp_json_encode( $staff->get_data() ) );
		$this->as_user( (int) $staff->get_data()['id'] );
		$this->assertSame( 403, $this->call( 'GET', '/tenant/profile' )->get_status() );
	}

	public function test_values_are_checked(): void {
		$b = $this->customer( 'Hotel Bianchi', 'www.hotel-bianchi.it' );
		$c = $this->customer( 'Le Rose', 'www.lerose.it' );
		$this->as_user( $b['admin'] );

		$bad = $this->call(
			'PUT',
			'/tenant/profile',
			$this->profile(
				array(
					'whatsapp' => '333 1234567',
					'email'    => 'non-una-email',
				),
				array(),
				array(
					'font'       => 'comic',
					'return_url' => 'https://www.lerose.it/prenota/',
				)
			)
		);
		$this->assertSame( 400, $bad->get_status() );
		$fields = $bad->get_data()['data']['fields'];
		foreach ( array( 'contacts.whatsapp', 'contacts.email', 'font', 'return_url' ) as $key ) {
			$this->assertArrayHasKey( $key, $fields, $key );
		}

		// A logo uploaded by another customer is refused.
		$logo = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $logo, TenantAccess::ATTACHMENT_META, $c['tenant'] );
		$this->assertSame( 400, $this->call( 'PUT', '/tenant/profile', array( 'logo_id' => $logo ) )->get_status() );
		update_post_meta( $logo, TenantAccess::ATTACHMENT_META, $b['tenant'] );

		$ok = $this->call(
			'PUT',
			'/tenant/profile',
			array(
				'logo_id'    => $logo,
				'font'       => 'serif',
				'return_url' => 'https://www.hotel-bianchi.it/prenota/',
			)
		);
		$this->assertSame( 200, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
		$this->assertNotNull( $ok->get_data()['logo'] );

		$service = $this->service( TenantProfile::class );
		$this->assertSame( 'https://www.hotel-bianchi.it/prenota/', $service->return_url_for( (int) $b['property']['id'] ) );
		// The domain removed: the return page is no longer used.
		$this->as_user( $this->platform );
		$this->call( 'DELETE', '/platform/tenants/' . $b['tenant'] . '/domains', array(), array( 'host' => 'www.hotel-bianchi.it' ) );
		$this->assertSame( '', $service->return_url_for( (int) $b['property']['id'] ) );
	}

	public function test_the_form_shows_property_contacts_first_then_the_public_ones(): void {
		$b = $this->customer( 'Hotel Bianchi', 'www.hotel-bianchi.it' );
		$c = $this->customer( 'Le Rose', 'www.lerose.it', array( 'phone' => '+39 0835 999' ) );
		$this->as_user( $b['admin'] );
		$this->assertSame(
			200,
			$this->call(
				'PUT',
				'/tenant/profile',
				$this->profile(
					array(
						'trade_name'   => 'Bianchi Hospitality',
						'contact_name' => 'Giulia Bianchi',
						'email'        => 'direzione@hotel-bianchi.it',
						'booking_email' => 'prenota@hotel-bianchi.it',
						'phone'        => '+39 06 1234567',
						'whatsapp'     => '+39 333 1234567',
						'website'      => 'www.hotel-bianchi.it',
					),
					array(
						'trade_name'    => true,
						'booking_email' => true,
						'phone'         => true,
						'whatsapp'      => true,
					)
				)
			)->get_status()
		);

		$public = $this->service( PublicBookingService::class );
		$form   = $public->property( (string) $b['property']['slug'] );
		$this->assertIsArray( $form );
		$this->assertSame( '+39 06 1234567', $form['phone'] );
		$this->assertSame( 'prenota@hotel-bianchi.it', $form['email'] );
		$this->assertTrue( $form['business']['footer'] );
		$this->assertSame( 'Bianchi Hospitality', $form['business']['name'] );
		$this->assertSame( '393331234567', $form['business']['whatsapp'] );
		// Not public: the contact person, the main e-mail and the website.
		$json = (string) wp_json_encode( $form );
		$this->assertStringNotContainsString( 'Giulia', $json );
		$this->assertStringNotContainsString( 'direzione@', $json );
		$this->assertNull( $form['business']['website'] );

		// The property's own phone wins; nothing of B reaches C's form.
		$other = $public->property( (string) $c['property']['slug'] );
		$this->assertSame( '+39 0835 999', $other['phone'] );
		$this->assertFalse( $other['business']['footer'] );
		$this->assertNull( $other['business']['whatsapp'] );
		$this->assertStringNotContainsString( 'bianchi', strtolower( (string) wp_json_encode( $other ) ) );

		// The guest's booking page has the same contacts.
		$booking = $this->book( (int) $b['property']['id'], (int) $this->service( \BookingManager\Inventory\RoomTypeRepository::class )->for_property( (int) $b['property']['id'] )[0]['id'], 3, 5 );
		$this->assertIsArray( $booking );
		$page = $public->public_payload( $booking );
		$this->assertSame( '393331234567', $page['property']['business']['whatsapp'] );
		$this->assertSame( 'prenota@hotel-bianchi.it', $page['property']['email'] );
	}
}
