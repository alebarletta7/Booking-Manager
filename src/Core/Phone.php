<?php
/**
 * Phone numbers in international form (E.164: "+393401234567"), so search, duplicate
 * detection and a future WhatsApp channel all see the same value whatever was typed
 * ("340 123 4567", "0039 340…", "+39 340-123…").
 *
 * Same rules as Beach Manager's WhatsApp\Phone (copied, not shared): Italy keeps the
 * leading 0 of landlines (+39 06…), elsewhere the trunk 0 is dropped.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class Phone {

	/** "+<country><number>", or null when it cannot be a phone number. */
	public static function normalize( string $raw, string $country_code = '39' ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		$international = str_starts_with( $raw, '+' );
		$digits        = (string) preg_replace( '/\D+/', '', $raw );
		if ( ! $international && str_starts_with( $digits, '00' ) ) {
			$digits        = substr( $digits, 2 );
			$international = true;
		}
		$country_code = (string) preg_replace( '/\D+/', '', $country_code );
		if ( ! $international && '' !== $country_code ) {
			// "39 340…" typed without "+": already has the country code.
			$has_code = str_starts_with( $digits, $country_code ) && strlen( $digits ) >= 11;
			$national = '39' === $country_code ? $digits : (string) preg_replace( '/^0/', '', $digits );
			$digits   = $has_code ? $digits : $country_code . $national;
		}
		$length = strlen( $digits );
		if ( $length < 8 || $length > 15 || str_starts_with( $digits, '0' ) ) {
			return null;
		}
		return '+' . $digits;
	}

	/** Digits for a search: "340 12" → "34012" (null below 4 digits). */
	public static function search_digits( string $raw ): ?string {
		$digits = (string) preg_replace( '/\D+/', '', $raw );
		return strlen( $digits ) >= 4 ? $digits : null;
	}

	/** Country calling code of an ISO country (only the common ones; default Italy). */
	public static function country_code( ?string $iso ): string {
		$map = array(
			'IT' => '39',
			'SM' => '378',
			'VA' => '39',
			'CH' => '41',
			'FR' => '33',
			'DE' => '49',
			'AT' => '43',
			'ES' => '34',
			'GB' => '44',
			'US' => '1',
			'MT' => '356',
			'SI' => '386',
			'HR' => '385',
			'GR' => '30',
		);
		return $map[ strtoupper( (string) $iso ) ] ?? '39';
	}
}
