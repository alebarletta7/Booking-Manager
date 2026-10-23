<?php
/**
 * The property's own texts of the messages (bkng_templates): subject and text per
 * message, channel and language. A message without its own text uses the default.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class TemplateRepository {

	/** @return array<string, mixed>|null the active row, in the language or else in Italian */
	public function find( int $property_id, string $channel, string $key, string $language = 'it' ): ?array {
		global $wpdb;
		$t = Schema::table( 'templates' );
		foreach ( array_unique( array( $language, 'it' ) ) as $lang ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE property_id = %d AND channel = %s AND template_key = %s AND language = %s AND is_active = 1", $property_id, $channel, $key, $lang ), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}
		return null;
	}

	/** @return array<int, array<string, mixed>> every row of a property */
	public function for_property( int $property_id ): array {
		global $wpdb;
		$t = Schema::table( 'templates' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE property_id = %d ORDER BY template_key, channel, language", $property_id ), ARRAY_A );
	}

	public function save( int $property_id, string $channel, string $key, string $language, ?string $subject, ?string $body, int $user_id ): void {
		global $wpdb;
		$t   = Schema::table( 'templates' );
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (property_id, channel, template_key, language, subject, body, is_active, updated_by, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, 1, %d, %s, %s) ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), is_active = 1, updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)", $property_id, $channel, $key, $language, (string) $subject, (string) $body, $user_id, $now, $now ) );
	}

	/** Back to the default text. */
	public function delete( int $property_id, string $channel, string $key, string $language ): void {
		global $wpdb;
		$wpdb->delete(
			Schema::table( 'templates' ),
			array(
				'property_id'  => $property_id,
				'channel'      => $channel,
				'template_key' => $key,
				'language'     => $language,
			)
		);
	}
}
