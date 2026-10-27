<?php
/**
 * WhatsApp channel, like Night Manager's: the guest messages go out from the site's
 * WhatsApp Business number (WhatsApp → Collegamento) with the texts approved by WhatsApp
 * (WhatsApp → Messaggi).
 *
 * - Every guest with a mobile number gets them, unless the guest said «Non voglio
 *   ricevere messaggi WhatsApp» (a guest_consents row, purpose "whatsapp", not granted).
 * - The text is the approved version of the message, filled when it leaves with the
 *   booking as it is then; a message whose new text waits for approval goes with the
 *   previous approved one, or waits for it when there is none yet.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\Channels;

use BookingManager\Bookings\ManageLink;
use BookingManager\Guests\GuestRepository;
use BookingManager\Notifications\Message;
use BookingManager\Notifications\NotificationTemplates;
use BookingManager\Notifications\SendResult;
use BookingManager\Notifications\WhatsApp\WhatsAppSettings;
use BookingManager\Notifications\WhatsApp\WhatsAppTemplates;
use BookingManager\Notifications\WhatsApp\WhatsAppVariables;
use BookingManager\Payments\PaymentService;

defined( 'ABSPATH' ) || exit;

class WhatsAppChannel implements NotificationChannel {

	/** Purpose of the guest_consents row that records the guest's choice. */
	public const CONSENT = 'whatsapp';

	public function __construct(
		private WhatsAppSettings $settings,
		private GuestRepository $guests,
		private ManageLink $manage,
		private NotificationTemplates $templates
	) {}

	public function key(): string {
		return 'whatsapp';
	}

	public function label(): string {
		return __( 'WhatsApp', 'booking-manager' );
	}

	public function settings(): WhatsAppSettings {
		return $this->settings;
	}

	/** Connected, switched on and this message active: it is queued. */
	public function sends( string $key ): bool {
		return $this->settings->active() && WhatsAppTemplates::is_active( $this->settings->config(), $key );
	}

	public function unavailable( array $property ): ?string {
		if ( ! $this->settings->connected() ) {
			return __( 'WhatsApp non è collegato (WhatsApp → Collegamento).', 'booking-manager' );
		}
		if ( ! $this->settings->active() ) {
			return __( 'I messaggi WhatsApp sono spenti (WhatsApp → Messaggi).', 'booking-manager' );
		}
		return null;
	}

	/** The guest's mobile number as WhatsApp wants it; '' without one or after an opt-out. */
	public function recipient( array $booking ): string {
		if ( $this->opted_out( $booking ) ) {
			return '';
		}
		return (string) WhatsAppSettings::wa_phone( (string) ( $booking['phone'] ?? '' ), $this->settings->country_code() );
	}

	/** @param array<string, mixed> $booking */
	public function opted_out( array $booking ): bool {
		$guest = (int) ( $booking['guest_id'] ?? 0 );
		return $guest > 0 && false === $this->guests->latest_consent( $guest, self::CONSENT );
	}

	public function send( Message $message ): SendResult {
		$booking = $message->booking;
		if ( $this->opted_out( $booking ) ) {
			return SendResult::skipped( __( 'L’ospite non vuole ricevere messaggi WhatsApp.', 'booking-manager' ) );
		}
		$config   = $this->settings->config();
		$provider = $this->settings->provider_for( $config );
		$creds    = $this->settings->credentials( $config );
		if ( ! $provider || '' === (string) $config['account'] || null === $creds ) {
			return SendResult::skipped( __( 'WhatsApp non è più collegato.', 'booking-manager' ) );
		}
		$key      = $message->template_key;
		$state    = (array) ( $config['templates'][ $key ] ?? array() );
		$approved = (array) ( $state['approved'] ?? array() );
		if ( ! $approved ) {
			return 'pending' === ( $state['latest']['status'] ?? '' )
				? SendResult::retry( __( 'Il testo del messaggio è ancora in attesa di approvazione da parte di WhatsApp.', 'booking-manager' ) )
				: SendResult::failed( __( 'Il testo del messaggio non è approvato da WhatsApp: controllalo in WhatsApp → Messaggi.', 'booking-manager' ) );
		}
		$values = $this->values( $booking, $message->property, $key );
		$button = self::button_param( $approved, $values );
		if ( false === $button ) {
			return SendResult::failed( self::button_error() );
		}
		return $provider->send( $creds, $message->recipient, (string) $approved['name'], (string) $config['language'], WhatsAppTemplates::params( (array) $approved['params'], $values ), $button );
	}

	/**
	 * The values of the {variables} for a booking.
	 *
	 * @param array<string, mixed> $booking
	 * @param array<string, mixed> $property
	 * @return array<string, string>
	 */
	public function values( array $booking, array $property, string $key ): array {
		$link = empty( $booking['manage_token_hash'] ) ? home_url( '/' ) : $this->manage->url( $booking );
		$now  = NotificationTemplates::GUEST_ACCEPTED === $key ? PaymentService::online_amount( $booking, (string) ( $property['settings']['online_payment'] ?? 'full' ) ) : 0;
		return WhatsAppVariables::for_booking( $booking, $property, $this->templates->rooms_text( $booking ), $link, $now );
	}

	/**
	 * The {{1}} of the approved text's button (unit-tested): null when the text has no
	 * button, false when the booking page cannot go in it (the site's address changed
	 * after the approval).
	 *
	 * @param array<string, mixed>  $approved
	 * @param array<string, string> $values
	 */
	public static function button_param( array $approved, array $values ): string|false|null {
		$base = (string) ( $approved['button'] ?? '' );
		if ( '' === $base ) {
			return null;
		}
		return WhatsAppVariables::button_suffix( (string) ( $values['link'] ?? '' ), $base, home_url( '/' ) ) ?? false;
	}

	public static function button_error(): string {
		return __( 'Il link del pulsante non corrisponde più all’indirizzo del sito: salva di nuovo il messaggio in WhatsApp → Messaggi per chiederne l’approvazione.', 'booking-manager' );
	}
}
