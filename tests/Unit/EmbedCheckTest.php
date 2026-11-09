<?php
/**
 * The «Verifica incorporamento» diagnosis: which headers stop a customer's site
 * from showing the form.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Unit;

use BookingManager\Tenants\EmbedCheck;
use BookingManager\Tenants\Domains;
use PHPUnit\Framework\TestCase;

final class EmbedCheckTest extends TestCase {

	private const OURS = "frame-ancestors 'self' hotel.it www.hotel.it";

	public function test_the_plugin_headers_alone_pass(): void {
		$this->assertSame( array(), EmbedCheck::diagnose( 200, 'active', array(), array( self::OURS ), array( 'hotel.it' ) ) );
	}

	public function test_server_x_frame_options_is_reported(): void {
		$problems = EmbedCheck::diagnose( 200, 'active', array( 'SAMEORIGIN' ), array( self::OURS ), array( 'hotel.it' ) );
		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'X-Frame-Options: SAMEORIGIN', $problems[0] );
	}

	public function test_a_second_frame_ancestors_is_reported(): void {
		$problems = EmbedCheck::diagnose( 200, 'active', array(), array( self::OURS, "default-src 'self'; frame-ancestors 'self'" ), array( 'hotel.it' ) );
		$this->assertCount( 2, $problems );
		$this->assertStringContainsString( 'più di una regola', $problems[0] );
		$this->assertStringContainsString( 'hotel.it', $problems[1] );
	}

	public function test_no_domain_and_inactive_property_are_reported(): void {
		$this->assertCount( 1, EmbedCheck::diagnose( 200, 'active', array(), array( "frame-ancestors 'self'" ), array() ) );
		$this->assertStringContainsString( 'non è attiva', EmbedCheck::diagnose( 404, 'inactive', array(), array( self::OURS ), array( 'hotel.it' ) )[0] );
		$this->assertStringContainsString( '500', EmbedCheck::diagnose( 500, 'active', array(), array( self::OURS ), array( 'hotel.it' ) )[0] );
	}

	public function test_wildcards_and_schemes_in_other_policies_are_understood(): void {
		$this->assertSame( array(), EmbedCheck::diagnose( 200, 'active', array(), array( 'frame-ancestors https://hotel.it' ), array( 'hotel.it' ) ) );
		$this->assertSame( array(), EmbedCheck::diagnose( 200, 'active', array(), array( 'frame-ancestors *.hotel.it' ), array( 'www.hotel.it' ) ) );
	}

	public function test_www_counterpart(): void {
		$this->assertSame( 'www.hotel.it', Domains::counterpart( 'hotel.it' ) );
		$this->assertSame( 'hotel.it', Domains::counterpart( 'www.hotel.it' ) );
		$this->assertSame( '', Domains::counterpart( 'www.it' ) );
	}
}
