<?php
/**
 * Values of the {variables} of a WhatsApp message for a booking.
 *
 * WhatsApp parameters must be one line and never empty: line breaks and tabs become
 * spaces, long runs of spaces are shortened and an empty value is written «-».
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\WhatsApp;

use BookingManager\Bookings\ManageLink;
use BookingManager\Core\Money;
use BookingManager\Core\Phone;

defined( 'ABSPATH' ) || exit;

final class WhatsAppVariables {

	/** Query argument of the short link behind the button: code and key of the booking. */
	public const ARG = 'bkng_wa';

	/**
	 * Every variable, filled (unit-tested).
	 *
	 * @param array<string, mixed> $booking  row of bkng_bookings
	 * @param array<string, mixed> $property row of bkng_properties
	 * @param string               $rooms    «1 × Matrimoniale, 1 × Singola»
	 * @param string               $link     the guest's booking page
	 * @param int                  $pay_now  cents to pay online now (0: nothing)
	 * @return array<string, string>
	 */
	public static function for_booking( array $booking, array $property, string $rooms, string $link, int $pay_now = 0 ): array {
		$currency = (string) ( $booking['currency'] ?? $property['currency'] ?? 'EUR' );
		$first    = trim( (string) ( $booking['first_name'] ?? '' ) );
		$last     = trim( (string) ( $booking['last_name'] ?? '' ) );
		$guests   = (int) ( $booking['adults'] ?? 0 ) + (int) ( $booking['children'] ?? 0 );
		$paid     = (int) ( $booking['paid'] ?? 0 ) - (int) ( $booking['refunded'] ?? 0 );
		$expires  = (string) ( $booking['option_expires_at'] ?? '' );
		$zone     = new \DateTimeZone( (string) ( $property['timezone'] ?? 'UTC' ) ?: 'UTC' );
		$phone    = (string) ( $property['phone'] ?? '' );
		$values   = array(
			'nome'           => '' !== $first ? $first : $last,
			'cognome'        => $last,
			'ospite'         => trim( $first . ' ' . $last ),
			'struttura'      => (string) ( $property['name'] ?? '' ),
			'indirizzo'      => trim( implode( ', ', array_filter( array( trim( (string) ( $property['address'] ?? '' ) ), trim( (string) ( $property['city'] ?? '' ) ) ) ) ) ),
			'telefono'       => '' !== $phone ? (string) ( Phone::normalize( $phone ) ?? $phone ) : '',
			'codice'         => (string) ( $booking['code'] ?? '' ),
			'arrivo'         => self::date( (string) ( $booking['arrival'] ?? '' ) ),
			'partenza'       => self::date( (string) ( $booking['departure'] ?? '' ) ),
			'notti'          => (string) (int) ( $booking['nights'] ?? 0 ),
			/* translators: %d: number of guests. */
			'ospiti'         => sprintf( _n( '%d ospite', '%d ospiti', $guests, 'booking-manager' ), $guests ),
			'camere'         => $rooms,
			'totale'         => Money::format( (int) ( $booking['total'] ?? 0 ), $currency ),
			'pagato'         => Money::format( max( 0, $paid ), $currency ),
			'da_pagare'      => Money::format( max( 0, (int) ( $booking['balance'] ?? 0 ) ), $currency ),
			'da_pagare_ora'  => Money::format( max( 0, $pay_now ), $currency ),
			'scadenza'       => '' === $expires ? '' : wp_date( 'j F Y, H:i', (int) strtotime( $expires . ' UTC' ), $zone ),
			'checkin_dalle'  => substr( (string) ( $property['checkin_from'] ?? '' ), 0, 5 ),
			'checkin_alle'   => substr( (string) ( $property['checkin_to'] ?? '' ), 0, 5 ),
			'checkout_entro' => substr( (string) ( $property['checkout_until'] ?? '' ), 0, 5 ),
			'motivo'         => trim( (string) ( $booking['cancel_reason'] ?? '' ) ),
			'link'           => $link,
		);
		return array_map( array( self::class, 'param' ), $values );
	}

	/** One line, never empty (unit-tested). */
	public static function param( string $value ): string {
		$value = trim( (string) preg_replace( '/ {4,}/', '   ', (string) preg_replace( '/[\r\n\t]+/', ' ', $value ) ) );
		return '' === $value ? '-' : mb_substr( $value, 0, 300 );
	}

	/** "sabato 14 novembre 2026" from "Y-m-d". */
	public static function date( string $day ): string {
		return '' === $day ? '' : wp_date( 'l j F Y', (int) strtotime( $day . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * A made-up booking for previews and for Meta's reviewers: never a guest's data.
	 *
	 * @param array<string, mixed> $property
	 * @return array<string, string>
	 */
	public static function sample( array $property ): array {
		$arrival = gmdate( 'Y-m-d', time() + 14 * DAY_IN_SECONDS );
		$link    = add_query_arg(
			array(
				ManageLink::ARG_CODE  => 'ESEMPIO2',
				ManageLink::ARG_TOKEN => str_repeat( 'A', 32 ),
			),
			home_url( '/' )
		);
		return self::for_booking(
			array(
				'code'              => 'ESEMPIO2',
				'first_name'        => 'Maria',
				'last_name'         => 'Rossi',
				'arrival'           => $arrival,
				'departure'         => gmdate( 'Y-m-d', strtotime( $arrival . ' +2 days' ) ),
				'nights'            => 2,
				'adults'            => 2,
				'children'          => 0,
				'total'             => 24000,
				'paid'              => 7200,
				'refunded'          => 0,
				'balance'           => 16800,
				'currency'          => (string) ( $property['currency'] ?? 'EUR' ),
				'option_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ),
				'cancel_reason'     => __( 'Richiesta dell’ospite', 'booking-manager' ),
			),
			$property + array(
				'name'           => (string) get_bloginfo( 'name' ),
				'checkin_from'   => '14:00',
				'checkin_to'     => '20:00',
				'checkout_until' => '10:00',
			),
			__( '1 × Camera matrimoniale', 'booking-manager' ),
			$link,
			7200
		);
	}

	/**
	 * The fixed part of the button's URL: the site's address with the one-argument short
	 * link, so the URL is this plus {{1}} (unit-tested).
	 */
	public static function button_base( string $home ): string {
		return $home . ( str_contains( $home, '?' ) ? '&' : '?' ) . self::ARG . '=';
	}

	/**
	 * The button's {{1}} for a booking page link: «CODE.KEY» (unit-tested). Null when the
	 * link has no code and key, or the approved text's base is not this site's address
	 * any more (the site moved after the text was approved).
	 */
	public static function button_suffix( string $link, string $approved_base, string $home ): ?string {
		if ( self::button_base( $home ) !== $approved_base ) {
			return null;
		}
		parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $query );
		$code  = $query[ ManageLink::ARG_CODE ] ?? '';
		$token = $query[ ManageLink::ARG_TOKEN ] ?? '';
		return is_string( $code ) && is_string( $token ) && 1 === preg_match( '/^[A-Z0-9]{6,16}$/D', $code ) && 1 === preg_match( '/^[A-Z0-9]{32}$/D', $token ) ? $code . '.' . $token : null;
	}

	/**
	 * «CODE.KEY» of the short link back to code and key (unit-tested).
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public static function parse_short( string $value ): ?array {
		return 1 === preg_match( '/^([A-Z0-9]{6,16})\.([A-Z0-9]{32})$/D', $value, $m ) ? array( $m[1], $m[2] ) : null;
	}
}
