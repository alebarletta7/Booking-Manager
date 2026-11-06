<?php
/**
 * Single registry of every Booking Manager capability.
 *
 * Code ALWAYS checks capabilities (current_user_can('bkng_…')), never role names.
 * Roles are just editable bundles of capabilities.
 *
 * Booking Manager capabilities are never granted because of another product's role:
 * a Night, Beach or Restaurant Manager operator gets nothing here unless given a
 * Booking Manager role.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Access;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const PREFIX = 'bkng_';

	public const ROLE_HOUSEKEEPING = 'bkng_housekeeping';
	public const ROLE_FRONTDESK    = 'bkng_front_desk';
	public const ROLE_RESERVATIONS = 'bkng_reservations';
	public const ROLE_MANAGER      = 'bkng_property_manager';
	public const ROLE_ADMIN        = 'bkng_admin';

	/** Short role keys used in the default matrix. */
	private const H = 'housekeeping';
	private const F = 'frontdesk';
	private const P = 'reservations';
	private const M = 'manager';
	private const A = 'admin';

	/**
	 * Capability => [group, default roles]. Labels are in labels() so they can be translated.
	 *
	 * @return array<string, array{group: string, roles: string[]}>
	 */
	public static function definitions(): array {
		$all  = array( self::H, self::F, self::P, self::M, self::A );
		$fpma = array( self::F, self::P, self::M, self::A );
		$pma  = array( self::P, self::M, self::A );
		$ma   = array( self::M, self::A );
		$a    = array( self::A );

		return array(
			// Access & dashboard.
			'bkng_access'                   => array( 'group' => 'access', 'roles' => $all ),
			'bkng_view_dashboard'           => array( 'group' => 'access', 'roles' => $all ),
			// Bookings.
			'bkng_view_bookings'            => array( 'group' => 'bookings', 'roles' => $fpma ),
			'bkng_create_booking'           => array( 'group' => 'bookings', 'roles' => $fpma ),
			'bkng_edit_booking'             => array( 'group' => 'bookings', 'roles' => $fpma ),
			'bkng_confirm_booking'          => array( 'group' => 'bookings', 'roles' => $pma ),
			'bkng_cancel_booking'           => array( 'group' => 'bookings', 'roles' => $pma ),
			'bkng_mark_no_show'             => array( 'group' => 'bookings', 'roles' => $fpma ),
			'bkng_override_restrictions'    => array( 'group' => 'bookings', 'roles' => $ma ),
			// Front desk.
			'bkng_checkin'                  => array( 'group' => 'frontdesk', 'roles' => $fpma ),
			'bkng_view_payments'            => array( 'group' => 'frontdesk', 'roles' => $fpma ),
			'bkng_record_payment'           => array( 'group' => 'frontdesk', 'roles' => $fpma ),
			'bkng_refund_payment'           => array( 'group' => 'frontdesk', 'roles' => $ma ),
			// Housekeeping.
			'bkng_view_housekeeping'        => array( 'group' => 'housekeeping', 'roles' => $all ),
			'bkng_update_housekeeping'      => array( 'group' => 'housekeeping', 'roles' => $all ),
			// Guests.
			'bkng_view_guests'              => array( 'group' => 'guests', 'roles' => $fpma ),
			'bkng_edit_guest'               => array( 'group' => 'guests', 'roles' => $pma ),
			'bkng_export_guest_data'        => array( 'group' => 'guests', 'roles' => $a ),
			'bkng_anonymize_guest'          => array( 'group' => 'guests', 'roles' => $a ),
			// Reports & control.
			'bkng_view_reports'             => array( 'group' => 'reports', 'roles' => $ma ),
			'bkng_export_reports'           => array( 'group' => 'reports', 'roles' => $ma ),
			'bkng_view_audit_log'           => array( 'group' => 'reports', 'roles' => $a ),
			// Property configuration.
			'bkng_manage_rooms'             => array( 'group' => 'property', 'roles' => $ma ),
			'bkng_manage_rates'             => array( 'group' => 'property', 'roles' => $ma ),
			'bkng_manage_property_settings' => array( 'group' => 'property', 'roles' => $ma ),
			'bkng_manage_payment_accounts'  => array( 'group' => 'property', 'roles' => $ma ),
			// Administration.
			'bkng_manage_properties'        => array( 'group' => 'config', 'roles' => $a ),
			'bkng_access_all_properties'    => array( 'group' => 'config', 'roles' => $a ),
			'bkng_manage_staff'             => array( 'group' => 'config', 'roles' => $a ),
			'bkng_manage_roles'             => array( 'group' => 'config', 'roles' => $a ),
			'bkng_manage_settings'          => array( 'group' => 'config', 'roles' => $a ),
			'bkng_manage_integrations'      => array( 'group' => 'config', 'roles' => $a ),
		);
	}

	/** @return string[] */
	public static function all(): array {
		return array_keys( self::definitions() );
	}

	public static function exists( string $cap ): bool {
		return isset( self::definitions()[ $cap ] );
	}

	/**
	 * Default capability list for one of our preset roles.
	 *
	 * @return string[]
	 */
	public static function defaults_for( string $role_slug ): array {
		$key = array(
			self::ROLE_HOUSEKEEPING => self::H,
			self::ROLE_FRONTDESK    => self::F,
			self::ROLE_RESERVATIONS => self::P,
			self::ROLE_MANAGER      => self::M,
			self::ROLE_ADMIN        => self::A,
		)[ $role_slug ] ?? null;
		if ( null === $key ) {
			return array();
		}
		$caps = array();
		foreach ( self::definitions() as $cap => $def ) {
			if ( in_array( $key, $def['roles'], true ) ) {
				$caps[] = $cap;
			}
		}
		return $caps;
	}

	/** @return array<string, string> */
	public static function groups(): array {
		return array(
			'access'       => __( 'Accesso e dashboard', 'booking-manager' ),
			'bookings'     => __( 'Prenotazioni', 'booking-manager' ),
			'frontdesk'    => __( 'Reception e pagamenti', 'booking-manager' ),
			'housekeeping' => __( 'Housekeeping', 'booking-manager' ),
			'guests'       => __( 'Ospiti', 'booking-manager' ),
			'reports'      => __( 'Report e controllo', 'booking-manager' ),
			'property'     => __( 'Configurazione della struttura', 'booking-manager' ),
			'config'       => __( 'Amministrazione', 'booking-manager' ),
		);
	}

	/** @return array<string, string> */
	public static function labels(): array {
		return array(
			'bkng_access'                   => __( 'Accedere al gestionale', 'booking-manager' ),
			'bkng_view_dashboard'           => __( 'Vedere la dashboard', 'booking-manager' ),
			'bkng_view_bookings'            => __( 'Vedere prenotazioni, calendario e planning', 'booking-manager' ),
			'bkng_create_booking'           => __( 'Creare prenotazioni', 'booking-manager' ),
			'bkng_edit_booking'             => __( 'Modificare e spostare prenotazioni', 'booking-manager' ),
			'bkng_confirm_booking'          => __( 'Confermare prenotazioni in attesa', 'booking-manager' ),
			'bkng_cancel_booking'           => __( 'Annullare prenotazioni', 'booking-manager' ),
			'bkng_mark_no_show'             => __( 'Segnare un no-show', 'booking-manager' ),
			'bkng_override_restrictions'    => __( 'Prenotare ignorando soggiorno minimo, anticipo e chiusure', 'booking-manager' ),
			'bkng_checkin'                  => __( 'Registrare check-in e check-out', 'booking-manager' ),
			'bkng_view_payments'            => __( 'Vedere pagamenti e saldi', 'booking-manager' ),
			'bkng_record_payment'           => __( 'Registrare pagamenti e richiedere caparre', 'booking-manager' ),
			'bkng_refund_payment'           => __( 'Registrare ed eseguire rimborsi', 'booking-manager' ),
			'bkng_view_housekeeping'        => __( 'Vedere lo stato delle camere', 'booking-manager' ),
			'bkng_update_housekeeping'      => __( 'Aggiornare lo stato delle camere', 'booking-manager' ),
			'bkng_view_guests'              => __( 'Cercare ospiti e vedere la scheda', 'booking-manager' ),
			'bkng_edit_guest'               => __( 'Modificare i dati degli ospiti', 'booking-manager' ),
			'bkng_export_guest_data'        => __( 'Esportare i dati di un ospite', 'booking-manager' ),
			'bkng_anonymize_guest'          => __( 'Anonimizzare un ospite', 'booking-manager' ),
			'bkng_view_reports'             => __( 'Vedere report e statistiche', 'booking-manager' ),
			'bkng_export_reports'           => __( 'Esportare i report', 'booking-manager' ),
			'bkng_view_audit_log'           => __( 'Consultare il log delle operazioni', 'booking-manager' ),
			'bkng_manage_rooms'             => __( 'Gestire tipologie e camere', 'booking-manager' ),
			'bkng_manage_rates'             => __( 'Gestire tariffe, restrizioni e chiusure', 'booking-manager' ),
			'bkng_manage_property_settings' => __( 'Gestire le impostazioni della struttura', 'booking-manager' ),
			'bkng_manage_payment_accounts'  => __( 'Collegare i conti di pagamento (Stripe, Nexi)', 'booking-manager' ),
			'bkng_manage_properties'        => __( 'Creare e archiviare strutture', 'booking-manager' ),
			'bkng_access_all_properties'    => __( 'Accedere a tutte le strutture', 'booking-manager' ),
			'bkng_manage_staff'             => __( 'Gestire gli operatori', 'booking-manager' ),
			'bkng_manage_roles'             => __( 'Gestire ruoli e permessi', 'booking-manager' ),
			'bkng_manage_settings'          => __( 'Gestire le impostazioni generali', 'booking-manager' ),
			'bkng_manage_integrations'      => __( 'Gestire integrazioni e canali', 'booking-manager' ),
		);
	}

	/** @return array<string, string> */
	public static function role_names(): array {
		return array(
			self::ROLE_HOUSEKEEPING => __( 'Housekeeping (Booking Manager)', 'booking-manager' ),
			self::ROLE_FRONTDESK    => __( 'Reception (Booking Manager)', 'booking-manager' ),
			self::ROLE_RESERVATIONS => __( 'Addetto prenotazioni (Booking Manager)', 'booking-manager' ),
			self::ROLE_MANAGER      => __( 'Responsabile di struttura (Booking Manager)', 'booking-manager' ),
			self::ROLE_ADMIN        => __( 'Amministratore Booking Manager', 'booking-manager' ),
		);
	}
}
