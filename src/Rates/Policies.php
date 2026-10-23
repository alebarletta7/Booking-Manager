<?php
/**
 * Deposit and cancellation terms of a rate plan, normalized (pure, unit-tested).
 *
 * The terms are copied into every booking (terms_snapshot): changing a plan later never
 * changes what a guest already accepted.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rates;

use BookingManager\Core\Dates;

defined( 'ABSPATH' ) || exit;

final class Policies {

	public const DEPOSIT_TYPES = array( 'none', 'percent', 'fixed', 'first_night', 'full' );
	public const PENALTIES     = array( 'none', 'first_night', 'percent', 'full' );

	/**
	 * @param mixed $raw
	 * @return array{type: string, value: int, due_days: int}
	 *   value: basis points for percent (3000 = 30%), cents for fixed.
	 *   due_days: 0 = at booking, N = N days before arrival.
	 */
	public static function deposit( $raw ): array {
		$raw  = is_array( $raw ) ? $raw : array();
		$type = (string) ( $raw['type'] ?? 'none' );
		$type = in_array( $type, self::DEPOSIT_TYPES, true ) ? $type : 'none';
		return array(
			'type'     => $type,
			'value'    => 'percent' === $type ? min( 10000, max( 0, (int) ( $raw['value'] ?? 0 ) ) ) : max( 0, (int) ( $raw['value'] ?? 0 ) ),
			'due_days' => min( 365, max( 0, (int) ( $raw['due_days'] ?? 0 ) ) ),
		);
	}

	/**
	 * @param mixed $raw
	 * @return array{refundable: bool, free_until_days: int, penalty: string, percent: int, text: string}
	 *   free_until_days: free cancellation up to N days before arrival.
	 *   penalty after that: none | first_night | percent (basis points) | full.
	 */
	public static function cancellation( $raw ): array {
		$raw     = is_array( $raw ) ? $raw : array();
		$penalty = (string) ( $raw['penalty'] ?? 'none' );
		$penalty = in_array( $penalty, self::PENALTIES, true ) ? $penalty : 'none';
		return array(
			'refundable'      => ! array_key_exists( 'refundable', $raw ) || (bool) $raw['refundable'],
			'free_until_days' => min( 365, max( 0, (int) ( $raw['free_until_days'] ?? 0 ) ) ),
			'penalty'         => $penalty,
			'percent'         => min( 10000, max( 0, (int) ( $raw['percent'] ?? 0 ) ) ),
			'text'            => sanitize_textarea_field( (string) ( $raw['text'] ?? '' ) ),
		);
	}

	/**
	 * The deposit to ask for a stay (cents), never more than the total.
	 *
	 * @param array{type: string, value: int, due_days: int} $policy
	 */
	public static function deposit_amount( array $policy, int $total, int $first_night ): int {
		$amount = match ( $policy['type'] ) {
			'percent'     => intdiv( $total * $policy['value'] + 5000, 10000 ),
			'fixed'       => $policy['value'],
			'first_night' => $first_night,
			'full'        => $total,
			default       => 0,
		};
		return min( $total, max( 0, $amount ) );
	}

	/**
	 * When the deposit is due (Y-m-d): today for 0 days, else N days before arrival but
	 * never before today.
	 *
	 * @param array{type: string, value: int, due_days: int} $policy
	 */
	public static function deposit_due_date( array $policy, string $arrival, string $today ): ?string {
		if ( 'none' === $policy['type'] ) {
			return null;
		}
		$due = Dates::add_days( $arrival, -$policy['due_days'] );
		return 0 === $policy['due_days'] || $due < $today ? $today : $due;
	}

	/**
	 * The penalty if the guest cancels today (cents), from the terms in the booking.
	 *
	 * @param array{refundable: bool, free_until_days: int, penalty: string, percent: int} $policy
	 */
	public static function cancellation_penalty( array $policy, string $arrival, string $today, int $total, int $first_night ): int {
		if ( ! $policy['refundable'] ) {
			return $total;
		}
		if ( Dates::diff_days( $today, $arrival ) >= $policy['free_until_days'] && $policy['free_until_days'] > 0 ) {
			return 0;
		}
		if ( 0 === $policy['free_until_days'] && 'none' === $policy['penalty'] ) {
			return 0;
		}
		return match ( $policy['penalty'] ) {
			'first_night' => min( $total, $first_night ),
			'percent'     => min( $total, intdiv( $total * $policy['percent'] + 5000, 10000 ) ),
			'full'        => $total,
			default       => 0,
		};
	}
}
