<?php
/**
 * Reading the operations log, newest first. Entries of a property and entries that belong
 * to no property (customers, staff) are shown together.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Audit;

use BookingManager\Core\Clock;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class AuditLogReader {

	/**
	 * Dates "from"/"to" are days of the property's calendar (timezone $tz), not UTC days.
	 *
	 * @param array{entity_type?: string, entity_id?: int, action?: string, user_id?: int, from?: string, to?: string, page?: int, per_page?: int} $filters
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function search( int $property_id, array $filters, string $tz = 'UTC' ): array {
		global $wpdb;
		$table  = Schema::table( 'audit_log' );
		$where  = array( '(property_id = %d OR property_id IS NULL)' );
		$params = array( $property_id );

		if ( ! empty( $filters['entity_type'] ) ) {
			$where[]  = 'entity_type = %s';
			$params[] = $filters['entity_type'];
		}
		if ( ! empty( $filters['entity_id'] ) ) {
			$where[]  = 'entity_id = %d';
			$params[] = (int) $filters['entity_id'];
		}
		if ( ! empty( $filters['action'] ) ) {
			$where[]  = 'action LIKE %s';
			$params[] = $wpdb->esc_like( $filters['action'] ) . '%';
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = self::local_to_utc( $filters['from'] . ' 00:00:00', $tz );
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[]  = 'created_at < %s';
			$params[] = self::local_to_utc( gmdate( 'Y-m-d', (int) strtotime( $filters['to'] . ' UTC +1 day' ) ) . ' 00:00:00', $tz );
		}

		$per_page = max( 1, min( 100, (int) ( $filters['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$sql      = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name internal, fixed fragments.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql}", $params ) );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ),
			ARRAY_A
		);
		// phpcs:enable

		$names = array();
		$items = array();
		foreach ( $rows as $r ) {
			$user_id = null === $r['user_id'] ? null : (int) $r['user_id'];
			if ( $user_id && ! array_key_exists( $user_id, $names ) ) {
				$user              = get_userdata( $user_id );
				$names[ $user_id ] = $user ? $user->display_name : null;
			}
			$items[] = array(
				'id'          => (int) $r['id'],
				'created_at'  => Clock::iso( $r['created_at'] ),
				'user_id'     => $user_id,
				'user_name'   => $user_id ? $names[ $user_id ] : null,
				'actor_type'  => (string) $r['actor_type'],
				'action'      => (string) $r['action'],
				'entity_type' => (string) $r['entity_type'],
				'entity_id'   => null === $r['entity_id'] ? null : (int) $r['entity_id'],
				'property_id' => null === $r['property_id'] ? null : (int) $r['property_id'],
				'old'         => null === $r['old_values'] ? null : json_decode( (string) $r['old_values'], true ),
				'new'         => null === $r['new_values'] ? null : json_decode( (string) $r['new_values'], true ),
				'reason'      => $r['reason'],
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/** "Y-m-d H:i:s" in $tz → the same instant in UTC. */
	public static function local_to_utc( string $local, string $tz ): string {
		try {
			$zone = new \DateTimeZone( $tz );
		} catch ( \Exception $e ) {
			$zone = new \DateTimeZone( 'UTC' );
		}
		return ( new \DateTimeImmutable( $local, $zone ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
