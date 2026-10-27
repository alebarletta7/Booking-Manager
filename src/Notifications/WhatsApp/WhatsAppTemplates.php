<?php
/**
 * The WhatsApp messages to guests: when they go out and their default text (editable in
 * WhatsApp → Messaggi, like in Night Manager). Same moments as the e-mails.
 *
 * Texts are plain text with {variables}; {{nome}} is accepted too and saved as {nome}.
 * For the provider every variable becomes a numbered parameter ({{1}}, {{2}}… in order of
 * appearance), which is how WhatsApp templates work.
 *
 * WhatsApp rules checked before saving (otherwise Meta rejects the text):
 * - at most 1024 characters;
 * - the text cannot start or end with a variable (punctuation around it does not count);
 * - two variables need some text between them.
 *
 * WhatsApp cannot hide a link behind a word, so the link to the guest's booking page goes
 * in a button under the text («Vedi la prenotazione», «Completa il pagamento»). Its URL is
 * the site's address with the booking code and key at the end, the only place where Meta
 * lets a button's link change from message to message.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\WhatsApp;

use BookingManager\Notifications\NotificationTemplates as T;

defined( 'ABSPATH' ) || exit;

final class WhatsAppTemplates {

	/** The messages, in the order the admin shows them (keys of NotificationTemplates). */
	public const KEYS = array( T::GUEST_RECEIVED, T::GUEST_ACCEPTED, T::GUEST_CONFIRMED, T::GUEST_UPDATED, T::GUEST_CANCELLED, T::GUEST_PRE_ARRIVAL, T::GUEST_PAYMENT, T::GUEST_CHECKED_IN );

	public const MAX_LENGTH = 1024;

	/** Text of a message's link button (Meta allows at most 25 characters). */
	public static function button_text( string $key ): string {
		return T::GUEST_ACCEPTED === $key ? __( 'Completa il pagamento', 'booking-manager' ) : __( 'Vedi la prenotazione', 'booking-manager' );
	}

	/**
	 * @return array<string, array{label: string, when: string, active: bool, button: bool, body: string}>
	 */
	public static function all(): array {
		return array(
			T::GUEST_RECEIVED    => array(
				'label'  => __( 'Richiesta ricevuta', 'booking-manager' ),
				'when'   => __( 'Quando arriva una richiesta dal sito che la struttura deve ancora accettare.', 'booking-manager' ),
				'active' => true,
				'button' => true,
				'body'   => __( "Ciao {nome}, abbiamo ricevuto la tua richiesta di prenotazione a {struttura} dal {arrivo} al {partenza} ({ospiti}).\n\nCodice: {codice}\n\nLa controlliamo e ti scriviamo appena è confermata. Grazie!", 'booking-manager' ),
			),
			T::GUEST_ACCEPTED    => array(
				'label'  => __( 'Richiesta accettata: completa il pagamento', 'booking-manager' ),
				'when'   => __( 'Quando accetti una richiesta e l’ospite deve pagare online per confermarla, con il pulsante per pagare.', 'booking-manager' ),
				'active' => true,
				'button' => true,
				'body'   => __( "Ciao {nome}, buone notizie: abbiamo accettato la tua richiesta a {struttura} dal {arrivo} al {partenza} e le camere sono riservate per te.\n\nDa pagare ora: {da_pagare_ora}\nEntro: {scadenza}\n\nTocca «Completa il pagamento» qui sotto per confermare la prenotazione: appena arriva il pagamento ti mandiamo la conferma.", 'booking-manager' ),
			),
			T::GUEST_CONFIRMED   => array(
				'label'  => __( 'Prenotazione confermata', 'booking-manager' ),
				'when'   => __( 'Quando la prenotazione è confermata (anche dopo il pagamento online), con il pulsante per aprirla.', 'booking-manager' ),
				'active' => true,
				'button' => true,
				'body'   => __( "Ciao {nome}, la tua prenotazione a {struttura} è confermata!\n\nArrivo: {arrivo}, check-in dalle {checkin_dalle} alle {checkin_alle}\nPartenza: {partenza}, check-out entro le {checkout_entro}\nCamere: {camere}\nOspiti: {ospiti}\nTotale: {totale}\nCodice: {codice}\nIndirizzo: {indirizzo}\n\nTi aspettiamo!", 'booking-manager' ),
			),
			T::GUEST_UPDATED     => array(
				'label'  => __( 'Prenotazione modificata', 'booking-manager' ),
				'when'   => __( 'Quando cambiano date, camere od ospiti di una prenotazione confermata.', 'booking-manager' ),
				'active' => true,
				'button' => true,
				'body'   => __( "Ciao {nome}, la tua prenotazione {codice} a {struttura} è stata modificata.\n\nArrivo: {arrivo}\nPartenza: {partenza}\nCamere: {camere}\nOspiti: {ospiti}\nTotale: {totale}\n\nPer qualsiasi domanda chiamaci al {telefono}, a presto!", 'booking-manager' ),
			),
			T::GUEST_CANCELLED   => array(
				'label'  => __( 'Prenotazione annullata', 'booking-manager' ),
				'when'   => __( 'Quando una prenotazione viene annullata (dalla struttura, dall’ospite o per un’opzione scaduta).', 'booking-manager' ),
				'active' => true,
				'button' => false,
				'body'   => __( "Ciao {nome}, la prenotazione {codice} a {struttura} dal {arrivo} al {partenza} è stata annullata.\n\nMotivo: {motivo}\n\nPer informazioni chiamaci al {telefono}. Speriamo di ospitarti presto.", 'booking-manager' ),
			),
			T::GUEST_PRE_ARRIVAL => array(
				'label'  => __( 'Promemoria prima dell’arrivo', 'booking-manager' ),
				'when'   => __( 'Qualche giorno prima dell’arrivo, come impostato in Struttura → Notifiche.', 'booking-manager' ),
				'active' => true,
				'button' => true,
				'body'   => __( "Ciao {nome}, ti aspettiamo a {struttura} il {arrivo}!\n\nCheck-in dalle {checkin_dalle} alle {checkin_alle}\nIndirizzo: {indirizzo}\nDa pagare in struttura: {da_pagare}\n\nPer qualsiasi cosa chiamaci al {telefono}. Buon viaggio!", 'booking-manager' ),
			),
			T::GUEST_PAYMENT     => array(
				'label'  => __( 'Pagamento ricevuto', 'booking-manager' ),
				'when'   => __( 'Quando arriva un pagamento online per una prenotazione già confermata.', 'booking-manager' ),
				'active' => true,
				'button' => true,
				'body'   => __( "Ciao {nome}, abbiamo ricevuto il tuo pagamento per la prenotazione {codice} a {struttura}.\n\nTotale: {totale}\nGià pagato: {pagato}\nDa pagare: {da_pagare}\n\nGrazie!", 'booking-manager' ),
			),
			T::GUEST_CHECKED_IN  => array(
				'label'  => __( 'Benvenuto (dopo il check-in)', 'booking-manager' ),
				'when'   => __( 'Al check-in, se in Struttura → Notifiche è attivo il messaggio di benvenuto.', 'booking-manager' ),
				'active' => true,
				'button' => false,
				'body'   => __( "Ciao {nome}, benvenuto a {struttura}! Il check-in è registrato.\n\nPartenza: {partenza}, check-out entro le {checkout_entro}\n\nPer qualsiasi necessità chiamaci al {telefono}. Buon soggiorno!", 'booking-manager' ),
			),
		);
	}

	/** @return array<string, string> variable (without braces) → what it is */
	public static function variables(): array {
		return array(
			'nome'           => __( 'Nome dell’ospite', 'booking-manager' ),
			'cognome'        => __( 'Cognome dell’ospite', 'booking-manager' ),
			'ospite'         => __( 'Nome e cognome dell’ospite', 'booking-manager' ),
			'struttura'      => __( 'Nome della struttura', 'booking-manager' ),
			'indirizzo'      => __( 'Indirizzo della struttura', 'booking-manager' ),
			'telefono'       => __( 'Telefono della struttura', 'booking-manager' ),
			'codice'         => __( 'Codice della prenotazione', 'booking-manager' ),
			'arrivo'         => __( 'Giorno di arrivo (es. sabato 14 novembre 2026)', 'booking-manager' ),
			'partenza'       => __( 'Giorno di partenza', 'booking-manager' ),
			'notti'          => __( 'Numero di notti', 'booking-manager' ),
			'ospiti'         => __( 'Ospiti (es. 2 ospiti)', 'booking-manager' ),
			'camere'         => __( 'Camere prenotate (es. 1 × Matrimoniale)', 'booking-manager' ),
			'totale'         => __( 'Totale della prenotazione', 'booking-manager' ),
			'pagato'         => __( 'Quanto è già stato pagato', 'booking-manager' ),
			'da_pagare'      => __( 'Quanto resta da pagare', 'booking-manager' ),
			'da_pagare_ora'  => __( 'Quanto pagare online ora (intero o caparra)', 'booking-manager' ),
			'scadenza'       => __( 'Fino a quando le camere restano riservate in attesa del pagamento', 'booking-manager' ),
			'checkin_dalle'  => __( 'Check-in dalle (es. 14:00)', 'booking-manager' ),
			'checkin_alle'   => __( 'Check-in fino alle', 'booking-manager' ),
			'checkout_entro' => __( 'Check-out entro le', 'booking-manager' ),
			'motivo'         => __( 'Motivo dell’annullamento', 'booking-manager' ),
			'link'           => __( 'Link alla pagina della prenotazione (privato, solo per l’ospite)', 'booking-manager' ),
		);
	}

	public static function exists( string $key ): bool {
		return in_array( $key, self::KEYS, true );
	}

	/** {{nome}} → {nome}; Windows line ends and trailing spaces removed (unit-tested). */
	public static function normalize( string $body ): string {
		$body = (string) preg_replace( '/\{\{\s*([a-z_]+)\s*\}\}/', '{$1}', str_replace( "\r\n", "\n", $body ) );
		return trim( (string) preg_replace( '/[ \t]+$/m', '', $body ) );
	}

	/** @return string[] variables in order of appearance (repeated ones too) */
	public static function used( string $body ): array {
		preg_match_all( '/\{([a-z_]+)\}/', $body, $m );
		return $m[1];
	}

	/**
	 * Problems that would stop the text (empty when fine; unit-tested).
	 *
	 * @return string[]
	 */
	public static function problems( string $body ): array {
		$problems = array();
		if ( '' === $body ) {
			return array( __( 'Scrivi il testo del messaggio.', 'booking-manager' ) );
		}
		if ( mb_strlen( $body ) > self::MAX_LENGTH ) {
			/* translators: %d: maximum number of characters */
			$problems[] = sprintf( __( 'Testo troppo lungo: WhatsApp accetta al massimo %d caratteri.', 'booking-manager' ), self::MAX_LENGTH );
		}
		$unknown = array_values( array_unique( array_diff( self::used( $body ), array_keys( self::variables() ) ) ) );
		if ( $unknown ) {
			/* translators: %s: list of variables such as {nmoe} */
			$problems[] = sprintf( __( 'Variabili sconosciute: %s', 'booking-manager' ), implode( ', ', array_map( static fn( $v ) => '{' . $v . '}', $unknown ) ) );
		}
		// Punctuation does not count as text for Meta: «… chiamaci al {telefono}.» ends with a variable.
		if ( preg_match( '/^[\s\p{P}]*\{[a-z_]+\}/u', $body ) || preg_match( '/\{[a-z_]+\}[\s\p{P}]*$/u', $body ) ) {
			$problems[] = __( 'Il testo non può iniziare o finire con una variabile: WhatsApp lo rifiuterebbe. Aggiungi qualche parola prima o dopo.', 'booking-manager' );
		}
		if ( preg_match( '/\}\s*\{/', $body ) ) {
			$problems[] = __( 'Tra due variabili ci vuole qualche parola: WhatsApp rifiuta variabili attaccate.', 'booking-manager' );
		}
		if ( preg_match( '/\{\{|\}\}/', $body ) ) {
			$problems[] = __( 'Parentesi graffe doppie non valide: scrivi le variabili come {nome}.', 'booking-manager' );
		}
		return $problems;
	}

	/**
	 * The text as the provider wants it: {nome} → {{1}}, {codice} → {{2}}… (unit-tested)
	 *
	 * @return array{body: string, params: string[]} params: the variable behind each number
	 */
	public static function numbered( string $body ): array {
		$params = array();
		$text   = (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( $m ) use ( &$params ) {
				$params[] = $m[1];
				return '{{' . count( $params ) . '}}';
			},
			$body
		);
		return array(
			'body'   => $text,
			'params' => $params,
		);
	}

	/**
	 * The text with the values filled in (preview, and what the guest reads).
	 *
	 * @param array<string, string> $values
	 */
	public static function fill( string $body, array $values ): string {
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static fn( $m ) => array_key_exists( $m[1], $values ) ? $values[ $m[1] ] : $m[0],
			$body
		);
	}

	/**
	 * Values of the approved text's parameters, in order.
	 *
	 * @param string[]              $params
	 * @param array<string, string> $values
	 * @return string[]
	 */
	public static function params( array $params, array $values ): array {
		return array_map( static fn( $v ) => $values[ $v ] ?? '-', array_values( $params ) );
	}

	/** Whether a message goes out (its switch in WhatsApp → Messaggi). */
	public static function is_active( array $config, string $key ): bool {
		if ( ! self::exists( $key ) ) {
			return false;
		}
		$text = (array) ( $config['texts'][ $key ] ?? array() );
		return array_key_exists( 'is_active', $text ) ? (bool) $text['is_active'] : self::all()[ $key ]['active'];
	}

	/** Whether a message has the link button under it. */
	public static function has_button( array $config, string $key ): bool {
		if ( ! self::exists( $key ) ) {
			return false;
		}
		$text = (array) ( $config['texts'][ $key ] ?? array() );
		return array_key_exists( 'button', $text ) ? (bool) $text['button'] : self::all()[ $key ]['button'];
	}
}
