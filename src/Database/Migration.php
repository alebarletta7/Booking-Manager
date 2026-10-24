<?php
/**
 * Base class for versioned migrations.
 *
 * Rules:
 * - migrations are forward-only and must be idempotent (safe to re-run after a crash);
 * - a migration never edits an earlier one: schema changes always go in a new class;
 * - the class name defines the order: Migration_YYYYMMDD_NNNNNN_Description.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database;

defined( 'ABSPATH' ) || exit;

abstract class Migration {

	abstract public function up( \wpdb $wpdb ): void;

	/** Stable identifier stored in the migrations table. */
	public function id(): string {
		$parts = explode( '\\', static::class );
		return (string) end( $parts );
	}

	/**
	 * Runs dbDelta for the given CREATE TABLE statements.
	 *
	 * @param string[] $statements
	 * @return string[] dbDelta messages
	 */
	protected function delta( array $statements ): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		return dbDelta( $statements );
	}
}
