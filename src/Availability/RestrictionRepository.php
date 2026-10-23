<?php
/**
 * Data access for bkng_restrictions (regole di soggiorno e chiusure).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Availability;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class RestrictionRepository {

	public const FIELDS = array( 'room_type_id', 'rate_plan_id', 'date_from', 'date_to', 'min_stay', 'max_stay', 'min_advance_days', 'max_advance_days', 'arrival_weekdays', 'departure_weekdays', 'closed', 'closed_to_arrival', 'closed_to_departure', 'note' );

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'restrictions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Restrictions of a property that touch [from, to] (both inclusive).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function between( int $property_id, string $from, string $to ): array {
		global $wpdb;
		$table = Schema::table( 'restrictions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE property_id = %d AND date_from <= %s AND date_to >= %s ORDER BY date_from, id", $property_id, $to, $from ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** @param array<string, mixed> $data */
	public function create( int $property_id, array $data, int $user_id ): int {
		global $wpdb;
		$now                = gmdate( 'Y-m-d H:i:s' );
		$row                = array_intersect_key( $data, array_flip( self::FIELDS ) );
		$row['property_id'] = $property_id;
		$row['created_by']  = $user_id ? $user_id : null;
		$row['created_at']  = $now;
		$row['updated_at']  = $now;
		$wpdb->insert( Schema::table( 'restrictions' ), $row );
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $data */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$row               = array_intersect_key( $data, array_flip( self::FIELDS ) );
		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( Schema::table( 'restrictions' ), $row, array( 'id' => $id ) );
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'restrictions' ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		$row['id']          = (int) $row['id'];
		$row['property_id'] = (int) $row['property_id'];
		foreach ( array( 'room_type_id', 'rate_plan_id', 'min_stay', 'max_stay', 'min_advance_days', 'max_advance_days' ) as $key ) {
			$row[ $key ] = null !== $row[ $key ] ? (int) $row[ $key ] : null;
		}
		foreach ( array( 'closed', 'closed_to_arrival', 'closed_to_departure' ) as $key ) {
			$row[ $key ] = (bool) (int) $row[ $key ];
		}
		return $row;
	}
}
