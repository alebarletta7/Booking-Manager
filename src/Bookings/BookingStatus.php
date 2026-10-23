<?php
/**
 * Commercial status of a booking and the transitions allowed (pure, unit-tested).
 *
 * The commercial status is separate from the payment status (PaymentStatus) and from the
 * stay of each room (StayStatus: expected, in house, departed): a confirmed booking can
 * be unpaid, a paid one can still be pending.
 *
 * pending   → option, confirmed, cancelled
 * option    → confirmed, cancelled (also automatically when the option expires)
 * confirmed → in_house, cancelled, no_show, pending
 * in_house  → completed, confirmed (check-in undone)
 * no_show   → confirmed (marked by mistake: rooms are checked again)
 * completed, cancelled: final
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Bookings;

defined( 'ABSPATH' ) || exit;

final class BookingStatus {

	public const PENDING   = 'pending';
	public const OPTION    = 'option';
	public const CONFIRMED = 'confirmed';
	public const IN_HOUSE  = 'in_house';
	public const COMPLETED = 'completed';
	public const CANCELLED = 'cancelled';
	public const NO_SHOW   = 'no_show';

	private const TRANSITIONS = array(
		self::PENDING   => array( self::OPTION, self::CONFIRMED, self::CANCELLED ),
		self::OPTION    => array( self::CONFIRMED, self::CANCELLED ),
		self::CONFIRMED => array( self::IN_HOUSE, self::CANCELLED, self::NO_SHOW, self::PENDING ),
		self::IN_HOUSE  => array( self::COMPLETED, self::CONFIRMED ),
		self::NO_SHOW   => array( self::CONFIRMED ),
		self::COMPLETED => array(),
		self::CANCELLED => array(),
	);

	/** @return string[] */
	public static function all(): array {
		return array_keys( self::TRANSITIONS );
	}

	public static function exists( string $status ): bool {
		return isset( self::TRANSITIONS[ $status ] );
	}

	public static function can( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	/** @return string[] */
	public static function next( string $from ): array {
		return self::TRANSITIONS[ $from ] ?? array();
	}

	/** Statuses that keep rooms out of sale. */
	public static function occupies( string $status ): bool {
		return in_array( $status, array( self::PENDING, self::OPTION, self::CONFIRMED, self::IN_HOUSE ), true );
	}

	/** Statuses where the stay can still be changed (dates, rooms, guests). */
	public static function editable( string $status ): bool {
		return in_array( $status, array( self::PENDING, self::OPTION, self::CONFIRMED, self::IN_HOUSE ), true );
	}

	/** Statuses a booking can be created with. */
	public static function initial( string $status ): bool {
		return in_array( $status, array( self::PENDING, self::OPTION, self::CONFIRMED ), true );
	}

	/** @return array<string, string> */
	public static function labels(): array {
		return array(
			self::PENDING   => __( 'In attesa', 'booking-manager' ),
			self::OPTION    => __( 'Opzione', 'booking-manager' ),
			self::CONFIRMED => __( 'Confermata', 'booking-manager' ),
			self::IN_HOUSE  => __( 'In soggiorno', 'booking-manager' ),
			self::COMPLETED => __( 'Conclusa', 'booking-manager' ),
			self::CANCELLED => __( 'Annullata', 'booking-manager' ),
			self::NO_SHOW   => __( 'No-show', 'booking-manager' ),
		);
	}
}
