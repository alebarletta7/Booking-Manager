<?php
/**
 * Stay of one room of a booking, separate from the booking's commercial status:
 *
 * expected → in_house (check-in) → departed (check-out)
 * in_house → expected (check-in undone)
 * removed: the room was taken out of the booking (change of stay), kept for history.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Bookings;

defined( 'ABSPATH' ) || exit;

final class StayStatus {

	public const EXPECTED = 'expected';
	public const IN_HOUSE = 'in_house';
	public const DEPARTED = 'departed';
	public const REMOVED  = 'removed';

	/** @return array<string, string> */
	public static function labels(): array {
		return array(
			self::EXPECTED => __( 'In arrivo', 'booking-manager' ),
			self::IN_HOUSE => __( 'In soggiorno', 'booking-manager' ),
			self::DEPARTED => __( 'Partito', 'booking-manager' ),
			self::REMOVED  => __( 'Rimossa', 'booking-manager' ),
		);
	}

	public static function label( string $status ): string {
		return self::labels()[ $status ] ?? $status;
	}
}
