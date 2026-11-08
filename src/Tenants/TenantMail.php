<?php
/**
 * How the e-mails of a customer leave (0.2.5), in bkng_tenants.settings['mail'].
 *
 * 1. Servizio della piattaforma (default): From is the platform's address (the site's,
 *    or BKNG_MAIL_FROM in wp-config.php), the sender name is the property's, Reply-To
 *    is the property's e-mail or else the customer's booking e-mail. Nothing to set up.
 * 2. SMTP del cliente: host, port, encryption, user, password (sealed with SecretBox,
 *    never returned), From address and name. It is used only after a test e-mail sent
 *    with exactly those settings went through, and only if the plan includes it
 *    (custom_smtp). Changing any of them puts it back to «da verificare». The From
 *    address must be the SMTP user or on the domain of one of the customer's e-mails.
 *    A message that fails stays in the queue with its retries: it is never sent by
 *    the platform's server under another sender.
 *
 * The customer of a message always comes from the booking's property, never from the
 * user logged in or from the last message sent.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

use BookingManager\Audit\AuditLogger;
use BookingManager\Core\SecretBox;
use BookingManager\Core\Validation;

defined( 'ABSPATH' ) || exit;

class TenantMail {

	public const KEY = 'mail';

	public const MODES   = array( 'platform', 'smtp' );
	public const SECURES = array( 'tls', 'ssl', 'none' );

	/** Fields whose change asks for a new test. */
	private const VERIFIED_FIELDS = array( 'host', 'port', 'secure', 'user', 'from_email' );

	public function __construct(
		private TenantRepository $tenants,
		private Plans $plans,
		private SecretBox $box,
		private AuditLogger $audit
	) {}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'mode'        => 'platform',
			'host'        => '',
			'port'        => 587,
			'secure'      => 'tls',
			'user'        => '',
			'password'    => '',
			'from_email'  => '',
			'from_name'   => '',
			'verified'    => '',
			'verified_at' => null,
		);
	}

	/**
	 * Stored settings, completed (the password still sealed).
	 *
	 * @param array<string, mixed>|null $tenant
	 * @return array<string, mixed>
	 */
	public static function of( ?array $tenant ): array {
		$stored = is_array( $tenant['settings'][ self::KEY ] ?? null ) ? $tenant['settings'][ self::KEY ] : array();
		return array_intersect_key( $stored, self::defaults() ) + self::defaults();
	}

	/** The fingerprint of the settings a test proved working. */
	private static function fingerprint( array $mail ): string {
		$parts = array();
		foreach ( self::VERIFIED_FIELDS as $field ) {
			$parts[] = strtolower( (string) $mail[ $field ] );
		}
		return hash( 'sha256', implode( '|', $parts ) . '|' . (string) $mail['password'] );
	}

	/** May the customer use its own server (plan feature custom_smtp)? */
	public function smtp_allowed( array $tenant ): bool {
		return ! empty( $this->plans->features_of( $tenant )['custom_smtp'] );
	}

	/**
	 * For the "Attività" screen: never the password.
	 *
	 * @return array<string, mixed>|null
	 */
	public function payload( int $tenant_id ): ?array {
		$tenant = $tenant_id > 0 ? $this->tenants->find( $tenant_id ) : null;
		if ( ! $tenant ) {
			return null;
		}
		$mail = self::of( $tenant );
		return array(
			'mode'         => (string) $mail['mode'],
			'host'         => (string) $mail['host'],
			'port'         => (int) $mail['port'],
			'secure'       => (string) $mail['secure'],
			'user'         => (string) $mail['user'],
			'password_set' => '' !== (string) $mail['password'],
			'from_email'   => (string) $mail['from_email'],
			'from_name'    => (string) $mail['from_name'],
			'verified'     => '' !== (string) $mail['verified'] && hash_equals( (string) $mail['verified'], self::fingerprint( $mail ) ),
			'verified_at'  => $mail['verified_at'],
			'allowed'      => $this->smtp_allowed( $tenant ),
			'active'       => null !== $this->smtp_for( $tenant ),
			'platform'     => array(
				'from'     => self::platform_from(),
				'reply_to' => $this->reply_to( $tenant, null ),
			),
			'version'      => (int) $tenant['version'],
		);
	}

	/**
	 * @param array<string, mixed> $input mode, host, port, secure, user, password (empty: unchanged), from_email, from_name, version
	 * @return array<string, mixed>|\WP_Error the new payload
	 */
	public function save( int $tenant_id, array $input ): array|\WP_Error {
		$tenant = $tenant_id > 0 ? $this->tenants->find( $tenant_id ) : null;
		if ( ! $tenant ) {
			return TenantService::not_found();
		}
		if ( isset( $input['version'] ) && (int) $input['version'] !== (int) $tenant['version'] ) {
			return new \WP_Error( 'bkng_conflict', __( 'I dati sono stati modificati da qualcun altro. Ricarica e riprova.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$mail = $this->validate( $input, $tenant );
		if ( $mail instanceof \WP_Error ) {
			return $mail;
		}
		$old = self::of( $tenant );
		$this->write( $tenant, $mail );
		$this->audit->log(
			array(
				'action'      => 'tenant.mail_updated',
				'entity_type' => 'tenant',
				'entity_id'   => (int) $tenant['id'],
				'tenant_id'   => (int) $tenant['id'],
				// Never the password, not even sealed.
				'old'         => array_diff_key( $old, array( 'password' => 1 ) ),
				'new'         => array_diff_key( $mail, array( 'password' => 1 ) ),
			)
		);
		return (array) $this->payload( (int) $tenant['id'] );
	}

	/**
	 * Sends a test e-mail with the saved SMTP settings; when it goes through they become
	 * «verificate» and the customer's e-mails use them from now on.
	 *
	 * @return array{ok: bool, error: string, settings: array<string, mixed>}|\WP_Error
	 */
	public function test( int $tenant_id, string $to ): array|\WP_Error {
		$tenant = $tenant_id > 0 ? $this->tenants->find( $tenant_id ) : null;
		if ( ! $tenant ) {
			return TenantService::not_found();
		}
		if ( ! is_email( $to ) ) {
			return Validation::error( array( 'to' => __( 'Scrivi un indirizzo email valido.', 'booking-manager' ) ) );
		}
		$mail = self::of( $tenant );
		if ( 'smtp' !== $mail['mode'] || '' === $mail['host'] ) {
			return Validation::error( array( 'mode' => __( 'Salva prima le impostazioni del server SMTP.', 'booking-manager' ) ) );
		}
		if ( ! $this->smtp_allowed( $tenant ) ) {
			return new \WP_Error( 'bkng_plan_feature', __( 'Il piano non comprende un server email proprio: contatta la piattaforma.', 'booking-manager' ), array( 'status' => 403 ) );
		}
		$config = $this->config( $mail );
		if ( null === $config ) {
			return new \WP_Error( 'bkng_secret_unreadable', __( 'La password salvata non si può leggere: scrivila di nuovo.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$error = '';
		$ok    = self::send_with(
			$config,
			$to,
			/* translators: %s: customer name. */
			sprintf( __( 'Prova del server email – %s', 'booking-manager' ), (string) $tenant['name'] ),
			'<p>' . esc_html__( 'Questa è una email di prova di Booking Manager inviata con il server SMTP della tua attività. Se la leggi, le email ai tuoi ospiti partiranno da questo server.', 'booking-manager' ) . '</p>',
			array(),
			$error
		);
		if ( $ok ) {
			$mail['verified']    = self::fingerprint( $mail );
			$mail['verified_at'] = gmdate( 'Y-m-d H:i:s' );
			$this->write( $tenant, $mail );
		}
		$this->audit->log(
			array(
				'action'      => $ok ? 'tenant.mail_verified' : 'tenant.mail_test_failed',
				'entity_type' => 'tenant',
				'entity_id'   => (int) $tenant['id'],
				'tenant_id'   => (int) $tenant['id'],
				'new'         => array(
					'host'  => $mail['host'],
					'error' => mb_substr( $error, 0, 300 ),
				),
			)
		);
		return array(
			'ok'       => $ok,
			'error'    => $error,
			'settings' => (array) $this->payload( (int) $tenant['id'] ),
		);
	}

	/**
	 * The SMTP settings to use for a customer's e-mails, or null for the platform's service:
	 * mode smtp, allowed by the plan, verified with the current settings, readable password.
	 *
	 * @param array<string, mixed>|null $tenant
	 * @return array{host: string, port: int, secure: string, user: string, password: string, from_email: string, from_name: string}|null
	 */
	public function smtp_for( ?array $tenant ): ?array {
		if ( ! $tenant ) {
			return null;
		}
		$mail = self::of( $tenant );
		if ( 'smtp' !== $mail['mode'] || '' === (string) $mail['verified'] || ! hash_equals( (string) $mail['verified'], self::fingerprint( $mail ) ) || ! $this->smtp_allowed( $tenant ) ) {
			return null;
		}
		return $this->config( $mail );
	}

	/** The customer of a property, for the e-mail channel. */
	public function tenant_of_property( int $property_id ): ?array {
		if ( ! Tenancy::ready() ) {
			return null;
		}
		$id = Tenancy::of_property( $property_id );
		return $id > 0 ? $this->tenants->find( $id ) : null;
	}

	/**
	 * Reply-To of the guest e-mails: the property's e-mail, else the customer's booking,
	 * main or information e-mail.
	 *
	 * @param array<string, mixed>|null $tenant
	 * @param array<string, mixed>|null $property
	 */
	public function reply_to( ?array $tenant, ?array $property ): string {
		$own = (string) ( $property['email'] ?? '' );
		if ( is_email( $own ) ) {
			return $own;
		}
		$c = TenantProfile::of( $tenant )['contacts'];
		foreach ( array( 'booking_email', 'email', 'info_email' ) as $key ) {
			if ( is_email( (string) $c[ $key ] ) ) {
				return (string) $c[ $key ];
			}
		}
		return '';
	}

	/** From address of the platform's service: BKNG_MAIL_FROM, or what WordPress uses. */
	public static function platform_from(): string {
		if ( defined( 'BKNG_MAIL_FROM' ) && is_email( (string) BKNG_MAIL_FROM ) ) {
			return (string) BKNG_MAIL_FROM;
		}
		$host = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
		$host = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		return (string) apply_filters( 'wp_mail_from', 'wordpress@' . $host );
	}

	/**
	 * Sends one message through an SMTP server and puts the shared PHPMailer back as it
	 * was, so the next wp_mail() of the site (or of another customer) is not affected.
	 *
	 * @param array<string, mixed>                         $config  smtp_for()
	 * @param string[]                                     $headers extra headers (Reply-To)
	 * @param callable|null                                $extend  more phpmailer_init work (inline images, text)
	 */
	public static function send_with( array $config, string $to, string $subject, string $html, array $headers, string &$error, ?callable $extend = null ): bool {
		$error   = '';
		$on_fail = static function ( $e ) use ( &$error ) {
			$error = $e instanceof \WP_Error ? $e->get_error_message() : __( 'Invio non riuscito.', 'booking-manager' );
		};
		$saved   = array();
		$on_init = static function ( $phpmailer ) use ( $config, $extend, &$saved ) {
			foreach ( array( 'Mailer', 'Host', 'Port', 'SMTPSecure', 'SMTPAuth', 'Username', 'Password', 'SMTPAutoTLS', 'From', 'FromName', 'Sender' ) as $prop ) {
				$saved[ $prop ] = $phpmailer->$prop;
			}
			$phpmailer->isSMTP();
			$phpmailer->Host        = $config['host'];
			$phpmailer->Port        = (int) $config['port'];
			$phpmailer->SMTPSecure  = 'none' === $config['secure'] ? '' : $config['secure'];
			$phpmailer->SMTPAutoTLS = 'none' !== $config['secure'];
			$phpmailer->SMTPAuth    = '' !== $config['user'];
			$phpmailer->Username    = $config['user'];
			$phpmailer->Password    = $config['password'];
			$phpmailer->setFrom( $config['from_email'], '' !== $config['from_name'] ? $config['from_name'] : $phpmailer->FromName, false );
			$phpmailer->Sender = $config['from_email'];
			if ( $extend ) {
				$extend( $phpmailer );
			}
		};
		$from = static fn() => $config['from_email'];
		add_filter( 'wp_mail_from', $from, 999 );
		add_action( 'wp_mail_failed', $on_fail );
		// Last, after any SMTP plugin of the site.
		add_action( 'phpmailer_init', $on_init, 999 );
		try {
			$ok = wp_mail( $to, $subject, $html, array_merge( array( 'Content-Type: text/html; charset=UTF-8' ), $headers ) );
		} catch ( \Throwable $e ) {
			$ok    = false;
			$error = $e->getMessage();
		} finally {
			remove_filter( 'wp_mail_from', $from, 999 );
			remove_action( 'wp_mail_failed', $on_fail );
			remove_action( 'phpmailer_init', $on_init, 999 );
			global $phpmailer;
			if ( $saved && is_object( $phpmailer ) ) {
				foreach ( $saved as $prop => $value ) {
					$phpmailer->$prop = $value;
				}
			}
		}
		$error = wp_strip_all_tags( $error );
		return (bool) $ok;
	}

	/**
	 * @param array<string, mixed> $mail
	 * @return array<string, mixed>|null
	 */
	private function config( array $mail ): ?array {
		$secret = $this->box->open( (string) $mail['password'] );
		if ( null === $secret ) {
			return null;
		}
		return array(
			'host'       => (string) $mail['host'],
			'port'       => (int) $mail['port'],
			'secure'     => (string) $mail['secure'],
			'user'       => (string) $mail['user'],
			'password'   => (string) ( $secret['password'] ?? '' ),
			'from_email' => (string) $mail['from_email'],
			'from_name'  => (string) $mail['from_name'],
		);
	}

	/**
	 * @param array<string, mixed> $tenant
	 * @param array<string, mixed> $mail
	 */
	private function write( array $tenant, array $mail ): void {
		$settings              = is_array( $tenant['settings'] ) ? $tenant['settings'] : array();
		$settings[ self::KEY ] = $mail;
		$this->tenants->update( (int) $tenant['id'], array( 'settings' => $settings ) );
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $tenant
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validate( array $input, array $tenant ): array|\WP_Error {
		$current = self::of( $tenant );
		$mail    = $current;
		$errors  = array();
		$mode    = (string) ( $input['mode'] ?? $current['mode'] );
		if ( ! in_array( $mode, self::MODES, true ) ) {
			$errors['mode'] = __( 'Scelta non valida.', 'booking-manager' );
		}
		$mail['mode'] = $mode;
		if ( 'smtp' === $mode && ! $this->smtp_allowed( $tenant ) ) {
			$errors['mode'] = __( 'Il piano non comprende un server email proprio: contatta la piattaforma.', 'booking-manager' );
		}
		$mail['host']       = strtolower( trim( (string) ( $input['host'] ?? $current['host'] ) ) );
		$mail['port']       = (int) ( $input['port'] ?? $current['port'] );
		$mail['secure']     = (string) ( $input['secure'] ?? $current['secure'] );
		$mail['user']       = trim( sanitize_text_field( (string) ( $input['user'] ?? $current['user'] ) ) );
		$mail['from_email'] = sanitize_email( (string) ( $input['from_email'] ?? $current['from_email'] ) );
		$mail['from_name']  = mb_substr( sanitize_text_field( (string) ( $input['from_name'] ?? $current['from_name'] ) ), 0, 120 );
		$password           = (string) ( $input['password'] ?? '' );
		if ( '' !== $password ) {
			$mail['password'] = $this->box->seal( array( 'password' => $password ) );
		}
		if ( 'smtp' === $mode ) {
			if ( ! preg_match( '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $mail['host'] ) ) {
				$errors['host'] = __( 'Scrivi il nome del server, ad esempio smtp.miodominio.it.', 'booking-manager' );
			}
			if ( $mail['port'] < 1 || $mail['port'] > 65535 ) {
				$errors['port'] = __( 'Porta non valida.', 'booking-manager' );
			}
			if ( ! in_array( $mail['secure'], self::SECURES, true ) ) {
				$errors['secure'] = __( 'Scelta non valida.', 'booking-manager' );
			}
			if ( ! is_email( $mail['from_email'] ) ) {
				$errors['from_email'] = __( 'Scrivi l\'indirizzo del mittente.', 'booking-manager' );
			} elseif ( ! $this->from_allowed( $mail['from_email'], $mail['user'], $tenant ) ) {
				$errors['from_email'] = __( 'Il mittente deve essere l\'utente SMTP o un indirizzo dello stesso dominio delle email dell\'attività.', 'booking-manager' );
			}
		}
		// Anything that changes the connection asks for a new test.
		if ( self::fingerprint( $mail ) !== self::fingerprint( $current ) ) {
			$mail['verified']    = '';
			$mail['verified_at'] = null;
		}
		return $errors ? Validation::error( $errors ) : $mail;
	}

	/** The From address is the SMTP user, or on the domain of one of the customer's e-mails. */
	private function from_allowed( string $from, string $user, array $tenant ): bool {
		if ( strtolower( $from ) === strtolower( $user ) ) {
			return true;
		}
		$domain   = strtolower( (string) substr( strrchr( $from, '@' ) ?: '', 1 ) );
		$contacts = TenantProfile::of( $tenant )['contacts'];
		foreach ( array( 'email', 'booking_email', 'info_email' ) as $key ) {
			$own = strtolower( (string) $contacts[ $key ] );
			if ( '' !== $domain && str_contains( $own, '@' ) && substr( (string) strrchr( $own, '@' ), 1 ) === $domain ) {
				return true;
			}
		}
		return false;
	}
}
