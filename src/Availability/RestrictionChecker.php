<?php
/**
 * Stay rules: minimum and maximum stay, booking advance, arrival and departure
 * weekdays, closures. Pure (unit-tested): the caller loads the property settings and the
 * restriction rows that can apply.
 *
 * A restriction applies when its room type and rate plan are empty or equal to the
 * stay's. Stay length, advance and arrival rules are read on the arrival date (the
 * hotel convention), departure rules on the departure date, closures on every night.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Availability;

use BookingManager\Core\Dates;

defined( 'ABSPATH' ) || exit;

final class RestrictionChecker {

	/**
	 * @param array<string, mixed>             $settings     property settings (PropertySettings)
	 * @param array<int, array<string, mixed>> $restrictions rows of bkng_restrictions
	 * @param string                           $today        Y-m-d in the property's timezone
	 * @param string|null                      $now_time     H:i in the property's timezone (online only: same-day cut-off)
	 * @return array<string, string> code => message; empty when the stay is allowed
	 */
	public static function check( array $settings, array $restrictions, string $arrival, string $departure, string $today, ?int $room_type_id = null, ?int $rate_plan_id = null, ?string $now_time = null ): array {
		$errors = array();
		$nights = Dates::count( $arrival, $departure );
		if ( $nights < 1 ) {
			return array( 'dates' => __( 'La partenza deve essere successiva all\'arrivo.', 'booking-manager' ) );
		}
		if ( $nights > Dates::MAX_NIGHTS ) {
			return array( 'dates' => __( 'Il soggiorno è troppo lungo.', 'booking-manager' ) );
		}
		if ( $arrival < $today ) {
			return array( 'arrival' => __( 'La data di arrivo è già passata.', 'booking-manager' ) );
		}

		$rules = array_values( array_filter( $restrictions, static fn( array $r ) => self::applies( $r, $room_type_id, $rate_plan_id ) ) );
		$on    = static fn( string $date ) => array_values( array_filter( $rules, static fn( array $r ) => (string) $r['date_from'] <= $date && (string) $r['date_to'] >= $date ) );

		$at_arrival   = $on( $arrival );
		$at_departure = $on( $departure );

		$min_stay = (int) ( $settings['min_stay'] ?? 1 );
		$max_stay = (int) ( $settings['max_stay'] ?? Dates::MAX_NIGHTS );
		$min_adv  = (int) ( $settings['min_advance_days'] ?? 0 );
		$max_adv  = (int) ( $settings['max_advance_days'] ?? 365 );
		foreach ( $at_arrival as $r ) {
			if ( null !== $r['min_stay'] && '' !== (string) $r['min_stay'] ) {
				$min_stay = max( $min_stay, (int) $r['min_stay'] );
			}
			if ( null !== $r['max_stay'] && '' !== (string) $r['max_stay'] ) {
				$max_stay = min( $max_stay, (int) $r['max_stay'] );
			}
			if ( null !== $r['min_advance_days'] && '' !== (string) $r['min_advance_days'] ) {
				$min_adv = max( $min_adv, (int) $r['min_advance_days'] );
			}
			if ( null !== $r['max_advance_days'] && '' !== (string) $r['max_advance_days'] ) {
				$max_adv = min( $max_adv, (int) $r['max_advance_days'] );
			}
		}

		if ( $nights < $min_stay ) {
			/* translators: %d: minimum number of nights. */
			$errors['min_stay'] = sprintf( _n( 'Il soggiorno minimo è di %d notte.', 'Il soggiorno minimo è di %d notti.', $min_stay, 'booking-manager' ), $min_stay );
		}
		if ( $nights > $max_stay ) {
			/* translators: %d: maximum number of nights. */
			$errors['max_stay'] = sprintf( _n( 'Il soggiorno massimo è di %d notte.', 'Il soggiorno massimo è di %d notti.', $max_stay, 'booking-manager' ), $max_stay );
		}

		$advance = Dates::diff_days( $today, $arrival );
		if ( $advance < $min_adv ) {
			/* translators: %d: number of days. */
			$errors['min_advance'] = sprintf( _n( 'Bisogna prenotare con almeno %d giorno di anticipo.', 'Bisogna prenotare con almeno %d giorni di anticipo.', $min_adv, 'booking-manager' ), $min_adv );
		}
		if ( $advance > $max_adv ) {
			/* translators: %d: number of days. */
			$errors['max_advance'] = sprintf( _n( 'Si può prenotare al massimo %d giorno prima.', 'Si può prenotare al massimo %d giorni prima.', $max_adv, 'booking-manager' ), $max_adv );
		}
		if ( 0 === $advance && null !== $now_time && $now_time > (string) ( $settings['same_day_until'] ?? '23:59' ) ) {
			$errors['same_day'] = __( 'Per oggi non si accettano più prenotazioni online.', 'booking-manager' );
		}

		foreach ( $at_arrival as $r ) {
			if ( ! empty( $r['closed_to_arrival'] ) ) {
				$errors['closed_to_arrival'] = __( 'In questa data non sono previsti arrivi.', 'booking-manager' );
			}
			if ( ! Dates::on_weekdays( $r['arrival_weekdays'] ?? null, $arrival ) ) {
				$errors['arrival_weekday'] = __( 'In questo periodo l\'arrivo è possibile solo in alcuni giorni della settimana.', 'booking-manager' );
			}
		}
		foreach ( $at_departure as $r ) {
			if ( ! empty( $r['closed_to_departure'] ) ) {
				$errors['closed_to_departure'] = __( 'In questa data non sono previste partenze.', 'booking-manager' );
			}
			if ( ! Dates::on_weekdays( $r['departure_weekdays'] ?? null, $departure ) ) {
				$errors['departure_weekday'] = __( 'In questo periodo la partenza è possibile solo in alcuni giorni della settimana.', 'booking-manager' );
			}
		}
		foreach ( Dates::nights( $arrival, $departure ) as $night ) {
			foreach ( $on( $night ) as $r ) {
				if ( ! empty( $r['closed'] ) ) {
					$errors['closed'] = __( 'La struttura è chiusa in una o più notti del soggiorno.', 'booking-manager' );
					break 2;
				}
			}
		}
		return $errors;
	}

	/** @param array<string, mixed> $r */
	private static function applies( array $r, ?int $room_type_id, ?int $rate_plan_id ): bool {
		$type = isset( $r['room_type_id'] ) && '' !== (string) $r['room_type_id'] ? (int) $r['room_type_id'] : null;
		$plan = isset( $r['rate_plan_id'] ) && '' !== (string) $r['rate_plan_id'] ? (int) $r['rate_plan_id'] : null;
		if ( null !== $type && null !== $room_type_id && $type !== $room_type_id ) {
			return false;
		}
		if ( null !== $plan && null !== $rate_plan_id && $plan !== $rate_plan_id ) {
			return false;
		}
		// A rule for a specific type or plan does not apply when the stay names none.
		return ! ( ( null !== $type && null === $room_type_id ) || ( null !== $plan && null === $rate_plan_id ) );
	}
}
