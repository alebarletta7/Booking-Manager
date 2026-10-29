<?php
/**
 * Links between our records and a channel's ids (bkng_channel_refs): a booking and the
 * channel's reservation number, a room type and the channel's room code.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels;

use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class ChannelRefRepository {

	public const BOOKING   = 'booking';
	public const ROOM_TYPE = 'room_type';

	/** Our id for a channel's id, or null. */
	public function entity_id( string $channel, string $entity_type, string $external_id ): ?int {
		global $wpdb;
		$t = Schema::table( 'channel_refs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT entity_id FROM {$t} WHERE channel = %s AND entity_type = %s AND external_id = %s", $channel, $entity_type, $external_id ) );
		return null === $id ? null : (int) $id;
	}

	/**
	 * Links (or re-links) a channel's id to one of our records.
	 *
	 * @param array<string, mixed>|null $meta
	 */
	public function link( int $property_id, string $channel, string $entity_type, int $entity_id, string $external_id, ?array $meta = null ): void {
		global $wpdb;
		$t   = Schema::table( 'channel_refs' );
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (property_id, entity_type, entity_id, channel, external_id, meta, created_at, updated_at) VALUES (%d, %s, %d, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE entity_id = VALUES(entity_id), property_id = VALUES(property_id), meta = VALUES(meta), updated_at = VALUES(updated_at)", $property_id, $entity_type, $entity_id, $channel, $external_id, (string) wp_json_encode( $meta ), $now, $now ) );
	}

	public function unlink( string $channel, string $entity_type, string $external_id ): void {
		global $wpdb;
		$wpdb->delete(
			Schema::table( 'channel_refs' ),
			array(
				'channel'     => $channel,
				'entity_type' => $entity_type,
				'external_id' => $external_id,
			)
		);
	}

	/** @return array<int, array<string, mixed>> links of a property for a channel */
	public function for_property( int $property_id, string $channel, string $entity_type ): array {
		global $wpdb;
		$t = Schema::table( 'channel_refs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT entity_id, external_id FROM {$t} WHERE property_id = %d AND channel = %s AND entity_type = %s ORDER BY id", $property_id, $channel, $entity_type ), ARRAY_A );
	}
}
