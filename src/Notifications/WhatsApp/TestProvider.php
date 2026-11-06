<?php
/**
 * Test mode: nothing leaves the site. Every message is "sent" at once and appears in the
 * log with an id starting with "prova-", so the whole flow (events, texts, log) can be
 * tried before connecting a real WhatsApp account, even on a site not reachable from
 * the internet. Texts count as approved straight away.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\WhatsApp;

use BookingManager\Notifications\SendResult;

defined( 'ABSPATH' ) || exit;

class TestProvider implements WhatsAppProvider {

	public const ID = 'test';

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Prova (nessun messaggio parte davvero)', 'booking-manager' );
	}

	public function fields(): array {
		return array();
	}

	public function check( array $credentials ): array|\WP_Error {
		return array( 'account' => __( 'Modalità di prova', 'booking-manager' ) );
	}

	public function send( array $credentials, string $to, string $template, string $language, array $params, ?string $button = null ): SendResult {
		return SendResult::sent( 'prova-' . wp_generate_password( 12, false, false ) );
	}

	public function submit_template( array $credentials, string $name, string $language, string $body, array $examples, ?array $button = null ): array|\WP_Error {
		return array(
			'status' => 'approved',
			'reason' => '',
		);
	}

	public function template_status( array $credentials, string $name, string $language ): array|\WP_Error {
		return array(
			'status' => 'approved',
			'reason' => '',
		);
	}
}
