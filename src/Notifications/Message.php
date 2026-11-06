<?php
/**
 * A notification ready for a channel: recipient, texts and the values a WhatsApp
 * template would need. Built right before sending from the booking as it is then.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications;

defined( 'ABSPATH' ) || exit;

final class Message {

	/**
	 * @param array<string, mixed>                                                   $property
	 * @param array<string, mixed>                                                   $booking
	 * @param array<string, string>                                                  $vars   Named values (name, property, arrival, departure, nights, guests, code…).
	 * @param array<int, array{cid: string, data: string, name: string, mime: string}> $inline Images embedded in the e-mail (the QR).
	 * @param array<int, array{data: string, name: string, mime: string}>              $attachments Files attached to the e-mail (the .ics).
	 */
	public function __construct(
		public readonly string $template_key,
		public readonly string $audience,
		public readonly string $recipient,
		public readonly array $property,
		public readonly array $booking,
		public readonly string $subject,
		public readonly string $text,
		public readonly string $html,
		public readonly array $vars = array(),
		public readonly array $inline = array(),
		public readonly array $attachments = array()
	) {}
}
