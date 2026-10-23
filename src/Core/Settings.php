<?php
/**
 * Global plugin settings (one autoloaded option).
 *
 * Per-property settings (booking rules, e-mails, check-in, WhatsApp) live in the
 * bkng_properties table (Properties\PropertySettings), not here.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

class Settings {

	public const OPTION = 'bkng_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Uninstall removes data ONLY when an administrator explicitly enables this.
			'delete_data_on_uninstall'  => false,
			// Audit log: store a truncated IP address (privacy-friendly) or nothing.
			'audit_store_ip'            => false,
			// Customers without bookings for this many months are anonymized (0 = never).
			'customer_retention_months' => 0,
			// Audit log rows older than this many months are deleted every day (0 = keep).
			'audit_retention_months'    => 24,
			// Notification log rows older than this many months are deleted (0 = keep).
			'notification_retention_months' => 12,
			// Check-in log rows older than this many months are deleted (0 = keep).
			'checkin_retention_months'  => 24,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Updates known keys only, with type coercion based on the default value.
	 *
	 * @param array<string, mixed> $values
	 */
	public function update( array $values ): void {
		$current  = $this->all();
		$defaults = self::defaults();
		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$current[ $key ] = self::coerce( $defaults[ $key ], $value );
		}
		update_option( self::OPTION, $current, true );
	}

	/**
	 * @param mixed $default
	 * @param mixed $value
	 * @return mixed
	 */
	private static function coerce( $default, $value ) {
		if ( is_bool( $default ) ) {
			return (bool) rest_sanitize_boolean( $value );
		}
		if ( is_int( $default ) ) {
			return min( 600, max( 0, (int) $value ) );
		}
		return sanitize_text_field( (string) $value );
	}
}
