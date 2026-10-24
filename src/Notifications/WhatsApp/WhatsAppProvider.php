<?php
/**
 * A WhatsApp sender (for example the Meta Cloud API or a provider such as Twilio),
 * supplied by an add-on through the bkng_whatsapp_provider filter.
 *
 * Booking Manager does not ship one: WhatsApp messages need an approved business
 * account and templates approved by Meta, so the channel is prepared and stays off
 * ("skipped", with the reason) until a provider is installed and the property turns it
 * on. Nothing is ever recorded as sent without a provider's answer.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\WhatsApp;

use BookingManager\Notifications\Message;
use BookingManager\Notifications\SendResult;

defined( 'ABSPATH' ) || exit;

interface WhatsAppProvider {

	/** Name shown in the log (e.g. "meta_cloud"). */
	public function key(): string;

	/**
	 * Null when the provider can send for this property, otherwise why not.
	 *
	 * @param array<string, mixed> $property
	 */
	public function unavailable( array $property ): ?string;

	/**
	 * Sends a template message. $message->vars holds the values ({nome}, {arrivo}…) and
	 * $message->template_key the message; the provider maps them to its approved template.
	 */
	public function send( Message $message ): SendResult;
}
