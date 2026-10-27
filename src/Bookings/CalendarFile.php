<?php
/**
 * The booking as a calendar file (.ics, RFC 5545): the guest adds the stay to their phone
 * or computer calendar from the e-mail or the booking page (same as Beach Manager).
 *
 * One all-day event from the arrival day to the departure day included, titled with the
 * property's name, with its address and the check-in and check-out times. Same UID for
 * the same booking, so a calendar that imports the file again (after a change) updates
 * the event instead of doubling it. Pure logic: no database, no requests.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Bookings;

defined( 'ABSPATH' ) || exit;

final class CalendarFile {

	public const MIME = 'text/calendar; charset=utf-8';

	public static function filename( string $code ): string {
		return 'prenotazione-' . preg_replace( '/[^A-Za-z0-9]/', '', $code ) . '.ics';
	}

	/**
	 * The file for a booking (arrival, departure, code) of a property.
	 *
	 * @param array<string, mixed> $booking
	 * @param array<string, mixed> $property
	 * @return array{name: string, data: string, mime: string}
	 */
	public static function for_booking( array $booking, array $property, int $now ): array {
		return array(
			'name' => self::filename( (string) $booking['code'] ),
			'data' => self::build(
				array(
					'code'      => (string) $booking['code'],
					'arrival'   => (string) $booking['arrival'],
					'departure' => (string) $booking['departure'],
				),
				$property,
				(string) wp_parse_url( home_url(), PHP_URL_HOST ),
				$now
			),
			'mime' => self::MIME,
		);
	}

	/**
	 * Only what the guest needs in the calendar: where (name and address) and the times
	 * of check-in and check-out.
	 *
	 * @param array{code: string, arrival: string, departure: string} $booking
	 * @param array<string, mixed> $property name, address, postcode, city, checkin_from, checkin_to, checkout_until
	 * @param string $host domain for the UID (the site's)
	 * @param int    $now  Unix time
	 */
	public static function build( array $booking, array $property, string $host, int $now ): string {
		$name  = trim( (string) ( $property['name'] ?? '' ) );
		$city  = trim( trim( (string) ( $property['postcode'] ?? '' ) ) . ' ' . trim( (string) ( $property['city'] ?? '' ) ) );
		$place = implode( ', ', array_filter( array( trim( (string) ( $property['address'] ?? '' ) ), $city ) ) );
		$end   = ( new \DateTimeImmutable( $booking['departure'] ) )->modify( '+1 day' );
		$host  = preg_replace( '/[^A-Za-z0-9.\-]/', '', $host ) ?: 'booking-manager';

		$times = array();
		$from  = substr( (string) ( $property['checkin_from'] ?? '' ), 0, 5 );
		$to    = substr( (string) ( $property['checkin_to'] ?? '' ), 0, 5 );
		$out   = substr( (string) ( $property['checkout_until'] ?? '' ), 0, 5 );
		if ( '' !== $from && '' !== $to ) {
			/* translators: 1: from time, 2: until time. */
			$times[] = sprintf( __( 'Check-in dalle %1$s alle %2$s', 'booking-manager' ), $from, $to );
		} elseif ( '' !== $from ) {
			/* translators: %s: time. */
			$times[] = sprintf( __( 'Check-in dalle %s', 'booking-manager' ), $from );
		}
		if ( '' !== $out ) {
			/* translators: %s: time. */
			$times[] = sprintf( __( 'Check-out entro le %s', 'booking-manager' ), $out );
		}
		/* translators: %s: booking code. */
		$times[] = sprintf( __( 'Prenotazione %s', 'booking-manager' ), $booking['code'] );

		$event = array(
			'UID:' . self::text( strtolower( $booking['code'] ) . '@' . $host ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z', $now ),
			'DTSTART;VALUE=DATE:' . str_replace( '-', '', $booking['arrival'] ),
			'DTEND;VALUE=DATE:' . $end->format( 'Ymd' ),
			'SUMMARY:' . self::text( $name ),
		);
		if ( '' !== $place ) {
			$event[] = 'LOCATION:' . self::text( $place );
		}
		$event[] = 'DESCRIPTION:' . self::text( implode( "\n", $times ) );
		$event[] = 'TRANSP:TRANSPARENT';

		$lines = array_merge(
			array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Booking Manager//IT', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT' ),
			$event,
			array( 'END:VEVENT', 'END:VCALENDAR' )
		);
		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/** TEXT value escaping (RFC 5545 §3.3.11). */
	private static function text( string $value ): string {
		$value = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\;', '\\,' ), $value );
		return str_replace( array( "\r\n", "\n", "\r" ), '\\n', $value );
	}

	/** Lines longer than 75 octets continue on the next line after a space, never splitting a character. */
	private static function fold( string $line ): string {
		$out = '';
		$len = 0;
		foreach ( mb_str_split( $line ) as $char ) {
			$bytes = strlen( $char );
			if ( $len + $bytes > 75 ) {
				$out .= "\r\n ";
				$len  = 1;
			}
			$out .= $char;
			$len += $bytes;
		}
		return $out;
	}
}
