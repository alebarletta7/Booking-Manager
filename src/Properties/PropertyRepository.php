<?php
/**
 * Data access for bkng_properties (strutture). No permission checks here: services and
 * controllers decide who may call what; queries that list properties take the caller's
 * allowed ids from PropertyScope.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Properties;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class PropertyRepository {

	/** Columns written from the API (settings and status have their own methods). */
	public const FIELDS = array( 'name', 'slug', 'kind', 'timezone', 'currency', 'booking_mode', 'checkin_from', 'checkin_to', 'checkout_until', 'address', 'city', 'postcode', 'province', 'country', 'phone', 'email', 'website', 'company_name', 'vat_number' );

	public const KINDS = array( 'bnb', 'rooms', 'holiday_home', 'apartments', 'residence', 'guesthouse', 'hotel' );

	/** Kinds where every room is sold on its own: one hidden type per room. */
	public const ROOM_IS_TYPE = array( 'bnb', 'rooms', 'holiday_home', 'apartments' );

	/** @var array<int, array<string, mixed>|null> */
	private array $cache = array();

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		if ( ! array_key_exists( $id, $this->cache ) ) {
			global $wpdb;
			$table = Schema::table( 'properties' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$row                = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			$this->cache[ $id ] = $row ? self::hydrate( $row ) : null;
		}
		return $this->cache[ $id ];
	}

	/** @return array<string, mixed>|null */
	public function find_by_slug( string $slug ): ?array {
		global $wpdb;
		$table = Schema::table( 'properties' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param int[]|null $allowed null = every property.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( ?array $allowed = null, bool $include_archived = true ): array {
		global $wpdb;
		if ( is_array( $allowed ) && ! $allowed ) {
			return array();
		}
		$table = Schema::table( 'properties' );
		$where = array( '1=1' );
		if ( is_array( $allowed ) ) {
			$where[] = 'id IN (' . implode( ',', array_map( 'intval', $allowed ) ) . ')';
		}
		if ( ! $include_archived ) {
			$where[] = "status = 'active'";
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name and integer list.
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY status ASC, name ASC, id ASC', ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	public function count(): int {
		global $wpdb;
		$table = Schema::table( 'properties' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public function slug_taken( string $slug, int $except_id = 0 ): bool {
		global $wpdb;
		$table = Schema::table( 'properties' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d", $slug, $except_id ) );
	}

	/**
	 * The site's timezone when it is a named zone (needed for daylight saving time),
	 * otherwise Europe/Rome: a fixed offset such as "+00:00" would be wrong half the year.
	 */
	public static function default_timezone(): string {
		$tz = (string) wp_timezone_string();
		return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : 'Europe/Rome';
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array_intersect_key( $data, array_flip( self::FIELDS ) );
		if ( empty( $row['slug'] ) ) {
			$row['slug'] = $this->unique_slug( (string) ( $row['name'] ?? 'struttura' ) );
		}
		$row += array(
			'kind'     => 'bnb',
			'timezone' => self::default_timezone(),
			'currency' => 'EUR',
		);
		$row['uuid']       = wp_generate_uuid4();
		$row['settings']   = wp_json_encode( PropertySettings::merge( array(), is_array( $data['settings'] ?? null ) ? $data['settings'] : array() ) );
		$row['status']     = 'active';
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$wpdb->insert( Schema::table( 'properties' ), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$row = array_intersect_key( $data, array_flip( self::FIELDS ) );
		if ( ! $row ) {
			return;
		}
		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( Schema::table( 'properties' ), $row, array( 'id' => $id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'properties' ) . ' SET version = version + 1 WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
		unset( $this->cache[ $id ] );
	}

	/**
	 * @param array<string, mixed> $settings Full settings (already merged).
	 */
	public function save_settings( int $id, array $settings ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'properties' ),
			array(
				'settings'   => wp_json_encode( $settings ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);
		unset( $this->cache[ $id ] );
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update(
			Schema::table( 'properties' ),
			array(
				'status'      => $status,
				'archived_at' => 'archived' === $status ? $now : null,
				'updated_at'  => $now,
			),
			array( 'id' => $id )
		);
		unset( $this->cache[ $id ] );
	}

	public function unique_slug( string $name, int $except_id = 0 ): string {
		$base = sanitize_title( $name );
		$base = '' !== $base ? substr( $base, 0, 180 ) : 'struttura';
		$slug = $base;
		for ( $i = 2; $this->slug_taken( $slug, $except_id ); $i++ ) {
			$slug = $base . '-' . $i;
		}
		return $slug;
	}

	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		$row['id']       = (int) $row['id'];
		$row['version']  = (int) ( $row['version'] ?? 1 );
		$row['settings'] = PropertySettings::from_json( isset( $row['settings'] ) ? (string) $row['settings'] : null );
		foreach ( array( 'checkin_from', 'checkin_to', 'checkout_until' ) as $time ) {
			$row[ $time ] = substr( (string) ( $row[ $time ] ?? '' ), 0, 5 );
		}
		return $row;
	}
}
