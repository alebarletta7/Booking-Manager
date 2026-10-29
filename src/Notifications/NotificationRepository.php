<?php
/**
 * The notifications outbox (bkng_notifications): one row per message and channel, with
 * its status, attempts and last error. Rows are claimed with a conditional update, so
 * two cron runs never send the same message twice.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class NotificationRepository {

	public const QUEUED  = 'queued';
	public const SENDING = 'sending';
	public const SENT    = 'sent';
	public const FAILED  = 'failed';
	public const SKIPPED = 'skipped';

	public const STATUSES = array( self::QUEUED, self::SENDING, self::SENT, self::FAILED, self::SKIPPED );

	/**
	 * New queued row. Returns 0 when the dedupe key already exists (same message for the
	 * same booking version already queued).
	 *
	 * @param array<string, mixed> $row
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$row += array(
			'status'          => self::QUEUED,
			'attempts'        => 0,
			'next_attempt_at' => $now,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$t       = Schema::table( 'notifications' );
		$columns = array();
		$marks   = array();
		$values  = array();
		foreach ( $row as $column => $value ) {
			if ( ! preg_match( '/^[a-z_]+$/', (string) $column ) ) {
				continue;
			}
			$columns[] = $column;
			if ( null === $value ) {
				$marks[] = 'NULL';
				continue;
			}
			$marks[]  = is_int( $value ) ? '%d' : '%s';
			$values[] = $value;
		}
		// INSERT IGNORE: the unique dedupe_key turns a second identical event into a no-op.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- whitelisted columns, placeholders built above.
		$n = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (" . implode( ',', $columns ) . ') VALUES (' . implode( ',', $marks ) . ')', $values ) );
		return 1 === (int) $n ? (int) $wpdb->insert_id : 0;
	}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$t = Schema::table( 'notifications' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/** @return int[] ids of queued rows whose time has come. */
	public function due( string $now, int $limit ): array {
		global $wpdb;
		$t = Schema::table( 'notifications' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = %s AND next_attempt_at <= %s ORDER BY next_attempt_at ASC, id ASC LIMIT %d", self::QUEUED, $now, $limit ) ) );
	}

	/** Takes a queued row for sending; false when another process took it first. */
	public function claim( int $id, string $now ): bool {
		global $wpdb;
		$t = Schema::table( 'notifications' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = %s AND next_attempt_at <= %s", self::SENDING, $now, $id, self::QUEUED, $now ) );
		return 1 === (int) $n;
	}

	/**
	 * Rows left "sending" by a request that died more than 15 minutes ago: they may have
	 * gone out, so they are closed as failed with an explanation, not sent again.
	 */
	public function fail_stale( string $now, string $error ): int {
		global $wpdb;
		$t     = Schema::table( 'notifications' );
		$stale = gmdate( 'Y-m-d H:i:s', (int) strtotime( $now . ' UTC' ) - 15 * MINUTE_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = %s, last_error = %s, updated_at = %s WHERE status = %s AND updated_at < %s", self::FAILED, $error, $now, self::SENDING, $stale ) );
	}

	/**
	 * A message by the provider's id (WhatsApp delivery updates).
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_provider_id( string $channel, string $provider_id ): ?array {
		global $wpdb;
		$t = Schema::table( 'notifications' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE provider_message_id = %s AND channel = %s ORDER BY id DESC LIMIT 1", $provider_id, $channel ), ARRAY_A );
		return $row ?: null;
	}

	/** @param array<string, mixed> $data */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		return false !== $wpdb->update( Schema::table( 'notifications' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Log with filters, newest first.
	 *
	 * @param int[]|null           $properties null = every property.
	 * @param array<string, mixed> $f {property_id?, booking_id?, status?, channel?, page?, per_page?}
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function search( ?array $properties, array $f ): array {
		global $wpdb;
		$t      = Schema::table( 'notifications' );
		$where  = array( '1=1' );
		$params = array();
		if ( is_array( $properties ) ) {
			if ( ! $properties ) {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}
			$where[] = 'property_id IN (' . implode( ',', array_map( 'intval', $properties ) ) . ')';
		}
		foreach ( array( 'property_id' => '%d', 'booking_id' => '%d', 'status' => '%s', 'channel' => '%s' ) as $key => $mark ) {
			if ( ! empty( $f[ $key ] ) ) {
				$where[]  = "{$key} = {$mark}";
				$params[] = $f[ $key ];
			}
		}
		$per_page = max( 1, min( 200, (int) ( $f['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $f['page'] ?? 1 ) );
		$sql      = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- internal table, fixed fragments.
		$count = "SELECT COUNT(*) FROM {$t} WHERE {$sql}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : $wpdb->get_var( $count ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => $rows,
			'total' => $total,
		);
	}

	/**
	 * Counts per status since a UTC moment (dashboard warnings).
	 *
	 * @param int[]|null $properties
	 * @return array<string, int>
	 */
	public function counts_since( ?array $properties, string $utc ): array {
		global $wpdb;
		$t   = Schema::table( 'notifications' );
		$out = array_fill_keys( self::STATUSES, 0 );
		if ( is_array( $properties ) && ! $properties ) {
			return $out;
		}
		$in = is_array( $properties ) ? ' AND property_id IN (' . implode( ',', array_map( 'intval', $properties ) ) . ')' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name, integer list.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$t} WHERE created_at >= %s{$in} GROUP BY status", $utc ), ARRAY_A );
		foreach ( $rows as $r ) {
			$out[ (string) $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** Deletes finished rows older than $utc (queued ones are kept). */
	public function purge_before( string $utc ): int {
		global $wpdb;
		$t = Schema::table( 'notifications' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s AND status IN ('sent','failed','skipped') LIMIT 5000", $utc ) );
	}
}
