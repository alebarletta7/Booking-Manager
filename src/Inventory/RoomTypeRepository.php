<?php
/**
 * Data access for bkng_room_types (tipologie). No permission checks here.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Inventory;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class RoomTypeRepository {

	public const FIELDS = array( 'name', 'slug', 'description', 'max_adults', 'max_children', 'max_occupancy', 'base_occupancy', 'base_price', 'extra_adult_price', 'extra_child_price', 'size_sqm', 'beds', 'images', 'amenities', 'is_hidden', 'online_enabled', 'sort_order' );

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = Schema::table( 'room_types' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, array<string, mixed>> id => row
	 */
	public function find_many( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$table = Schema::table( 'room_types' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name and integer list.
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')', ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$row               = self::hydrate( $row );
			$out[ $row['id'] ] = $row;
		}
		return $out;
	}

	/** @return array<int, array<string, mixed>> */
	public function for_property( int $property_id, bool $include_archived = false ): array {
		global $wpdb;
		$table  = Schema::table( 'room_types' );
		$status = $include_archived ? '' : " AND status = 'active'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE property_id = %d{$status} ORDER BY sort_order ASC, name ASC, id ASC", $property_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	public function slug_taken( int $property_id, string $slug, int $except_id = 0 ): bool {
		global $wpdb;
		$table = Schema::table( 'room_types' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE property_id = %d AND slug = %s AND id <> %d", $property_id, $slug, $except_id ) );
	}

	public function unique_slug( int $property_id, string $name, int $except_id = 0 ): string {
		$base = sanitize_title( $name );
		$base = '' !== $base ? substr( $base, 0, 180 ) : 'camera';
		$slug = $base;
		for ( $i = 2; $this->slug_taken( $property_id, $slug, $except_id ); $i++ ) {
			$slug = $base . '-' . $i;
		}
		return $slug;
	}

	/** @param array<string, mixed> $data */
	public function create( int $property_id, array $data ): int {
		global $wpdb;
		$now                = gmdate( 'Y-m-d H:i:s' );
		$row                = self::encode( array_intersect_key( $data, array_flip( self::FIELDS ) ) );
		$row['uuid']        = wp_generate_uuid4();
		$row['property_id'] = $property_id;
		$row['slug']        = $this->unique_slug( $property_id, (string) ( $data['slug'] ?? $data['name'] ?? '' ) );
		$row['status']      = 'active';
		$row['created_at']  = $now;
		$row['updated_at']  = $now;
		$wpdb->insert( Schema::table( 'room_types' ), $row );
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $data */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$row = self::encode( array_intersect_key( $data, array_flip( self::FIELDS ) ) );
		if ( ! $row ) {
			return;
		}
		$table = Schema::table( 'room_types' );
		$sets  = array();
		$args  = array();
		foreach ( $row as $column => $value ) {
			if ( null === $value ) {
				$sets[] = "{$column} = NULL";
				continue;
			}
			$sets[] = "{$column} = " . ( is_int( $value ) ? '%d' : '%s' );
			$args[] = $value;
		}
		$args[] = gmdate( 'Y-m-d H:i:s' );
		$args[] = $id;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal column names, values prepared.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $sets ) . ', version = version + 1, updated_at = %s WHERE id = %d', $args ) );
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;
		$table = Schema::table( 'room_types' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, version = version + 1, updated_at = %s WHERE id = %d", $status, gmdate( 'Y-m-d H:i:s' ), $id ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function encode( array $row ): array {
		foreach ( array( 'images', 'amenities' ) as $json ) {
			if ( array_key_exists( $json, $row ) ) {
				$row[ $json ] = wp_json_encode( array_values( (array) $row[ $json ] ) );
			}
		}
		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		foreach ( array( 'id', 'property_id', 'max_adults', 'max_children', 'max_occupancy', 'base_occupancy', 'base_price', 'extra_adult_price', 'extra_child_price', 'sort_order', 'version' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$row['size_sqm']       = null !== $row['size_sqm'] ? (int) $row['size_sqm'] : null;
		$row['is_hidden']      = (bool) (int) $row['is_hidden'];
		$row['online_enabled'] = (bool) (int) $row['online_enabled'];
		foreach ( array( 'images', 'amenities' ) as $json ) {
			$decoded      = json_decode( (string) $row[ $json ], true );
			$row[ $json ] = is_array( $decoded ) ? $decoded : array();
		}
		$row['images'] = array_values( array_filter( array_map( 'intval', $row['images'] ) ) );
		return $row;
	}
}
