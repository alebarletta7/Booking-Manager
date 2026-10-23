<?php
/**
 * Data access for bkng_units (camere, appartamenti) and their out-of-service blocks.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Inventory;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class UnitRepository {

	public const FIELDS = array( 'room_type_id', 'code', 'name', 'floor', 'notes', 'sort_order' );

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = Schema::table( 'units' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function for_property( int $property_id, bool $include_archived = false ): array {
		global $wpdb;
		$table  = Schema::table( 'units' );
		$status = $include_archived ? '' : " AND status = 'active'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE property_id = %d{$status} ORDER BY sort_order ASC, code ASC, id ASC", $property_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** @return array<int, array<string, mixed>> */
	public function for_type( int $room_type_id, bool $include_archived = false ): array {
		global $wpdb;
		$table  = Schema::table( 'units' );
		$status = $include_archived ? '' : " AND status = 'active'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_type_id = %d{$status} ORDER BY sort_order ASC, code ASC, id ASC", $room_type_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	public function count_active( int $room_type_id ): int {
		global $wpdb;
		$table = Schema::table( 'units' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE room_type_id = %d AND status = 'active'", $room_type_id ) );
	}

	public function code_taken( int $property_id, string $code, int $except_id = 0 ): bool {
		global $wpdb;
		$table = Schema::table( 'units' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE property_id = %d AND code = %s AND id <> %d", $property_id, $code, $except_id ) );
	}

	/** @param array<string, mixed> $data */
	public function create( int $property_id, array $data ): int {
		global $wpdb;
		$now                = gmdate( 'Y-m-d H:i:s' );
		$row                = array_intersect_key( $data, array_flip( self::FIELDS ) );
		$row['uuid']        = wp_generate_uuid4();
		$row['property_id'] = $property_id;
		$row['op_status']   = 'ready';
		$row['status']      = 'active';
		$row['created_at']  = $now;
		$row['updated_at']  = $now;
		$wpdb->insert( Schema::table( 'units' ), $row );
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $data */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$row = array_intersect_key( $data, array_flip( self::FIELDS ) );
		if ( ! $row ) {
			return;
		}
		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( Schema::table( 'units' ), $row, array( 'id' => $id ) );
		$this->bump( $id );
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'units' ),
			array(
				'status'     => $status,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);
		$this->bump( $id );
	}

	/**
	 * Operational status (housekeeping), only when it is still the one the caller saw.
	 *
	 * @return bool false when somebody changed it in the meantime.
	 */
	public function set_op_status( int $id, string $from, string $to, ?string $note, int $user_id ): bool {
		global $wpdb;
		$table = Schema::table( 'units' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET op_status = %s, op_note = %s, op_updated_at = %s, op_updated_by = %d, version = version + 1, updated_at = %s WHERE id = %d AND op_status = %s", $to, (string) $note, $now, $user_id, $now, $id, $from ) );
		if ( 1 !== (int) $n ) {
			return false;
		}
		$wpdb->insert(
			Schema::table( 'unit_status_log' ),
			array(
				'property_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT property_id FROM {$table} WHERE id = %d", $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'unit_id'     => $id,
				'from_status' => $from,
				'to_status'   => $to,
				'note'        => $note,
				'user_id'     => $user_id ? $user_id : null,
				'created_at'  => $now,
			)
		);
		return true;
	}

	private function bump( int $id ): void {
		global $wpdb;
		$table = Schema::table( 'units' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET version = version + 1 WHERE id = %d", $id ) );
	}

	/* ------------------------------------------------------------------ Blocks */

	/** @return array<string, mixed>|null */
	public function find_block( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'unit_blocks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate_block( $row ) : null;
	}

	/** @return array<int, array<string, mixed>> active blocks of a property overlapping [from, to). */
	public function blocks_between( int $property_id, string $from, string $to ): array {
		global $wpdb;
		$table = Schema::table( 'unit_blocks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE property_id = %d AND status = 'active' AND date_from < %s AND date_to > %s ORDER BY date_from, id", $property_id, $to, $from ), ARRAY_A );
		return array_map( array( self::class, 'hydrate_block' ), $rows );
	}

	/** @return int[] ids of active blocks of a unit ending after $from. */
	public function future_blocks( int $unit_id, string $from ): array {
		global $wpdb;
		$table = Schema::table( 'unit_blocks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE unit_id = %d AND status = 'active' AND date_to > %s", $unit_id, $from ) ) );
	}

	/** @param array<string, mixed> $data */
	public function create_block( array $data ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			Schema::table( 'unit_blocks' ),
			array(
				'property_id'  => (int) $data['property_id'],
				'unit_id'      => (int) $data['unit_id'],
				'room_type_id' => (int) $data['room_type_id'],
				'date_from'    => (string) $data['date_from'],
				'date_to'      => (string) $data['date_to'],
				'kind'         => (string) $data['kind'],
				'reason'       => $data['reason'] ?? null,
				'status'       => 'active',
				'created_by'   => $data['created_by'] ?? null,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function cancel_block( int $id ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'unit_blocks' ),
			array(
				'status'     => 'cancelled',
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		foreach ( array( 'id', 'property_id', 'room_type_id', 'sort_order', 'version' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$row['op_updated_by'] = null !== $row['op_updated_by'] ? (int) $row['op_updated_by'] : null;
		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate_block( array $row ): array {
		foreach ( array( 'id', 'property_id', 'unit_id', 'room_type_id' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		return $row;
	}
}
