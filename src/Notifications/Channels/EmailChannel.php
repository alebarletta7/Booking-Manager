<?php
/**
 * E-mail through wp_mail(): it uses whatever the site uses (PHP mail, an SMTP plugin…),
 * or, from 0.2.5, the SMTP server of the property's customer once verified (TenantMail).
 * The customer comes from the message's property, never from the user logged in.
 *
 * The message is HTML with a plain-text alternative; the QR code is embedded as an inline
 * image (cid), so it shows even when the mail client blocks remote images. A wp_mail()
 * failure is captured from the wp_mail_failed action and stored in the log: the booking
 * itself is never affected.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\Channels;

use BookingManager\Notifications\Message;
use BookingManager\Notifications\SendResult;
use BookingManager\Tenants\TenantMail;

defined( 'ABSPATH' ) || exit;

class EmailChannel implements NotificationChannel {

	private string $last_error = '';

	public function __construct( private ?TenantMail $mail = null ) {}

	public function key(): string {
		return 'email';
	}

	public function label(): string {
		return __( 'E-mail', 'booking-manager' );
	}

	public function unavailable( array $property ): ?string {
		return empty( $property['settings']['email_enabled'] )
			? __( 'E-mail disattivate per questa struttura.', 'booking-manager' )
			: null;
	}

	public function recipient( array $booking ): string {
		$email = (string) ( $booking['email'] ?? '' );
		return is_email( $email ) ? $email : '';
	}

	public function send( Message $message ): SendResult {
		if ( ! is_email( $message->recipient ) ) {
			return SendResult::failed( __( 'Indirizzo e-mail non valido.', 'booking-manager' ) );
		}
		$property = $message->property;
		$name     = trim( (string) ( $property['settings']['sender_name'] ?? '' ) );
		$name     = '' === $name ? (string) $property['name'] : $name;
		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		$tenant   = $this->mail ? $this->mail->tenant_of_property( (int) ( $property['id'] ?? 0 ) ) : null;
		// Guests answer the property, or else the customer's booking e-mail (0.2.5).
		$reply_to = 'staff' === $message->audience ? '' : ( $this->mail ? $this->mail->reply_to( $tenant, $property ) : (string) ( $property['email'] ?? '' ) );
		if ( 'staff' === $message->audience && is_email( (string) ( $message->booking['email'] ?? '' ) ) ) {
			$reply_to = (string) $message->booking['email'];
		}
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$smtp = $this->mail ? $this->mail->smtp_for( $tenant ) : null;
		if ( null !== $smtp ) {
			if ( '' === $smtp['from_name'] ) {
				$smtp['from_name'] = $name;
			}
			$error = '';
			$ok    = TenantMail::send_with(
				$smtp,
				$message->recipient,
				$message->subject,
				$message->html,
				array_slice( $headers, 1 ),
				$error,
				static function ( $phpmailer ) use ( $message ) {
					self::extend( $phpmailer, $message );
				}
			);
			// The customer's server refused or is down: retried later, never sent by the platform instead.
			return $ok ? SendResult::sent() : SendResult::retry( '' === $error ? __( 'Il server SMTP dell\'attività non ha accettato il messaggio.', 'booking-manager' ) : mb_substr( $error, 0, 400 ) );
		}

		$this->last_error = '';
		$from_name        = static fn() => $name;
		$platform_from    = static fn( $from ) => TenantMail::platform_from() ?: $from;
		$on_fail          = function ( $error ) {
			$this->last_error = $error instanceof \WP_Error ? $error->get_error_message() : __( 'Invio non riuscito.', 'booking-manager' );
		};
		$on_init          = static function ( $phpmailer ) use ( $message ) {
			self::extend( $phpmailer, $message );
		};
		add_filter( 'wp_mail_from_name', $from_name, 99 );
		if ( defined( 'BKNG_MAIL_FROM' ) ) {
			add_filter( 'wp_mail_from', $platform_from, 99 );
		}
		add_action( 'wp_mail_failed', $on_fail );
		add_action( 'phpmailer_init', $on_init );
		try {
			$ok = wp_mail( $message->recipient, $message->subject, $message->html, $headers );
		} catch ( \Throwable $e ) {
			$ok               = false;
			$this->last_error = $e->getMessage();
		} finally {
			remove_filter( 'wp_mail_from_name', $from_name, 99 );
			remove_filter( 'wp_mail_from', $platform_from, 99 );
			remove_action( 'wp_mail_failed', $on_fail );
			remove_action( 'phpmailer_init', $on_init );
		}
		if ( $ok ) {
			return SendResult::sent();
		}
		// The mail system may be down for a moment: try again later.
		return SendResult::retry( '' === $this->last_error ? __( 'wp_mail() non è riuscito a inviare il messaggio.', 'booking-manager' ) : mb_substr( wp_strip_all_tags( $this->last_error ), 0, 400 ) );
	}

	/** Text alternative, inline images (QR, logo) and attachments (.ics) of a message. */
	private static function extend( $phpmailer, Message $message ): void {
		$phpmailer->AltBody = $message->text;
		foreach ( $message->inline as $image ) {
			$phpmailer->addStringEmbeddedImage( $image['data'], $image['cid'], $image['name'], 'base64', $image['mime'] );
		}
		foreach ( $message->attachments as $file ) {
			$phpmailer->addStringAttachment( $file['data'], $file['name'], 'base64', $file['mime'] );
		}
	}
}
