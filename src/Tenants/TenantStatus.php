<?php
/**
 * States of a customer (tenant) of the platform.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

defined( 'ABSPATH' ) || exit;

final class TenantStatus {

	public const ACTIVE    = 'active';
	public const TRIAL     = 'trial';
	public const SUSPENDED = 'suspended';
	public const EXPIRED   = 'expired';
	public const ARCHIVED  = 'archived';

	public const ALL = array( self::ACTIVE, self::TRIAL, self::SUSPENDED, self::EXPIRED, self::ARCHIVED );

	/** States in which new bookings and new rooms are allowed. */
	public const CAN_SELL = array( self::ACTIVE, self::TRIAL );

	/** @return array<string, string> */
	public static function labels(): array {
		return array(
			self::ACTIVE    => __( 'Attivo', 'booking-manager' ),
			self::TRIAL     => __( 'In prova', 'booking-manager' ),
			self::SUSPENDED => __( 'Sospeso', 'booking-manager' ),
			self::EXPIRED   => __( 'Scaduto', 'booking-manager' ),
			self::ARCHIVED  => __( 'Archiviato', 'booking-manager' ),
		);
	}
}
