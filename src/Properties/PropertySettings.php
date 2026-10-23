<?php
/**
 * Per-property settings, stored as JSON in bkng_properties.settings.
 *
 * Only known keys are kept, each coerced to the type of its default, so a request can
 * never store arbitrary data. Secrets (e.g. the Turnstile key of the property) never
 * travel to the browser: public() removes them.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Properties;

defined( 'ABSPATH' ) || exit;

final class PropertySettings {

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			// Availability.
			'hold_minutes'          => 15,
			'option_hours'          => 48,
			'min_stay'              => 1,
			'max_stay'              => 30,
			'min_advance_days'      => 0,
			'max_advance_days'      => 365,
			'same_day_until'        => '18:00',
			'max_rooms_per_booking' => 4,
			'children_max_age'      => 12,
			'auto_assign_unit'      => true,
			// Public form.
			'online_enabled'        => true,
			'show_prices'           => true,
			// The guest may cancel from the link in the e-mail (the rate's cancellation
			// terms decide what is refunded; a non-refundable rate can never be cancelled online).
			'guest_cancel_online'   => true,
			'terms_text'            => '',
			'privacy_url'           => '',
			'marketing_consent'     => false,
			// Payments.
			'payment_provider'      => 'none',
			'payment_mode'          => 'test',
			'online_payment'        => 'none',
			'balance_due_days'      => 0,
			// Tourist tax: a configurable amount, never a fiscal engine.
			'tourist_tax_enabled'   => false,
			'tourist_tax_amount'    => 0,
			'tourist_tax_max_nights' => 0,
			'tourist_tax_min_age'   => 0,
			// Notifications.
			'notify_email'          => '',
			'sender_name'           => '',
			'reminder_days'         => 2,
			'send_pre_arrival'      => true,
			'send_checkin_confirmation' => false,
			// Housekeeping.
			'housekeeping_enabled'  => true,
			'dirty_on_checkout'     => true,
			'block_dirty_checkin'   => false,
		);
	}

	/** Allowed values for the keys that are an enumeration. */
	private const ENUMS = array(
		'payment_mode'     => array( 'test', 'live' ),
		'online_payment'   => array( 'none', 'deposit', 'full' ),
	);

	/** Integer keys and their limits. */
	private const LIMITS = array(
		'hold_minutes'           => array( 5, 120 ),
		'option_hours'           => array( 1, 720 ),
		'min_stay'               => array( 1, 365 ),
		'max_stay'               => array( 1, 365 ),
		'min_advance_days'       => array( 0, 730 ),
		'max_advance_days'       => array( 1, 1095 ),
		'max_rooms_per_booking'  => array( 1, 50 ),
		'children_max_age'       => array( 0, 17 ),
		'balance_due_days'       => array( 0, 365 ),
		'tourist_tax_amount'     => array( 0, 100000 ),
		'tourist_tax_max_nights' => array( 0, 365 ),
		'tourist_tax_min_age'    => array( 0, 17 ),
		'reminder_days'          => array( 0, 30 ),
	);

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	public static function merge( array $base, array $changes ): array {
		$defaults = self::defaults();
		$out      = array_merge( $defaults, array_intersect_key( $base, $defaults ) );
		foreach ( $changes as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$out[ $key ] = self::coerce( (string) $key, $defaults[ $key ], $value );
		}
		if ( $out['max_stay'] < $out['min_stay'] ) {
			$out['max_stay'] = $out['min_stay'];
		}
		return $out;
	}

	/** @return array<string, mixed> */
	public static function from_json( ?string $json ): array {
		$data = null !== $json ? json_decode( $json, true ) : null;
		return self::merge( array(), is_array( $data ) ? $data : array() );
	}

	/**
	 * @param mixed $default
	 * @param mixed $value
	 * @return mixed
	 */
	private static function coerce( string $key, $default, $value ) {
		if ( is_bool( $default ) ) {
			return (bool) rest_sanitize_boolean( $value );
		}
		if ( is_int( $default ) ) {
			[ $min, $max ] = self::LIMITS[ $key ] ?? array( 0, PHP_INT_MAX );
			return min( $max, max( $min, (int) $value ) );
		}
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( isset( self::ENUMS[ $key ] ) ) {
			return in_array( $value, self::ENUMS[ $key ], true ) ? $value : $default;
		}
		if ( 'payment_provider' === $key ) {
			// Any registered provider (add-ons may add their own): set only by
			// PaymentAccountService::select(), after the account is verified.
			return preg_match( '/^[a-z0-9_-]{2,20}$/', $value ) ? $value : $default;
		}
		if ( 'same_day_until' === $key ) {
			return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $default;
		}
		if ( 'privacy_url' === $key ) {
			return esc_url_raw( $value );
		}
		if ( 'notify_email' === $key ) {
			return is_email( $value ) ? $value : '';
		}
		if ( 'terms_text' === $key ) {
			return sanitize_textarea_field( $value );
		}
		return sanitize_text_field( $value );
	}
}
