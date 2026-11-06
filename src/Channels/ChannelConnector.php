<?php
/**
 * A connection to a sales channel (OTA or channel manager), supplied by an add-on
 * through the bkng_channel_connectors filter.
 *
 * Booking Manager ships none: every channel has its own contract and certification, and
 * no channel's API is imitated here. What is ready is the plumbing a connector needs:
 * - reservations come in through ChannelService::import_reservation() (idempotent,
 *   mapped with bkng_channel_refs, every attempt in bkng_sync_log);
 * - changes of availability go out through push_availability(), called after every
 *   booking change of the property.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels;

defined( 'ABSPATH' ) || exit;

interface ChannelConnector {

	/** Stable key stored in channel_refs and sync_log (e.g. "acme_channel_manager"). */
	public function key(): string;

	public function label(): string;

	/**
	 * Null when the connector is set up for this property, otherwise why not.
	 *
	 * @param array<string, mixed> $property
	 */
	public function unavailable( array $property ): ?string;

	/**
	 * Availability of some room types changed between two dates ([from, to)): the
	 * connector reads it (AvailabilityService) and sends it to its channel.
	 *
	 * @param int[] $room_type_ids
	 * @return true|\WP_Error
	 */
	public function push_availability( int $property_id, array $room_type_ids, string $from, string $to ): bool|\WP_Error;
}
