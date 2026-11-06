<?php
/**
 * Single source of "now", replaceable in tests.
 *
 * Technical timestamps are stored in UTC; service dates are local to the property.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

class Clock {

	public function now_utc(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/** MySQL DATETIME string in UTC. */
	public function now_mysql(): string {
		return $this->now_utc()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A stored UTC DATETIME as ISO 8601 with its offset ("2026-10-04T10:17:44Z"), so that
	 * browsers show it in local time. (WordPress' mysql_to_rfc3339() adds no offset and
	 * browsers would read UTC values as local time.)
	 */
	public static function iso( ?string $utc ): ?string {
		if ( null === $utc || '' === $utc || str_starts_with( $utc, '0000-00-00' ) ) {
			return null;
		}
		$ts = strtotime( $utc . ' UTC' );
		return false === $ts ? null : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	/** Today's date (Y-m-d) in the given IANA timezone. */
	public function today_in( string $timezone ): string {
		try {
			$tz = new \DateTimeZone( $timezone );
		} catch ( \Exception $e ) {
			$tz = wp_timezone();
		}
		return $this->now_utc()->setTimezone( $tz )->format( 'Y-m-d' );
	}

	/** Current local date-time ("Y-m-d H:i:s") in the given IANA timezone. */
	public function now_in( string $timezone ): string {
		try {
			$tz = new \DateTimeZone( $timezone );
		} catch ( \Exception $e ) {
			$tz = wp_timezone();
		}
		return $this->now_utc()->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
	}
}
