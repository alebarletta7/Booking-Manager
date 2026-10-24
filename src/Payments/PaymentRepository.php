<?php
/**
 * SQL for payments, their ledger (payment_transactions) and received webhook events.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

use BookingManager\Database\LockFailed;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class PaymentRepository {

	/* ------------------------------------------------------------------ payments */

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		global $wpdb;
		$data += array( 'uuid' => wp_generate_uuid4() );
		if ( false === $wpdb->insert( Schema::table( 'payments' ), $data ) ) {
			throw new LockFailed( 'insert payment' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal.
		}
		return (int) $wpdb->insert_id;
	}

	/** @return array<string, mixed>|null */
	public function find( int $id, bool $for_update = false ): ?array {
		global $wpdb;
		$t   = Schema::table( 'payments' );
		$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) . ( $for_update ? ' FOR UPDATE' : '' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return $row ?: null;
	}

	/** @return array<string, mixed>|null */
	public function find_by( string $gateway, string $column, string $value ): ?array {
		global $wpdb;
		if ( ! in_array( $column, array( 'external_id', 'provider_payment_id' ), true ) || '' === $value ) {
			return null;
		}
		$t   = Schema::table( 'payments' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE gateway = %s AND {$column} = %s ORDER BY id DESC LIMIT 1", $gateway, $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/** @return array<int, array<string, mixed>> */
	public function for_booking( int $booking_id ): array {
		global $wpdb;
		$t = Schema::table( 'payments' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE booking_id = %d ORDER BY id", $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param array<string, mixed> $data */
	public function update( int $id, array $data ): void {
		global $wpdb;
		if ( $data && false === $wpdb->update( Schema::table( 'payments' ), $data, array( 'id' => $id ) ) ) {
			throw new LockFailed( 'update payment' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal.
		}
	}

	/**
	 * Money collected and given back on a booking, from its payments.
	 *
	 * @return array{paid: int, refunded: int}
	 */
	public function booking_totals( int $booking_id ): array {
		global $wpdb;
		$t       = Schema::table( 'payments' );
		$settled = "'" . implode( "','", PaymentStatus::SETTLED ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and constants are internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) AS paid, COALESCE(SUM(refunded), 0) AS refunded FROM {$t} WHERE booking_id = %d AND status IN ({$settled})", $booking_id ), ARRAY_A );
		return array(
			'paid'     => (int) ( $row['paid'] ?? 0 ),
			'refunded' => (int) ( $row['refunded'] ?? 0 ),
		);
	}

	/** @return array<int, array<string, mixed>> online payments still open after a date (reconciliation) */
	public function open_before( string $before, int $limit = 50 ): array {
		// Also closed here but with the provider holding the money (a capture still pending
		// when the booking expired): when it completes, the late-payment rules apply.
		global $wpdb;
		$t    = Schema::table( 'payments' );
		$open = "'" . implode( "','", PaymentStatus::OPEN ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and constants are internal.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE ( status IN ({$open}) OR ( status IN ('cancelled','failed') AND provider_payment_id IS NOT NULL AND received_at IS NULL ) ) AND gateway <> 'manual' AND external_id IS NOT NULL AND created_at <= %s AND created_at >= %s ORDER BY id LIMIT %d", $before, gmdate( 'Y-m-d H:i:s', strtotime( $before . ' UTC' ) - 2 * DAY_IN_SECONDS ), $limit ), ARRAY_A );
	}

	/**
	 * Online payments taken whose booking still waits for its outcome (held, expired, or
	 * cancelled before the money arrived).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function paid_unsettled( int $limit = 50 ): array {
		global $wpdb;
		$p = Schema::table( 'payments' );
		$b = Schema::table( 'bookings' );
		$t = Schema::table( 'payment_transactions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are internal.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.* FROM {$p} p JOIN {$b} b ON b.id = p.booking_id WHERE p.status = 'paid' AND p.gateway <> 'manual' AND ( b.status = 'option' OR ( b.status = 'cancelled' AND p.received_at >= b.cancelled_at ) ) AND NOT EXISTS ( SELECT 1 FROM {$t} t WHERE t.payment_id = p.id AND t.idempotency_key LIKE %s ) ORDER BY p.id LIMIT %d", 'late-%', $limit ), ARRAY_A );
	}

	/** @return array<int, array<string, mixed>> refunds still pending without a provider reference */
	public function uncertain_refunds( string $before, int $limit = 20 ): array {
		global $wpdb;
		$t = Schema::table( 'payment_transactions' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE type IN ('REFUND','PARTIAL_REFUND') AND status = 'pending' AND external_ref IS NULL AND provider <> 'manual' AND idempotency_key IS NOT NULL AND created_at <= %s AND created_at >= %s ORDER BY id LIMIT %d", $before, gmdate( 'Y-m-d H:i:s', strtotime( $before . ' UTC' ) - 5 * HOUR_IN_SECONDS ), $limit ), ARRAY_A );
	}

	/** Was this payment already given back automatically (late payment)? */
	public function has_late_refund( int $payment_id ): bool {
		global $wpdb;
		$t = Schema::table( 'payment_transactions' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE payment_id = %d AND idempotency_key LIKE %s LIMIT 1", $payment_id, 'late-%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------ ledger */

	/** @param array<string, mixed> $data */
	public function insert_transaction( array $data ): int {
		global $wpdb;
		if ( false === $wpdb->insert( Schema::table( 'payment_transactions' ), $data ) ) {
			throw new LockFailed( 'insert transaction' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal.
		}
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $data */
	public function update_transaction( int $id, array $data ): void {
		global $wpdb;
		if ( false === $wpdb->update( Schema::table( 'payment_transactions' ), $data, array( 'id' => $id ) ) ) {
			throw new LockFailed( 'update transaction' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal.
		}
	}

	/** @return array<string, mixed>|null */
	public function transaction_by( string $column, $value, ?int $payment_id = null ): ?array {
		global $wpdb;
		if ( ! in_array( $column, array( 'id', 'external_ref', 'idempotency_key' ), true ) || null === $value || '' === $value ) {
			return null;
		}
		$t     = Schema::table( 'payment_transactions' );
		$where = $wpdb->prepare( "{$column} = %s", (string) $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null !== $payment_id ) {
			$where .= $wpdb->prepare( ' AND payment_id = %d', $payment_id );
		}
		$row = $wpdb->get_row( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		return $row ?: null;
	}

	/** @return array<int, array<string, mixed>> */
	public function transactions( int $payment_id ): array {
		global $wpdb;
		$t = Schema::table( 'payment_transactions' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE payment_id = %d ORDER BY id", $payment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Refunds of a payment: given back, and still on their way.
	 *
	 * @return array{succeeded: int, pending: int}
	 */
	public function refund_sums( int $payment_id ): array {
		global $wpdb;
		$t     = Schema::table( 'payment_transactions' );
		$types = "'" . implode( "','", TransactionType::REFUNDS ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and constants are internal.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(CASE WHEN status = 'succeeded' THEN amount END), 0) AS succeeded, COALESCE(SUM(CASE WHEN status = 'pending' THEN amount END), 0) AS pending FROM {$t} WHERE payment_id = %d AND type IN ({$types})", $payment_id ), ARRAY_A );
		return array(
			'succeeded' => (int) ( $row['succeeded'] ?? 0 ),
			'pending'   => (int) ( $row['pending'] ?? 0 ),
		);
	}

	/* ------------------------------------------------------------------ webhook events */

	/**
	 * Records a received event once. Returns the stored row (existing one on duplicates).
	 *
	 * @param array<string, mixed> $data
	 * @return array{row: array<string, mixed>, duplicate: bool}
	 */
	public function record_event( array $data ): array {
		global $wpdb;
		$t        = Schema::table( 'webhook_events' );
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( $t, $data );
		$wpdb->suppress_errors( $suppress );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE gateway = %s AND event_id = %s", $data['gateway'], $data['event_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			throw new LockFailed( 'record webhook' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal.
		}
		return array(
			'row'       => $row,
			'duplicate' => false === $ok,
		);
	}

	/** @param array<string, mixed> $data */
	public function update_event( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'webhook_events' ), $data, array( 'id' => $id ) );
	}
}
