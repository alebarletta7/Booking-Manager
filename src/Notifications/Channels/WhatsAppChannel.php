<?php
/**
 * WhatsApp channel, prepared only: it sends through a WhatsAppProvider supplied by an
 * add-on (filter bkng_whatsapp_provider) and is "skipped", with the reason, otherwise.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\Channels;

use BookingManager\Core\Phone;
use BookingManager\Notifications\Message;
use BookingManager\Notifications\SendResult;
use BookingManager\Notifications\WhatsApp\WhatsAppProvider;

defined( 'ABSPATH' ) || exit;

class WhatsAppChannel implements NotificationChannel {

	public function key(): string {
		return 'whatsapp';
	}

	public function label(): string {
		return __( 'WhatsApp', 'booking-manager' );
	}

	public function provider(): ?WhatsAppProvider {
		/**
		 * The WhatsApp sender (an object implementing WhatsAppProvider), null for none.
		 *
		 * @param WhatsAppProvider|null $provider
		 */
		$provider = apply_filters( 'bkng_whatsapp_provider', null );
		return $provider instanceof WhatsAppProvider ? $provider : null;
	}

	public function unavailable( array $property ): ?string {
		if ( empty( $property['settings']['whatsapp_enabled'] ) ) {
			return __( 'WhatsApp non attivo per questa struttura.', 'booking-manager' );
		}
		$provider = $this->provider();
		if ( ! $provider ) {
			return __( 'Nessun servizio WhatsApp collegato: il canale è predisposto ma serve un fornitore (account WhatsApp Business e modelli approvati).', 'booking-manager' );
		}
		return $provider->unavailable( $property );
	}

	public function recipient( array $booking ): string {
		return (string) ( Phone::normalize( (string) ( $booking['phone'] ?? '' ) ) ?? '' );
	}

	public function send( Message $message ): SendResult {
		$provider = $this->provider();
		if ( ! $provider ) {
			return SendResult::skipped( __( 'Nessun servizio WhatsApp collegato.', 'booking-manager' ) );
		}
		return $provider->send( $message );
	}
}
