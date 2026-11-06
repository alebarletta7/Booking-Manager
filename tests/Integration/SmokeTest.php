<?php
/**
 * The plugin boots, creates its tables and roles.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Database\Migrator;
use BookingManager\Database\Schema;
use BookingManager\Tests\TestCase;

final class SmokeTest extends TestCase {

	public function test_every_table_exists(): void {
		$this->assertSame( array(), ( new Migrator() )->missing_tables() );
		$this->assertSame( Schema::VERSION, get_option( Schema::DB_VERSION_OPTION ) );
	}

	public function test_roles_are_created(): void {
		foreach ( array( 'bkng_housekeeping', 'bkng_front_desk', 'bkng_reservations', 'bkng_property_manager', 'bkng_admin' ) as $role ) {
			$this->assertNotNull( get_role( $role ), $role );
		}
		$this->assertTrue( get_role( 'bkng_housekeeping' )->has_cap( 'bkng_update_housekeeping' ) );
		$this->assertFalse( get_role( 'bkng_housekeeping' )->has_cap( 'bkng_view_bookings' ) );
	}
}
