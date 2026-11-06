<?php
/**
 * A WhatsApp sending service. The rest of the plugin only talks to this interface, so a
 * provider can be swapped (or added with the bkng_whatsapp_providers filter) without
 * touching anything else.
 *
 * Business-initiated WhatsApp messages are "templates": a text approved in advance by the
 * provider, with numbered parameters filled at sending time. Providers that need no
 * approval report templates as approved straight away.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\WhatsApp;

use BookingManager\Notifications\SendResult;

defined( 'ABSPATH' ) || exit;

interface WhatsAppProvider {

	public function id(): string;

	public function label(): string;

	/**
	 * Fields the administrator fills in to connect.
	 *
	 * @return array<int, array{name: string, label: string, secret: bool, required: bool, help: string}>
	 */
	public function fields(): array;

	/**
	 * Checks the credentials with the provider.
	 *
	 * @param array<string, string> $credentials
	 * @return array{account: string}|\WP_Error what to show as "connected to"
	 */
	public function check( array $credentials ): array|\WP_Error;

	/**
	 * Sends one approved template.
	 *
	 * @param array<string, string> $credentials
	 * @param string[]              $params     values of {{1}}, {{2}}… in order
	 * @param string|null           $button     end of the link of the template's button
	 *                                          («Vedi la prenotazione»), null when it has none
	 */
	public function send( array $credentials, string $to, string $template, string $language, array $params, ?string $button = null ): SendResult;

	/**
	 * Asks for a template to be approved.
	 *
	 * @param array<string, string> $credentials
	 * @param string                $body     text with {{1}}, {{2}}…
	 * @param string[]              $examples example value of each parameter
	 * @param array{text: string, url: string, example: string}|null $button a link button under
	 *        the text: url ends with {{1}} (the part that changes per message), example is a whole link
	 * @return array{status: string, reason: string}|\WP_Error status: approved, pending, rejected…
	 *         An error with data duplicate=true: that name is already taken at the provider.
	 */
	public function submit_template( array $credentials, string $name, string $language, string $body, array $examples, ?array $button = null ): array|\WP_Error;

	/**
	 * @param array<string, string> $credentials
	 * @return array{status: string, reason: string}|\WP_Error
	 */
	public function template_status( array $credentials, string $name, string $language ): array|\WP_Error;
}
