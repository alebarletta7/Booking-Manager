<?php
/**
 * Operational status of a room (housekeeping), separate from its sale: a dirty room is
 * still sellable, a room out of service is closed with a block in the planning.
 *
 * dirty → cleaning → clean → ready (inspected), and maintenance for a small fault that
 * does not close the room. Any change is allowed: the staff knows the room better than a
 * state machine, and every change is written to unit_status_log.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Housekeeping;

defined( 'ABSPATH' ) || exit;

final class OpStatus {

	public const DIRTY       = 'dirty';
	public const CLEANING    = 'cleaning';
	public const CLEAN       = 'clean';
	public const READY       = 'ready';
	public const MAINTENANCE = 'maintenance';

	/** @return array<string, string> */
	public static function labels(): array {
		return array(
			self::DIRTY       => __( 'Da pulire', 'booking-manager' ),
			self::CLEANING    => __( 'In pulizia', 'booking-manager' ),
			self::CLEAN       => __( 'Pulita, da controllare', 'booking-manager' ),
			self::READY       => __( 'Pronta', 'booking-manager' ),
			self::MAINTENANCE => __( 'Manutenzione', 'booking-manager' ),
		);
	}

	public static function exists( string $status ): bool {
		return isset( self::labels()[ $status ] );
	}

	public static function label( string $status ): string {
		return self::labels()[ $status ] ?? $status;
	}

	/** A guest can be given the room (the check-in is refused otherwise, when the property asks so). */
	public static function guest_ready( string $status ): bool {
		return in_array( $status, array( self::CLEAN, self::READY ), true );
	}
}
