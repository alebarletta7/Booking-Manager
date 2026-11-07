<?php
/**
 * The customer's business details (0.2.4): contacts, WhatsApp click-to-chat, logo and
 * font of the booking form, and the optional page of its site where the guest lands
 * after paying. Stored in bkng_tenants.settings['profile'], next to anything else the
 * settings hold.
 *
 * - Every contact has its own «Mostra al pubblico»: only those reach the public form.
 * - The chain for the guest is property → customer: the phone, e-mail and website of
 *   the property (always public, as before) win; otherwise the customer's public ones.
 * - WhatsApp: only the customer's number, never the platform's. The button opens
 *   https://wa.me/{number}?text=… with a text the customer writes.
 * - The return address must be on one of the customer's authorised domains.
 *
 * Nothing here is a credential: the profile has no secret.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

use BookingManager\Audit\AuditLogger;
use BookingManager\Core\Validation;

defined( 'ABSPATH' ) || exit;

class TenantProfile {

	public const KEY = 'profile';

	/** Contacts, in the order of the screen. */
	public const CONTACTS = array( 'trade_name', 'contact_name', 'email', 'booking_email', 'info_email', 'phone', 'whatsapp', 'website' );

	/** Font of the form: key => CSS stack ('' = the page's own, as before). */
	public const FONTS = array(
		''         => '',
		'sans'     => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
		'serif'    => 'Georgia, "Iowan Old Style", "Palatino Linotype", Palatino, "Times New Roman", serif',
		'humanist' => 'Optima, Candara, "Gill Sans", "Noto Sans", "Trebuchet MS", sans-serif',
		'rounded'  => 'ui-rounded, "SF Pro Rounded", "Arial Rounded MT Bold", "Varela Round", "Segoe UI", sans-serif',
	);

	public const TEXT_MAX = 300;

	public function __construct( private TenantRepository $tenants, private AuditLogger $audit ) {}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'contacts'      => array_fill_keys( self::CONTACTS, '' ),
			'public'        => array_fill_keys( self::CONTACTS, false ),
			'same_email'    => false,
			'whatsapp_text' => '',
			'logo_id'       => 0,
			'font'          => '',
			'return_url'    => '',
		);
	}

	/**
	 * The stored profile, completed with the defaults.
	 *
	 * @param array<string, mixed>|null $tenant
	 * @return array<string, mixed>
	 */
	public static function of( ?array $tenant ): array {
		$stored = is_array( $tenant['settings'][ self::KEY ] ?? null ) ? $tenant['settings'][ self::KEY ] : array();
		$out    = self::defaults();
		foreach ( array( 'contacts', 'public' ) as $group ) {
			foreach ( self::CONTACTS as $key ) {
				if ( isset( $stored[ $group ][ $key ] ) ) {
					$out[ $group ][ $key ] = 'public' === $group ? (bool) $stored[ $group ][ $key ] : (string) $stored[ $group ][ $key ];
				}
			}
		}
		$out['same_email']    = ! empty( $stored['same_email'] );
		$out['whatsapp_text'] = (string) ( $stored['whatsapp_text'] ?? '' );
		$out['logo_id']       = (int) ( $stored['logo_id'] ?? 0 );
		$font                 = (string) ( $stored['font'] ?? '' );
		$out['font']          = isset( self::FONTS[ $font ] ) ? $font : '';
		$out['return_url']    = (string) ( $stored['return_url'] ?? '' );
		return $out;
	}

	/**
	 * For the "Attività" screen.
	 *
	 * @return array<string, mixed>|null
	 */
	public function payload( int $tenant_id ): ?array {
		$tenant = $tenant_id > 0 ? $this->tenants->find( $tenant_id ) : null;
		if ( ! $tenant ) {
			return null;
		}
		$profile = self::of( $tenant );
		return array(
			'tenant'  => array(
				'id'      => (int) $tenant['id'],
				'name'    => (string) $tenant['name'],
				'version' => (int) $tenant['version'],
			),
			'profile' => $profile,
			'logo'    => self::image( (int) $profile['logo_id'] ),
			'domains' => $this->tenants->domains( (int) $tenant['id'] ),
			'fonts'   => array_keys( self::FONTS ),
		);
	}

	/**
	 * @param array<string, mixed> $input profile fields and the tenant's version
	 * @return array<string, mixed>|\WP_Error the new payload
	 */
	public function save( int $tenant_id, array $input ): array|\WP_Error {
		$tenant = $tenant_id > 0 ? $this->tenants->find( $tenant_id ) : null;
		if ( ! $tenant ) {
			return TenantService::not_found();
		}
		if ( isset( $input['version'] ) && (int) $input['version'] !== (int) $tenant['version'] ) {
			return new \WP_Error( 'bkng_conflict', __( 'I dati sono stati modificati da qualcun altro. Ricarica e riprova.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$profile = $this->validate( $input, $tenant );
		if ( $profile instanceof \WP_Error ) {
			return $profile;
		}
		$old                      = self::of( $tenant );
		$settings                 = is_array( $tenant['settings'] ) ? $tenant['settings'] : array();
		$settings[ self::KEY ]    = $profile;
		$this->tenants->update( (int) $tenant['id'], array( 'settings' => $settings ) );
		$this->audit->log(
			array(
				'action'      => 'tenant.profile_updated',
				'entity_type' => 'tenant',
				'entity_id'   => (int) $tenant['id'],
				'tenant_id'   => (int) $tenant['id'],
				'old'         => $old,
				'new'         => $profile,
			)
		);
		return (array) $this->payload( (int) $tenant['id'] );
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $tenant
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validate( array $input, array $tenant ): array|\WP_Error {
		$current  = self::of( $tenant );
		$contacts = is_array( $input['contacts'] ?? null ) ? $input['contacts'] : $current['contacts'];
		$public   = is_array( $input['public'] ?? null ) ? $input['public'] : $current['public'];
		$out      = self::defaults();
		$errors   = array();

		foreach ( self::CONTACTS as $key ) {
			$value                    = trim( (string) ( $contacts[ $key ] ?? '' ) );
			$out['public'][ $key ]    = ! empty( $public[ $key ] );
			$out['contacts'][ $key ] = match ( $key ) {
				'email', 'booking_email', 'info_email' => sanitize_email( $value ),
				'phone'    => preg_replace( '/[^0-9+ ()\/.-]/', '', $value ),
				'whatsapp' => self::whatsapp_number( $value ),
				'website'  => '' === $value ? '' : esc_url_raw( preg_match( '#^https?://#i', $value ) ? $value : 'https://' . $value, array( 'http', 'https' ) ),
				default    => mb_substr( sanitize_text_field( $value ), 0, 120 ),
			};
			if ( '' !== $value && '' === $out['contacts'][ $key ] ) {
				$errors[ 'contacts.' . $key ] = 'whatsapp' === $key
					? __( 'Scrivi il numero in formato internazionale, ad esempio +39 333 1234567.', 'booking-manager' )
					: __( 'Il valore non è valido.', 'booking-manager' );
			}
		}
		$out['same_email'] = ! empty( $input['same_email'] ?? $current['same_email'] );
		if ( $out['same_email'] ) {
			$out['contacts']['booking_email'] = $out['contacts']['email'];
			$out['contacts']['info_email']    = $out['contacts']['email'];
		}

		$text                 = sanitize_textarea_field( (string) ( $input['whatsapp_text'] ?? $current['whatsapp_text'] ) );
		$out['whatsapp_text'] = mb_substr( $text, 0, self::TEXT_MAX );

		$logo = (int) ( $input['logo_id'] ?? $current['logo_id'] );
		if ( $logo > 0 && ( ! Validation::public_image( $logo ) || ! TenantAccess::allows_attachment( $logo, (int) $tenant['id'] ) ) ) {
			$errors['logo_id'] = __( 'Scegli un\'immagine della libreria media.', 'booking-manager' );
		}
		$out['logo_id'] = max( 0, $logo );

		$font = (string) ( $input['font'] ?? $current['font'] );
		if ( ! isset( self::FONTS[ $font ] ) ) {
			$errors['font'] = __( 'Carattere non disponibile.', 'booking-manager' );
		}
		$out['font'] = $font;

		$return = trim( (string) ( $input['return_url'] ?? $current['return_url'] ) );
		if ( '' !== $return ) {
			$checked = $this->check_return_url( $return, (int) $tenant['id'] );
			if ( null === $checked ) {
				$errors['return_url'] = __( 'L\'indirizzo deve essere una pagina https di uno dei domini autorizzati.', 'booking-manager' );
			}
			$return = (string) $checked;
		}
		$out['return_url'] = $return;

		return $errors ? Validation::error( $errors ) : $out;
	}

	/**
	 * A WhatsApp number as wa.me wants it: digits only, with the country code (8 to 15
	 * digits). "+39 333 123 4567" and "0039 333…" both give "393331234567". A number
	 * without country code (starting with 3 or 0 in Italy) is refused: it would open a
	 * chat with somebody else.
	 */
	public static function whatsapp_number( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$international = str_starts_with( $value, '+' ) || str_starts_with( $value, '00' );
		$digits        = (string) preg_replace( '/\D/', '', $value );
		if ( str_starts_with( $value, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		return $international && preg_match( '/^[1-9]\d{7,14}$/', $digits ) ? $digits : '';
	}

	/** The address if it is a page of one of the customer's domains, else null. */
	public function check_return_url( string $url, int $tenant_id ): ?string {
		$url   = esc_url_raw( $url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		// https, except on a platform that is itself on http (a local test site).
		$https_only = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		if ( ( $https_only && 'https' !== ( $parts['scheme'] ?? '' ) ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		return in_array( $host, $this->tenants->domains( $tenant_id ), true ) ? $url : null;
	}

	/**
	 * What the guest sees of the business of a property: name and logo of the customer,
	 * contacts (property first, then the customer's public ones), WhatsApp and font.
	 *
	 * @param array<string, mixed> $property
	 * @return array{footer: bool, name: string, logo: ?string, font: string, phone: ?string, email: ?string, website: ?string, whatsapp: ?string, whatsapp_text: string}
	 */
	public function public_for( array $property ): array {
		$tenant  = Tenancy::ready() ? $this->tenants->find( Tenancy::of_property( (int) $property['id'] ) ) : null;
		$profile = self::of( $tenant );
		$c       = $profile['contacts'];
		$shown   = fn( string $key ): string => $profile['public'][ $key ] ? (string) $c[ $key ] : '';
		$email   = $shown( 'booking_email' );
		$email   = '' !== $email ? $email : $shown( 'info_email' );
		$email   = '' !== $email ? $email : $shown( 'email' );
		$pick    = static fn( mixed $own, string $fallback ): ?string => '' !== trim( (string) $own ) ? (string) $own : ( '' !== $fallback ? $fallback : null );
		$logo    = self::image( (int) $profile['logo_id'] );
		return array(
			// The footer with the business appears only when the customer chose to show something.
			'footer'        => null !== $logo || '' !== implode( '', array_map( $shown, self::CONTACTS ) ),
			'name'          => $shown( 'trade_name' ),
			'logo'          => $logo,
			'font'          => self::FONTS[ $profile['font'] ] ?? '',
			'phone'         => $pick( $property['phone'] ?? null, $shown( 'phone' ) ),
			'email'         => $pick( $property['email'] ?? null, $email ),
			'website'       => $pick( $property['website'] ?? null, $shown( 'website' ) ),
			'whatsapp'      => '' !== $shown( 'whatsapp' ) ? $shown( 'whatsapp' ) : null,
			'whatsapp_text' => $profile['whatsapp_text'],
		);
	}

	/** Address of the customer's return page for a property, or ''. */
	public function return_url_for( int $property_id ): string {
		if ( ! Tenancy::ready() ) {
			return '';
		}
		$tenant_id = Tenancy::of_property( $property_id );
		$tenant    = $tenant_id > 0 ? $this->tenants->find( $tenant_id ) : null;
		$url       = (string) self::of( $tenant )['return_url'];
		// Checked again: a domain may have been removed since.
		return '' !== $url ? (string) $this->check_return_url( $url, $tenant_id ) : '';
	}

	private static function image( int $id ): ?string {
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $id, 'medium' );
		return $url ? (string) $url : null;
	}
}
