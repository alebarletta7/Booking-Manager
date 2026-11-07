<?php
/**
 * What a customer (tenant) may do now, from its status, its expiry and its plan: the
 * customer's counterpart of LicenseGate (which stays the platform's licence).
 *
 * | status                      | effect |
 * |-----------------------------|--------|
 * | active, trial (not expired) | everything, within the plan's limits |
 * | suspended, expired          | no new bookings (staff app, public form, holds) and no new properties, room types or rooms; everything else keeps working: reading, changes, cancellations, check-in/out, payments and refunds, privacy requests, the guest's page, messages about existing bookings |
 * | archived                    | the customer's staff sees nothing (PropertyScope), the public pages are gone; data kept, visible to the platform |
 *
 * Reservations from the portals (channel import) are always saved, with an entry in the
 * activity log: refusing them would not stop the guest from arriving (decision D3).
 * The expiry is checked at every call (expires_at against the server's clock) and once a
 * day by cron, which also writes the status and the log.
 *
 * Services ask the gate (BookingService, InventoryService, PropertyService,
 * PublicBookingService), so no path around the REST API skips it. The platform
 * administrator is bound by the status for bookings, but not by limits and status for
 * properties and rooms: the platform sets them up for its customers.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

use BookingManager\Access\PropertyScope;
use BookingManager\Audit\AuditLogger;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class TenantGate {

	public const ERROR_CODE = 'bkng_tenant_inactive';

	public const LIMIT_CODE = 'bkng_plan_limit';

	public function __construct(
		private TenantRepository $tenants,
		private Plans $plans,
		private PropertyScope $scope,
		private ?AuditLogger $audit = null
	) {}

	public function hooks(): void {
		add_action( \BookingManager\Core\Installer::DAILY_HOOK, array( $this, 'expire_due' ) );
	}

	/**
	 * The status that counts now: active or trial past expires_at count as expired.
	 *
	 * @param array<string, mixed> $tenant
	 */
	public static function state( array $tenant, ?int $now = null ): string {
		$status = (string) ( $tenant['status'] ?? TenantStatus::ACTIVE );
		if ( in_array( $status, TenantStatus::CAN_SELL, true ) && ! empty( $tenant['expires_at'] ) ) {
			$expires = strtotime( (string) $tenant['expires_at'] . ' UTC' );
			if ( false !== $expires && $expires <= ( $now ?? time() ) ) {
				return TenantStatus::EXPIRED;
			}
		}
		return $status;
	}

	/** Whether a customer may take new bookings and add rooms. Before the migration: yes. */
	public function sells( int $tenant_id ): bool {
		if ( ! Tenancy::migrated() || $tenant_id <= 0 ) {
			return true;
		}
		$tenant = $this->tenants->find( $tenant_id );
		return null === $tenant || in_array( self::state( $tenant ), TenantStatus::CAN_SELL, true );
	}

	public function archived( int $tenant_id ): bool {
		if ( ! Tenancy::migrated() || $tenant_id <= 0 ) {
			return false;
		}
		$tenant = $this->tenants->find( $tenant_id );
		return null !== $tenant && TenantStatus::ARCHIVED === $tenant['status'];
	}

	/**
	 * A new booking for a property: null when allowed. Reservations from the portals
	 * (import) always pass and are logged.
	 *
	 * @param array<string, mixed> $property
	 */
	public function booking( array $property, string $channel ): ?\WP_Error {
		$tenant = Tenancy::of_property( (int) $property['id'] );
		if ( $this->sells( $tenant ) ) {
			return null;
		}
		if ( 'import' === $channel ) {
			return null;
		}
		return 'online' === $channel
			? new \WP_Error( self::ERROR_CODE, __( 'Le prenotazioni online non sono disponibili in questo momento. Contatta la struttura.', 'booking-manager' ), array( 'status' => 403 ) )
			: new \WP_Error( self::ERROR_CODE, $this->inactive_text( $tenant ), array( 'status' => 403 ) );
	}

	/** Activity log entry for a portal reservation saved while the customer cannot sell. */
	public function note_import( int $property_id, int $booking_id ): void {
		$tenant = Tenancy::of_property( $property_id );
		if ( $this->sells( $tenant ) || ! $this->audit ) {
			return;
		}
		$this->audit->log(
			array(
				'action'      => 'tenant.booking_while_inactive',
				'entity_type' => 'booking',
				'entity_id'   => $booking_id,
				'property_id' => $property_id,
				'new'         => array( 'status' => $this->current_state( $tenant ) ),
			)
		);
	}

	/** New room types or rooms ($new_units rooms) in a property: null when allowed. */
	public function rooms( int $property_id, int $new_units = 0 ): ?\WP_Error {
		if ( ! Tenancy::migrated() || $this->scope->is_platform( get_current_user_id() ) ) {
			return null;
		}
		$tenant = Tenancy::of_property( $property_id );
		if ( ! $this->sells( $tenant ) ) {
			return new \WP_Error( self::ERROR_CODE, $this->inactive_text( $tenant ), array( 'status' => 403 ) );
		}
		if ( $new_units > 0 ) {
			return $this->within( $tenant, 'max_units', $new_units );
		}
		return null;
	}

	/** A new property for a customer: null when allowed. */
	public function property( int $tenant_id ): ?\WP_Error {
		if ( ! Tenancy::migrated() || $tenant_id <= 0 || $this->scope->is_platform( get_current_user_id() ) ) {
			return null;
		}
		if ( ! $this->sells( $tenant_id ) ) {
			return new \WP_Error( self::ERROR_CODE, $this->inactive_text( $tenant_id ), array( 'status' => 403 ) );
		}
		return $this->within( $tenant_id, 'max_properties', 1 );
	}

	/**
	 * Properties and rooms in use (archived ones do not count).
	 *
	 * @return array{max_properties: int, max_units: int}
	 */
	public function usage( int $tenant_id ): array {
		global $wpdb;
		$properties = Schema::table( 'properties' );
		$units      = Schema::table( 'units' );
		return array(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
			'max_properties' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$properties} WHERE tenant_id = %d AND status <> 'archived'", $tenant_id ) ),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
			'max_units'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$units} u JOIN {$properties} p ON p.id = u.property_id WHERE p.tenant_id = %d AND p.status <> 'archived' AND u.status <> 'archived'", $tenant_id ) ),
		);
	}

	/** Status now (expiry included) of a customer, '' when unknown. */
	public function current_state( int $tenant_id ): string {
		$tenant = $tenant_id > 0 ? $this->tenants->find( $tenant_id ) : null;
		return $tenant ? self::state( $tenant ) : '';
	}

	/**
	 * Daily: customers past their expiry become expired (with a log entry).
	 *
	 * @return int how many
	 */
	public function expire_due(): int {
		if ( ! Tenancy::migrated() ) {
			return 0;
		}
		$count = 0;
		foreach ( $this->tenants->all() as $tenant ) {
			if ( TenantStatus::EXPIRED !== $tenant['status'] && TenantStatus::EXPIRED === self::state( $tenant ) ) {
				$this->tenants->update( (int) $tenant['id'], array( 'status' => TenantStatus::EXPIRED ) );
				if ( $this->audit ) {
					$this->audit->log(
						array(
							'action'      => 'tenant.expired',
							'entity_type' => 'tenant',
							'entity_id'   => (int) $tenant['id'],
							'old'         => array( 'status' => $tenant['status'] ),
							'new'         => array(
								'status'     => TenantStatus::EXPIRED,
								'expires_at' => $tenant['expires_at'],
							),
						)
					);
				}
				++$count;
			}
		}
		return $count;
	}

	private function within( int $tenant_id, string $limit, int $adding ): ?\WP_Error {
		$tenant = $this->tenants->find( $tenant_id );
		if ( ! $tenant ) {
			return null;
		}
		$max = $this->plans->limits_of( $tenant )[ $limit ] ?? 0;
		if ( $max <= 0 ) {
			return null;
		}
		$used = $this->usage( $tenant_id )[ $limit ];
		if ( $used + $adding <= $max ) {
			return null;
		}
		$message = 'max_properties' === $limit
			/* translators: %d: maximum number of properties */
			? sprintf( _n( 'Il tuo piano permette %d struttura: per aggiungerne altre contatta la piattaforma.', 'Il tuo piano permette %d strutture: per aggiungerne altre contatta la piattaforma.', $max, 'booking-manager' ), $max )
			/* translators: 1: maximum number of rooms, 2: rooms already in use */
			: sprintf( __( 'Il tuo piano permette %1$d camere e ne usi già %2$d: per aggiungerne altre contatta la piattaforma.', 'booking-manager' ), $max, $used );
		return new \WP_Error( self::LIMIT_CODE, $message, array( 'status' => 403 ) );
	}

	private function inactive_text( int $tenant_id ): string {
		return TenantStatus::EXPIRED === $this->current_state( $tenant_id )
			? __( 'Il tuo abbonamento è scaduto: non si possono registrare nuove prenotazioni né aggiungere camere. Le prenotazioni esistenti restano gestibili. Contatta la piattaforma per rinnovarlo.', 'booking-manager' )
			: __( 'Il tuo account è sospeso: non si possono registrare nuove prenotazioni né aggiungere camere. Le prenotazioni esistenti restano gestibili. Contatta la piattaforma.', 'booking-manager' );
	}
}
