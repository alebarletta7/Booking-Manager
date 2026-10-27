<?php
/**
 * iCal calendars of the portals (Booking.com, Airbnb, Vrbo…): reading their events and
 * writing a room's closed dates. Pure functions (unit-tested).
 *
 * The portals' calendars carry dates only: an event is a stay or a closure from
 * DTSTART (first night) to DTEND (day of departure, not a night), with a UID that stays
 * the same while the reservation exists. Airbnb writes «Reserved» for a reservation and
 * «Airbnb (Not available)» for a closure; Booking.com writes «CLOSED - Not available» for
 * both. No guest data travels this way.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Ical;

defined( 'ABSPATH' ) || exit;

final class IcalCalendar {

	/** Portals offered when adding a calendar (any other goes as «other»). */
	public const CHANNELS = array( 'booking', 'airbnb', 'vrbo', 'expedia', 'other' );

	/** @return array<string, string> channel => name */
	public static function channel_names(): array {
		return array(
			'booking' => 'Booking.com',
			'airbnb'  => 'Airbnb',
			'vrbo'    => 'Vrbo',
			'expedia' => 'Expedia',
			'other'   => __( 'Altro portale', 'booking-manager' ),
		);
	}

	/**
	 * The events of a calendar: stays and closures with their nights.
	 *
	 * @return array<int, array{uid: string, from: string, to: string, summary: string}> from = first night, to = departure day (Y-m-d)
	 */
	public static function parse( string $ics ): array {
		// Unfold: a line starting with a space or a tab continues the previous one.
		$text   = (string) preg_replace( "/\r?\n[ \t]/", '', str_replace( "\r\n", "\n", $ics ) );
		$events = array();
		$event  = null;
		foreach ( explode( "\n", $text ) as $line ) {
			$line = rtrim( $line, "\r" );
			if ( 'BEGIN:VEVENT' === strtoupper( $line ) ) {
				$event = array();
				continue;
			}
			if ( 'END:VEVENT' === strtoupper( $line ) ) {
				if ( is_array( $event ) ) {
					$done = self::event( $event, count( $events ) );
					if ( $done ) {
						$events[] = $done;
					}
				}
				$event = null;
				continue;
			}
			if ( ! is_array( $event ) || ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $head, $value ] = explode( ':', $line, 2 );
			$name             = strtoupper( (string) strtok( $head, ';' ) );
			if ( ! isset( $event[ $name ] ) ) {
				$event[ $name ] = $value;
			}
		}
		return $events;
	}

	/**
	 * @param array<string, string> $e properties of a VEVENT
	 * @return array{uid: string, from: string, to: string, summary: string}|null
	 */
	private static function event( array $e, int $index ): ?array {
		if ( 'CANCELLED' === strtoupper( trim( (string) ( $e['STATUS'] ?? '' ) ) ) ) {
			return null;
		}
		$from = self::date( (string) ( $e['DTSTART'] ?? '' ) );
		if ( null === $from ) {
			return null;
		}
		$to = self::date( (string) ( $e['DTEND'] ?? '' ) );
		// No end: one night (an all-day event lasts one day).
		$to = $to ?? gmdate( 'Y-m-d', (int) strtotime( $from . ' +1 day' ) );
		if ( $to <= $from ) {
			return null;
		}
		$summary = self::unescape( (string) ( $e['SUMMARY'] ?? '' ) );
		$uid     = trim( self::unescape( (string) ( $e['UID'] ?? '' ) ) );
		return array(
			// Without a UID the dates name the event (a calendar that always writes them the same).
			'uid'     => mb_substr( '' !== $uid ? $uid : 'senza-uid-' . $from . '-' . $to . '-' . $index, 0, 190 ),
			'from'    => $from,
			'to'      => $to,
			'summary' => mb_substr( $summary, 0, 120 ),
		);
	}

	/** "20261110", "20261110T140000Z" → "2026-11-10"; null when not a date. */
	public static function date( string $value ): ?string {
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})/', trim( $value ), $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return $m[1] . '-' . $m[2] . '-' . $m[3];
	}

	private static function unescape( string $value ): string {
		return str_replace( array( '\\n', '\\N', '\\,', '\\;', '\\\\' ), array( ' ', ' ', ',', ';', '\\' ), $value );
	}

	/**
	 * Whether an event looks like a guest's reservation rather than a closure: Airbnb's
	 * «Reserved». Booking.com writes «CLOSED - Not available» for both, so for it this
	 * cannot be told.
	 */
	public static function is_reservation( string $summary ): bool {
		return 1 === preg_match( '/^\s*reserved\b/i', $summary );
	}

	/**
	 * Contiguous ranges of a sorted list of nights.
	 *
	 * @param string[] $nights Y-m-d, sorted, unique
	 * @return array<int, array{0: string, 1: string}> [first night, day after the last]
	 */
	public static function ranges( array $nights ): array {
		$out = array();
		foreach ( $nights as $night ) {
			$next = gmdate( 'Y-m-d', (int) strtotime( $night . ' +1 day' ) );
			$last = count( $out ) - 1;
			if ( $last >= 0 && $out[ $last ][1] === $night ) {
				$out[ $last ][1] = $next;
			} else {
				$out[] = array( $night, $next );
			}
		}
		return $out;
	}

	/**
	 * A room's calendar for the portals: one «Non disponibile» event per closed period,
	 * nothing else (no names, no prices).
	 *
	 * @param array<int, array{0: string, 1: string}> $ranges closed periods [first night, departure day)
	 */
	public static function build( array $ranges, string $uid_prefix, string $name, int $now ): string {
		$host  = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'booking-manager' );
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Booking Manager//IT', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . self::text( $name ) );
		foreach ( $ranges as [ $from, $to ] ) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $uid_prefix . '-' . str_replace( '-', '', $from ) . '-' . str_replace( '-', '', $to ) . '@' . $host;
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', $now );
			$lines[] = 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $from );
			$lines[] = 'DTEND;VALUE=DATE:' . str_replace( '-', '', $to );
			$lines[] = 'SUMMARY:' . self::text( __( 'Non disponibile', 'booking-manager' ) );
			$lines[] = 'TRANSP:OPAQUE';
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", $lines ) . "\r\n";
	}

	private static function text( string $value ): string {
		$value = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\;', '\\,' ), $value );
		return mb_substr( str_replace( array( "\r\n", "\n", "\r" ), ' ', $value ), 0, 60 );
	}
}
