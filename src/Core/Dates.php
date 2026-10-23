<?php
/**
 * Pure date helpers for stays. A stay runs from the arrival day to the departure day;
 * the nights are arrival … departure − 1: the departure day is never an occupied night.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class Dates {

	/** Longest stay the plugin handles in one booking line. */
	public const MAX_NIGHTS = 365;

	/**
	 * The nights of a stay (Y-m-d), empty when departure is not after arrival.
	 *
	 * @return string[]
	 */
	public static function nights( string $arrival, string $departure ): array {
		$count = self::count( $arrival, $departure );
		$out   = array();
		$day   = new \DateTimeImmutable( $arrival . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = $day->format( 'Y-m-d' );
			$day   = $day->modify( '+1 day' );
		}
		return $out;
	}

	/** Number of nights between two dates (0 when departure <= arrival). */
	public static function count( string $arrival, string $departure ): int {
		$a = \DateTimeImmutable::createFromFormat( '!Y-m-d', $arrival, new \DateTimeZone( 'UTC' ) );
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $departure, new \DateTimeZone( 'UTC' ) );
		if ( false === $a || false === $d || $d <= $a ) {
			return 0;
		}
		return (int) $a->diff( $d )->days;
	}

	public static function add_days( string $date, int $days ): string {
		return ( new \DateTimeImmutable( $date . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	/** ISO weekday: 1 = Monday … 7 = Sunday. */
	public static function weekday( string $date ): int {
		return (int) ( new \DateTimeImmutable( $date . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->format( 'N' );
	}

	/** Days from $from to $to (negative when $to is earlier). */
	public static function diff_days( string $from, string $to ): int {
		$a = new \DateTimeImmutable( $from . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		$b = new \DateTimeImmutable( $to . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		$d = (int) $a->diff( $b )->days;
		return $b < $a ? -$d : $d;
	}

	/**
	 * A weekday list as stored ("1,2,3"), normalized; null when empty or invalid.
	 *
	 * @param mixed $value "1,3,5" or [1, 3, 5]
	 */
	public static function weekdays( $value ): ?string {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$days  = array();
		foreach ( $items as $item ) {
			$n = (int) trim( (string) $item );
			if ( $n >= 1 && $n <= 7 ) {
				$days[ $n ] = $n;
			}
		}
		ksort( $days );
		return $days ? implode( ',', $days ) : null;
	}

	/** Whether a weekday list ("1,2,3"; null = every day) contains the weekday of $date. */
	public static function on_weekdays( ?string $list, string $date ): bool {
		if ( null === $list || '' === $list ) {
			return true;
		}
		return in_array( (string) self::weekday( $date ), explode( ',', $list ), true );
	}
}
