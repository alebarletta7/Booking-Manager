<?php
/**
 * Append-only audit log.
 *
 * Writes never throw: an audit failure must not break the operation being audited,
 * but it is reported through the bkng_audit_failed action for monitoring.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Audit;

use BookingManager\Core\Settings;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class AuditLogger {

	private ?string $request_id = null;

	public function __construct( private Settings $settings ) {}

	/**
	 * @param array<string, mixed> $args {
	 *     @type string     $action      e.g. booking.created, booking.checked_in, property.updated
	 *     @type string     $entity_type e.g. booking, property, customer
	 *     @type int|null   $entity_id
	 *     @type int|null   $property_id
	 *     @type mixed      $old         previous values (array or scalar)
	 *     @type mixed      $new         new values
	 *     @type string     $reason      mandatory for forced operations
	 *     @type string     $actor_type  user|system|customer|gateway (default: user if logged in, else system)
	 *     @type int|null   $user_id     defaults to the current user
	 * }
	 */
	public function log( array $args ): ?int {
		try {
			return $this->write( $args );
		} catch ( \Throwable $e ) {
			self::report_failure( $e->getMessage(), $args );
			return null;
		}
	}

	/**
	 * Fires bkng_audit_failed; a listener that throws is ignored, log() never throws.
	 *
	 * @param array<string, mixed> $args
	 */
	private static function report_failure( string $error, array $args ): void {
		try {
			do_action( 'bkng_audit_failed', $error, $args );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- nothing else to do.
			unset( $e );
		}
	}

	/** @param array<string, mixed> $args see log(). */
	private function write( array $args ): ?int {
		global $wpdb;

		$user_id = array_key_exists( 'user_id', $args ) ? $args['user_id'] : get_current_user_id();
		$user_id = $user_id ? (int) $user_id : null;

		$row = array(
			'property_id' => isset( $args['property_id'] ) ? (int) $args['property_id'] : null,
			'user_id'     => $user_id,
			'actor_type'  => sanitize_key( (string) ( $args['actor_type'] ?? ( $user_id ? 'user' : 'system' ) ) ),
			'action'      => substr( preg_replace( '/[^a-z0-9_.\-]/', '', strtolower( (string) ( $args['action'] ?? '' ) ) ), 0, 60 ),
			'entity_type' => substr( sanitize_key( (string) ( $args['entity_type'] ?? '' ) ), 0, 40 ),
			'entity_id'   => isset( $args['entity_id'] ) ? (int) $args['entity_id'] : null,
			'old_values'  => self::encode( $args['old'] ?? null ),
			'new_values'  => self::encode( $args['new'] ?? null ),
			'reason'      => isset( $args['reason'] ) ? sanitize_textarea_field( (string) $args['reason'] ) : null,
			'ip'          => $this->settings->get( 'audit_store_ip' ) ? self::truncated_ip() : null,
			'request_id'  => $this->request_id(),
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( '' === $row['action'] || '' === $row['entity_type'] ) {
			self::report_failure( 'missing action or entity_type', $args );
			return null;
		}

		$ok = $wpdb->insert( Schema::table( 'audit_log' ), $row );
		if ( false === $ok ) {
			self::report_failure( (string) $wpdb->last_error, $args );
			return null;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Deletes entries older than the retention period (0 = keep everything), in slices so
	 * a big log never locks the table for long.
	 *
	 * @return int Rows deleted.
	 */
	public function purge( int $months, int $now ): int {
		global $wpdb;
		$before = self::retention_cutoff( $months, $now );
		if ( null === $before ) {
			return 0;
		}
		$table   = Schema::table( 'audit_log' );
		$deleted = 0;
		for ( $i = 0; $i < 50; $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is internal.
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s ORDER BY id LIMIT 1000", $before ) );
			$deleted += max( 0, $n );
			if ( $n < 1000 ) {
				break;
			}
		}
		return $deleted;
	}

	/**
	 * UTC date before which entries are deleted (unit-tested); null when kept forever.
	 */
	public static function retention_cutoff( int $months, int $now ): ?string {
		if ( $months <= 0 ) {
			return null;
		}
		return ( new \DateTimeImmutable( '@' . $now ) )->modify( "-{$months} months" )->format( 'Y-m-d H:i:s' );
	}

	/** Correlates every entry written during the same HTTP request. */
	public function request_id(): string {
		if ( null === $this->request_id ) {
			$this->request_id = wp_generate_uuid4();
		}
		return $this->request_id;
	}

	/** @param mixed $value */
	private static function encode( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$json = wp_json_encode( $value );
		return false === $json ? null : $json;
	}

	private static function truncated_ip(): ?string {
		return self::truncate_ip( \BookingManager\Core\ClientIp::current() );
	}

	/**
	 * IPv4 with last octet zeroed, IPv6 with last 80 bits zeroed (GDPR-friendly), as
	 * text (unit-tested): "203.0.113.0", "2001:db8:1::". Null for anything else.
	 */
	public static function truncate_ip( string $ip ): ?string {
		$packed = false === filter_var( $ip, FILTER_VALIDATE_IP ) ? false : inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}
		$short = 4 === strlen( $packed ) ? substr( $packed, 0, 3 ) . "\0" : substr( $packed, 0, 6 ) . str_repeat( "\0", 10 );
		$text  = inet_ntop( $short );
		return false === $text ? null : $text;
	}
}
