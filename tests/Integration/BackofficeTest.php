<?php
/**
 * The site's Backoffice (Custom Backoffice Manager): who may open the Booking Manager
 * card, and where «Cambia Gestionale» leads.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests\Integration;

use BookingManager\Admin\Backoffice;
use BookingManager\Tests\TestCase;

final class BackofficeTest extends TestCase {

	/** @param array<string, mixed> $result */
	private static function ask( int $user, string $manager, string $reason ): bool {
		return (bool) apply_filters( 'cbm_user_can_access_manager', false, $user, $manager, array( 'reason' => $reason ) );
	}

	public function test_booking_manager_users_open_the_card_when_no_role_is_chosen(): void {
		$staff = self::factory()->user->create( array( 'role' => 'bkng_admin' ) );
		$desk  = self::factory()->user->create( array( 'role' => 'bkng_front_desk' ) );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertTrue( self::ask( $staff, 'booking', 'no_role' ) );
		$this->assertTrue( self::ask( $desk, 'booking', 'members_missing' ) );
		$this->assertFalse( self::ask( $other, 'booking', 'no_role' ), 'without bkng_access nobody enters' );
		$this->assertFalse( self::ask( $staff, 'beach', 'no_role' ), 'other gestionali are not ours' );
		$this->assertFalse( self::ask( $staff, 'booking', 'inactive' ) );
		$this->assertFalse( self::ask( $staff, 'booking', 'missing_caps' ) );
		$this->assertTrue( (bool) apply_filters( 'cbm_user_can_access_manager', true, $other, 'booking', array( 'reason' => 'ok' ) ), 'a yes stays a yes' );
	}

	public function test_roles_chosen_in_the_backoffice_are_respected(): void {
		$this->assertTrue( Backoffice::decide( 'no_role', false, true ) );
		$this->assertFalse( Backoffice::decide( 'no_role', true, true ) );
		$this->assertTrue( Backoffice::decide( 'members_missing', true, true ) );
		$this->assertFalse( Backoffice::decide( 'members_missing', false, false ) );
		$this->assertFalse( Backoffice::decide( 'not_installed', false, true ) );
	}

	public function test_switch_leads_home_without_the_backoffice(): void {
		$this->assertSame( home_url( '/' ), Backoffice::switch_url() );
	}
}
