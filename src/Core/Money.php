<?php
/**
 * Amounts in cents written for people: "€ 1.234,50" (Italian format, the currency symbol
 * when known, the ISO code otherwise).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class Money {

	private const SYMBOLS = array(
		'EUR' => '€',
		'USD' => '$',
		'GBP' => '£',
		'CHF' => 'CHF',
	);

	public static function format( int $cents, string $currency = 'EUR' ): string {
		$currency = strtoupper( $currency );
		$sign     = $cents < 0 ? '-' : '';
		$text     = number_format( abs( $cents ) / 100, 2, ',', '.' );
		return $sign . ( self::SYMBOLS[ $currency ] ?? $currency ) . ' ' . $text;
	}
}
