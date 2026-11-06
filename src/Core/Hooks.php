<?php
/**
 * Public hook names.
 *
 * Integrations (Night, Beach and Restaurant Manager, a channel manager, a future unified
 * dashboard, custom code)
 * should rely ONLY on these hooks, on the public PHP entry point bkng() and on the REST
 * API, never on our tables. Every name here is part of the public contract: renaming
 * one is a breaking change (see docs/HOOKS.md).
 *
 * Every domain action receives the entity id first, then a context array without
 * personal data (property_id, property_uuid, booking_uuid, status, source, external
 * references).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class Hooks {

	// Lifecycle.
	public const LOADED   = 'bkng_loaded';
	public const MIGRATED = 'bkng_migrated';

	// Properties.
	public const PROPERTY_CREATED  = 'bkng_property_created';
	public const PROPERTY_ARCHIVED = 'bkng_property_archived';

	// Bookings.
	public const BOOKING_CREATED    = 'bkng_booking_created';
	public const BOOKING_CONFIRMED  = 'bkng_booking_confirmed';
	public const BOOKING_UPDATED    = 'bkng_booking_updated';
	public const BOOKING_CANCELLED  = 'bkng_booking_cancelled';
	public const BOOKING_CHECKED_IN = 'bkng_booking_checked_in';
	public const BOOKING_NO_SHOW    = 'bkng_booking_no_show';
	public const BOOKING_COMPLETED  = 'bkng_booking_completed';

	// Stays (one room of a booking).
	public const ROOM_CHECKED_IN  = 'bkng_room_checked_in';
	public const ROOM_CHECKED_OUT = 'bkng_room_checked_out';

	// Payments.
	public const PAYMENT_RECORDED = 'bkng_payment_recorded';
	public const PAYMENT_REFUNDED = 'bkng_payment_refunded';
	public const PAYMENT_FAILED   = 'bkng_payment_failed';

	// Rooms (housekeeping).
	public const UNIT_STATUS_CHANGED = 'bkng_unit_status_changed';

	// Notifications.
	public const NOTIFICATION_SENT   = 'bkng_notification_sent';
	public const NOTIFICATION_FAILED = 'bkng_notification_failed';

	// What a channel manager must send again (entity id: the property). Context:
	// room_type_ids (empty: every type), rate_plan_ids (rates only; empty: every plan),
	// from / to (Y-m-d, [from, to); null: from today on, for as far as channels sell).
	public const AVAILABILITY_CHANGED = 'bkng_availability_changed';
	public const RATES_CHANGED        = 'bkng_rates_changed';

	/**
	 * Availability of some room types changed (rooms added or archived, out-of-service
	 * periods, holds of the public form). Bookings have their own hooks above.
	 *
	 * @param int[] $type_ids
	 */
	public static function availability_changed( int $property_id, array $type_ids, ?string $from = null, ?string $to = null ): void {
		self::dispatch(
			self::AVAILABILITY_CHANGED,
			$property_id,
			array(
				'room_type_ids' => array_values( array_unique( array_map( 'intval', $type_ids ) ) ),
				'from'          => $from,
				'to'            => $to,
			)
		);
	}

	/**
	 * Prices or stay rules changed for some types and plans.
	 *
	 * @param int[] $type_ids
	 * @param int[] $plan_ids
	 */
	public static function rates_changed( int $property_id, array $type_ids = array(), array $plan_ids = array(), ?string $from = null, ?string $to = null ): void {
		self::dispatch(
			self::RATES_CHANGED,
			$property_id,
			array(
				'room_type_ids' => array_values( array_unique( array_map( 'intval', $type_ids ) ) ),
				'rate_plan_ids' => array_values( array_unique( array_map( 'intval', $plan_ids ) ) ),
				'from'          => $from,
				'to'            => $to,
			)
		);
	}

	/**
	 * Fires one of the actions above. A listener that throws never breaks the operation
	 * that already happened (the booking is saved before any hook runs).
	 *
	 * @param array<string, mixed> $context
	 */
	public static function dispatch( string $hook, int $entity_id, array $context = array() ): void {
		try {
			do_action( $hook, $entity_id, $context ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $hook is one of the bkng_* constants above.
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Booking Manager: listener of ' . $hook . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only, no personal data.
			}
		}
	}
}
