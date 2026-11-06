<?php
/**
 * Reservations received from Channex (bkng_cx_revisions): one row per revision, so a
 * revision is processed once whatever the number of webhooks and polls, and its
 * acknowledgement is retried until Channex takes it.
 *
 * The payload is kept for the staff and for a new attempt (e.g. after mapping a room),
 * always WITHOUT the card block ("guarantee"): the plugin is not PCI DSS certified.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

use BookingManager\Core\Clock;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class ChannexRevisions {

	/** @return array<string, mixed>|null */
	public function find( string $revision_id ): ?array {
		global $wpdb;
		$t = Schema::table( 'cx_revisions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE revision_id = %s", $revision_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Records a revision as received (or counts one more attempt).
	 *
	 * @param array<string, mixed> $attributes the revision's attributes
	 */
	public function receive( string $revision_id, ?int $property_id, array $attributes ): void {
		global $wpdb;
		$t   = Schema::table( 'cx_revisions' );
		$now = gmdate( 'Y-m-d H:i:s' );
		unset( $attributes['guarantee'] );
		$payload = (string) wp_json_encode( $attributes );
		if ( $this->find( $revision_id ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET attempts = attempts + 1, payload = %s, updated_at = %s WHERE revision_id = %s", $payload, $now, $revision_id ) );
			return;
		}
		$wpdb->insert(
			$t,
			array(
				'revision_id'         => substr( $revision_id, 0, 36 ),
				'channex_booking_id'  => substr( (string) ( $attributes['booking_id'] ?? '' ), 0, 36 ),
				'channex_property_id' => substr( (string) ( $attributes['property_id'] ?? '' ), 0, 36 ),
				'property_id'         => $property_id,
				'status'              => substr( (string) ( $attributes['status'] ?? '' ), 0, 20 ),
				'ota_name'            => mb_substr( (string) ( $attributes['ota_name'] ?? '' ), 0, 64 ),
				'ota_code'            => mb_substr( (string) ( $attributes['ota_reservation_code'] ?? $attributes['unique_id'] ?? '' ), 0, 64 ),
				'arrival'             => self::date( $attributes['arrival_date'] ?? null ),
				'departure'           => self::date( $attributes['departure_date'] ?? null ),
				'cx_inserted_at'      => self::datetime( $attributes['inserted_at'] ?? null ) ?? $now,
				'attempts'            => 1,
				'payload'             => $payload,
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);
	}

	/**
	 * Outcome of a revision processed (the booking is saved).
	 *
	 * @param array{local_booking_id?: int|null, outcome: string, overbooked?: bool, needs_attention?: bool, error?: string|null} $result
	 */
	public function processed( string $revision_id, array $result ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'cx_revisions' ),
			array(
				'local_booking_id' => $result['local_booking_id'] ?? null,
				'outcome'          => $result['outcome'],
				'overbooked'       => ! empty( $result['overbooked'] ) ? 1 : 0,
				'needs_attention'  => ! empty( $result['needs_attention'] ) ? 1 : 0,
				'error'            => isset( $result['error'] ) ? mb_substr( (string) $result['error'], 0, 1000 ) : null,
				'processed_at'     => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'revision_id' => $revision_id )
		);
	}

	/** A revision that could not be saved: Channex will propose it again (no ack). */
	public function failed( string $revision_id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'cx_revisions' ),
			array(
				'error'      => mb_substr( $error, 0, 1000 ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'revision_id' => $revision_id )
		);
	}

	public function acked( string $revision_id ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'cx_revisions' ),
			array(
				'acked_at'   => gmdate( 'Y-m-d H:i:s' ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'revision_id' => $revision_id )
		);
	}

	/** Back to "to process" for a new attempt from the stored payload. */
	public function reopen( string $revision_id ): void {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'cx_revisions' ),
			array(
				'processed_at'    => null,
				'needs_attention' => 0,
				'error'           => null,
			),
			array( 'revision_id' => $revision_id )
		);
	}

	/** Processed but not acknowledged (the ack failed): to acknowledge again. */
	public function unacked( int $property_id ): array {
		global $wpdb;
		$t = Schema::table( 'cx_revisions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT revision_id FROM {$t} WHERE property_id = %d AND processed_at IS NOT NULL AND acked_at IS NULL ORDER BY created_at LIMIT 50", $property_id ) ) );
	}

	/**
	 * Latest revisions of a property for the screen (no guest data beyond the name).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $property_id, int $limit = 50 ): array {
		global $wpdb;
		$t = Schema::table( 'cx_revisions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT revision_id, channex_booking_id, status, ota_name, ota_code, arrival, departure, local_booking_id, outcome, overbooked, needs_attention, attempts, processed_at, acked_at, error, created_at FROM {$t} WHERE property_id = %d ORDER BY needs_attention DESC, created_at DESC LIMIT %d", $property_id, max( 1, min( 200, $limit ) ) ), ARRAY_A );
		return array_map(
			static fn( array $r ) => array(
				'revision_id'     => (string) $r['revision_id'],
				'booking_id'      => (string) $r['channex_booking_id'],
				'status'          => (string) $r['status'],
				'ota_name'        => (string) $r['ota_name'],
				'ota_code'        => (string) $r['ota_code'],
				'arrival'         => $r['arrival'],
				'departure'       => $r['departure'],
				'local_booking_id' => null === $r['local_booking_id'] ? null : (int) $r['local_booking_id'],
				'outcome'         => $r['outcome'],
				'overbooked'      => (bool) (int) $r['overbooked'],
				'needs_attention' => (bool) (int) $r['needs_attention'],
				'attempts'        => (int) $r['attempts'],
				'processed_at'    => Clock::iso( $r['processed_at'] ),
				'acked_at'        => Clock::iso( $r['acked_at'] ),
				'error'           => $r['error'],
				'at'              => Clock::iso( (string) $r['created_at'] ),
			),
			$rows
		);
	}

	/** Revisions that need the staff (unmapped, overbooked, not saved). */
	public function attention_count( int $property_id ): int {
		global $wpdb;
		$t = Schema::table( 'cx_revisions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE property_id = %d AND ( needs_attention = 1 OR ( processed_at IS NULL AND error IS NOT NULL ) )", $property_id ) );
	}

	/** Closes the warning of a revision handled by the staff. */
	public function resolve( string $revision_id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'cx_revisions' ), array( 'needs_attention' => 0 ), array( 'revision_id' => $revision_id ) );
	}

	private static function date( $value ): ?string {
		$value = substr( (string) $value, 0, 10 );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}

	private static function datetime( $value ): ?string {
		$time = strtotime( (string) $value . ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', (string) $value ) ? '' : ' UTC' ) );
		return false === $time || '' === (string) $value ? null : gmdate( 'Y-m-d H:i:s', $time );
	}
}
