<?php
/**
 * Runs pending migrations under a database lock and records them.
 *
 * Triggered on activation AND on every request where the stored database version is
 * older than the code (plugin updated by uploading a new zip without reactivation).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Database;

defined( 'ABSPATH' ) || exit;

class Migrator {

	/**
	 * Ordered list of migrations. Append only — never reorder or remove entries.
	 *
	 * @return class-string<Migration>[]
	 */
	public static function migrations(): array {
		return array(
			Migrations\Migration_20261023_000001_Initial::class,
			Migrations\Migration_20261025_000001_CancellationOption::class,
			Migrations\Migration_20261027_000001_WhatsAppDelivery::class,
			Migrations\Migration_20261029_000001_Channex::class,
		);
	}

	/** Id of the last migration applied, so a migration added within a version still runs. */
	public const LAST_OPTION = 'bkng_db_last_migration';

	public static function needs_migration(): bool {
		return get_option( Schema::DB_VERSION_OPTION ) !== Schema::VERSION || get_option( self::LAST_OPTION ) !== self::last_id();
	}

	/** Id of the newest migration in the code. */
	public static function last_id(): string {
		$all   = self::migrations();
		$parts = explode( '\\', (string) end( $all ) );
		return (string) end( $parts );
	}

	/**
	 * @return array{status: string, applied: string[], error?: string}
	 */
	public function run(): array {
		global $wpdb;

		$lock = $this->lock_name();
		// GET_LOCK with timeout 0: if another request is already migrating, do nothing.
		$got_lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
		if ( 1 !== $got_lock ) {
			return array(
				'status'  => 'locked',
				'applied' => array(),
			);
		}

		$applied_now = array();
		try {
			$this->ensure_migrations_table();
			$done  = $this->applied();
			$batch = $this->next_batch();

			foreach ( self::migrations() as $class ) {
				$migration = new $class();
				if ( in_array( $migration->id(), $done, true ) ) {
					continue;
				}
				$suppress = $wpdb->suppress_errors( true );
				$migration->up( $wpdb );
				$wpdb->suppress_errors( $suppress );
				if ( '' !== $wpdb->last_error ) {
					throw new \RuntimeException( $migration->id() . ': ' . $wpdb->last_error );
				}
				$wpdb->insert(
					Schema::table( 'migrations' ),
					array(
						'migration'  => $migration->id(),
						'batch'      => $batch,
						'applied_at' => gmdate( 'Y-m-d H:i:s' ),
					),
					array( '%s', '%d', '%s' )
				);
				$applied_now[] = $migration->id();
			}

			$missing = $this->missing_tables();
			if ( $missing ) {
				throw new \RuntimeException( 'Missing tables after migration: ' . implode( ', ', $missing ) );
			}

			update_option( Schema::DB_VERSION_OPTION, Schema::VERSION, true );
			update_option( self::LAST_OPTION, self::last_id(), true );

			/**
			 * Fires after migrations ran successfully.
			 *
			 * @param string[] $applied_now Migration ids applied in this run.
			 */
			do_action( \BookingManager\Core\Hooks::MIGRATED, $applied_now ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- bkng_migrated.

			return array(
				'status'  => 'ok',
				'applied' => $applied_now,
			);
		} catch ( \Throwable $e ) {
			update_option( 'bkng_migration_error', $e->getMessage(), false );
			return array(
				'status'  => 'error',
				'applied' => $applied_now,
				'error'   => $e->getMessage(),
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** @return string[] */
	public function applied(): array {
		global $wpdb;
		$table = Schema::table( 'migrations' );
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT migration FROM {$table} ORDER BY id" ) );
	}

	/** @return string[] short names of expected tables that do not exist */
	public function missing_tables(): array {
		$missing = array();
		foreach ( Schema::tables() as $short ) {
			if ( ! $this->table_exists( Schema::table( $short ) ) ) {
				$missing[] = $short;
			}
		}
		return $missing;
	}

	public function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private function ensure_migrations_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = Schema::table( 'migrations' );
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  migration varchar(190) NOT NULL,
  batch int(10) unsigned NOT NULL,
  applied_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY migration (migration)
) {$wpdb->get_charset_collate()};"
		);
	}

	private function next_batch(): int {
		global $wpdb;
		$table = Schema::table( 'migrations' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return 1 + (int) $wpdb->get_var( "SELECT COALESCE(MAX(batch), 0) FROM {$table}" );
	}

	private function lock_name(): string {
		global $wpdb;
		// One lock per site (multisite) and per database prefix.
		return 'bkng_migrate_' . substr( md5( $wpdb->prefix ), 0, 12 );
	}
}
