<?php
/**
 * Data access for rate plans (piani tariffari), price periods and adjustments
 * (supplementi, sconti, lunghe permanenze).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rates;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class RateRepository {

	public const PLAN_FIELDS       = array( 'name', 'description', 'room_type_ids', 'modifier_bp', 'meal_plan', 'cancellation_policy', 'deposit_policy', 'is_default', 'is_public', 'sort_order' );
	public const PRICE_FIELDS      = array( 'rate_plan_id', 'room_type_id', 'label', 'date_from', 'date_to', 'weekdays', 'price_night', 'extra_adult_price', 'extra_child_price', 'priority' );
	public const ADJUSTMENT_FIELDS = array( 'rate_plan_id', 'kind', 'name', 'description', 'amount', 'percent_bp', 'per', 'min_nights', 'max_nights', 'date_from', 'date_to', 'room_type_ids', 'is_optional', 'is_public', 'free_cancel_days', 'sort_order' );

	/* ------------------------------------------------------------------ Plans */

	/** @return array<string, mixed>|null */
	public function find_plan( int $id ): ?array {
		return $this->find( 'rate_plans', $id, array( self::class, 'hydrate_plan' ) );
	}

	/** @return array<int, array<string, mixed>> */
	public function plans( int $property_id, bool $include_archived = false ): array {
		global $wpdb;
		$table  = Schema::table( 'rate_plans' );
		$status = $include_archived ? '' : " AND status = 'active'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE property_id = %d{$status} ORDER BY is_default DESC, sort_order ASC, id ASC", $property_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate_plan' ), $rows );
	}

	/** The plan used when none is chosen (the default one, else the first active one). */
	public function default_plan( int $property_id ): ?array {
		$plans = $this->plans( $property_id );
		return $plans[0] ?? null;
	}

	/** @param array<string, mixed> $data */
	public function create_plan( int $property_id, array $data ): int {
		return $this->insert( 'rate_plans', $property_id, self::encode_plan( array_intersect_key( $data, array_flip( self::PLAN_FIELDS ) ) ), true );
	}

	/** @param array<string, mixed> $data */
	public function update_plan( int $id, array $data ): void {
		$this->update( 'rate_plans', $id, self::encode_plan( array_intersect_key( $data, array_flip( self::PLAN_FIELDS ) ) ), true );
	}

	public function set_plan_status( int $id, string $status ): void {
		$this->update( 'rate_plans', $id, array( 'status' => $status ), true );
	}

	/** Only one default plan per property. */
	public function clear_default( int $property_id, int $except_id ): void {
		global $wpdb;
		$table = Schema::table( 'rate_plans' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_default = 0 WHERE property_id = %d AND id <> %d", $property_id, $except_id ) );
	}

	/* ------------------------------------------------------------------ Prices */

	/** @return array<string, mixed>|null */
	public function find_price( int $id ): ?array {
		return $this->find( 'rate_prices', $id, array( self::class, 'hydrate_price' ) );
	}

	/**
	 * Price periods of a type touching [from, to] (inclusive).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function prices_for( int $room_type_id, string $from, string $to ): array {
		global $wpdb;
		$table = Schema::table( 'rate_prices' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_type_id = %d AND date_from <= %s AND date_to >= %s ORDER BY priority DESC, id DESC", $room_type_id, $to, $from ), ARRAY_A );
		return array_map( array( self::class, 'hydrate_price' ), $rows );
	}

	/** @return array<int, array<string, mixed>> every price period of a property */
	public function prices( int $property_id ): array {
		global $wpdb;
		$table = Schema::table( 'rate_prices' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE property_id = %d ORDER BY date_from ASC, priority DESC, id ASC", $property_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate_price' ), $rows );
	}

	/** @param array<string, mixed> $data */
	public function create_price( int $property_id, array $data ): int {
		return $this->insert( 'rate_prices', $property_id, array_intersect_key( $data, array_flip( self::PRICE_FIELDS ) ), false );
	}

	/** @param array<string, mixed> $data */
	public function update_price( int $id, array $data ): void {
		$this->update( 'rate_prices', $id, array_intersect_key( $data, array_flip( self::PRICE_FIELDS ) ), false );
	}

	public function delete_price( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'rate_prices' ), array( 'id' => $id ), array( '%d' ) );
	}

	/* ------------------------------------------------------------------ Adjustments */

	/** @return array<string, mixed>|null */
	public function find_adjustment( int $id ): ?array {
		return $this->find( 'rate_adjustments', $id, array( self::class, 'hydrate_adjustment' ) );
	}

	/** @return array<int, array<string, mixed>> */
	public function adjustments( int $property_id, bool $include_archived = false ): array {
		global $wpdb;
		$table  = Schema::table( 'rate_adjustments' );
		$status = $include_archived ? '' : " AND status = 'active'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE property_id = %d{$status} ORDER BY sort_order ASC, id ASC", $property_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate_adjustment' ), $rows );
	}

	/** @param array<string, mixed> $data */
	public function create_adjustment( int $property_id, array $data ): int {
		$row = array_intersect_key( $data, array_flip( self::ADJUSTMENT_FIELDS ) );
		if ( array_key_exists( 'room_type_ids', $row ) ) {
			$row['room_type_ids'] = wp_json_encode( array_values( array_map( 'intval', (array) $row['room_type_ids'] ) ) );
		}
		$row['uuid'] = wp_generate_uuid4();
		return $this->insert( 'rate_adjustments', $property_id, $row, true, false );
	}

	/** @param array<string, mixed> $data */
	public function update_adjustment( int $id, array $data ): void {
		$row = array_intersect_key( $data, array_flip( self::ADJUSTMENT_FIELDS ) );
		if ( array_key_exists( 'room_type_ids', $row ) ) {
			$row['room_type_ids'] = wp_json_encode( array_values( array_map( 'intval', (array) $row['room_type_ids'] ) ) );
		}
		$this->update( 'rate_adjustments', $id, $row, true );
	}

	public function set_adjustment_status( int $id, string $status ): void {
		$this->update( 'rate_adjustments', $id, array( 'status' => $status ), true );
	}

	/* ------------------------------------------------------------------ Internals */

	/** @return array<string, mixed>|null */
	private function find( string $short, int $id, callable $hydrate ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = Schema::table( $short );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $hydrate( $row ) : null;
	}

	/** @param array<string, mixed> $row */
	private function insert( string $short, int $property_id, array $row, bool $with_status, bool $with_uuid = true ): int {
		global $wpdb;
		$now                = gmdate( 'Y-m-d H:i:s' );
		$row['property_id'] = $property_id;
		if ( $with_status ) {
			$row['status'] = 'active';
		}
		if ( $with_uuid && 'rate_plans' === $short ) {
			$row['uuid'] = wp_generate_uuid4();
		}
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$wpdb->insert( Schema::table( $short ), $row );
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $row */
	private function update( string $short, int $id, array $row, bool $bump ): void {
		global $wpdb;
		if ( ! $row ) {
			return;
		}
		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( Schema::table( $short ), $row, array( 'id' => $id ) );
		if ( $bump && 'rate_plans' === $short ) {
			$table = Schema::table( $short );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET version = version + 1 WHERE id = %d", $id ) );
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function encode_plan( array $row ): array {
		if ( array_key_exists( 'room_type_ids', $row ) ) {
			$row['room_type_ids'] = wp_json_encode( array_values( array_map( 'intval', (array) $row['room_type_ids'] ) ) );
		}
		foreach ( array( 'cancellation_policy', 'deposit_policy' ) as $json ) {
			if ( array_key_exists( $json, $row ) ) {
				$row[ $json ] = wp_json_encode( (array) $row[ $json ] );
			}
		}
		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate_plan( array $row ): array {
		foreach ( array( 'id', 'property_id', 'modifier_bp', 'sort_order', 'version' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$row['is_default']          = (bool) (int) $row['is_default'];
		$row['is_public']           = (bool) (int) $row['is_public'];
		$row['room_type_ids']       = self::ids( $row['room_type_ids'] );
		$row['cancellation_policy'] = Policies::cancellation( json_decode( (string) $row['cancellation_policy'], true ) );
		$row['deposit_policy']      = Policies::deposit( json_decode( (string) $row['deposit_policy'], true ) );
		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate_price( array $row ): array {
		foreach ( array( 'id', 'property_id', 'room_type_id', 'price_night', 'priority' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		foreach ( array( 'rate_plan_id', 'extra_adult_price', 'extra_child_price' ) as $nullable ) {
			$row[ $nullable ] = null !== $row[ $nullable ] ? (int) $row[ $nullable ] : null;
		}
		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate_adjustment( array $row ): array {
		foreach ( array( 'id', 'property_id', 'sort_order' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		foreach ( array( 'rate_plan_id', 'amount', 'percent_bp', 'min_nights', 'max_nights', 'free_cancel_days' ) as $nullable ) {
			$row[ $nullable ] = null !== ( $row[ $nullable ] ?? null ) ? (int) $row[ $nullable ] : null;
		}
		$row['is_optional']   = (bool) (int) $row['is_optional'];
		$row['is_public']     = (bool) (int) $row['is_public'];
		$row['room_type_ids'] = self::ids( $row['room_type_ids'] );
		return $row;
	}

	/**
	 * @param mixed $json
	 * @return int[]
	 */
	private static function ids( $json ): array {
		$ids = json_decode( (string) $json, true );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
	}
}
