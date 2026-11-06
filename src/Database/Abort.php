<?php
/**
 * Thrown inside Transaction::run() to roll back everything written so far and give the
 * caller a WP_Error: returning the error from the callback would COMMIT partial writes.
 *
 *     try {
 *         return Transaction::run_retrying( function () { … throw new Abort( $error ); … } );
 *     } catch ( Abort $e ) {
 *         return $e->error;
 *     }
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database;

defined( 'ABSPATH' ) || exit;

final class Abort extends \RuntimeException {

	public function __construct( public readonly \WP_Error $error ) {
		parent::__construct( $error->get_error_code() );
	}

	/**
	 * Runs $callback in a retrying transaction; an Abort becomes its WP_Error.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T|\WP_Error
	 */
	public static function run( callable $callback ) {
		try {
			return Transaction::run_retrying( $callback );
		} catch ( Abort $e ) {
			return $e->error;
		} catch ( LockFailed $e ) {
			return new \WP_Error( 'bkng_busy', __( 'Il sistema è occupato da un\'altra operazione sulle stesse camere. Riprova.', 'booking-manager' ), array( 'status' => 503 ) );
		}
	}
}
