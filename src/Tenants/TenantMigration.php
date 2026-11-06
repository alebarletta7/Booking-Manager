<?php
/**
 * Links the data that existed before 0.2.0 to the first (default) tenant.
 *
 * Run only on purpose (`wp booking-manager tenant migrate`), never on page load:
 *
 * 1. refuses to start without the confirmation that a backup was made;
 * 2. stores a check snapshot (rows per table, booking and payment totals);
 * 3. creates the default tenant, or reuses the one already marked as default;
 * 4. sets tenant_id only where it is NULL, in batches: no row is deleted, duplicated
 *    or renumbered, no amount or status is touched;
 * 5. links the Booking Manager staff to the tenant (WordPress administrators stay
 *    platform administrators, outside any tenant);
 * 6. verifies the result against the snapshot.
 *
 * rollback() puts tenant_id back to NULL (the plugin then works exactly as before);
 * the real way back is restoring the backup.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

use BookingManager\Access\Capabilities;
use BookingManager\Audit\AuditLogger;
use BookingManager\Database\Migrations\Migration_20261106_000001_Tenants;
use BookingManager\Database\Migrator;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class TenantMigration {

	/** The snapshot taken before the data migration. */
	public const SNAPSHOT_OPTION = 'bkng_tenancy_snapshot';

	private const BATCH = 1000;

	public function __construct(
		private TenantRepository $tenants,
		private AuditLogger $audit
	) {}

	/** @return string[] tables that receive tenant_id */
	public static function tables(): array {
		return array_keys( Migration_20261106_000001_Tenants::COLUMNS );
	}

	/**
	 * What migrate() would do, without writing anything.
	 *
	 * @return array{schema_ready: bool, migrated: bool, default_tenant: ?array, rows: array<string, int>, users: int[]}
	 */
	public function plan(): array {
		$rows = array();
		if ( $this->schema_ready() ) {
			foreach ( self::tables() as $short ) {
				$rows[ $short ] = $this->count_null( $short );
			}
		}
		return array(
			'schema_ready'   => $this->schema_ready(),
			'migrated'       => Tenancy::migrated(),
			'default_tenant' => $this->schema_ready() ? $this->tenants->find_default() : null,
			'rows'           => $rows,
			'users'          => $this->schema_ready() ? $this->staff_without_tenant() : array(),
		);
	}

	/**
	 * @param array{name?: string, user_id?: int} $options
	 * @return array<string, mixed>|\WP_Error report: tenant, rows updated per table, users linked, verification
	 */
	public function migrate( bool $backup_done, array $options = array() ): array|\WP_Error {
		if ( ! $backup_done ) {
			return new \WP_Error( 'bkng_backup_required', __( 'Prima della migrazione fai un backup del database e conferma di averlo fatto.', 'booking-manager' ) );
		}
		if ( ! $this->schema_ready() ) {
			return new \WP_Error( 'bkng_schema_not_ready', __( 'Le tabelle dei clienti non esistono ancora: aggiorna il database del plugin e riprova.', 'booking-manager' ) );
		}
		$by = isset( $options['user_id'] ) ? (int) $options['user_id'] : null;

		$snapshot = $this->snapshot();
		update_option( self::SNAPSHOT_OPTION, $snapshot, false );

		$tenant = $this->tenants->find_default();
		if ( ! $tenant ) {
			$id = $this->tenants->create(
				array(
					'name'          => $this->default_name( (string) ( $options['name'] ?? '' ) ),
					'business_type' => 'struttura_ricettiva',
					'status'        => TenantStatus::ACTIVE,
					'plan'          => 'unlimited',
					'is_default'    => 1,
					'activated_at'  => gmdate( 'Y-m-d H:i:s' ),
					'created_by'    => $by,
				)
			);
			if ( $id <= 0 ) {
				return new \WP_Error( 'bkng_tenant_create', __( 'Impossibile creare il cliente predefinito.', 'booking-manager' ) );
			}
			$tenant = (array) $this->tenants->find( $id );
		}
		$tenant_id = (int) $tenant['id'];
		update_option( Tenancy::DEFAULT_OPTION, $tenant_id, true );
		Tenancy::flush();

		$host = Domains::normalize( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( null !== $host ) {
			$this->tenants->add_domain( $tenant_id, $host, $by );
		}

		$updated = array();
		foreach ( self::tables() as $short ) {
			$updated[ $short ] = $this->fill( $short, $tenant_id );
		}

		$linked = array();
		foreach ( $this->staff_without_tenant() as $user_id ) {
			if ( $this->tenants->add_user( $tenant_id, $user_id, $by ) ) {
				$linked[] = $user_id;
			}
		}

		$check = $this->verify();
		if ( $check['ok'] ) {
			update_option( Tenancy::MIGRATED_OPTION, gmdate( 'Y-m-d H:i:s' ), true );
		}
		$this->audit->log(
			array(
				'action'      => 'tenancy.migrated',
				'entity_type' => 'tenant',
				'entity_id'   => $tenant_id,
				'user_id'     => $by,
				'new'         => array(
					'rows'  => $updated,
					'users' => count( $linked ),
					'ok'    => $check['ok'],
				),
			)
		);
		return array(
			'tenant' => $tenant,
			'rows'   => $updated,
			'users'  => $linked,
			'verify' => $check,
		);
	}

	/**
	 * Compares the database with the snapshot taken by migrate() and checks the links.
	 * Rows added after the snapshot (the site keeps working) are not counted.
	 *
	 * @return array{ok: bool, problems: string[], checks: array<string, mixed>}
	 */
	public function verify(): array {
		$problems = array();
		$checks   = array();
		if ( ! $this->schema_ready() ) {
			return array(
				'ok'       => false,
				'problems' => array( 'schema: le tabelle dei clienti non esistono.' ),
				'checks'   => array(),
			);
		}
		$snapshot = get_option( self::SNAPSHOT_OPTION, array() );
		if ( is_array( $snapshot ) && ! empty( $snapshot['tables'] ) ) {
			$now = $this->snapshot( $snapshot['max_ids'] ?? array() );
			foreach ( $snapshot['tables'] as $table => $count ) {
				if ( ( $now['tables'][ $table ] ?? null ) !== $count ) {
					$problems[] = sprintf( 'righe di %s: %d prima, %d dopo', $table, (int) $count, (int) ( $now['tables'][ $table ] ?? 0 ) );
				}
			}
			foreach ( array( 'bookings', 'payments', 'payment_transactions' ) as $key ) {
				if ( ( $now[ $key ] ?? null ) !== ( $snapshot[ $key ] ?? null ) ) {
					$problems[] = sprintf( 'importi o stati di %s cambiati rispetto a prima della migrazione', $key );
				}
			}
			$checks['snapshot_at'] = $snapshot['taken_at'] ?? null;
		} else {
			$problems[] = 'nessuna istantanea: esegui prima la migrazione.';
		}

		$default = Tenancy::default_id();
		if ( $default <= 0 || ! $this->tenants->find( $default ) ) {
			$problems[] = 'cliente predefinito mancante.';
		}
		foreach ( self::tables() as $short ) {
			$null                       = $this->count_null( $short );
			$checks[ 'null_' . $short ] = $null;
			if ( $null > 0 ) {
				$problems[] = sprintf( '%d righe di %s senza cliente', $null, $short );
			}
		}

		global $wpdb;
		$p = $wpdb->prefix . Schema::PREFIX;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}properties pr LEFT JOIN {$p}tenants t ON t.id = pr.tenant_id WHERE t.id IS NULL" );
		$mixed   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}bookings b JOIN {$p}properties pr ON pr.id = b.property_id JOIN {$p}guests g ON g.id = b.guest_id WHERE g.tenant_id <> pr.tenant_id" );
		// phpcs:enable
		$checks['properties_without_tenant'] = $orphans;
		$checks['bookings_guest_other_tenant'] = $mixed;
		if ( $orphans > 0 ) {
			$problems[] = sprintf( '%d strutture collegate a un cliente inesistente', $orphans );
		}
		if ( $mixed > 0 ) {
			$problems[] = sprintf( '%d prenotazioni con l\'ospite di un altro cliente', $mixed );
		}
		$users                         = $this->staff_without_tenant();
		$checks['staff_without_tenant'] = count( $users );
		if ( $users ) {
			$problems[] = sprintf( '%d utenti dello staff senza cliente', count( $users ) );
		}

		return array(
			'ok'       => ! $problems,
			'problems' => $problems,
			'checks'   => $checks,
		);
	}

	/**
	 * Puts tenant_id back to NULL and forgets the default tenant. Nothing is deleted:
	 * tenants, users and domains stay and are reused by the next migrate().
	 *
	 * @return array<string, int> rows changed per table
	 */
	public function rollback( ?int $by = null ): array {
		global $wpdb;
		$changed = array();
		if ( ! $this->schema_ready() ) {
			return $changed;
		}
		foreach ( self::tables() as $short ) {
			$table = Schema::table( $short );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$changed[ $short ] = (int) $wpdb->query( "UPDATE {$table} SET tenant_id = NULL WHERE tenant_id IS NOT NULL" );
		}
		delete_option( Tenancy::DEFAULT_OPTION );
		delete_option( Tenancy::MIGRATED_OPTION );
		Tenancy::flush();
		$this->audit->log(
			array(
				'action'      => 'tenancy.rolled_back',
				'entity_type' => 'tenant',
				'user_id'     => $by,
				'new'         => array( 'rows' => $changed ),
			)
		);
		return $changed;
	}

	/**
	 * Rows per table and the totals that must not change, limited to the rows that
	 * existed when the snapshot was taken ($max_ids from that snapshot, or now).
	 *
	 * @param array<string, int> $max_ids
	 * @return array<string, mixed>
	 */
	public function snapshot( array $max_ids = array() ): array {
		global $wpdb;
		$tables = array();
		$taken  = array();
		foreach ( Schema::tables() as $short ) {
			if ( in_array( $short, array( 'migrations', 'tenants', 'tenant_users', 'tenant_domains' ), true ) || ! $this->has_column( $short, 'id' ) ) {
				continue;
			}
			$table = Schema::table( $short );
			$max   = $max_ids[ $short ] ?? null;
			if ( null === $max ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				$max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table}" );
			}
			$taken[ $short ] = (int) $max;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$tables[ $short ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id <= %d", $max ) );
		}
		$p = $wpdb->prefix . Schema::PREFIX;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$bookings     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, payment_status, COUNT(*) n, SUM(total) total, SUM(paid) paid, SUM(refunded) refunded, SUM(balance) balance, SUM(property_id) props, SUM(COALESCE(guest_id, 0)) guests FROM {$p}bookings WHERE id <= %d GROUP BY status, payment_status ORDER BY status, payment_status", $taken['bookings'] ?? 0 ), ARRAY_A );
		$payments     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n, SUM(amount) amount, SUM(refunded) refunded, SUM(booking_id) bookings FROM {$p}payments WHERE id <= %d GROUP BY status ORDER BY status", $taken['payments'] ?? 0 ), ARRAY_A );
		$transactions = (array) $wpdb->get_results( $wpdb->prepare( "SELECT type, status, COUNT(*) n, SUM(amount) amount FROM {$p}payment_transactions WHERE id <= %d GROUP BY type, status ORDER BY type, status", $taken['payment_transactions'] ?? 0 ), ARRAY_A );
		// phpcs:enable
		return array(
			'taken_at'             => gmdate( 'Y-m-d H:i:s' ),
			'max_ids'              => $taken,
			'tables'               => $tables,
			'bookings'             => $bookings,
			'payments'             => $payments,
			'payment_transactions' => $transactions,
		);
	}

	public function schema_ready(): bool {
		global $wpdb;
		$table = Schema::table( 'tenants' );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) && $this->has_column( 'properties', 'tenant_id' ) && in_array( Migrator::last_id(), array( (string) get_option( Migrator::LAST_OPTION ) ), true );
	}

	/** Sets tenant_id where it is NULL, 1000 rows at a time. */
	private function fill( string $short, int $tenant_id ): int {
		global $wpdb;
		$table = Schema::table( $short );
		$total = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$n      = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET tenant_id = %d WHERE tenant_id IS NULL LIMIT %d", $tenant_id, self::BATCH ) );
			$total += max( 0, $n );
		} while ( $n >= self::BATCH );
		return $total;
	}

	private function count_null( string $short ): int {
		global $wpdb;
		$table = Schema::table( $short );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE tenant_id IS NULL" );
	}

	private function has_column( string $short, string $column ): bool {
		global $wpdb;
		$table = Schema::table( $short );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
	}

	/**
	 * Users with a Booking Manager role who belong to no tenant. WordPress
	 * administrators are excluded: they run the platform.
	 *
	 * @return int[]
	 */
	private function staff_without_tenant(): array {
		$ids = get_users(
			array(
				'role__in' => array_keys( Capabilities::role_names() ),
				'fields'   => 'ID',
				'number'   => -1,
			)
		);
		$out = array();
		foreach ( array_map( 'intval', (array) $ids ) as $user_id ) {
			if ( ! user_can( $user_id, 'manage_options' ) && 0 === $this->tenants->tenant_of_user( $user_id ) ) {
				$out[] = $user_id;
			}
		}
		return $out;
	}

	private function default_name( string $given ): string {
		$given = sanitize_text_field( $given );
		if ( '' !== $given ) {
			return mb_substr( $given, 0, 190 );
		}
		global $wpdb;
		$table = Schema::table( 'properties' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row  = $wpdb->get_row( "SELECT name, company_name FROM {$table} ORDER BY id LIMIT 1", ARRAY_A );
		$name = trim( (string) ( $row['company_name'] ?? '' ) );
		$name = '' !== $name ? $name : trim( (string) ( $row['name'] ?? '' ) );
		$name = '' !== $name ? $name : trim( (string) get_bloginfo( 'name' ) );
		return mb_substr( '' !== $name ? $name : 'Cliente predefinito', 0, 190 );
	}
}
