<?php
/**
 * Data access for bookings, their rooms and their guests. No permission checks here.
 *
 * Every change of a booking row goes through update(), which writes only when the
 * version is still the one the caller read (optimistic locking) and bumps it.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Bookings;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class BookingRepository {

	private const INT_COLUMNS = array( 'id', 'property_id', 'nights', 'adults', 'children', 'total', 'taxes_total', 'deposit_due', 'paid', 'refunded', 'balance', 'version', 'qr_version' );

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		return $this->one( 'id = %d', $id );
	}

	/** @return array<string, mixed>|null */
	public function find_by_code( string $code ): ?array {
		return $this->one( 'code = %s', $code );
	}

	/** @return array<string, mixed>|null */
	public function find_by_uuid( string $uuid ): ?array {
		return $this->one( 'uuid = %s', $uuid );
	}

	/** @return array<string, mixed>|null */
	public function find_by_idempotency( string $key ): ?array {
		return $this->one( 'idempotency_key = %s', $key );
	}

	/** @return array<string, mixed>|null */
	public function find_by_qr( string $qr_id ): ?array {
		return $this->one( 'qr_id = %s', $qr_id );
	}

	/** @return array<string, mixed>|null */
	public function find_by_manage_hash( string $hash ): ?array {
		return $this->one( 'manage_token_hash = %s', $hash );
	}

	/**
	 * @param int|string $value
	 * @return array<string, mixed>|null
	 */
	private function one( string $where, $value ): ?array {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal names, value prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where}", $value ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Bookings of a property, newest arrival first, with filters.
	 *
	 * @param array<string, mixed> $f property_id (required), status[], from, to (stays overlapping), arrival, departure, search, guest_id, source, limit, offset
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function search( array $f ): array {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		$where = array( 'property_id = %d' );
		$args  = array( (int) $f['property_id'] );
		if ( ! empty( $f['status'] ) ) {
			$statuses = array_values( array_filter( (array) $f['status'], array( BookingStatus::class, 'exists' ) ) );
			if ( $statuses ) {
				$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
				$args    = array_merge( $args, $statuses );
			}
		}
		if ( ! empty( $f['from'] ) && ! empty( $f['to'] ) ) {
			$where[] = 'arrival < %s AND departure > %s';
			$args[]  = (string) $f['to'];
			$args[]  = (string) $f['from'];
		}
		if ( ! empty( $f['arrival'] ) ) {
			$where[] = 'arrival = %s';
			$args[]  = (string) $f['arrival'];
		}
		if ( ! empty( $f['departure'] ) ) {
			$where[] = 'departure = %s';
			$args[]  = (string) $f['departure'];
		}
		if ( ! empty( $f['guest_id'] ) ) {
			$where[] = 'guest_id = %d';
			$args[]  = (int) $f['guest_id'];
		}
		if ( ! empty( $f['source'] ) ) {
			$where[] = 'source = %s';
			$args[]  = (string) $f['source'];
		}
		if ( ! empty( $f['payment_status'] ) ) {
			$where[] = 'payment_status = %s';
			$args[]  = (string) $f['payment_status'];
		}
		$term = trim( (string) ( $f['search'] ?? '' ) );
		if ( '' !== $term ) {
			$like    = '%' . $wpdb->esc_like( $term ) . '%';
			$where[] = '(code = %s OR last_name LIKE %s OR first_name LIKE %s OR email = %s OR phone LIKE %s OR external_ref = %s)';
			array_push( $args, BookingCodes::clean( $term ), $like, $like, strtolower( $term ), $like, $term );
		}
		$sql_where = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal names, values prepared.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}", $args ) );
		$order = 'created' === ( $f['order'] ?? '' ) ? 'created_at DESC, id DESC' : 'arrival DESC, id DESC';
		if ( 'arrival_asc' === ( $f['order'] ?? '' ) ) {
			$order = 'arrival ASC, id ASC';
		}
		$args[] = max( 1, min( 200, (int) ( $f['limit'] ?? 50 ) ) );
		$args[] = max( 0, (int) ( $f['offset'] ?? 0 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal names, values prepared.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY {$order} LIMIT %d OFFSET %d", $args ), ARRAY_A );
		return array(
			'items' => array_map( array( self::class, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/** @param array<string, mixed> $row */
	public function insert( array $row ): int {
		global $wpdb;
		$now               = gmdate( 'Y-m-d H:i:s' );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		foreach ( array( 'price_snapshot', 'terms_snapshot', 'extras' ) as $json ) {
			if ( isset( $row[ $json ] ) && is_array( $row[ $json ] ) ) {
				$row[ $json ] = wp_json_encode( $row[ $json ] );
			}
		}
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( Schema::table( 'bookings' ), $row );
		$wpdb->suppress_errors( $suppress );
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Writes changes only if the booking still has $version; bumps the version.
	 *
	 * @param array<string, mixed> $changes
	 * @return bool false when someone else changed the booking in the meantime.
	 */
	public function update( int $id, int $version, array $changes ): bool {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		$sets  = array();
		$args  = array();
		foreach ( array( 'price_snapshot', 'terms_snapshot', 'extras' ) as $json ) {
			if ( isset( $changes[ $json ] ) && is_array( $changes[ $json ] ) ) {
				$changes[ $json ] = wp_json_encode( $changes[ $json ] );
			}
		}
		foreach ( $changes as $column => $value ) {
			if ( ! preg_match( '/^[a-z_]+$/', (string) $column ) ) {
				continue;
			}
			if ( null === $value ) {
				$sets[] = "{$column} = NULL";
				continue;
			}
			$sets[] = "{$column} = " . ( is_int( $value ) ? '%d' : '%s' );
			$args[] = $value;
		}
		$sets[] = 'version = version + 1';
		$sets[] = 'updated_at = %s';
		$args[] = gmdate( 'Y-m-d H:i:s' );
		$args[] = $id;
		$args[] = $version;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal names, values prepared.
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE id = %d AND version = %d', $args ) );
		return 1 === (int) $n;
	}

	/** Locks the booking row (inside a transaction). */
	public function lock( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/* ------------------------------------------------------------------ Rooms */

	/** @return array<int, array<string, mixed>> */
	public function rooms( int $booking_id, bool $only_active = false ): array {
		global $wpdb;
		$table  = Schema::table( 'booking_rooms' );
		$active = $only_active ? " AND stay_status <> 'removed'" : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d{$active} ORDER BY sort_order, id", $booking_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate_room' ), $rows );
	}

	/** @return array<string, mixed>|null */
	public function find_room( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'booking_rooms' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate_room( $row ) : null;
	}

	/**
	 * Stays of a property overlapping [from, to), for the calendar and the planning.
	 *
	 * @return array<int, array<string, mixed>> rooms joined with their booking's main fields
	 */
	public function stays_between( int $property_id, string $from, string $to, bool $include_cancelled = false ): array {
		global $wpdb;
		$rooms    = Schema::table( 'booking_rooms' );
		$bookings = Schema::table( 'bookings' );
		$filter   = $include_cancelled ? '' : ' AND r.occupies = 1';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.*, b.code, b.status, b.payment_status, b.first_name, b.last_name, b.source, b.balance, b.currency, b.version AS booking_version FROM {$rooms} r JOIN {$bookings} b ON b.id = r.booking_id WHERE r.property_id = %d AND r.arrival < %s AND r.departure > %s{$filter} ORDER BY r.arrival, r.id", $property_id, $to, $from ), ARRAY_A );
		return array_map(
			static function ( array $row ): array {
				$room                    = self::hydrate_room( $row );
				$room['booking_version'] = (int) $row['booking_version'];
				$room['balance']         = (int) $row['balance'];
				return $room;
			},
			$rows
		);
	}

	/** @param array<string, mixed> $row */
	public function insert_room( array $row ): int {
		global $wpdb;
		$now               = gmdate( 'Y-m-d H:i:s' );
		$row['uuid']       = wp_generate_uuid4();
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		if ( isset( $row['nightly'] ) && is_array( $row['nightly'] ) ) {
			$row['nightly'] = wp_json_encode( $row['nightly'] );
		}
		$wpdb->insert( Schema::table( 'booking_rooms' ), $row );
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $changes */
	public function update_room( int $id, array $changes ): void {
		global $wpdb;
		if ( isset( $changes['nightly'] ) && is_array( $changes['nightly'] ) ) {
			$changes['nightly'] = wp_json_encode( $changes['nightly'] );
		}
		$changes['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( Schema::table( 'booking_rooms' ), $changes, array( 'id' => $id ) );
	}

	/** Sets occupies for every room of a booking (status change). */
	public function set_occupies( int $booking_id, bool $occupies ): void {
		global $wpdb;
		$table = Schema::table( 'booking_rooms' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET occupies = %d, updated_at = %s WHERE booking_id = %d AND stay_status <> 'removed'", $occupies ? 1 : 0, gmdate( 'Y-m-d H:i:s' ), $booking_id ) );
	}

	/* ------------------------------------------------------------------ Guests */

	/** @return array<int, array<string, mixed>> */
	public function guests( int $booking_id ): array {
		global $wpdb;
		$table = Schema::table( 'booking_guests' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY is_holder DESC, sort_order, id", $booking_id ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['id']              = (int) $row['id'];
			$row['booking_room_id'] = null !== $row['booking_room_id'] ? (int) $row['booking_room_id'] : null;
			$row['guest_id']        = null !== $row['guest_id'] ? (int) $row['guest_id'] : null;
			$row['is_holder']       = (bool) (int) $row['is_holder'];
		}
		return $rows;
	}

	/**
	 * Replaces the guest list of a booking.
	 *
	 * @param array<int, array<string, mixed>> $guests
	 */
	public function replace_guests( int $booking_id, array $guests ): void {
		global $wpdb;
		$table = Schema::table( 'booking_guests' );
		$wpdb->delete( $table, array( 'booking_id' => $booking_id ), array( '%d' ) );
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( array_values( $guests ) as $i => $g ) {
			$wpdb->insert(
				$table,
				array(
					'booking_id'      => $booking_id,
					'booking_room_id' => $g['booking_room_id'] ?? null,
					'guest_id'        => $g['guest_id'] ?? null,
					'first_name'      => (string) ( $g['first_name'] ?? '' ),
					'last_name'       => (string) ( $g['last_name'] ?? '' ),
					'age_band'        => (string) ( $g['age_band'] ?? 'adult' ),
					'is_holder'       => ! empty( $g['is_holder'] ) ? 1 : 0,
					'sort_order'      => $i,
					'created_at'      => $now,
				)
			);
		}
	}

	/* ------------------------------------------------------------------ Hydration */

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		foreach ( self::INT_COLUMNS as $int ) {
			if ( array_key_exists( $int, $row ) ) {
				$row[ $int ] = (int) $row[ $int ];
			}
		}
		$row['guest_id'] = null !== ( $row['guest_id'] ?? null ) ? (int) $row['guest_id'] : null;
		foreach ( array( 'price_snapshot', 'terms_snapshot', 'extras' ) as $json ) {
			$decoded      = isset( $row[ $json ] ) ? json_decode( (string) $row[ $json ], true ) : null;
			$row[ $json ] = is_array( $decoded ) ? $decoded : array();
		}
		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate_room( array $row ): array {
		foreach ( array( 'id', 'booking_id', 'property_id', 'room_type_id', 'adults', 'children', 'price_total', 'sort_order' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		foreach ( array( 'unit_id', 'rate_plan_id', 'checked_in_by', 'checked_out_by' ) as $nullable ) {
			$row[ $nullable ] = null !== $row[ $nullable ] ? (int) $row[ $nullable ] : null;
		}
		$row['occupies'] = (bool) (int) $row['occupies'];
		$decoded         = json_decode( (string) $row['nightly'], true );
		$row['nightly']  = is_array( $decoded ) ? $decoded : array();
		return $row;
	}
}
