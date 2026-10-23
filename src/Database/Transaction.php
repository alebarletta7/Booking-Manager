<?php
/**
 * Runs a callback inside an InnoDB transaction.
 *
 * Nested calls reuse the outer transaction. When the caller already owns a transaction
 * (e.g. the WordPress test suite wraps every test in one), set $use_savepoints so we
 * open a SAVEPOINT instead of an implicit COMMIT of the caller's work.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database;

defined( 'ABSPATH' ) || exit;

final class Transaction {

	public static bool $use_savepoints = false;

	private static int $depth = 0;

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 * @throws \Throwable Re-thrown after rollback.
	 */
	public static function run( callable $callback ) {
		global $wpdb;
		$outer = 0 === self::$depth;
		if ( $outer ) {
			if ( self::$use_savepoints ) {
				$wpdb->query( 'SAVEPOINT bkng_tx' );
			} else {
				$wpdb->query( 'START TRANSACTION' );
			}
		}
		++self::$depth;
		try {
			$result = $callback();
			--self::$depth;
			if ( $outer ) {
				if ( self::$use_savepoints ) {
					$wpdb->query( 'RELEASE SAVEPOINT bkng_tx' );
				} else {
					$wpdb->query( 'COMMIT' );
				}
			}
			return $result;
		} catch ( \Throwable $e ) {
			--self::$depth;
			if ( $outer ) {
				if ( self::$use_savepoints ) {
					$wpdb->query( 'ROLLBACK TO SAVEPOINT bkng_tx' );
				} else {
					$wpdb->query( 'ROLLBACK' );
				}
			}
			throw $e;
		}
	}

	/**
	 * Like run(), but starts again (up to $attempts times, with a short random pause)
	 * when MySQL aborted the transaction with a deadlock or a lock timeout: the whole
	 * transaction was rolled back, so running the callback again is safe.
	 *
	 * Inside an outer transaction (or with savepoints) there is nothing to retry from
	 * here: the error goes up to whoever owns the transaction.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 * @throws \Throwable The last error when every attempt failed.
	 */
	public static function run_retrying( callable $callback, int $attempts = 3 ) {
		$attempt = 0;
		while ( true ) {
			try {
				return self::run( $callback );
			} catch ( LockFailed $e ) {
				++$attempt;
				if ( $attempt >= $attempts || self::$depth > 0 || self::$use_savepoints ) {
					throw $e;
				}
				usleep( random_int( 20000, 120000 ) );
			}
		}
	}
}
