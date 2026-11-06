<?php
/**
 * Optional supplements that include free cancellation (e.g. «Cancellazione gratuita»
 * for 15 € or 10%): rate_adjustments.free_cancel_days, the days before arrival up to
 * which the booking can then be cancelled for free. NULL: a plain supplement.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database\Migrations;

use BookingManager\Database\Migration;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Migration_20261025_000001_CancellationOption extends Migration {

	public function up( \wpdb $wpdb ): void {
		$table = Schema::table( 'rate_adjustments' );
		// Idempotent: the column may exist after a crash between ALTER and the record.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'free_cancel_days' ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name is internal.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN free_cancel_days smallint(5) unsigned NULL AFTER is_public" );
		}
	}
}
