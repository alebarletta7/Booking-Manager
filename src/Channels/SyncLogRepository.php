<?php
/**
 * Register of exchanges with channels (bkng_sync_log): direction, record, outcome and a
 * short message. No guest data: ids and outcomes only.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class SyncLogRepository {

	public const IN  = 'in';
	public const OUT = 'out';

	/**
	 * A new row. With an idempotency key already used, nothing is written and 0 returned:
	 * the same message from a channel is handled once.
	 *
	 * @param array<string, mixed> $row
	 */
	public function log( array $row ): int {
		global $wpdb;
		$t    = Schema::table( 'sync_log' );
		$data = array(
			'property_id'     => isset( $row['property_id'] ) ? (int) $row['property_id'] : null,
			'channel'         => substr( (string) $row['channel'], 0, 40 ),
			'direction'       => (string) $row['direction'],
			'entity_type'     => $row['entity_type'] ?? null,
			'entity_id'       => isset( $row['entity_id'] ) ? (int) $row['entity_id'] : null,
			'external_id'     => isset( $row['external_id'] ) ? substr( (string) $row['external_id'], 0, 190 ) : null,
			'idempotency_key' => isset( $row['idempotency_key'] ) ? substr( (string) $row['idempotency_key'], 0, 120 ) : null,
			'status'          => (string) $row['status'],
			'message'         => isset( $row['message'] ) ? mb_substr( (string) $row['message'], 0, 255 ) : null,
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
		);
		$cols  = array();
		$marks = array();
		$vals  = array();
		foreach ( $data as $col => $value ) {
			$cols[] = $col;
			if ( null === $value ) {
				$marks[] = 'NULL';
				continue;
			}
			$marks[] = is_int( $value ) ? '%d' : '%s';
			$vals[]  = $value;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fixed columns, placeholders built above.
		$n = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', $marks ) . ')', $vals ) );
		return 1 === (int) $n ? (int) $wpdb->insert_id : 0;
	}

	/** @return array<string, mixed>|null */
	public function by_key( string $key ): ?array {
		global $wpdb;
		$t = Schema::table( 'sync_log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE idempotency_key = %s", $key ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Sets the outcome of a row written before the work was done. A failed row frees its
	 * idempotency key: the channel's next attempt is handled again (a room may be free now).
	 */
	public function finish( int $id, string $status, ?int $entity_id, ?string $message ): void {
		global $wpdb;
		$data = array(
			'status'    => $status,
			'entity_id' => $entity_id,
			'message'   => null === $message ? null : mb_substr( $message, 0, 255 ),
		);
		if ( 'error' === $status ) {
			$data['idempotency_key'] = null;
		}
		$wpdb->update( Schema::table( 'sync_log' ), $data, array( 'id' => $id ) );
	}

	/** @return array<int, array<string, mixed>> */
	public function recent( int $property_id, int $limit = 50 ): array {
		global $wpdb;
		$t = Schema::table( 'sync_log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE property_id = %d ORDER BY id DESC LIMIT %d", $property_id, max( 1, min( 200, $limit ) ) ), ARRAY_A );
	}

	/** Deletes rows older than $months months (0 = keep). */
	public function purge( int $months, int $now ): int {
		global $wpdb;
		if ( $months <= 0 ) {
			return 0;
		}
		$t = Schema::table( 'sync_log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s LIMIT 5000", gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months', $now ) ) ) );
	}
}
