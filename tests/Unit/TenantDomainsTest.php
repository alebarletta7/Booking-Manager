<?php
/**
 * Authorised domains: only bare public host names are accepted.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Unit;

use BookingManager\Tenants\Domains;
use PHPUnit\Framework\TestCase;

final class TenantDomainsTest extends TestCase {

	/** @return array<string, array{0: string, 1: ?string}> */
	public static function hosts(): array {
		return array(
			'bare'            => array( 'hotel-esempio.it', 'hotel-esempio.it' ),
			'www upper case'  => array( 'WWW.Hotel-Esempio.IT', 'www.hotel-esempio.it' ),
			'subdomain'       => array( 'booking.hotel-esempio.it', 'booking.hotel-esempio.it' ),
			'https url'       => array( 'https://www.hotel-esempio.it/', 'www.hotel-esempio.it' ),
			'trailing dot'    => array( 'hotel-esempio.it.', 'hotel-esempio.it' ),
			'idn'             => array( 'caffè.it', 'xn--caff-8oa.it' ),
			'path'            => array( 'https://hotel-esempio.it/prenota', null ),
			'port'            => array( 'https://hotel-esempio.it:8443', null ),
			'user'            => array( 'https://u@hotel-esempio.it', null ),
			'other scheme'    => array( 'javascript://hotel-esempio.it', null ),
			'wildcard'        => array( '*.hotel-esempio.it', null ),
			'ip'              => array( '192.168.1.10', null ),
			'localhost'       => array( 'localhost', null ),
			'local tld'       => array( 'hotel.local', null ),
			'no dot'          => array( 'hotel', null ),
			'empty'           => array( '   ', null ),
			'spaces'          => array( 'hotel esempio.it', null ),
			'label dash'      => array( '-hotel.it', null ),
		);
	}

	/** @dataProvider hosts */
	public function test_normalize( string $input, ?string $expected ): void {
		if ( 'xn--caff-8oa.it' === $expected && ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'intl non disponibile' );
		}
		$this->assertSame( $expected, Domains::normalize( $input ) );
	}
}
