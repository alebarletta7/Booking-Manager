<?php
/**
 * 0.2.3: the booking form as a page to put in an iframe on the customer's site.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Bookings\ManageLink;
use BookingManager\Frontend\EmbedPage;
use BookingManager\Frontend\PublicFrontend;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Properties\PropertyService;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantRepository;
use BookingManager\Tenants\TenantStatus;
use BookingManager\Tests\TestCase;

final class EmbedPageTest extends TestCase {

	private TenantRepository $tenants;

	public function set_up(): void {
		parent::set_up();
		Tenancy::flush();
		$this->tenants = new TenantRepository();
		update_option( 'permalink_structure', '/%postname%/' );
		$this->admin();
	}

	/**
	 * A customer other than the default one, with one domain and one property.
	 *
	 * @return array{tenant: int, property: array<string, mixed>}
	 */
	private function customer( string $name, string $domain ): array {
		$tenant = $this->tenants->create(
			array(
				'name'   => $name,
				'slug'   => sanitize_title( $name ),
				'status' => TenantStatus::ACTIVE,
				'plan'   => 'unlimited',
			)
		);
		$this->assertGreaterThan( 0, $tenant );
		$this->assertTrue( $this->tenants->add_domain( $tenant, $domain ) );
		$property = $this->service( PropertyService::class )->create(
			array(
				'name'      => 'Villa ' . $name,
				'kind'      => 'hotel',
				'timezone'  => 'Europe/Rome',
				'currency'  => 'EUR',
				'tenant_id' => $tenant,
			),
			get_current_user_id()
		);
		$this->assertIsArray( $property );
		Tenancy::flush();
		return array(
			'tenant'   => $tenant,
			'property' => $property,
		);
	}

	private function page(): EmbedPage {
		return $this->service( EmbedPage::class );
	}

	public function test_addresses_are_recognised_only_under_the_base(): void {
		$this->assertSame( 'villa-bianchi', EmbedPage::match( '/booking/villa-bianchi/', array(), '/' ) );
		$this->assertSame( 'villa-bianchi', EmbedPage::match( '/booking/villa-bianchi?bkng_booking=X', array(), '' ) );
		$this->assertSame( 'villa-bianchi', EmbedPage::match( '/wp/booking/villa-bianchi/', array(), '/wp/' ) );
		$this->assertSame( 'villa-bianchi', EmbedPage::match( '/index.php/booking/villa-bianchi/', array(), '/' ) );
		$this->assertSame( 'villa', EmbedPage::match( '/', array( EmbedPage::ARG => 'villa' ), '/' ) );
		$this->assertNull( EmbedPage::match( '/booking/embed.js', array(), '/' ) );

		$this->assertNull( EmbedPage::match( '/booking/', array(), '/' ) );
		$this->assertNull( EmbedPage::match( '/booking/villa/altro/', array(), '/' ) );
		$this->assertNull( EmbedPage::match( '/booking/../wp-admin/', array(), '/' ) );
		$this->assertNull( EmbedPage::match( '/prenota/villa/', array(), '/' ) );
		$this->assertNull( EmbedPage::match( '/booking/villa/', array(), '/wp/' ) );
		$this->assertNull( EmbedPage::match( '/', array( EmbedPage::ARG => '<script>' ), '/' ) );
		$this->assertNull( EmbedPage::match( '/', array( EmbedPage::ARG => array( 'x' ) ), '/' ) );
	}

	public function test_frame_ancestors_lists_self_and_only_clean_hosts(): void {
		$this->assertSame( "frame-ancestors 'self'", EmbedPage::csp( array() ) );
		$this->assertSame(
			"frame-ancestors 'self' www.hotel-bianchi.it hotel-bianchi.it",
			EmbedPage::csp( array( 'www.hotel-bianchi.it', 'hotel-bianchi.it', "evil.it 'unsafe-inline'", 'a.it; script-src *', 'www.hotel-bianchi.it' ) )
		);
	}

	public function test_page_of_a_customer_is_framed_only_by_its_domains(): void {
		$b = $this->customer( 'Hotel Bianchi', 'www.hotel-bianchi.it' );
		$c = $this->customer( 'Le Rose', 'www.lerose.it' );

		$response = $this->page()->render( (string) $b['property']['slug'] );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( "frame-ancestors 'self' www.hotel-bianchi.it", $response['headers']['Content-Security-Policy'] );
		$this->assertStringNotContainsString( 'lerose', $response['headers']['Content-Security-Policy'] );
		$this->assertSame( 'strict-origin', $response['headers']['Referrer-Policy'] );
		$this->assertSame( 'nosniff', $response['headers']['X-Content-Type-Options'] );
		$this->assertStringContainsString( 'noindex', $response['headers']['X-Robots-Tag'] );
		$this->assertSame( 'no-store, private', $response['headers']['Cache-Control'] );

		// The same form as the shortcode, without the theme, links outside the frame.
		$this->assertStringContainsString( 'class="bkng-public alignwide" data-bkng=', $response['body'] );
		$this->assertStringContainsString( '&quot;property&quot;:&quot;' . $b['property']['slug'] . '&quot;', $response['body'] );
		$this->assertStringContainsString( '<base target="_top">', $response['body'] );
		$this->assertStringContainsString( 'class="bkng-embed-body"', $response['body'] );
		$this->assertStringNotContainsString( 'wp-block-library', $response['body'] );

		$other = $this->page()->render( (string) $c['property']['slug'] );
		$this->assertSame( "frame-ancestors 'self' www.lerose.it", $other['headers']['Content-Security-Policy'] );
	}

	public function test_unknown_inactive_or_archived_gives_404_and_suspended_keeps_the_page(): void {
		$b = $this->customer( 'Hotel Bianchi', 'www.hotel-bianchi.it' );

		$missing = $this->page()->render( 'non-esiste' );
		$this->assertSame( 404, $missing['status'] );
		$this->assertSame( "frame-ancestors 'self'", $missing['headers']['Content-Security-Policy'] );
		$this->assertStringNotContainsString( 'data-bkng', $missing['body'] );

		$this->tenants->update( $b['tenant'], array( 'status' => TenantStatus::SUSPENDED ) );
		$this->assertSame( 200, $this->page()->render( (string) $b['property']['slug'] )['status'] );

		$this->tenants->update( $b['tenant'], array( 'status' => TenantStatus::ARCHIVED ) );
		$archived = $this->page()->render( (string) $b['property']['slug'] );
		$this->assertSame( 404, $archived['status'] );
		$this->assertStringNotContainsString( 'hotel-bianchi', $archived['headers']['Content-Security-Policy'] );

		$this->tenants->update( $b['tenant'], array( 'status' => TenantStatus::ACTIVE ) );
		( new PropertyRepository() )->set_status( (int) $b['property']['id'], 'inactive' );
		$this->assertSame( 404, $this->page()->render( (string) $b['property']['slug'] )['status'] );
	}

	public function test_other_pages_under_booking_are_left_to_wordpress(): void {
		$b    = $this->customer( 'Hotel Bianchi', 'www.hotel-bianchi.it' );
		$slug = (string) $b['property']['slug'];
		$this->assertTrue( $this->page()->owns( $slug, '/booking/' . $slug . '/' ) );
		// Not one of our properties: e.g. another plugin's form on a page /booking/ristorante/.
		$parent = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'booking', 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'ristorante', 'post_parent' => $parent, 'post_status' => 'publish', 'post_content' => '[restaurant_manager_prenota]' ) );
		$this->assertFalse( $this->page()->owns( 'ristorante', '/booking/ristorante/' ) );
		$this->assertFalse( $this->page()->owns( 'non-esiste', '/booking/non-esiste/' ) );
		// A page of the site at the same address as a property wins.
		self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => $slug, 'post_parent' => $parent, 'post_status' => 'publish' ) );
		$this->assertFalse( $this->page()->owns( $slug, '/booking/' . $slug . '/' ) );
		$this->assertTrue( $this->page()->owns( $slug, '/?bkng_embed=' . $slug ) );
	}

	public function test_manage_link_and_payment_return_open_the_page_when_no_page_has_the_form(): void {
		$b       = $this->customer( 'Hotel Bianchi', 'www.hotel-bianchi.it' );
		$slug    = (string) $b['property']['slug'];
		$booking = array(
			'code'        => 'BK-TEST1',
			'uuid'        => wp_generate_uuid4(),
			'property_id' => (int) $b['property']['id'],
		);
		$url = $this->service( ManageLink::class )->url( $booking );
		$this->assertStringStartsWith( home_url( '/booking/' . $slug . '/' ), $url );
		$this->assertStringContainsString( ManageLink::ARG_CODE . '=BK-TEST1', $url );
		$this->assertStringContainsString( ManageLink::ARG_TOKEN . '=', $url );

		// A page of the site with the shortcode still wins, as before.
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => PublicFrontend::shortcode_for( $slug ),
			)
		);
		PublicFrontend::remember_page( $slug, $page );
		$this->assertStringStartsWith( (string) get_permalink( $page ), $this->service( ManageLink::class )->url( $booking ) );
	}

	public function test_manage_view_of_the_page(): void {
		$b        = $this->customer( 'Hotel Bianchi', 'www.hotel-bianchi.it' );
		$response = $this->page()->render( (string) $b['property']['slug'], true );
		$this->assertSame( 200, $response['status'] );
		$this->assertStringContainsString( '&quot;manageOnly&quot;:true', $response['body'] );
	}

	public function test_height_script_and_code_to_paste(): void {
		$script = (string) file_get_contents( BKNG_DIR . 'assets/embed/embed.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'bkng:height', $script );
		$this->assertStringContainsString( 'event.origin !== origin', $script );

		$b       = $this->customer( 'Hotel Bianchi', 'www.hotel-bianchi.it' );
		$payload = PropertyService::payload( $b['property'] );
		$this->assertTrue( $payload['embed']['enabled'] );
		$this->assertSame( home_url( '/booking/' . $b['property']['slug'] . '/' ), $payload['embed']['url'] );
		$this->assertSame( array( 'www.hotel-bianchi.it' ), $payload['embed']['domains'] );
		$this->assertStringContainsString( '<iframe src="' . home_url( '/booking/' . $b['property']['slug'] . '/' ) . '"', $payload['embed']['snippet'] );
		$this->assertStringContainsString( '<script src="' . BKNG_URL . 'assets/embed/embed.js" async></script>', $payload['embed']['snippet'] );

		update_option( 'permalink_structure', '' );
		$this->assertSame( add_query_arg( EmbedPage::ARG, 'villa', home_url( '/' ) ), EmbedPage::url( 'villa' ) );
	}
}
