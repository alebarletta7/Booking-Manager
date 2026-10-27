<?php
/**
 * Data access for bkng_guests and bkng_guest_consents.
 *
 * E-mail and phone are also stored as salted hashes (email_hash, phone_hash): searching
 * for duplicates never needs a LIKE on personal data, and the hashes survive in the
 * index only as long as the guest is not anonymized.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Guests;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class GuestRepository {

	public const FIELDS = array( 'first_name', 'last_name', 'email', 'phone', 'country', 'language', 'notes' );

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = Schema::table( 'guests' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Properties where the guest has at least one booking: a guest card is visible to the
	 * staff of those properties only.
	 *
	 * @return int[]
	 */
	public function property_ids( int $id ): array {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT property_id FROM {$table} WHERE guest_id = %d", $id ) ) );
	}

	/** @return array<int, array<string, mixed>> guests with this e-mail hash (newest first). */
	public function by_email_hash( string $hash ): array {
		return $this->by( 'email_hash', $hash );
	}

	/** @return array<int, array<string, mixed>> */
	public function by_phone_hash( string $hash ): array {
		return $this->by( 'phone_hash', $hash );
	}

	/** @return array<int, array<string, mixed>> */
	private function by( string $column, string $hash ): array {
		global $wpdb;
		$table = Schema::table( 'guests' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table and column names.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s AND anonymized_at IS NULL ORDER BY id DESC LIMIT 20", $hash ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Search by name, e-mail or phone among guests with a booking in the allowed properties.
	 *
	 * @param int[]|null $allowed_properties null = all
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $term, ?array $allowed_properties, int $limit = 30, int $offset = 0 ): array {
		global $wpdb;
		if ( is_array( $allowed_properties ) && ! $allowed_properties ) {
			return array();
		}
		$guests   = Schema::table( 'guests' );
		$bookings = Schema::table( 'bookings' );
		$where    = array( 'g.anonymized_at IS NULL' );
		$args     = array();
		$term     = trim( $term );
		if ( '' !== $term ) {
			$like    = '%' . $wpdb->esc_like( $term ) . '%';
			$where[] = '(g.last_name LIKE %s OR g.first_name LIKE %s OR CONCAT(g.first_name, " ", g.last_name) LIKE %s OR g.email_hash = %s OR g.phone_hash = %s)';
			array_push( $args, $like, $like, $like, self::hash_email( $term ), self::hash_phone( $term ) );
		}
		if ( is_array( $allowed_properties ) ) {
			$where[] = "EXISTS (SELECT 1 FROM {$bookings} b WHERE b.guest_id = g.id AND b.property_id IN (" . implode( ',', array_map( 'intval', $allowed_properties ) ) . '))';
		}
		$args[] = max( 1, min( 100, $limit ) );
		$args[] = max( 0, $offset );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal names, values prepared.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT g.* FROM {$guests} g WHERE " . implode( ' AND ', $where ) . ' ORDER BY g.last_name, g.first_name, g.id LIMIT %d OFFSET %d', $args ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** @param array<string, mixed> $data */
	public function create( array $data ): int {
		global $wpdb;
		$now               = gmdate( 'Y-m-d H:i:s' );
		$row               = self::with_hashes( array_intersect_key( $data, array_flip( self::FIELDS ) ) );
		$row['uuid']       = wp_generate_uuid4();
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$wpdb->insert( Schema::table( 'guests' ), $row );
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $data */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$row = self::with_hashes( array_intersect_key( $data, array_flip( self::FIELDS ) ) );
		if ( ! $row ) {
			return;
		}
		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( Schema::table( 'guests' ), $row, array( 'id' => $id ) );
		$table = Schema::table( 'guests' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET version = version + 1 WHERE id = %d", $id ) );
	}

	/** Recomputes stays_count and last_stay from the bookings that were not cancelled. */
	public function refresh_stats( int $id ): void {
		global $wpdb;
		$guests   = Schema::table( 'guests' );
		$bookings = Schema::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$wpdb->query( $wpdb->prepare( "UPDATE {$guests} g SET stays_count = (SELECT COUNT(*) FROM {$bookings} b WHERE b.guest_id = g.id AND b.status IN ('confirmed','in_house','completed')), last_stay = (SELECT MAX(b.arrival) FROM {$bookings} b WHERE b.guest_id = g.id AND b.status IN ('in_house','completed')) WHERE g.id = %d", $id ) );
	}

	public function anonymize( int $id ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update(
			Schema::table( 'guests' ),
			array(
				'first_name'    => '',
				'last_name'     => __( 'Anonimo', 'booking-manager' ),
				'email'         => null,
				'email_hash'    => null,
				'phone'         => null,
				'phone_hash'    => null,
				'notes'         => null,
				'anonymized_at' => $now,
				'updated_at'    => $now,
			),
			array( 'id' => $id )
		);
	}

	/** @param array<string, mixed> $data */
	public function add_consent( array $data ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'guest_consents' ),
			array(
				'guest_id'     => (int) $data['guest_id'],
				'property_id'  => $data['property_id'] ?? null,
				'purpose'      => (string) $data['purpose'],
				'granted'      => ! empty( $data['granted'] ) ? 1 : 0,
				'text_version' => $data['text_version'] ?? null,
				'source'       => (string) ( $data['source'] ?? 'staff' ),
				'booking_id'   => $data['booking_id'] ?? null,
				'created_by'   => $data['created_by'] ?? null,
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/** The guest's last answer for a purpose (true granted, false refused), null when never asked. */
	public function latest_consent( int $guest_id, string $purpose ): ?bool {
		global $wpdb;
		$table = Schema::table( 'guest_consents' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT granted FROM {$table} WHERE guest_id = %d AND purpose = %s ORDER BY id DESC LIMIT 1", $guest_id, $purpose ) );
		return null === $value ? null : (bool) (int) $value;
	}

	/** @return array<int, array<string, mixed>> */
	public function consents( int $guest_id ): array {
		global $wpdb;
		$table = Schema::table( 'guest_consents' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT purpose, granted, source, text_version, property_id, booking_id, created_at FROM {$table} WHERE guest_id = %d ORDER BY id", $guest_id ), ARRAY_A );
	}

	public static function hash_email( string $email ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) . '|bkng-email' );
	}

	public static function hash_phone( string $phone ): string {
		return hash_hmac( 'sha256', (string) preg_replace( '/[^\d+]/', '', $phone ), wp_salt( 'auth' ) . '|bkng-phone' );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function with_hashes( array $row ): array {
		if ( array_key_exists( 'email', $row ) ) {
			$row['email_hash'] = ! empty( $row['email'] ) ? self::hash_email( (string) $row['email'] ) : null;
		}
		if ( array_key_exists( 'phone', $row ) ) {
			$row['phone_hash'] = ! empty( $row['phone'] ) ? self::hash_phone( (string) $row['phone'] ) : null;
		}
		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		$row['id']          = (int) $row['id'];
		$row['stays_count'] = (int) $row['stays_count'];
		$row['version']     = (int) $row['version'];
		return $row;
	}
}
