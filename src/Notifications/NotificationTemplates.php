<?php
/**
 * The messages: which ones exist, their default texts, the values they can use and how
 * an e-mail is put together from a booking.
 *
 * The texts of a guest message come from the property (bkng_templates), else from its
 * customer (0.2.5, TenantTemplates), else from the defaults here. The text can be plain
 * (one paragraph per line, as before) or limited HTML, cleaned with wp_kses (HTML_TAGS).
 * Custom messages (custom_N, 0.2.5) are free texts of a property, sent by hand.
 *
 * Placeholders: see PLACEHOLDERS; a value that is not there becomes empty.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications;

use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Bookings\CalendarFile;
use BookingManager\Core\Money;
use BookingManager\Core\QrCode;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Payments\PaymentService;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantProfile;
use BookingManager\Tenants\TenantTemplates;

defined( 'ABSPATH' ) || exit;

class NotificationTemplates {

	public const GUEST_RECEIVED   = 'guest_received';
	public const GUEST_ACCEPTED   = 'guest_accepted';
	public const GUEST_CONFIRMED  = 'guest_confirmed';
	public const GUEST_UPDATED    = 'guest_updated';
	public const GUEST_CANCELLED  = 'guest_cancelled';
	public const GUEST_PRE_ARRIVAL = 'guest_pre_arrival';
	public const GUEST_PAYMENT    = 'guest_payment';
	public const GUEST_CHECKED_IN = 'guest_checked_in';
	public const GUEST_PAYMENT_FAILED = 'guest_payment_failed';
	public const STAFF_NEW        = 'staff_new';
	public const STAFF_CANCELLED  = 'staff_cancelled';

	/** Guest e-mails that carry the stay as a calendar file (.ics), as in Beach Manager. */
	public const WITH_CALENDAR = array( self::GUEST_CONFIRMED, self::GUEST_UPDATED, self::GUEST_PRE_ARRIVAL, self::GUEST_PAYMENT );

	/** Custom messages of a property: custom_1, custom_2… */
	public const CUSTOM_PREFIX = 'custom_';

	public const PLACEHOLDERS = array(
		'{nome}', '{struttura}', '{codice}', '{arrivo}', '{partenza}', '{notti}', '{ospiti}', '{totale}', '{saldo}', '{checkin_dalle}', '{checkin_alle}', '{checkout_entro}',
		// 0.2.5: the longer names; the ones above keep working.
		'{nome_cliente}', '{cognome_cliente}', '{email_cliente}', '{nome_struttura}', '{codice_prenotazione}', '{data_prenotazione}', '{data_arrivo}', '{data_partenza}', '{numero_notti}', '{numero_ospiti}', '{importo_totale}', '{importo_saldo}', '{stato_prenotazione}', '{link_prenotazione}', '{telefono_struttura}', '{email_struttura}', '{whatsapp_struttura}', '{sito_struttura}',
	);

	/** Tags a formatted text may hold (wp_kses). */
	public const HTML_TAGS = array(
		'p'      => array(),
		'br'     => array(),
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
		'u'      => array(),
		'a'      => array(
			'href'  => true,
			'title' => true,
		),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'h2'     => array(),
		'h3'     => array(),
	);

	public function __construct(
		private TemplateRepository $templates,
		private BookingRepository $bookings,
		private RoomTypeRepository $types,
		private ?TenantTemplates $tenant_templates = null,
		private ?TenantProfile $profile = null
	) {}

	public static function is_custom( string $key ): bool {
		return 1 === preg_match( '/^' . self::CUSTOM_PREFIX . '\d{1,6}$/', $key );
	}

	/** A formatted text (HTML) rather than plain lines. */
	public static function is_html( string $text ): bool {
		return 1 === preg_match( '#<(p|br|strong|b|em|i|u|a|ul|ol|li|h2|h3)\b[^>]*>#i', $text );
	}

	/** The text as it is saved: plain stays plain, HTML keeps only HTML_TAGS. */
	public static function clean_body( string $body ): string {
		$body = str_replace( "\r\n", "\n", $body );
		// Scripts and styles go with their content, not only their tags.
		$body = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $body );
		return self::is_html( $body ) ? trim( wp_kses( $body, self::HTML_TAGS ) ) : sanitize_textarea_field( $body );
	}

	/** Plain text of a formatted text: paragraphs and items on their own lines. */
	public static function plain( string $html ): string {
		$text = preg_replace( array( '#<br\s*/?>#i', '#</(p|h2|h3|li|ul|ol)>#i', '#<li[^>]*>#i' ), array( "\n", "\n", '- ' ), $html );
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/** @return array<string, string> */
	public static function labels(): array {
		return array(
			self::GUEST_RECEIVED    => __( 'Richiesta ricevuta', 'booking-manager' ),
			self::GUEST_ACCEPTED    => __( 'Richiesta accettata: completa il pagamento', 'booking-manager' ),
			self::GUEST_CONFIRMED   => __( 'Prenotazione confermata', 'booking-manager' ),
			self::GUEST_UPDATED     => __( 'Prenotazione modificata', 'booking-manager' ),
			self::GUEST_CANCELLED   => __( 'Prenotazione annullata', 'booking-manager' ),
			self::GUEST_PRE_ARRIVAL => __( 'Promemoria prima dell\'arrivo', 'booking-manager' ),
			self::GUEST_PAYMENT     => __( 'Pagamento ricevuto', 'booking-manager' ),
			self::GUEST_CHECKED_IN  => __( 'Benvenuto (dopo il check-in)', 'booking-manager' ),
			self::GUEST_PAYMENT_FAILED => __( 'Pagamento non riuscito', 'booking-manager' ),
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
		if ( self::is_custom( $key ) ) {
			// A custom message has no default: its subject is also its title.
			return array(
				'subject' => '',
				'title'   => '',
				'intro'   => '',
			);
		}
		return match ( $key ) {
			self::GUEST_RECEIVED    => array(
				'subject' => __( 'Richiesta di prenotazione ricevuta – {struttura}', 'booking-manager' ),
				'title'   => __( 'Abbiamo ricevuto la tua richiesta', 'booking-manager' ),
				'intro'   => __( "Ciao {nome},\nla tua richiesta per il soggiorno dal {arrivo} al {partenza} è arrivata. La controlliamo e ti scriviamo appena è confermata.", 'booking-manager' ),
			),
			self::GUEST_ACCEPTED    => array(
				'subject' => __( 'Richiesta accettata: completa il pagamento – {struttura}', 'booking-manager' ),
				'title'   => __( 'Richiesta accettata: manca solo il pagamento', 'booking-manager' ),
				'intro'   => __( "Ciao {nome},
buone notizie: abbiamo accettato la tua richiesta per il soggiorno dal {arrivo} al {partenza} e le camere sono riservate per te. Completa il pagamento online per confermare la prenotazione: appena arriva ti mandiamo la conferma.", 'booking-manager' ),
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
				'intro'   => __( "Ciao {nome},\nabbiamo registrato il tuo pagamento per la prenotazione {codice}. Qui sotto trovi il totale e quanto hai già pagato.", 'booking-manager' ),
			),
			self::GUEST_PAYMENT_FAILED => array(
				'subject' => __( 'Pagamento non riuscito – {struttura}', 'booking-manager' ),
				'title'   => __( 'Il pagamento non è andato a buon fine', 'booking-manager' ),
				'intro'   => __( "Ciao {nome},\nil pagamento per la prenotazione {codice} dal {arrivo} al {partenza} non è riuscito e non ti è stato addebitato nulla. Le camere sono ancora riservate per te: puoi riprovare dal pulsante qui sotto, anche con un'altra carta.", 'booking-manager' ),
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
			self::GUEST_ACCEPTED, self::GUEST_PAYMENT_FAILED => BookingStatus::OPTION === $status,
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
	public static function vars( array $booking, array $property, array $extra = array() ): array {
		$guests = (int) $booking['adults'] + (int) $booking['children'];
		$v      = array(
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
		$created = (string) ( $booking['created_at'] ?? '' );
		return $v + array(
			'{nome_cliente}'        => $v['{nome}'],
			'{cognome_cliente}'     => trim( (string) ( $booking['last_name'] ?? '' ) ),
			'{email_cliente}'       => (string) ( $booking['email'] ?? '' ),
			'{nome_struttura}'      => $v['{struttura}'],
			'{codice_prenotazione}' => $v['{codice}'],
			'{data_prenotazione}'   => '' === $created ? '' : wp_date( 'j F Y', (int) strtotime( $created . ' UTC' ), new \DateTimeZone( (string) ( $property['timezone'] ?? 'UTC' ) ) ),
			'{data_arrivo}'         => $v['{arrivo}'],
			'{data_partenza}'       => $v['{partenza}'],
			'{numero_notti}'        => $v['{notti}'],
			'{numero_ospiti}'       => $v['{ospiti}'],
			'{importo_totale}'      => $v['{totale}'],
			'{importo_saldo}'       => $v['{saldo}'],
			'{stato_prenotazione}'  => BookingStatus::label( (string) ( $booking['status'] ?? '' ) ),
			'{link_prenotazione}'   => (string) ( $extra['link'] ?? '' ),
			'{telefono_struttura}'  => (string) ( $extra['phone'] ?? $property['phone'] ?? '' ),
			'{email_struttura}'     => (string) ( $extra['email'] ?? $property['email'] ?? '' ),
			'{whatsapp_struttura}'  => (string) ( $extra['whatsapp'] ?? '' ),
			'{sito_struttura}'      => (string) ( $extra['website'] ?? $property['website'] ?? '' ),
		);
	}

	/**
	 * The values of a booking with the public contacts (property, else its customer) and
	 * the link of the booking.
	 *
	 * @param array<string, mixed> $booking
	 * @param array<string, mixed> $property
	 * @return array<string, string>
	 */
	public function values( array $booking, array $property, ?string $link = null ): array {
		$extra = array( 'link' => (string) $link );
		if ( $this->profile ) {
			$public = $this->profile->public_for( $property );
			$extra += array(
				'phone'    => (string) ( $public['phone'] ?? '' ),
				'email'    => (string) ( $public['email'] ?? '' ),
				'website'  => (string) ( $public['website'] ?? '' ),
				// Shown with its "+", as people write it.
				'whatsapp' => empty( $public['whatsapp'] ) ? '' : '+' . ltrim( (string) $public['whatsapp'], '+' ),
			);
		}
		return self::vars( $booking, $property, $extra );
	}

	/**
	 * The saved texts of a message, before the placeholders: the property's, else its
	 * customer's, else none.
	 *
	 * @return array{subject: string, body: string, level: string}|null
	 */
	public function own( string $key, int $property_id, string $language ): ?array {
		$tenant = null;
		if ( self::editable( $key ) && $this->tenant_templates && Tenancy::ready() ) {
			$tenant = $this->tenant_templates->find( Tenancy::of_property( $property_id ), $key, $language );
		}
		$row = self::editable( $key ) || self::is_custom( $key ) ? $this->templates->find( $property_id, 'email', $key, $language ) : null;
		if ( $row ) {
			// Field by field: a subject left empty by the property is the customer's.
			$pick = static fn( string $own, ?string $fallback ): string => '' !== trim( $own ) ? $own : (string) $fallback;
			return array(
				'subject' => $pick( (string) $row['subject'], $tenant['subject'] ?? null ),
				'body'    => $pick( (string) $row['body'], $tenant['body'] ?? null ),
				'level'   => 'property',
			);
		}
		return $tenant ? $tenant + array( 'level' => 'tenant' ) : null;
	}

	/**
	 * The texts of a message for a property: its own where set, else its customer's, else
	 * the defaults, with the placeholders filled in. A formatted text comes back as HTML
	 * (html = true) with the values escaped and only HTML_TAGS left.
	 *
	 * @param array<string, mixed>                     $booking
	 * @param array<string, mixed>                     $property
	 * @param array{subject?: ?string, body?: ?string} $typed texts being edited (preview), not saved
	 * @return array{subject: string, title: string, intro: string, html: bool}
	 */
	public function texts( string $key, array $booking, array $property, array $typed = array(), ?string $link = null ): array {
		$texts = self::defaults( $key );
		$own   = array() !== $typed ? $typed : $this->own( $key, (int) $property['id'], (string) ( ( $booking['language'] ?? '' ) ?: 'it' ) );
		if ( $own ) {
			$texts['subject'] = '' !== trim( (string) ( $own['subject'] ?? '' ) ) ? (string) $own['subject'] : $texts['subject'];
			$texts['intro']   = '' !== trim( (string) ( $own['body'] ?? '' ) ) ? (string) $own['body'] : $texts['intro'];
		}
		if ( self::is_custom( $key ) ) {
			$texts['title'] = $texts['subject'];
		}
		$map  = $this->values( $booking, $property, $link );
		$html = self::is_html( $texts['intro'] );
		$out  = array(
			'subject' => trim( strtr( $texts['subject'], $map ) ),
			'title'   => trim( strtr( $texts['title'], $map ) ),
			'intro'   => $html
				? trim( wp_kses( strtr( $texts['intro'], array_map( 'esc_html', $map ) ), self::HTML_TAGS ) )
				: trim( strtr( $texts['intro'], $map ) ),
			'html'    => $html,
		);
		return $out;
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
		$texts  = $this->texts( $key, $booking, $property, $typed, $links['manage_url'] ?? null );
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
		} elseif ( ! $staff && ! empty( $links['manage_url'] ) && self::GUEST_PAYMENT_FAILED === $key ) {
			$button = array(
				'url'   => (string) $links['manage_url'],
				'label' => __( 'Riprova il pagamento', 'booking-manager' ),
			);
		} elseif ( ! $staff && ! empty( $links['manage_url'] ) && self::GUEST_ACCEPTED === $key ) {
			$button = array(
				'url'   => (string) $links['manage_url'],
				'label' => __( 'Completa il pagamento', 'booking-manager' ),
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
		} elseif ( self::GUEST_ACCEPTED === $key && ! empty( $booking['option_expires_at'] ) ) {
			$until = wp_date( 'j F Y, H:i', (int) strtotime( $booking['option_expires_at'] . ' UTC' ), new \DateTimeZone( (string) ( $property['timezone'] ?? 'UTC' ) ) );
			/* translators: %s: date and time. */
			$note = sprintf( __( 'Le camere restano riservate fino al %s: se il pagamento non arriva entro allora la richiesta viene annullata.', 'booking-manager' ), $until );
		} elseif ( $staff && ! empty( $booking['customer_notes'] ) ) {
			/* translators: %s: the guest's notes. */
			$note = sprintf( __( 'Note dell\'ospite: %s', 'booking-manager' ), (string) $booking['customer_notes'] );
		}
		$html = EmailDesign::render(
			array(
				'title'  => $texts['title'],
				'intro'  => $texts['intro'],
				'html'   => $texts['html'],
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
		$text = $texts['title'] . "\n\n" . ( $texts['html'] ? self::plain( $texts['intro'] ) : $texts['intro'] ) . "\n\n";
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
		$files = array();
		if ( ! $staff && in_array( $key, self::WITH_CALENDAR, true ) && self::in_calendar( $booking ) ) {
			$files[] = CalendarFile::for_booking( $booking, $property, time() );
		}
		return new Message( $key, $audience, $recipient, $property, $booking, $texts['subject'], $text, $html, $this->values( $booking, $property, $links['manage_url'] ?? null ), $inline, $files );
	}

	/** A stay worth a calendar event: confirmed (or under way) with its dates. */
	public static function in_calendar( array $booking ): bool {
		return in_array( (string) ( $booking['status'] ?? '' ), array( BookingStatus::CONFIRMED, BookingStatus::IN_HOUSE ), true )
			&& ! empty( $booking['arrival'] ) && ! empty( $booking['departure'] );
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
		$v         = self::vars( $booking, $property );
		$rooms     = empty( $booking['id'] ) ? array() : $this->bookings->rooms( (int) $booking['id'], true );
		$room_text = $this->rooms_text( $booking, "\n" );
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
			$now    = in_array( $key, array( self::GUEST_ACCEPTED, self::GUEST_PAYMENT_FAILED ), true ) ? PaymentService::online_amount( $booking, (string) ( $property['settings']['online_payment'] ?? 'full' ) ) : 0;
			if ( $now > 0 && $now < (int) $booking['balance'] ) {
				// A deposit: what is paid now, the rest as the property says.
				$rows[] = array( __( 'Da pagare ora (acconto)', 'booking-manager' ), Money::format( $now, (string) $booking['currency'] ), 'card' );
			}
			if ( (int) $booking['paid'] - (int) $booking['refunded'] > 0 ) {
				$rows[] = array( __( 'Già pagato', 'booking-manager' ), Money::format( (int) $booking['paid'] - (int) $booking['refunded'], (string) $booking['currency'] ), 'card' );
				if ( (int) $booking['balance'] > 0 ) {
					$rows[] = array( __( 'Da pagare', 'booking-manager' ), $v['{saldo}'], 'coins' );
				}
			}
		}
		$rows[]  = array( __( 'Codice prenotazione', 'booking-manager' ), $v['{codice}'], 'tag' );
		$address = trim( implode( ', ', array_filter( array( (string) ( $property['address'] ?? '' ), (string) ( $property['city'] ?? '' ) ) ) ) );
		if ( ! $staff && '' !== $address && in_array( $key, array( self::GUEST_CONFIRMED, self::GUEST_PRE_ARRIVAL ), true ) ) {
			$rows[] = array( __( 'Indirizzo', 'booking-manager' ), $address, 'pin' );
		}
		return $rows;
	}

	/**
	 * The rooms of a booking by type: «2 × Matrimoniale» and the others, one per $glue.
	 *
	 * @param array<string, mixed> $booking
	 */
	public function rooms_text( array $booking, string $glue = ', ' ): string {
		$rooms = empty( $booking['id'] ) ? array() : $this->bookings->rooms( (int) $booking['id'], true );
		$types = $this->types->find_many( array_column( $rooms, 'room_type_id' ) );
		$names = array();
		foreach ( $rooms as $room ) {
			$name           = isset( $types[ $room['room_type_id'] ] ) ? (string) $types[ $room['room_type_id'] ]['name'] : __( 'Camera', 'booking-manager' );
			$names[ $name ] = ( $names[ $name ] ?? 0 ) + 1;
		}
		return implode( $glue, array_map( static fn( string $n, int $c ) => ( $c > 1 ? $c . ' × ' : '' ) . $n, array_keys( $names ), $names ) );
	}

	private static function date( string $date ): string {
		return wp_date( 'j F Y', (int) strtotime( $date . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
	}
}
