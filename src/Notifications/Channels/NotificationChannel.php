<?php
/**
 * A way of delivering notifications (e-mail, WhatsApp, or one added by another plugin
 * through the bkng_notification_channels filter).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\Channels;

use BookingManager\Notifications\Message;
use BookingManager\Notifications\SendResult;

defined( 'ABSPATH' ) || exit;

interface NotificationChannel {

	/** Stable key stored in bkng_notifications.channel (e.g. "email"). */
	public function key(): string;

	public function label(): string;

	/**
	 * Null when the channel can send for this property, otherwise the reason it cannot
	 * (shown in the notification log; the message is marked "skipped", never "sent").
	 *
	 * @param array<string, mixed> $property
	 */
	public function unavailable( array $property ): ?string;

	/**
	 * The recipient of a booking on this channel ('' when there is none).
	 *
	 * @param array<string, mixed> $booking
	 */
	public function recipient( array $booking ): string;

	public function send( Message $message ): SendResult;
}
