<?php
/**
 * LicenseGate backed by the licence server (LicenseManager).
 *
 * - New bookings (staff and online) and room management need a licence that allows
 *   writing and a site that is not locked: the same rule WriteGate applies to the REST
 *   API, repeated here so that no other path (WP-CLI, an integration calling the
 *   services) can create a booking or change the rooms.
 * - Notifications are never stopped by the licence: confirmations, reminders and
 *   cancellations of bookings already taken must reach the customers.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

defined( 'ABSPATH' ) || exit;

final class ServerGate implements LicenseGate {

	public function __construct( private LicenseManager $license ) {}

	public function status(): string {
		return $this->license->get_status();
	}

	public function allows( string $operation ): bool {
		if ( self::OP_NOTIFICATIONS === $operation ) {
			return true;
		}
		return ! $this->license->is_locked() && $this->license->can_write();
	}

	public function describe(): array {
		return array(
			'status'    => $this->status(),
			'label'     => AccessMatrix::label( $this->status() ),
			'message'   => $this->license->read_only_reason(),
			'product'   => LicenseManager::PRODUCT,
			'connected' => '' !== $this->license->activation_id(),
		);
	}
}
