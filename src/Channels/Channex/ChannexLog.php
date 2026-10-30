<?php
/**
 * State and registers of the Channex sync, per property:
 *
 * - bkng_cx_state: pause after errors, last calls (spacing), last full sync, feed and
 *   reconciliation;
 * - bkng_cx_tasks: calls that sent availability or rates (Channex task id, warnings);
 * - bkng_cx_webhook_log: calls received (event, signature valid, hash of the body).
 *
 * No guest data in any of them.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class ChannexLog {

	public const STATE_FIELDS = array( 'paused_until', 'consecutive_failures', 'last_avail_call_at', 'last_restr_call_at', 'last_full_sync_at', 'last_feed_poll_at', 'last_reconcile_at', 'last_error' );

	/* ------------------------------------------------------------------ State */

	/** @return array<string, mixed> */
	public function state( int $property_id ): array {
		global $wpdb;
		$t = Schema::table( 'cx_state' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE property_id = %d", $property_id ), ARRAY_A );
		$out = array_fill_keys( self::STATE_FIELDS, null );
		$out['consecutive_failures'] = 0;
		if ( $row ) {
			$out = array_merge( $out, array_intersect_key( $row, $out ) );
			$out['consecutive_failures'] = (int) $row['consecutive_failures'];
		}
		$out['property_id'] = $property_id;
		return $out;
	}

	/** @param array<string, mixed> $changes */
	public function set_state( int $property_id, array $changes ): void {
		global $wpdb;
		$changes = array_intersect_key( $changes, array_flip( self::STATE_FIELDS ) );
		if ( isset( $changes['last_error'] ) ) {
			$changes['last_error'] = mb_substr( (string) $changes['last_error'], 0, 255 );
		}
		$t    = Schema::table( 'cx_state' );
		$now  = gmdate( 'Y-m-d H:i:s' );
		$cols = array( 'property_id', 'updated_at' );
		$vals = array( $property_id, $now );
		$fmt  = array( '%d', '%s' );
		$upd  = array( 'updated_at = VALUES(updated_at)' );
		foreach ( $changes as $col => $value ) {
			$cols[] = $col;
			$vals[] = $value;
			$fmt[]  = null === $value ? 'NULL' : ( is_int( $value ) ? '%d' : '%s' );
			$upd[]  = $col . ' = VALUES(' . $col . ')';
		}
		$vals = array_values( array_filter( $vals, static fn( $v ) => null !== $v ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fixed columns, placeholders built above.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', $fmt ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $upd ), $vals ) );
	}

	/* ------------------------------------------------------------------ Tasks */

	/**
	 * @param array<int, mixed> $warnings
	 */
	public function task( int $property_id, string $kind, ?string $task_id, int $count, int $status, array $warnings, ?string $error ): int {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'cx_tasks' ),
			array(
				'property_id'     => $property_id,
				'kind'            => $kind,
				'channex_task_id' => $task_id,
				'values_count'    => $count,
				'http_status'     => $status,
				'warnings'        => $warnings ? (string) wp_json_encode( array_slice( $warnings, 0, 200 ) ) : null,
				'error'           => null === $error ? null : mb_substr( $error, 0, 255 ),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @return array<int, array<string, mixed>> latest calls of a property */
	public function tasks( int $property_id, int $limit = 50 ): array {
		global $wpdb;
		$t = Schema::table( 'cx_tasks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE property_id = %d ORDER BY id DESC LIMIT %d", $property_id, max( 1, min( 500, $limit ) ) ), ARRAY_A );
		return array_map(
			static function ( array $r ): array {
				$warnings = json_decode( (string) $r['warnings'], true );
				return array(
					'id'       => (int) $r['id'],
					'kind'     => (string) $r['kind'],
					'task_id'  => $r['channex_task_id'],
					'values'   => (int) $r['values_count'],
					'status'   => (int) $r['http_status'],
					'warnings' => is_array( $warnings ) ? $warnings : array(),
					'error'    => $r['error'],
					'at'       => \BookingManager\Core\Clock::iso( (string) $r['created_at'] ),
				);
			},
			$rows
		);
	}

	/** Calls with warnings in the last $hours hours. */
	public function warnings_since( int $property_id, int $hours ): int {
		global $wpdb;
		$t = Schema::table( 'cx_tasks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE property_id = %d AND warnings IS NOT NULL AND created_at >= %s", $property_id, gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ) ) );
	}

	/* ------------------------------------------------------------------ Webhooks */

	public function webhook( string $event, ?int $property_id, ?string $channex_property_id, bool $valid, string $raw ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'cx_webhook_log' ),
			array(
				'event'               => mb_substr( '' !== $event ? $event : 'unknown', 0, 64 ),
				'property_id'         => $property_id,
				'channex_property_id' => null === $channex_property_id ? null : substr( $channex_property_id, 0, 36 ),
				'signature_valid'     => $valid ? 1 : 0,
				'payload_hash'        => hash( 'sha256', $raw ),
				'received_at'         => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function webhooks( ?int $property_id, int $limit = 30 ): array {
		global $wpdb;
		$t = Schema::table( 'cx_webhook_log' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = null === $property_id
			? (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A )
			: (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE property_id = %d OR property_id IS NULL ORDER BY id DESC LIMIT %d", $property_id, $limit ), ARRAY_A );
		// phpcs:enable
		return array_map(
			static fn( array $r ) => array(
				'event' => (string) $r['event'],
				'valid' => (bool) (int) $r['signature_valid'],
				'at'    => \BookingManager\Core\Clock::iso( (string) $r['received_at'] ),
			),
			$rows
		);
	}

	/** Deletes calls and webhooks older than $months months (0 = keep). */
	public function purge( int $months, int $now ): void {
		global $wpdb;
		if ( $months <= 0 ) {
			return;
		}
		$before = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months', $now ) );
		foreach ( array( 'cx_tasks' => 'created_at', 'cx_webhook_log' => 'received_at' ) as $table => $col ) {
			$t = Schema::table( $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table and column names.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE {$col} < %s LIMIT 5000", $before ) );
		}
	}
}
