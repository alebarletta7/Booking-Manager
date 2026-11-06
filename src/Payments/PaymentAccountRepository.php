<?php
/**
 * SQL for payment_accounts. Credentials go in and out sealed by SecretBox.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

use BookingManager\Core\SecretBox;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class PaymentAccountRepository {

	public function __construct( private SecretBox $box ) {}

	public function find( int $id ): ?PaymentAccount {
		global $wpdb;
		$t   = Schema::table( 'payment_accounts' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	public function find_for( int $property_id, string $provider, string $mode ): ?PaymentAccount {
		global $wpdb;
		$t   = Schema::table( 'payment_accounts' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE property_id = %d AND provider = %s AND mode = %s", $property_id, $provider, $mode ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/** @return array<int, array<string, mixed>> raw rows (no credentials) of a property */
	public function rows_for( int $property_id ): array {
		global $wpdb;
		$t = Schema::table( 'payment_accounts' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, property_id, provider, mode, status, settings, last_verified_at, last_error, updated_at FROM {$t} WHERE property_id = %d ORDER BY provider, mode", $property_id ), ARRAY_A );
	}

	/**
	 * Creates or updates the account of a property/provider/mode.
	 *
	 * @param array<string, mixed>|null $credentials null = keep
	 * @param array<string, mixed>|null $settings    null = keep
	 */
	public function save( int $property_id, string $provider, string $mode, string $status, ?array $credentials, ?array $settings, ?string $last_error, bool $verified ): PaymentAccount {
		global $wpdb;
		$t    = Schema::table( 'payment_accounts' );
		$now  = gmdate( 'Y-m-d H:i:s' );
		$user = get_current_user_id() ?: null;
		$data = array(
			'status'     => $status,
			'last_error' => null === $last_error ? null : mb_substr( $last_error, 0, 255 ),
			'updated_by' => $user,
			'updated_at' => $now,
		);
		if ( null !== $credentials ) {
			$data['credentials'] = $credentials ? $this->box->seal( $credentials ) : null;
		}
		if ( null !== $settings ) {
			$data['settings'] = wp_json_encode( $settings );
		}
		if ( $verified ) {
			$data['last_verified_at'] = $now;
		}
		$existing = $this->find_for( $property_id, $provider, $mode );
		if ( $existing ) {
			$wpdb->update( $t, $data, array( 'id' => $existing->id ) );
			return $this->find( $existing->id ) ?? $existing;
		}
		$wpdb->insert(
			$t,
			$data + array(
				'property_id' => $property_id,
				'provider'    => $provider,
				'mode'        => $mode,
				'created_by'  => $user,
				'created_at'  => $now,
			)
		);
		return $this->find( (int) $wpdb->insert_id );
	}

	/** @param array<string, mixed> $row */
	private function hydrate( array $row ): PaymentAccount {
		$credentials = $this->box->open( $row['credentials'] ?? null );
		$status      = (string) $row['status'];
		if ( null === $credentials ) {
			// The encryption key changed (e.g. new WordPress salts): credentials must be entered again.
			$credentials = array();
			$status      = PaymentAccount::STATUS_ERROR;
		}
		$settings = json_decode( (string) ( $row['settings'] ?? '' ), true );
		return new PaymentAccount( (int) $row['id'], (int) $row['property_id'], (string) $row['provider'], (string) $row['mode'], $status, $credentials, is_array( $settings ) ? $settings : array() );
	}
}
