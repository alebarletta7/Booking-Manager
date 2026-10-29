<?php
/**
 * Messages of bookings: an outbox filled after COMMIT by the booking hooks and emptied
 * by WP-Cron (every minute) and at the end of the request that queued them.
 *
 * - A message is a row in bkng_notifications per channel; the booking is never touched
 *   by a sending failure.
 * - The text is built right before sending from the booking as it is then; a message
 *   that no longer makes sense (a "confirmed" e-mail for a booking cancelled meanwhile)
 *   is skipped.
 * - Passing errors are retried after 1, 5, 15 and 60 minutes (5 attempts), then the row
 *   is "failed" with the error, visible in the log, and can be sent again by hand.
 * - A channel that cannot send (WhatsApp not connected) records "skipped" with the
 *   reason: never "sent".
 * - The log keeps the recipient and the outcome, never the text of the message.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications;

use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Bookings\ManageLink;
use BookingManager\Checkin\QrToken;
use BookingManager\Core\Clock;
use BookingManager\Core\Dates;
use BookingManager\Core\Hooks;
use BookingManager\Licensing\LicenseGate;
use BookingManager\Notifications\Channels\EmailChannel;
use BookingManager\Notifications\Channels\NotificationChannel;
use BookingManager\Notifications\Channels\WhatsAppChannel;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class NotificationService {

	public const MAX_ATTEMPTS = 5;
	private const BATCH       = 30;
	private const BATCH_TIME  = 40;
	private const INLINE      = 5;

	/** Guest messages that carry the check-in QR (confirmed bookings only). */
	private const WITH_QR = array( NotificationTemplates::GUEST_CONFIRMED, NotificationTemplates::GUEST_UPDATED, NotificationTemplates::GUEST_PRE_ARRIVAL );

	/** @var int[] rows queued by this request, sent at shutdown */
	private array $pending = array();

	/** @var array<string, NotificationChannel>|null */
	private ?array $channels = null;

	public function __construct(
		private NotificationRepository $repo,
		private NotificationTemplates $templates,
		private BookingRepository $bookings,
		private PropertyRepository $properties,
		private EmailChannel $email,
		private WhatsAppChannel $whatsapp,
		private QrToken $qr,
		private ManageLink $manage,
		private LicenseGate $license,
		private Clock $clock
	) {}

	public function hooks(): void {
		add_action( Hooks::BOOKING_CREATED, array( $this, 'on_created' ), 10, 2 );
		add_action( Hooks::BOOKING_CONFIRMED, array( $this, 'on_confirmed' ), 10, 2 );
		add_action( Hooks::BOOKING_UPDATED, array( $this, 'on_updated' ), 10, 2 );
		add_action( Hooks::BOOKING_CANCELLED, array( $this, 'on_cancelled' ), 10, 2 );
		add_action( Hooks::BOOKING_CHECKED_IN, array( $this, 'on_checked_in' ), 10, 2 );
		add_action( Hooks::PAYMENT_RECORDED, array( $this, 'on_payment' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Channels by key: e-mail, WhatsApp and any added with bkng_notification_channels.
	 *
	 * @return array<string, NotificationChannel>
	 */
	public function channels(): array {
		if ( null === $this->channels ) {
			/**
			 * Adds notification channels (objects implementing NotificationChannel).
			 *
			 * @param NotificationChannel[] $channels
			 */
			$list           = (array) apply_filters( 'bkng_notification_channels', array( $this->email, $this->whatsapp ) );
			$this->channels = array();
			foreach ( $list as $channel ) {
				if ( $channel instanceof NotificationChannel ) {
					$this->channels[ $channel->key() ] = $channel;
				}
			}
		}
		return $this->channels;
	}

	/* ------------------------------------------------------------------ Booking events */

	/** @param array<string, mixed> $ctx */
	public function on_created( int $booking_id, array $ctx ): void {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return;
		}
		if ( 'public_form' === $booking['source'] ) {
			$this->queue_staff( NotificationTemplates::STAFF_NEW, $booking );
		}
		// An online option waiting for its payment is on the payment page: the e-mail comes
		// with the confirmation.
		$awaiting_payment = BookingStatus::OPTION === $booking['status'] && 'public_form' === $booking['source'];
		if ( ! empty( $ctx['notify'] ) && ! $awaiting_payment && in_array( $booking['status'], array( BookingStatus::PENDING, BookingStatus::OPTION ), true ) ) {
			$this->queue_guest( NotificationTemplates::GUEST_RECEIVED, $booking );
		}
	}

	/** @param array<string, mixed> $ctx */
	public function on_confirmed( int $booking_id, array $ctx ): void {
		$booking = $this->bookings->find( $booking_id );
		// Not when a no-show marked by mistake is restored or a check-in undone.
		if ( $booking && ! empty( $ctx['notify'] ) && ! in_array( $ctx['previous_status'] ?? '', array( BookingStatus::NO_SHOW, BookingStatus::IN_HOUSE ), true ) ) {
			$this->queue_guest( NotificationTemplates::GUEST_CONFIRMED, $booking );
		}
	}

	/** @param array<string, mixed> $ctx */
	public function on_updated( int $booking_id, array $ctx ): void {
		if ( empty( $ctx['notify'] ) ) {
			return;
		}
		if ( 'accepted' === ( $ctx['change'] ?? '' ) ) {
			// Request accepted, the guest pays online: the confirmation comes with the payment.
			$booking = $this->bookings->find( $booking_id );
			if ( $booking && BookingStatus::OPTION === $booking['status'] ) {
				$this->queue_guest( NotificationTemplates::GUEST_ACCEPTED, $booking );
			}
			return;
		}
		if ( 'stay' !== ( $ctx['change'] ?? '' ) ) {
			return;
		}
		$booking = $this->bookings->find( $booking_id );
		if ( $booking && BookingStatus::CONFIRMED === $booking['status'] ) {
			$this->queue_guest( NotificationTemplates::GUEST_UPDATED, $booking );
		}
	}

	/** @param array<string, mixed> $ctx */
	public function on_cancelled( int $booking_id, array $ctx ): void {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return;
		}
		$by_guest = 'guest' === ( $ctx['actor'] ?? null );
		// The guest cancelled from the link and saw it on the page: the e-mail is a record.
		if ( ! empty( $ctx['notify'] ) ) {
			$this->queue_guest( NotificationTemplates::GUEST_CANCELLED, $booking );
		}
		if ( $by_guest ) {
			$this->queue_staff( NotificationTemplates::STAFF_CANCELLED, $booking );
		}
	}

	/** @param array<string, mixed> $ctx */
	public function on_checked_in( int $booking_id, array $ctx ): void {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking || empty( $ctx['notify'] ) ) {
			return;
		}
		$property = $this->properties->find( (int) $booking['property_id'] );
		if ( $property && ! empty( $property['settings']['send_checkin_confirmation'] ) ) {
			$this->queue_guest( NotificationTemplates::GUEST_CHECKED_IN, $booking );
		}
	}

	/**
	 * Receipt of an online payment. Not for desk payments (the guest is there), nor for
	 * the payment that confirms an option (the confirmation e-mail says it).
	 *
	 * @param array<string, mixed> $ctx
	 */
	public function on_payment( int $booking_id, array $ctx ): void {
		if ( 'manual' === ( $ctx['provider'] ?? 'manual' ) ) {
			return;
		}
		$booking = $this->bookings->find( $booking_id );
		if ( $booking && in_array( $booking['status'], array( BookingStatus::CONFIRMED, BookingStatus::IN_HOUSE ), true ) ) {
			$this->queue_guest( NotificationTemplates::GUEST_PAYMENT, $booking, 'pay' . (int) ( $ctx['payment_id'] ?? 0 ) );
		}
	}

	/**
	 * Hourly: the message before the arrival, for confirmed bookings arriving within the
	 * property's reminder_days. A booking gets one per arrival date (dedupe key).
	 */
	public function queue_reminders(): int {
		$queued = 0;
		foreach ( $this->properties->all( null, false ) as $property ) {
			$days = (int) $property['settings']['reminder_days'];
			if ( empty( $property['settings']['send_pre_arrival'] ) || $days <= 0 ) {
				continue;
			}
			$today = $this->clock->today_in( (string) $property['timezone'] );
			foreach ( $this->bookings->arriving( (int) $property['id'], Dates::add_days( $today, 1 ), Dates::add_days( $today, $days ), BookingStatus::CONFIRMED ) as $booking ) {
				// Booked a moment ago for the next days: the confirmation is enough.
				if ( strtotime( (string) $booking['created_at'] . ' UTC' ) > $this->clock->now_utc()->getTimestamp() - DAY_IN_SECONDS ) {
					continue;
				}
				$queued += $this->queue_guest( NotificationTemplates::GUEST_PRE_ARRIVAL, $booking, 'arr' . $booking['arrival'] );
			}
		}
		return $queued;
	}

	/* ------------------------------------------------------------------ Queueing */

	/**
	 * Queues a guest message on every channel the property uses.
	 *
	 * @param array<string, mixed> $booking
	 * @return int rows queued
	 */
	public function queue_guest( string $key, array $booking, string $version = '' ): int {
		$property = $this->properties->find( (int) $booking['property_id'] );
		if ( ! $property ) {
			return 0;
		}
		$queued = 0;
		foreach ( $this->channels() as $channel ) {
			if ( 'email' === $channel->key() && empty( $property['settings']['email_enabled'] ) ) {
				continue;
			}
			// WhatsApp: the site's number (WhatsApp → Collegamento), switched on, this message active.
			if ( 'whatsapp' === $channel->key() && ! $this->whatsapp->sends( $key ) ) {
				continue;
			}
			$recipient = $channel->recipient( $booking );
			if ( '' === $recipient ) {
				continue;
			}
			$queued += $this->queue( $channel->key(), $key, 'guest', $recipient, $booking, $version ) ? 1 : 0;
		}
		return $queued;
	}

	/** @param array<string, mixed> $booking */
	public function queue_staff( string $key, array $booking ): bool {
		$property = $this->properties->find( (int) $booking['property_id'] );
		if ( ! $property || empty( $property['settings']['notify_staff'] ) || empty( $property['settings']['email_enabled'] ) ) {
			return false;
		}
		$to = trim( (string) $property['settings']['notify_email'] );
		$to = '' === $to ? (string) $property['email'] : $to;
		return is_email( $to ) && $this->queue( 'email', $key, 'staff', $to, $booking, '' );
	}

	/** @param array<string, mixed> $booking */
	private function queue( string $channel, string $key, string $audience, string $recipient, array $booking, string $version ): bool {
		$version = '' === $version ? 'v' . (int) $booking['version'] : $version;
		$id      = $this->repo->insert(
			array(
				'property_id'  => (int) $booking['property_id'],
				'booking_id'   => (int) $booking['id'],
				'channel'      => $channel,
				'template_key' => $key,
				'audience'     => $audience,
				'recipient'    => mb_substr( $recipient, 0, 190 ),
				'dedupe_key'   => substr( $key . ':' . $channel . ':' . $booking['id'] . ':' . $version, 0, 120 ),
			)
		);
		if ( $id > 0 ) {
			$this->pending[] = $id;
		}
		return $id > 0;
	}

	/* ------------------------------------------------------------------ Sending */

	/** End of request: send what this request queued (WP-Cron sends the rest). */
	public function flush(): void {
		$ids           = array_slice( array_unique( $this->pending ), 0, self::INLINE );
		$this->pending = array();
		if ( ! $ids ) {
			return;
		}
		// The answer is already out: the guest's browser does not wait for the mail server.
		if ( doing_action( 'shutdown' ) && function_exists( 'fastcgi_finish_request' ) && ! headers_sent() ) {
			fastcgi_finish_request();
		}
		foreach ( $ids as $id ) {
			$this->send( $id );
		}
	}

	/**
	 * WP-Cron: rows left half-sent by a dead request are closed, then the due ones go.
	 *
	 * @return array<string, int> per resulting status
	 */
	public function run( int $limit = self::BATCH ): array {
		$done = array_fill_keys( NotificationRepository::STATUSES, 0 );
		$now  = $this->clock->now_mysql();
		$this->repo->fail_stale( $now, __( 'Invio interrotto: il messaggio potrebbe essere partito. Controlla prima di reinviarlo.', 'booking-manager' ) );
		$start = time();
		foreach ( $this->repo->due( $now, max( 1, $limit ) ) as $id ) {
			if ( time() - $start > self::BATCH_TIME ) {
				break;
			}
			++$done[ $this->send( $id ) ];
		}
		return $done;
	}

	/**
	 * Sends one queued row.
	 *
	 * @param bool $manual Sent again by hand: not dropped because the booking changed.
	 * @return string resulting status
	 */
	public function send( int $id, bool $manual = false ): string {
		$now = $this->clock->now_mysql();
		if ( ! $this->repo->claim( $id, $now ) ) {
			return (string) ( $this->repo->find( $id )['status'] ?? NotificationRepository::FAILED );
		}
		$row      = (array) $this->repo->find( $id );
		$booking  = empty( $row['booking_id'] ) ? null : $this->bookings->find( (int) $row['booking_id'] );
		$property = $this->properties->find( (int) $row['property_id'] );
		$channel  = $this->channels()[ (string) $row['channel'] ] ?? null;

		$result = null;
		if ( ! $booking || ! $property ) {
			$result = SendResult::skipped( __( 'Prenotazione o struttura non più presente.', 'booking-manager' ) );
		} elseif ( '' === (string) $row['recipient'] ) {
			$result = SendResult::skipped( __( 'Destinatario rimosso (dati dell\'ospite anonimizzati).', 'booking-manager' ) );
		} elseif ( ! $manual && ! NotificationTemplates::still_relevant( (string) $row['template_key'], $booking ) ) {
			$result = SendResult::skipped( __( 'Non più attuale: la prenotazione è cambiata prima dell\'invio.', 'booking-manager' ) );
		} elseif ( ! $channel ) {
			$result = SendResult::failed( __( 'Canale non disponibile.', 'booking-manager' ) );
		} elseif ( ! $this->license->allows( LicenseGate::OP_NOTIFICATIONS ) ) {
			$result = SendResult::skipped( __( 'Notifiche sospese dalla licenza.', 'booking-manager' ) );
		} else {
			$reason = $channel->unavailable( $property );
			if ( null !== $reason ) {
				$result = SendResult::skipped( $reason );
			}
		}
		if ( null === $result ) {
			try {
				$result = $channel->send( $this->compose( $row, $booking, $property ) );
			} catch ( \Throwable $e ) {
				$result = SendResult::retry( mb_substr( $e->getMessage(), 0, 400 ) );
			}
		}
		return $this->record( $row, $result );
	}

	/**
	 * A failed, skipped or sent row sent again by a person: a new row (the old one stays
	 * in the log), sent at once, to the guest's address as it is now.
	 *
	 * @return array<string, mixed>|\WP_Error the new row
	 */
	public function resend( int $id ): array|\WP_Error {
		$row = $this->repo->find( $id );
		if ( ! $row ) {
			return new \WP_Error( 'bkng_not_found', __( 'Notifica non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		if ( in_array( $row['status'], array( NotificationRepository::QUEUED, NotificationRepository::SENDING ), true ) ) {
			return new \WP_Error( 'bkng_in_progress', __( 'Questa notifica è già in coda.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$booking   = empty( $row['booking_id'] ) ? null : $this->bookings->find( (int) $row['booking_id'] );
		$channel   = $this->channels()[ (string) $row['channel'] ] ?? null;
		$recipient = 'staff' === $row['audience'] ? (string) $row['recipient'] : ( $booking && $channel ? $channel->recipient( $booking ) : '' );
		if ( ! $booking || '' === $recipient ) {
			return new \WP_Error( 'bkng_no_recipient', __( 'Non c\'è più un destinatario per questa notifica.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$new = $this->repo->insert(
			array(
				'property_id'  => (int) $row['property_id'],
				'booking_id'   => (int) $row['booking_id'],
				'channel'      => (string) $row['channel'],
				'template_key' => (string) $row['template_key'],
				'audience'     => (string) $row['audience'],
				'recipient'    => mb_substr( $recipient, 0, 190 ),
				'dedupe_key'   => null,
			)
		);
		if ( ! $new ) {
			return new \WP_Error( 'bkng_db_error', __( 'Non è stato possibile mettere in coda la notifica.', 'booking-manager' ), array( 'status' => 500 ) );
		}
		$this->send( $new, true );
		return $this->payload( (array) $this->repo->find( $new ) );
	}

	/**
	 * A guest message of a booking sent now by the staff (e.g. the confirmation again).
	 *
	 * @return array<string, mixed>|\WP_Error the new row
	 */
	public function send_now( int $booking_id, string $key ): array|\WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking || ! NotificationTemplates::editable( $key ) ) {
			return new \WP_Error( 'bkng_invalid', __( 'Prenotazione o messaggio non validi.', 'booking-manager' ), array( 'status' => 400 ) );
		}
		if ( ! NotificationTemplates::still_relevant( $key, $booking ) ) {
			return new \WP_Error( 'bkng_not_relevant', __( 'Questo messaggio non corrisponde allo stato attuale della prenotazione.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$recipient = $this->email->recipient( $booking );
		if ( '' === $recipient ) {
			return new \WP_Error( 'bkng_no_recipient', __( 'L\'ospite non ha un indirizzo e-mail valido.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$id = $this->repo->insert(
			array(
				'property_id'  => (int) $booking['property_id'],
				'booking_id'   => $booking_id,
				'channel'      => 'email',
				'template_key' => $key,
				'audience'     => 'guest',
				'recipient'    => mb_substr( $recipient, 0, 190 ),
				'dedupe_key'   => null,
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'bkng_db_error', __( 'Non è stato possibile mettere in coda la notifica.', 'booking-manager' ), array( 'status' => 500 ) );
		}
		$this->send( $id, true );
		return $this->payload( (array) $this->repo->find( $id ) );
	}

	/**
	 * Test e-mail of a property, sent at once (not queued, not logged).
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function send_test_email( int $property_id, string $to ): array {
		$property = $this->properties->find( $property_id );
		if ( ! $property || ! is_email( $to ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Indirizzo o struttura non validi.', 'booking-manager' ),
			);
		}
		$brand   = $this->templates->brand( $property );
		$text    = __( 'Questa è una e-mail di prova di Booking Manager. Se la leggi, le e-mail della struttura partono correttamente.', 'booking-manager' );
		$message = new Message(
			'test',
			'staff',
			$to,
			$property,
			array(),
			/* translators: %s: property name. */
			sprintf( __( 'Prova e-mail – %s', 'booking-manager' ), (string) $property['name'] ),
			$text,
			EmailDesign::render(
				array(
					'title' => __( 'E-mail di prova', 'booking-manager' ),
					'intro' => $text,
					'rows'  => array(),
					'tick'  => true,
				),
				$property,
				$brand
			),
			array(),
			$brand->inline()
		);
		$result = $this->email->send( $message );
		return array(
			'ok'    => SendResult::SENT === $result->outcome,
			'error' => $result->error,
		);
	}

	/**
	 * Preview of a guest e-mail with the texts being edited (not saved), built on a real
	 * booking of the property when there is one, or on example values never saved.
	 *
	 * @param array<string, mixed> $design look of the e-mails being edited (Email → Aspetto), not saved
	 * @return array{subject: string, html: string, defaults: array<string, string>, example: bool}|\WP_Error
	 */
	public function preview( int $property_id, string $key, ?string $subject = null, ?string $body = null, array $design = array() ): array|\WP_Error {
		$property = $this->properties->find( $property_id );
		if ( ! $property || ! NotificationTemplates::editable( $key ) ) {
			return new \WP_Error( 'bkng_invalid', __( 'Struttura o messaggio non validi.', 'booking-manager' ), array( 'status' => 400 ) );
		}
		$found   = $this->bookings->arriving( $property_id, $this->clock->today_in( (string) $property['timezone'] ), Dates::add_days( $this->clock->today_in( (string) $property['timezone'] ), 365 ), BookingStatus::CONFIRMED );
		$example = array() === $found;
		$booking = $example ? self::example_booking( $property ) : $found[0];
		$typed   = null === $subject && null === $body ? array() : array(
			'subject' => $subject,
			'body'    => $body,
		);
		$brand   = $this->templates->brand( $property, $design );
		$message = $this->templates->compose( $key, 'guest', (string) ( $property['email'] ?? '' ), $booking, $property, array( 'manage_url' => home_url( '/' ) ), $typed, $brand );
		$defaults = NotificationTemplates::defaults( $key );
		return array(
			'subject'  => $message->subject,
			// Embedded images as data: addresses, so the browser shows them.
			'html'     => $brand->for_browser( $message->html ),
			'defaults' => array(
				'subject' => $defaults['subject'],
				'body'    => $defaults['intro'],
			),
			'example'  => $example,
		);
	}

	/** Wait before the next try: 1, 5, 15, 60 minutes. */
	public static function retry_after( int $attempts ): int {
		$minutes = array( 1, 5, 15, 60 );
		return $minutes[ max( 0, min( count( $minutes ) - 1, $attempts - 1 ) ) ] * MINUTE_IN_SECONDS;
	}

	/**
	 * The log row as the API returns it.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function payload( array $row ): array {
		$labels = NotificationTemplates::labels();
		return array(
			'id'             => (int) $row['id'],
			'property_id'    => (int) $row['property_id'],
			'booking_id'     => null === $row['booking_id'] ? null : (int) $row['booking_id'],
			'booking_code'   => null === $row['booking_id'] ? null : ( $this->bookings->find( (int) $row['booking_id'] )['code'] ?? null ),
			'channel'        => (string) $row['channel'],
			'template'       => (string) $row['template_key'],
			'template_label' => $labels[ $row['template_key'] ] ?? (string) $row['template_key'],
			'audience'       => (string) $row['audience'],
			'recipient'      => (string) $row['recipient'],
			'status'         => (string) $row['status'],
			'attempts'       => (int) $row['attempts'],
			'last_error'     => $row['last_error'],
			'next_attempt'   => NotificationRepository::QUEUED === $row['status'] ? Clock::iso( (string) $row['next_attempt_at'] ) : null,
			'sent_at'        => Clock::iso( $row['sent_at'] ),
			'delivery'       => $row['delivery_status'] ?? null,
			'delivery_at'    => Clock::iso( $row['delivery_at'] ?? null ),
			'created_at'     => Clock::iso( (string) $row['created_at'] ),
		);
	}

	/** Deletes finished rows older than $months months (0 = keep). */
	public function purge( int $months ): int {
		if ( $months <= 0 ) {
			return 0;
		}
		return $this->repo->purge_before( gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months', $this->clock->now_utc()->getTimestamp() ) ) );
	}

	/* ------------------------------------------------------------------ Internals */

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $booking
	 * @param array<string, mixed> $property
	 */
	private function compose( array $row, array $booking, array $property ): Message {
		$key = (string) $row['template_key'];
		if ( 'whatsapp' === $row['channel'] ) {
			// WhatsApp fills its approved text itself, from the booking.
			return new Message( $key, (string) $row['audience'], (string) $row['recipient'], $property, $booking, '', '', '' );
		}
		$with_qr = 'guest' === $row['audience']
			&& 'email' === $row['channel']
			&& in_array( $key, self::WITH_QR, true )
			&& BookingStatus::CONFIRMED === $booking['status']
			&& ! empty( $booking['qr_id'] )
			&& ! empty( $property['settings']['qr_in_email'] );
		return $this->templates->compose(
			$key,
			(string) $row['audience'],
			(string) $row['recipient'],
			$booking,
			$property,
			array(
				'qr'         => $with_qr ? $this->qr->payload( (string) $booking['qr_id'] ) : null,
				'manage_url' => empty( $booking['manage_token_hash'] ) ? null : $this->manage->url( $booking ),
				'admin_url'  => admin_url( 'admin.php?page=booking-manager#/bookings/' . (int) $booking['id'] ),
			)
		);
	}

	/** @param array<string, mixed> $row */
	private function record( array $row, SendResult $result ): string {
		$id      = (int) $row['id'];
		$now     = $this->clock->now_mysql();
		$context = array(
			'booking_id' => (int) $row['booking_id'],
			'channel'    => (string) $row['channel'],
			'template'   => (string) $row['template_key'],
		);
		if ( SendResult::SENT === $result->outcome ) {
			$this->repo->update(
				$id,
				array(
					'status'              => NotificationRepository::SENT,
					'provider'            => (string) $row['channel'],
					'provider_message_id' => '' === $result->id ? null : mb_substr( $result->id, 0, 190 ),
					'delivery_status'     => 'whatsapp' === $row['channel'] ? 'sent' : null,
					'delivery_at'         => 'whatsapp' === $row['channel'] ? $now : null,
					'last_error'          => null,
					'sent_at'             => $now,
					'updated_at'          => $now,
				)
			);
			Hooks::dispatch( Hooks::NOTIFICATION_SENT, $id, $context );
			return NotificationRepository::SENT;
		}
		if ( SendResult::SKIPPED === $result->outcome ) {
			$this->repo->update(
				$id,
				array(
					'status'     => NotificationRepository::SKIPPED,
					'last_error' => mb_substr( $result->error, 0, 500 ),
					'updated_at' => $now,
				)
			);
			return NotificationRepository::SKIPPED;
		}
		$final = SendResult::RETRY !== $result->outcome || (int) $row['attempts'] >= self::MAX_ATTEMPTS;
		$this->repo->update(
			$id,
			array(
				'status'          => $final ? NotificationRepository::FAILED : NotificationRepository::QUEUED,
				'last_error'      => mb_substr( $result->error, 0, 500 ),
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', (int) strtotime( $now . ' UTC' ) + self::retry_after( (int) $row['attempts'] ) ),
				'updated_at'      => $now,
			)
		);
		if ( $final ) {
			Hooks::dispatch( Hooks::NOTIFICATION_FAILED, $id, $context + array( 'error' => $result->error ) );
		}
		return $final ? NotificationRepository::FAILED : NotificationRepository::QUEUED;
	}

	/**
	 * Example values for a preview when the property has no booking yet: never saved.
	 *
	 * @param array<string, mixed> $property
	 * @return array<string, mixed>
	 */
	private static function example_booking( array $property ): array {
		$day = ( new \DateTimeImmutable( 'now', new \DateTimeZone( (string) $property['timezone'] ) ) )->modify( '+7 days' )->format( 'Y-m-d' );
		return array(
			'id'                => 0,
			'property_id'       => (int) $property['id'],
			'code'              => 'ESEMPIO1',
			'first_name'        => __( 'Mario', 'booking-manager' ),
			'last_name'         => __( 'Rossi', 'booking-manager' ),
			'email'             => '',
			'phone'             => '',
			'language'          => 'it',
			'arrival'           => $day,
			'departure'         => Dates::add_days( $day, 2 ),
			'nights'            => 2,
			'adults'            => 2,
			'children'          => 0,
			'total'             => 24000,
			'paid'              => 0,
			'refunded'          => 0,
			'balance'           => 24000,
			'currency'          => (string) $property['currency'],
			'status'            => BookingStatus::CONFIRMED,
			'customer_notes'    => '',
			'cancel_reason'     => '',
			'version'           => 1,
		);
	}
}
