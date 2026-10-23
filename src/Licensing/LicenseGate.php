<?php
/**
 * The single point where Booking Manager asks the licence system what it may do.
 *
 * The implementation is ServerGate, backed by LicenseManager (the licence server shared
 * with Night, Beach and Restaurant Manager, product "booking-manager"). Bookings and
 * room management only talk to this interface; the REST API is guarded by WriteGate
 * as well. See docs/LICENZA.md.
 *
 * Operations that never pass through the gate: reading data, check-in and check-out of
 * guests already booked, payments of existing bookings, cancellations, guest privacy
 * requests. A guest in the house must never be stopped by the licence.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

defined( 'ABSPATH' ) || exit;

interface LicenseGate {

	/** New booking from the staff app. */
	public const OP_BOOKING_STAFF = 'booking.create.staff';

	/** New booking from the public form. */
	public const OP_BOOKING_ONLINE = 'booking.create.online';

	/** Creating, changing or archiving room types and rooms. */
	public const OP_ROOMS_MANAGE = 'rooms.manage';

	/** Sending messages to guests (e-mail, WhatsApp). */
	public const OP_NOTIFICATIONS = 'notifications.send';

	/** Machine-readable state, e.g. "not_connected", "active", "expired". */
	public function status(): string;

	/** Whether an operation (one of the OP_* constants) is allowed now. */
	public function allows( string $operation ): bool;

	/**
	 * What the app shows about the licence.
	 *
	 * @return array{status: string, label: string, message: string, product: string, connected: bool}
	 */
	public function describe(): array;
}
