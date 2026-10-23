<?php
/**
 * Thrown inside a transaction when MySQL gave up on it (deadlock, lock wait timeout).
 *
 * InnoDB has already rolled the transaction back at that point: going on would run the
 * next statements outside any transaction and without the day lock, so the work must
 * stop and start again from the beginning (Transaction::run_retrying).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database;

defined( 'ABSPATH' ) || exit;

final class LockFailed extends \RuntimeException {

	/** Throws when the last query failed because of a deadlock or a lock wait timeout. */
	public static function check(): void {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		if ( '' !== $error && ( false !== stripos( $error, 'deadlock' ) || false !== stripos( $error, 'lock wait timeout' ) ) ) {
			throw new self( $error );
		}
	}
}
