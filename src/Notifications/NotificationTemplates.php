<?php
/**
 * The messages: which ones exist, their default texts, the values they can use and how
 * an e-mail is put together from a booking.
 *
 * Every property can replace subject and text of each guest message (bkng_templates);
 * placeholders: {nome} {struttura} {codice} {arrivo} {partenza} {notti} {ospiti}
 * {totale} {saldo} {checkin_dalle} {checkin_alle} {checkout_entro}.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications;

use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Core\Money;
use BookingManager\Core\QrCode;
use BookingManager\Inventory\RoomTypeRepository;

defined( 'ABSPATH' ) || exit;

class NotificationTemplates {

	public const GUEST_RECEIVED   = 'guest_received';
	public const GUEST_CONFIRMED  = 'guest_confirmed';
	public const GUEST_UPDATED    = 'guest_updated';
	public const GUEST_CANCELLED  = 'guest_cancelled';
	public const GUEST_PRE_ARRIVAL = 'guest_pre_arrival';
	public const GUEST_PAYMENT    = 'guest_payment';
	public const GUEST_CHECKED_IN = 'guest_checked_in';
	public const STAFF_NEW        = 'staff_new';
	public const STAFF_CANCELLED  = 'staff_cancelled';

	public const PLACEHOLDERS = array( '{nome}', '{struttura}', '{codice}', '{arrivo}', '{partenza}', '{notti}', '{ospiti}', '{totale}', '{saldo}', '{checkin_dalle}', '{checkin_alle}', '{checkout_entro}' );

	public function __construct(
		private TemplateRepository $templates,
		private BookingRepository $bookings,
		private RoomTypeRepository $types
	) {}

	/** @return array<string, string> */
	public static function labels(): array {
		return array(
			self::GUEST_RECEIVED    => __( 'Richiesta ricevuta', 'booking-manager' ),
			self::GUEST_CONFIRMED   => __( 'Prenotazione confermata', 'booking-manager' ),
			self::GUEST_UPDATED     => __( 'Prenotazione modificata', 'booking-manager' ),
			self::GUEST_CANCELLED   => __( 'Prenotazione annullata', 'booking-manager' ),
			self::GUEST_PRE_ARRIVAL => __( 'Promemoria prima dell\'arrivo', 'booking-manager' ),
			self::GUEST_PAYMENT     => __( 'Pagamento ricevuto', 'booking-manager' ),
			self::GUEST_CHECKED_IN  => __( 'Benvenuto (dopo il check-in)', 'booking-manager' ),
			self::STAFF_NEW         => __( 'Struttura: nuova prenotazione online', 'booking-manager' ),
			self::STAFF_CANCELLED   => __( 'Struttura: annullata dall\'ospite', 'booking-manager' ),
		);
	}

	/** Guest messages whose texts a property can change. */
	public static function editable( string $key ): bool {
		return str_starts_with( $key, 'guest_' ) && isset( self::labels()[ $key ] );
	}

	/**
	 * Default subject and text of a message.
	 *
	 * @return array{subject: string, title: string, intro: string}
	 */
	public static function defaults( string $key ): array {
		return match ( $key ) {
			self::GUEST_RECEIVED    => array(
				'subject' => __( 'Richiesta di prenotazione ricevuta – {struttura}', 'booking-manager' ),
				'title'   => __( 'Abbiamo ricevuto la tua richiesta', 'booking-manager' ),
				'intro'   => __( "Ciao {nome},\nla tua richiesta per il soggiorno dal {arrivo} al {partenza} è arrivata. La controlliamo e ti scriviamo appena è confermata.", 'booking-manager' ),
			),
			self::GUEST_UPDATED     => array(
				'subject' => __( 'Prenotazione modificata – {struttura}', 'booking-manager' ),
				'title'   => __( 'La tua prenotazione è stata modificata', 'booking-manager' ),
				'intro'   => __( "Ciao {nome},\nquesti sono i dettagli aggiornati del tuo soggiorno.", 'booking-manager' ),
			),
			self::GUEST_CANCELLED   => array(
				'subject' => __( 'Prenotazione annullata – {struttura}', 'booking-manager' ),
				'title'   => __( 'La tua prenotazione è stata annullata', 'booking-manager' ),
				'intro'   => __( "Ciao {nome},\nla prenotazione {codice} dal {arrivo} al {partenza} è stata annullata. Speriamo di ospitarti presto.", 'booking-manager' ),
			),
			self::GUEST_PRE_ARRIVAL => array(
				'subject' => __( 'Ti aspettiamo il {arrivo} – {struttura}', 'booking-manager' ),
				'title'   => __( 'Il tuo arrivo si avvicina', 'booking-manager' ),
				'intro'   => __( "Ciao {nome},\nti aspettiamo il {arrivo}. Il check-in è dalle {checkin_dalle} alle {checkin_alle}; il giorno della partenza la camera va lasciata entro le {checkout_entro}.", 'booking-manager' ),
			),
			self::GUEST_PAYMENT     => array(
				'subject' => __( 'Pagamento ricevuto – {struttura}', 'booking-manager' ),
				'title'   => __( 'Grazie, abbiamo ricevuto il pagamento', 'booking-manager' ),
				'intro'   => __( "Ciao {nome},\nabbiamo registrato il tuo pagamento per la prenotazione {codice}. Saldo ancora da pagare: {saldo}.", 'booking-manager' ),
			),
			self::GUEST_CHECKED_IN  => array(
				'subject' => __( 'Benvenuto a {struttura}', 'booking-manager' ),
				'title'   => __( 'Benvenuto!', 'booking-manager' ),
				'intro'   => __( "Ciao {nome},\nil check-in è registrato. Per qualsiasi necessità durante il soggiorno siamo a disposizione. La partenza è il {partenza} entro le {checkout_entro}.", 'booking-manager' ),
			),
			self::STAFF_NEW         => array(
				'subject' => __( 'Nuova prenotazione online {codice}: {arrivo} – {partenza}', 'booking-manager' ),
				'title'   => __( 'Nuova prenotazione online', 'booking-manager' ),
				'intro'   => __( '{nome} ha prenotato dal sito: {arrivo} – {partenza}, {notti} notti, {ospiti}.', 'booking-manager' ),
			),
			self::STAFF_CANCELLED   => array(
				'subject' => __( 'Annullata dall\'ospite: {codice} ({arrivo})', 'booking-manager' ),
				'title'   => __( 'Prenotazione annullata dall\'ospite', 'booking-manager' ),
				'intro'   => __( '{nome} ha annullato la prenotazione {codice} dal link ricevuto: le camere sono di nuovo disponibili.', 'booking-manager' ),
			),
			default                 => array(
				'subject' => __( 'Prenotazione confermata – {struttura}', 'booking-manager' ),
				'title'   => __( 'La tua prenotazione è confermata', 'booking-manager' ),
				'intro'   => __( "Ciao {nome},\ngrazie per aver scelto {struttura}: il tuo soggiorno dal {arrivo} al {partenza} è confermato. Ti aspettiamo!", 'booking-manager' ),
			),
		};
	}

	/**
	 * Whether a message still makes sense for the booking as it is now (a "confirmed"
	 * e-mail for a booking cancelled before it left is dropped).
	 *
	 * @param array<string, mixed> $booking
	 */
	public static function still_relevant( string $key, array $booking ): bool {
		$status = (string) $booking['status'];
		return match ( $key ) {
			self::GUEST_RECEIVED                         => in_array( $status, array( BookingStatus::PENDING, BookingStatus::OPTION ), true ),
			self::GUEST_CONFIRMED, self::GUEST_UPDATED   => in_array( $status, array( BookingStatus::CONFIRMED, BookingStatus::IN_HOUSE ), true ),
			self::GUEST_PRE_ARRIVAL                      => BookingStatus::CONFIRMED === $status,
			self::GUEST_CANCELLED, self::STAFF_CANCELLED => BookingStatus::CANCELLED === $status,
			self::GUEST_CHECKED_IN                       => BookingStatus::IN_HOUSE === $status,
			default                                      => true,
		};
	}

	/**
	 * Values of a booking for the placeholders and for channels with fixed templates.
	 *
	 * @param array<string, mixed> $booking
	 * @param array<string, mixed> $property
	 * @return array<string, string>
	 */
	public static function vars( array $booking, array $property ): array {
		$guests = (int) $booking['adults'] + (int) $booking['children'];
		return array(
			'{nome}'           => trim( (string) $booking['first_name'] ),
			'{struttura}'      => (string) $property['name'],
			'{codice}'         => (string) $booking['code'],
			'{arrivo}'         => self::date( (string) $booking['arrival'] ),
			'{partenza}'       => self::date( (string) $booking['departure'] ),
			'{notti}'          => (string) (int) $booking['nights'],
			/* translators: %d: number of guests. */
			'{ospiti}'         => sprintf( _n( '%d ospite', '%d ospiti', $guests, 'booking-manager' ), $guests ),
			'{totale}'         => Money::format( (int) $booking['total'], (string) $booking['currency'] ),
			'{saldo}'          => Money::format( max( 0, (int) $booking['balance'] ), (string) $booking['currency'] ),
			'{checkin_dalle}'  => substr( (string) $property['checkin_from'], 0, 5 ),
			'{checkin_alle}'   => substr( (string) $property['checkin_to'], 0, 5 ),
			'{checkout_entro}' => substr( (string) $property['checkout_until'], 0, 5 ),
		);
	}

	/**
	 * The texts of a message for a property: its own where set, else the defaults, with
	 * the placeholders filled in.
	 *
	 * @param array<string, mixed>                     $booking
	 * @param array<string, mixed>                     $property
	 * @param array{subject?: ?string, body?: ?string} $typed texts being edited (preview), not saved
	 * @return array{subject: string, title: string, intro: string}
	 */
	public function texts( string $key, array $booking, array $property, array $typed = array() ): array {
		$texts = self::defaults( $key );
		if ( array() !== $typed ) {
			$texts['subject'] = '' !== trim( (string) ( $typed['subject'] ?? '' ) ) ? (string) $typed['subject'] : $texts['subject'];
			$texts['intro']   = '' !== trim( (string) ( $typed['body'] ?? '' ) ) ? (string) $typed['body'] : $texts['intro'];
		} elseif ( self::editable( $key ) ) {
			$own = $this->templates->find( (int) $property['id'], 'email', $key, (string) ( $booking['language'] ?: 'it' ) );
			if ( $own ) {
				$texts['subject'] = '' !== trim( (string) $own['subject'] ) ? (string) $own['subject'] : $texts['subject'];
				$texts['intro']   = '' !== trim( (string) $own['body'] ) ? (string) $own['body'] : $texts['intro'];
			}
		}
		$map = self::vars( $booking, $property );
		return array_map( static fn( string $t ) => trim( strtr( $t, $map ) ), $texts );
	}

	/**
	 * The e-mail of a booking.
	 *
	 * @param array<string, mixed>                                                 $booking
	 * @param array<string, mixed>                                                 $property
	 * @param array{qr?: string|null, manage_url?: string|null, admin_url?: string|null} $links
	 * @param array{subject?: ?string, body?: ?string}                              $typed texts being edited (preview)
	 */
	public function compose( string $key, string $audience, string $recipient, array $booking, array $property, array $links, array $typed = array(), ?EmailBrand $brand = null ): Message {
		$brand  = $brand ?? $this->brand( $property );
		$texts  = $this->texts( $key, $booking, $property, $typed );
		$staff  = 'staff' === $audience;
		$rows   = $this->rows( $key, $booking, $property, $staff );
		$inline = array();
		$cid    = null;
		if ( ! empty( $links['qr'] ) ) {
			$cid      = 'bkng-qr-' . $booking['code'];
			$inline[] = array(
				'cid'  => $cid,
				'data' => QrCode::png( (string) $links['qr'], 6 ),
				'name' => 'qr-' . $booking['code'] . '.png',
				'mime' => 'image/png',
			);
		}
		$button = null;
		if ( $staff && ! empty( $links['admin_url'] ) ) {
			$button = array(
				'url'   => (string) $links['admin_url'],
				'label' => __( 'Apri la prenotazione', 'booking-manager' ),
			);
		} elseif ( ! $staff && ! empty( $links['manage_url'] ) && self::GUEST_CANCELLED !== $key ) {
			$button = array(
				'url'   => (string) $links['manage_url'],
				'label' => __( 'Vedi o gestisci la prenotazione', 'booking-manager' ),
			);
		}
		$note = '';
		if ( self::GUEST_CANCELLED === $key && ! empty( $booking['cancel_reason'] ) && ! $staff ) {
			/* translators: %s: reason of the cancellation. */
			$note = sprintf( __( 'Motivo: %s', 'booking-manager' ), (string) $booking['cancel_reason'] );
		} elseif ( $staff && ! empty( $booking['customer_notes'] ) ) {
			/* translators: %s: the guest's notes. */
			$note = sprintf( __( 'Note dell\'ospite: %s', 'booking-manager' ), (string) $booking['customer_notes'] );
		}
		$html = EmailDesign::render(
			array(
				'title'  => $texts['title'],
				'intro'  => $texts['intro'],
				'rows'   => $rows,
				'tick'   => ! in_array( $key, array( self::GUEST_CANCELLED, self::STAFF_CANCELLED ), true ),
				'qr_cid' => $cid,
				'code'   => (string) $booking['code'],
				'button' => $button,
				'note'   => $note,
			),
			$property,
			$brand
		);
		$inline = array_merge( $inline, $brand->inline() );
		$text = $texts['title'] . "\n\n" . $texts['intro'] . "\n\n";
		foreach ( $rows as $row ) {
			$text .= $row[0] . ': ' . str_replace( "\n", ' · ', $row[1] ) . "\n";
		}
		if ( '' !== $note ) {
			$text .= "\n" . $note . "\n";
		}
		if ( $button ) {
			$text .= "\n" . $button['label'] . ': ' . $button['url'] . "\n";
		}
		$text .= "\n" . (string) $property['name'];
		return new Message( $key, $audience, $recipient, $property, $booking, $texts['subject'], $text, $html, self::vars( $booking, $property ), $inline );
	}

	/**
	 * The look of the property's e-mails; the header photo, when left automatic, is the
	 * first photo of its rooms.
	 *
	 * @param array<string, mixed> $property
	 * @param array<string, mixed> $design unsaved settings being edited (preview)
	 */
	public function brand( array $property, array $design = array() ): EmailBrand {
		$photo = 0;
		foreach ( $this->types->for_property( (int) $property['id'] ) as $type ) {
			$ids = (array) ( $type['images'] ?? array() );
			if ( $ids ) {
				$photo = (int) $ids[0];
				break;
			}
		}
		return EmailBrand::for_property( $property, $design, $photo );
	}

	/**
	 * Details of the stay shown in the e-mail (label, value, icon).
	 *
	 * @param array<string, mixed> $booking
	 * @param array<string, mixed> $property
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	private function rows( string $key, array $booking, array $property, bool $staff ): array {
		$v     = self::vars( $booking, $property );
		$rooms = empty( $booking['id'] ) ? array() : $this->bookings->rooms( (int) $booking['id'], true );
		$types = $this->types->find_many( array_column( $rooms, 'room_type_id' ) );
		$names = array();
		foreach ( $rooms as $room ) {
			$name           = isset( $types[ $room['room_type_id'] ] ) ? (string) $types[ $room['room_type_id'] ]['name'] : __( 'Camera', 'booking-manager' );
			$names[ $name ] = ( $names[ $name ] ?? 0 ) + 1;
		}
		$room_text = implode( "\n", array_map( static fn( string $n, int $c ) => ( $c > 1 ? $c . ' × ' : '' ) . $n, array_keys( $names ), $names ) );
		$rows      = array(
			/* translators: %s: check-in time. */
			array( __( 'Arrivo', 'booking-manager' ), $v['{arrivo}'] . "\n" . sprintf( __( 'check-in dalle %s', 'booking-manager' ), $v['{checkin_dalle}'] ), 'calendar' ),
			/* translators: %s: check-out time. */
			array( __( 'Partenza', 'booking-manager' ), $v['{partenza}'] . "\n" . sprintf( __( 'check-out entro le %s', 'booking-manager' ), $v['{checkout_entro}'] ), 'calendar' ),
			array( __( 'Notti', 'booking-manager' ), $v['{notti}'], 'moon' ),
			array( __( 'Ospiti', 'booking-manager' ), $v['{ospiti}'], 'users' ),
		);
		if ( '' !== $room_text ) {
			$rows[] = array( _n( 'Camera', 'Camere', max( 1, count( $rooms ) ), 'booking-manager' ), $room_text, 'bed' );
		}
		if ( $staff ) {
			$rows[] = array( __( 'Ospite', 'booking-manager' ), trim( $booking['first_name'] . ' ' . $booking['last_name'] ) . "\n" . implode( ' · ', array_filter( array( (string) $booking['email'], (string) $booking['phone'] ) ) ), 'user' );
		}
		if ( self::GUEST_CANCELLED !== $key && self::STAFF_CANCELLED !== $key ) {
			$rows[] = array( __( 'Totale', 'booking-manager' ), $v['{totale}'], 'coins' );
			if ( (int) $booking['paid'] - (int) $booking['refunded'] > 0 ) {
				$rows[] = array( __( 'Già pagato', 'booking-manager' ), Money::format( (int) $booking['paid'] - (int) $booking['refunded'], (string) $booking['currency'] ), 'card' );
				$rows[] = array( __( 'Saldo', 'booking-manager' ), $v['{saldo}'], 'coins' );
			}
		}
		$rows[]  = array( __( 'Codice prenotazione', 'booking-manager' ), $v['{codice}'], 'tag' );
		$address = trim( implode( ', ', array_filter( array( (string) ( $property['address'] ?? '' ), (string) ( $property['city'] ?? '' ) ) ) ) );
		if ( ! $staff && '' !== $address && in_array( $key, array( self::GUEST_CONFIRMED, self::GUEST_PRE_ARRIVAL ), true ) ) {
			$rows[] = array( __( 'Indirizzo', 'booking-manager' ), $address, 'pin' );
		}
		return $rows;
	}

	private static function date( string $date ): string {
		return wp_date( 'j F Y', (int) strtotime( $date . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
	}
}
