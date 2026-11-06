<?php
/**
 * The licence of this site: the one place the rest of the plugin asks.
 *
 *   get_status()  not_configured | inactive | active | lifetime | trial | expired
 *                 | suspended | revoked | site_changed | recovery_required | invalid
 *                 | offline_grace | unverified
 *   can_read()    always
 *   can_write()   AccessMatrix ∧ access.write of the server (true when not configured)
 *   can_update()  AccessMatrix ∧ access.update of the server
 *   is_locked()   no activation on this site: never activated, or «Disattiva» here
 *
 * Locked (is_locked): Booking Manager shows only the Licenza screen and its API answers
 * nothing but the licence (WriteGate full mode): a fresh install does nothing until the
 * licence is entered. A site that was activated is never locked again by the server or
 * by an outage: without can_write() only new bookings stop (staff app and public form:
 * WriteGate bookings mode), everything else keeps working.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

use BookingManager\Audit\AuditLogger;
use BookingManager\Updates\UpdateChecker;

defined( 'ABSPATH' ) || exit;

class LicenseManager {

	/** The product slug on the licence server. */
	public const PRODUCT = 'booking-manager';

	/** Uninstalling should not hang on a server that does not answer. */
	public const UNINSTALL_TIMEOUT = 5;

	/** Days of warning before the expiry. */
	public const EXPIRY_NOTICE_DAYS = 30;

	/** A short silence of the server does not alarm anybody. */
	public const OFFLINE_NOTICE_AFTER = 2 * LicenseState::DAY;

	/** Fired when the state changes: ( string $new, string $old ). */
	public const STATUS_CHANGED_HOOK = 'bkng_license_status_changed';

	private ?LicenseState $state = null;

	public function __construct( protected LicenseStore $store, protected LicenseClient $client, protected ?AuditLogger $audit = null ) {}

	/** For uninstall.php, where the plugin's container does not exist. */
	public static function standalone(): self {
		return new self( new LicenseStore(), new LicenseClient( ServerConfig::from_environment() ) );
	}

	/** Reads the stored licence again (after a change made elsewhere in the same request). */
	public function reload(): void {
		$this->state = null;
	}

	public function server(): ServerConfig {
		return $this->client->server();
	}

	public function is_configured(): bool {
		return $this->server()->is_configured();
	}

	// ------------------------------------------------------------------ what it is

	public function state(): LicenseState {
		if ( null === $this->state ) {
			if ( ! $this->is_configured() ) {
				$this->state = LicenseState::not_configured();
				return $this->state;
			}
			$data   = $this->store->all();
			$auth   = $this->verified( (string) $data['auth'] );
			$auth   = null !== $auth && true === ( $auth->ok ?? null ) ? $auth : null;
			$error  = $this->verified( (string) $data['signed_error'] );
			$signed = null !== $error && false === ( $error->ok ?? null ) && is_string( $error->error->code ?? null ) ? $error->error->code : '';
			if ( null === $auth && '' === $signed && '' === $data['activation_id'] && ! empty( $data['ever_activated'] ) ) {
				// The server gave this site's place away (unknown_activation, then no place
				// left): it was activated, so it only has to be connected again.
				$signed = AccessMatrix::RECOVERY_REQUIRED;
			}
			$this->state = LicenseState::from(
				$auth,
				$signed,
				'' !== (string) $data['last_error'],
				self::now( (int) $data['max_seen_time'], $this->time() ),
				LicenseState::domain_of( $this->home() )
			);
		}
		return $this->state;
	}

	/**
	 * «Now» for the grace period: the site's clock, unless it went back more than an hour
	 * from the latest time ever seen. Putting the date back does not stretch the grace.
	 */
	public static function now( int $max_seen, ?int $clock = null ): int {
		$now = $clock ?? time();
		return $now < $max_seen - 3600 ? $max_seen : $now;
	}

	/** The latest time seen so far, with this answer's signed server_time. */
	private function seen( ?ServerAnswer $answer = null ): int {
		$signed = $answer && is_string( $answer->data->server_time ?? null ) ? (int) strtotime( $answer->data->server_time ) : 0;
		return max( (int) $this->store->all()['max_seen_time'], $this->time(), $signed );
	}

	public function get_status(): string {
		return $this->state()->status;
	}

	public function can_read(): bool {
		return true;
	}

	public function can_write(): bool {
		return $this->state()->access['write'];
	}

	public function can_update(): bool {
		return $this->state()->access['update'];
	}

	public function is_active(): bool {
		return AccessMatrix::is_good( $this->get_status() );
	}

	/**
	 * Booking Manager is locked on this site: the licence server is configured and this
	 * site has no activation of its own, because it was never activated (fresh install,
	 * or an «Attiva» that did not give an activation, such as a suspended key) or an
	 * administrator deactivated it here. Only then: an outage, an expiry, a suspension or
	 * a place given away by the server never lock a site that was activated.
	 */
	public function is_locked(): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}
		$data = $this->store->all();
		return '' === $data['activation_id'] && empty( $data['ever_activated'] );
	}

	/** What a locked Booking Manager tells its users. */
	public function locked_text( bool $admin ): string {
		if ( ! $admin ) {
			return __( 'Booking Manager non è ancora attivato: chiedi all\'amministratore di inserire la licenza.', 'booking-manager' );
		}
		$status = $this->get_status();
		if ( AccessMatrix::INACTIVE === $status ) {
			return __( 'Inserisci la licenza per usare Booking Manager: finché non è attivata su questo sito è disponibile solo questa schermata.', 'booking-manager' );
		}
		/* translators: %s: licence state, e.g. Sospesa. */
		return sprintf( __( 'La licenza inserita non attiva questo sito (stato: %s). Booking Manager resta bloccato finché non inserisci una licenza valida.', 'booking-manager' ), AccessMatrix::label( $status ) );
	}

	public function requires_recovery(): bool {
		return in_array( $this->get_status(), array( AccessMatrix::RECOVERY_REQUIRED, AccessMatrix::SITE_CHANGED ), true );
	}

	/** ISO date in UTC; null for a lifetime licence or when there is no licence. */
	public function get_expiration(): ?string {
		$value = $this->state()->auth->license->expires_at ?? null;
		return is_string( $value ) ? $value : null;
	}

	/** Unix time of the last check the server answered and signed, null if never. */
	public function get_last_validation(): ?int {
		$at = (int) $this->store->all()['last_ok_at'];
		return $at > 0 ? $at : null;
	}

	/** Checks failed in a row (the retries grow with it). */
	public function fail_count(): int {
		return (int) $this->store->all()['fail_count'];
	}

	/** The activation this site signs with, '' when there is none. */
	public function activation_id(): string {
		return (string) $this->store->all()['activation_id'];
	}

	/**
	 * When the scheduled check is due (Unix time), null when there is nothing to check
	 * (not configured, or no activation: after «Disattiva» the site stays off).
	 */
	public function next_check_at(): ?int {
		$data = $this->store->all();
		if ( ! $this->is_configured() || '' === $data['activation_id'] ) {
			return null;
		}
		$last = (int) $data['last_attempt_at'];
		if ( '' !== (string) $data['last_error'] ) {
			return $last + LicenseScheduler::delay( (int) $data['fail_count'] );
		}
		$auth = $this->verified( (string) $data['auth'] );
		return max( $last, (int) $data['last_ok_at'] ) + LicenseState::revalidate_hours( $auth ) * 3600;
	}

	/** The server has not heard this plugin version yet (an update was installed). */
	public function version_unreported(): bool {
		$reported = (string) $this->store->all()['reported_version'];
		return '' !== $this->activation_id() && '' !== $reported && $reported !== $this->plugin_version();
	}

	// ------------------------------------------------------------------ what people read

	/**
	 * Why new bookings are blocked; '' when they are not. Shown to every user
	 * of the app and returned by the blocked API calls.
	 */
	public function read_only_reason(): string {
		$state = $this->state();
		if ( $state->access['write'] ) {
			return '';
		}
		if ( $this->is_locked() ) {
			return $this->locked_text( true );
		}
		$what = __( 'non si possono inserire nuove prenotazioni (dal gestionale e dal sito). Check-in, modifiche, annullamenti, notifiche e tutto il resto funzionano normalmente.', 'booking-manager' );
		switch ( $state->status ) {
			case AccessMatrix::EXPIRED:
				$expiry = $this->get_expiration();
				return $expiry
					/* translators: 1: date, 2: what can and cannot be done. */
					? sprintf( __( 'Licenza scaduta il %1$s: %2$s', 'booking-manager' ), $this->date_text( $expiry ), $what )
					/* translators: %s: what can and cannot be done. */
					: sprintf( __( 'Licenza scaduta: %s', 'booking-manager' ), $what );
			case AccessMatrix::SUSPENDED:
				/* translators: %s: what can and cannot be done. */
				return sprintf( __( 'Licenza sospesa: %s', 'booking-manager' ), $what );
			case AccessMatrix::REVOKED:
				/* translators: %s: what can and cannot be done. */
				return sprintf( __( 'Licenza revocata: %s', 'booking-manager' ), $what );
			case AccessMatrix::UNVERIFIED:
				/* translators: %s: what can and cannot be done. */
				return sprintf( __( 'Non è possibile verificare la licenza perché il server licenze non risponde da troppi giorni: %s', 'booking-manager' ), $what );
			case AccessMatrix::SITE_CHANGED:
			case AccessMatrix::RECOVERY_REQUIRED:
				/* translators: %s: what can and cannot be done. */
				return sprintf( __( 'La licenza va ricollegata a questo sito: %s', 'booking-manager' ), $what );
		}
		/* translators: %s: what can and cannot be done. */
		return sprintf( __( 'Nessuna licenza attiva: %s', 'booking-manager' ), $what );
	}

	/**
	 * The band at the top of the app: the block for everybody, the warnings before it for
	 * administrators only. Null when there is nothing to say.
	 *
	 * @return array{tone: string, text: string, settings: bool}|null
	 */
	public function notice( bool $admin ): ?array {
		if ( $this->is_locked() ) {
			return array(
				'tone'     => 'error',
				'text'     => $this->locked_text( $admin ),
				'settings' => $admin,
			);
		}
		$reason = $this->read_only_reason();
		if ( '' !== $reason ) {
			return array(
				'tone'     => 'error',
				'text'     => $admin ? $reason . ' ' . __( 'Inserisci o rinnova la licenza nella sezione Licenza.', 'booking-manager' ) : $reason . ' ' . __( 'Avvisa l\'amministratore.', 'booking-manager' ),
				'settings' => $admin,
			);
		}
		if ( ! $admin ) {
			return null;
		}
		$state = $this->state();
		$now   = $this->time();
		if ( AccessMatrix::OFFLINE_GRACE === $state->status ) {
			$last = (int) $this->store->all()['last_ok_at'];
			if ( $last > 0 && $now - $last < self::OFFLINE_NOTICE_AFTER ) {
				return null;
			}
			$days = max( 1, (int) floor( ( $now - $last ) / LicenseState::DAY ) );
			return array(
				'tone'     => null === $state->grace_until ? 'info' : 'warn',
				'text'     => null === $state->grace_until
					/* translators: %d: number of days. */
					? sprintf( _n( 'Il server licenze non risponde da %d giorno. La licenza a vita resta attiva.', 'Il server licenze non risponde da %d giorni. La licenza a vita resta attiva.', $days, 'booking-manager' ), $days )
					/* translators: 1: number of days, 2: date. */
					: sprintf( _n( 'Il server licenze non risponde da %1$d giorno. Tutto funziona normalmente fino al %2$s.', 'Il server licenze non risponde da %1$d giorni. Tutto funziona normalmente fino al %2$s.', $days, 'booking-manager' ), $days, $this->date_text( gmdate( 'c', (int) $state->grace_until ) ) ),
				'settings' => true,
			);
		}
		$expiry = $this->get_expiration();
		if ( null !== $expiry && in_array( $state->status, array( AccessMatrix::ACTIVE, AccessMatrix::TRIAL ), true ) && strtotime( $expiry ) - $now <= self::EXPIRY_NOTICE_DAYS * LicenseState::DAY ) {
			return array(
				'tone'     => 'warn',
				/* translators: %s: date. */
				'text'     => sprintf( __( 'La licenza scade il %s. Rinnovala per non bloccare le nuove prenotazioni.', 'booking-manager' ), $this->date_text( $expiry ) ),
				'settings' => true,
			);
		}
		return null;
	}

	/** Italian explanation of a stored error code ('' when there is none). */
	public static function error_text( string $code, array $data = array() ): string {
		switch ( $code ) {
			case '':
				return '';
			case 'timeout':
			case 'dns':
			case 'tls':
			case 'network':
			case 'http_5xx':
			case 'bad_response':
				/* translators: %s: technical error code. */
				return sprintf( __( 'Il server licenze non risponde (%s). Il gestionale continua a funzionare durante il periodo di tolleranza.', 'booking-manager' ), $code );
			case 'unsigned':
			case 'bad_signature':
				return __( 'La risposta del server licenze non aveva una firma valida ed è stata ignorata.', 'booking-manager' );
			case 'rate_limited':
				return __( 'Troppe richieste al server licenze: riprova tra qualche minuto.', 'booking-manager' );
			case 'maintenance':
				return __( 'Il server licenze è in manutenzione: riprova più tardi.', 'booking-manager' );
			case 'stale_request':
			case 'clock_skew':
				return __( 'L\'orologio del server del sito è sbagliato di oltre 10 minuti: correggilo (o chiedi all\'hosting), poi premi «Verifica ora».', 'booking-manager' );
			case 'key_unreadable':
				return __( 'La chiave dell\'installazione non si può più leggere (sono cambiate le chiavi di sicurezza di WordPress?): reinserisci la chiave di licenza e premi «Attiva».', 'booking-manager' );
			case 'invalid_license':
				return __( 'Chiave di licenza non valida.', 'booking-manager' );
			case 'activation_limit_reached':
				$sites = array_filter( array_map( 'strval', (array) ( $data['sites'] ?? array() ) ) );
				return $sites
					/* translators: %s: list of web sites. */
					? sprintf( __( 'La licenza è già attiva sul numero massimo di siti: %s. Disattivala su uno di questi, poi premi «Attiva».', 'booking-manager' ), implode( ', ', $sites ) )
					: __( 'La licenza è già attiva sul numero massimo di siti. Disattivala su un altro sito, poi premi «Attiva».', 'booking-manager' );
			case 'not_configured':
				return __( 'Il server delle licenze non è configurato in questa installazione.', 'booking-manager' );
		}
		/* translators: %s: error code from the licence server. */
		return sprintf( __( 'Verifica non riuscita (%s).', 'booking-manager' ), $code );
	}

	// ------------------------------------------------------------------ what it does

	/**
	 * Activates this site. Without a key, the saved one (to connect again after a
	 * deactivation or a change of address).
	 *
	 * @return array<string, mixed>|\WP_Error the summary
	 */
	public function activate( ?string $key = null ) {
		if ( ! $this->is_configured() ) {
			return self::error( 'bkng_license_not_configured', __( 'Il server delle licenze non è configurato in questa installazione di Booking Manager: vedi docs/LICENZA.md.', 'booking-manager' ), 409 );
		}
		$key = null === $key ? '' : trim( $key );
		if ( '' === $key ) {
			$key = (string) $this->store->key();
			if ( '' === $key ) {
				return self::error(
					'bkng_license_key_missing',
					$this->store->has_key()
						? __( 'La chiave salvata non si può più leggere: reinserisci la chiave di licenza.', 'booking-manager' )
						: __( 'Inserisci la chiave di licenza.', 'booking-manager' ),
					400
				);
			}
		}
		if ( strlen( $key ) > 64 || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 -]*$/', $key ) ) {
			return self::error( 'bkng_license_invalid', __( 'Chiave di licenza non valida.', 'booking-manager' ), 400 );
		}

		$before = $this->get_status();
		$result = $this->connect( $key );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$this->log( 'license.activated', array( 'status' => $this->get_status() ) );
		$this->changed( $before );
		return $this->summary();
	}

	/**
	 * Asks the server what the licence is today (scheduled, and «Verifica ora»). A site
	 * without an activation is left alone: after «Disattiva» it stays off.
	 *
	 * @param string $endpoint validate (new seq) or heartbeat (same seq, reports the versions)
	 * @return array<string, mixed> the summary
	 */
	public function refresh( string $endpoint = 'validate' ): array {
		$data = $this->store->all();
		if ( ! $this->is_configured() || '' === $data['activation_id'] ) {
			return $this->summary();
		}
		$before = $this->get_status();
		$secret = $this->store->secret();
		if ( null === $secret ) {
			$this->failed( 'key_unreadable' );
			$this->changed( $before );
			return $this->summary();
		}

		$answer = $this->check( $endpoint, $data, $secret );
		if ( ServerAnswer::REFUSED === $answer->kind && in_array( $answer->code, array( 'clock_skew', 'stale_request' ), true ) && isset( $answer->details['server_time'] ) ) {
			// The site's clock is off: once more, with the server's time.
			$server_time = strtotime( (string) $answer->details['server_time'] );
			if ( false !== $server_time ) {
				$this->store->update( array( 'clock_offset' => $server_time - $this->time() ) );
				$answer = $this->check( $endpoint, $this->store->all(), $secret );
			}
		}

		if ( $answer->is_authorization() ) {
			$this->keep( $answer );
		} elseif ( ServerAnswer::SIGNED === $answer->kind && in_array( $answer->code, array( AccessMatrix::SITE_CHANGED, AccessMatrix::RECOVERY_REQUIRED ), true ) ) {
			$this->store->update(
				array(
					'signed_error'    => $answer->json(),
					'last_attempt_at' => $this->time(),
					'last_error'      => '',
					'last_error_data' => array(),
					'fail_count'      => 0,
					'max_seen_time'   => $this->seen( $answer ),
				)
			);
		} elseif ( ServerAnswer::REFUSED === $answer->kind && 'unknown_activation' === $answer->code ) {
			$this->reactivate();
		} elseif ( ServerAnswer::SIGNED === $answer->kind && 'product_mismatch' === $answer->code ) {
			// Signed «this activation is another product's»: it is not this site's (NM-02).
			$this->store->update(
				array(
					'secret'          => '',
					'public_key'      => '',
					'activation_id'   => '',
					'auth'            => '',
					'signed_error'    => '',
					'last_attempt_at' => $this->time(),
					'last_error'      => $answer->code,
					'last_error_data' => array(),
				)
			);
		} else {
			$this->failed( $answer->code );
		}
		$this->state = null;
		$this->changed( $before );
		return $this->summary();
	}

	/** Same as validate without a new seq: tells the server the versions now installed. */
	public function heartbeat(): array {
		return $this->refresh( 'heartbeat' );
	}

	/**
	 * update/check or update/download-token, signed like validate. The stored
	 * authorization is left as it is: the scheduled check keeps it.
	 *
	 * @param array<string, mixed> $body added to the site's address and versions
	 * @return ServerAnswer|null null when this site has no activation to sign with
	 */
	public function update_call( string $endpoint, array $body = array() ): ?ServerAnswer {
		$data   = $this->store->all();
		$secret = $this->store->secret();
		if ( ! $this->is_configured() || '' === $data['activation_id'] || null === $secret ) {
			return null;
		}
		$body  += array(
			'product'  => self::PRODUCT,
			'site'     => array( 'url' => $this->home() ),
			'versions' => $this->versions(),
		);
		$answer = $this->client->signed( $endpoint, (string) $data['activation_id'], $secret, $body, (int) $data['clock_offset'] );
		if ( ServerAnswer::REFUSED === $answer->kind && in_array( $answer->code, array( 'clock_skew', 'stale_request' ), true ) && isset( $answer->details['server_time'] ) ) {
			$server_time = strtotime( (string) $answer->details['server_time'] );
			if ( false !== $server_time ) {
				$this->store->update( array( 'clock_offset' => $server_time - $this->time() ) );
				$answer = $this->client->signed( $endpoint, (string) $data['activation_id'], $secret, $body, $server_time - $this->time() );
			}
		}
		return $answer;
	}

	/**
	 * Frees the place on the server and forgets the installation key and the
	 * authorization. The licence key stays, to activate again with one click.
	 *
	 * @return array<string, mixed> the summary, with freed = whether the server confirmed
	 */
	public function deactivate( int $timeout = LicenseClient::TIMEOUT ): array {
		$before = $this->get_status();
		$data   = $this->store->all();
		$secret = $this->store->secret();
		$freed  = false;
		if ( '' !== $data['activation_id'] && null !== $secret && $this->is_configured() ) {
			$answer = $this->client->signed( 'deactivate', (string) $data['activation_id'], $secret, null, (int) $data['clock_offset'], $timeout );
			$freed  = ServerAnswer::SIGNED === $answer->kind || 'unknown_activation' === $answer->code;
		}
		$this->store->update(
			array(
				'secret'           => '',
				'public_key'       => '',
				'activation_id'    => '',
				'auth'             => '',
				'signed_error'     => '',
				'last_error'       => '',
				'last_error_data'  => array(),
				'activation_error' => '',
				'last_attempt_at'  => $this->time(),
				'clock_offset'     => 0,
				'fail_count'       => 0,
				'reported_version' => '',
				// Deactivated here by an administrator: locked until activated again.
				'ever_activated'   => false,
			)
		);
		$this->state = null;
		$this->log( 'license.deactivated', array( 'freed' => $freed ) );
		$this->changed( $before );
		return array( 'freed' => $freed ) + $this->summary();
	}

	/** Uninstall: frees the place if the server answers in time, then removes everything. */
	public function forget(): void {
		if ( '' !== $this->store->all()['activation_id'] ) {
			$this->deactivate( self::UNINSTALL_TIMEOUT );
		}
		$this->store->delete();
		$this->state = null;
	}

	/**
	 * What the Licenza panel, the REST API and WP-CLI show. Never the key, only its last
	 * 4 characters, and never the installation key.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		$data    = $this->store->all();
		$state   = $this->state();
		$license = $state->auth->license ?? null;
		$text    = static fn( $value ): ?string => is_string( $value ) ? $value : null;
		$iso     = static fn( int $at ): ?string => $at > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $at ) : null;
		$signed  = $this->verified( (string) $data['signed_error'] );
		$errdata = is_array( $data['last_error_data'] ) ? $data['last_error_data'] : array();
		$actdata = is_array( $data['activation_error_data'] ) ? $data['activation_error_data'] : array();
		return array(
			'configured'        => $this->is_configured(),
			'server'            => $this->server()->host(),
			'keys_source'       => $this->server()->source,
			'product'           => self::PRODUCT,
			'status'            => $state->status,
			'status_label'      => AccessMatrix::label( $state->status ),
			'can_write'         => $state->access['write'],
			'locked'            => $this->is_locked(),
			'can_update'        => $state->access['update'],
			'read_only_reason'  => $this->read_only_reason(),
			'type'              => $text( $license->type ?? null ),
			'plan'              => $text( $license->plan ?? null ),
			'expires_at'        => $text( $license->expires_at ?? null ),
			'updates_until'     => $text( $license->updates_until ?? null ),
			'support_until'     => $text( $license->support_until ?? null ),
			'domain'            => $text( $state->auth->activation->domain ?? null ),
			'environment'       => $text( $state->auth->activation->environment ?? null ),
			'site_domain'       => LicenseState::domain_of( $this->home() ),
			'activated_domain'  => $text( $signed->error->activated_domain ?? null ),
			'activated'         => '' !== $data['activation_id'],
			'has_key'           => '' !== $data['key'],
			'key_hint'          => (string) $data['key_hint'],
			'key_unreadable'    => '' !== $data['key'] && null === $this->store->key(),
			'last_ok_at'        => $iso( (int) $data['last_ok_at'] ),
			'last_attempt_at'   => $iso( (int) $data['last_attempt_at'] ),
			'next_check_at'     => null === $this->next_check_at() ? null : $iso( (int) $this->next_check_at() ),
			'last_error'        => (string) $data['last_error'],
			'last_error_text'   => self::error_text( (string) $data['last_error'], $errdata ),
			'activation_error'      => (string) $data['activation_error'],
			'activation_error_text' => self::error_text( (string) $data['activation_error'], $actdata ),
			// The sites holding the places of a full licence (activation_limit_reached).
			'sites'             => array_values( array_map( 'strval', (array) ( $errdata['sites'] ?? $actdata['sites'] ?? array() ) ) ),
			'grace_until'       => null !== $state->grace_until ? $iso( $state->grace_until ) : null,
			'revalidate_hours'  => LicenseState::revalidate_hours( $state->auth ),
			// The administrator's band, so the app updates it without reloading.
			'notice'            => $this->notice( true ),
		);
	}

	// ------------------------------------------------------------------ inside

	/**
	 * activate on the server and, on a signed answer, keep it.
	 *
	 * @return true|\WP_Error
	 */
	private function connect( string $key ) {
		$pair   = Signature::new_key_pair();
		$answer = $this->client->activate(
			array(
				'product'           => self::PRODUCT,
				'license_key'       => $key,
				'client_public_key' => $pair['public'],
				'site'              => $this->site(),
				'versions'          => $this->versions(),
			)
		);

		if ( ! $answer->is_authorization() ) {
			$error = $this->activation_error( $answer );
			$sites = (array) ( $error->get_error_data()['sites'] ?? array() );
			// Kept for the panel (the sites of a full licence). The licence in use, if any,
			// is not touched: a refused activation is not a failed check.
			$this->store->update(
				array(
					'activation_error'      => (string) $error->get_error_data()['answer'],
					'activation_error_data' => $sites ? array( 'sites' => $sites ) : array(),
				)
			);
			return $error;
		}

		// The previous activation of this site, if any, gives its place back.
		$before = $this->store->all();
		$secret = $this->store->secret();
		$new_id = is_string( $answer->data->activation->id ?? null ) ? $answer->data->activation->id : '';
		if ( '' !== $before['activation_id'] && $new_id !== $before['activation_id'] && null !== $secret ) {
			$this->client->signed( 'deactivate', (string) $before['activation_id'], $secret, null, (int) $before['clock_offset'], self::UNINSTALL_TIMEOUT );
		}

		$this->store->update(
			$this->store->seal_key( $key ) + array(
				// Suspended or revoked: the server answers without an activation.
				'secret'           => '' === $new_id ? '' : $this->store->seal_secret( $pair['secret'] ),
				'public_key'       => '' === $new_id ? '' : $pair['public'],
				'activation_id'    => $new_id,
				'auth'             => $answer->json(),
				'signed_error'     => '',
				'last_attempt_at'  => $this->time(),
				'last_ok_at'       => $this->time(),
				'last_error'            => '',
				'last_error_data'       => array(),
				'activation_error'      => '',
				'activation_error_data' => array(),
				'clock_offset'          => 0,
				'fail_count'            => 0,
				'max_seen_time'         => $this->seen( $answer ),
				'reported_version'      => $this->plugin_version(),
			)
			// Suspended or revoked without an activation: a site never activated stays locked.
			+ ( '' === $new_id ? array() : array( 'ever_activated' => true ) )
		);
		$this->state = null;
		return true;
	}

	/** The server forgot this activation: activate again with the saved key. */
	private function reactivate(): void {
		$key = $this->store->key();
		if ( null === $key ) {
			$this->failed( 'key_unreadable' );
			return;
		}
		$result = $this->connect( $key );
		if ( true === $result ) {
			$this->log( 'license.reactivated', array( 'status' => $this->get_status() ) );
			return;
		}
		$code = (string) $result->get_error_data()['answer'];
		if ( 'activation_limit_reached' === $code ) {
			// A signed «no place left»: this site has no activation any more. (An unsigned
			// «invalid licence» proves nothing, so it only counts as a failed check.)
			$this->store->update(
				array(
					'secret'          => '',
					'public_key'      => '',
					'activation_id'   => '',
					'auth'            => '',
					'signed_error'    => '',
					'last_attempt_at' => $this->time(),
					'last_error'      => $code,
					'last_error_data' => (array) $this->store->all()['activation_error_data'],
				)
			);
			return;
		}
		$this->failed( $code );
	}

	/** @param array<string, mixed> $data */
	private function check( string $endpoint, array $data, string $secret ): ServerAnswer {
		$body = array(
			'product'  => self::PRODUCT,
			'site'     => array( 'url' => $this->home() ),
			'versions' => $this->versions(),
		);
		if ( 'validate' === $endpoint ) {
			$auth = $this->verified( (string) $data['auth'] );
			$seq  = $auth->activation->seq ?? 0;
			$body = array( 'seq' => is_int( $seq ) ? $seq : 0 ) + $body;
		}
		return $this->client->signed( $endpoint, (string) $data['activation_id'], $secret, $body, (int) $data['clock_offset'] );
	}

	private function keep( ServerAnswer $answer ): void {
		$this->store->update(
			array(
				'auth'             => $answer->json(),
				'signed_error'     => '',
				'last_attempt_at'  => $this->time(),
				'last_ok_at'       => $this->time(),
				'last_error'       => '',
				'last_error_data'  => array(),
				'fail_count'       => 0,
				'max_seen_time'    => $this->seen( $answer ),
				'reported_version' => $this->plugin_version(),
			)
		);
	}

	/** The check did not end with a signed answer: the last authorization stays. */
	private function failed( string $code ): void {
		$this->store->update(
			array(
				'last_attempt_at' => $this->time(),
				'last_error'      => '' === $code ? 'error' : $code,
				'last_error_data' => array(),
				'fail_count'      => (int) $this->store->all()['fail_count'] + 1,
				'max_seen_time'   => $this->seen(),
			)
		);
		$this->state = null;
	}

	/** A stored answer, only if it still carries a valid signature. */
	private function verified( string $json ): ?\stdClass {
		if ( '' === $json ) {
			return null;
		}
		$data = json_decode( $json, false, 32 );
		if ( ! $data instanceof \stdClass ) {
			return null;
		}
		try {
			if ( null === Signature::verify_answer( $data, $this->server()->public_keys ) ) {
				return null;
			}
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
		return self::for_this_product( $data ) ? $data : null;
	}

	/**
	 * Every product of the family trusts the same server keys: an authorization of the
	 * other plugin, copied into this option, must not count (security fix NM-02). Servers
	 * before 0.6.1 do not write the product: those answers are still accepted.
	 */
	public static function for_this_product( \stdClass $data ): bool {
		$product = $data->license->product ?? null;
		return null === $product || self::PRODUCT === $product;
	}

	private function activation_error( ServerAnswer $answer ): \WP_Error {
		$data = array( 'answer' => $answer->code );
		switch ( $answer->code ) {
			case 'invalid_license':
				return self::error( 'bkng_license_invalid', __( 'Chiave di licenza non valida (o di un altro prodotto).', 'booking-manager' ), 400, $data );
			case 'activation_limit_reached':
				$sites = array();
				foreach ( (array) ( $answer->data->error->activations ?? array() ) as $activation ) {
					if ( is_string( $activation->domain ?? null ) ) {
						$sites[] = $activation->domain . ( is_string( $activation->path ?? null ) && '/' !== $activation->path ? $activation->path : '' );
					}
				}
				$sites = array_values( array_unique( $sites ) );
				return self::error( 'bkng_license_limit', self::error_text( 'activation_limit_reached', array( 'sites' => $sites ) ), 409, $data + array( 'sites' => $sites ) );
			case 'rate_limited':
				return self::error( 'bkng_license_busy', __( 'Troppi tentativi: riprova tra qualche minuto.', 'booking-manager' ), 429, $data );
			case 'maintenance':
				return self::error( 'bkng_license_unavailable', self::error_text( 'maintenance' ), 503, $data );
		}
		if ( ServerAnswer::UNAVAILABLE === $answer->kind ) {
			return self::error( 'bkng_license_unavailable', __( 'Il server delle licenze non risponde. Riprova più tardi.', 'booking-manager' ), 502, $data );
		}
		/* translators: %s: error code from the licence server. */
		return self::error( 'bkng_license_refused', sprintf( __( 'Il server delle licenze ha rifiutato la richiesta (%s).', 'booking-manager' ), $answer->code ), 400, $data );
	}

	/** @param array<string, mixed> $data */
	private static function error( string $code, string $message, int $status, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) + $data + array( 'answer' => '' ) );
	}

	/** Audit line and bkng_license_status_changed when the state moved. */
	private function changed( string $before ): void {
		$this->state = null;
		$after       = $this->get_status();
		if ( $after === $before ) {
			return;
		}
		$this->log(
			'license.status_changed',
			array(
				'from' => $before,
				'to'   => $after,
			)
		);
		if ( function_exists( 'do_action' ) ) {
			/**
			 * Fires when the licence state of this site changes.
			 *
			 * @param string $after  new state (AccessMatrix::states())
			 * @param string $before previous state
			 */
			do_action( self::STATUS_CHANGED_HOOK, $after, $before );
		}
	}

	/** @param array<string, mixed> $new */
	private function log( string $action, array $new ): void {
		if ( null === $this->audit ) {
			return;
		}
		$data = $this->store->all();
		$this->audit->log(
			array(
				'action'      => $action,
				'entity_type' => 'license',
				'new'         => $new + array(
					'key_hint'   => (string) $data['key_hint'],
					'activation' => (string) $data['activation_id'],
				),
			)
		);
	}

	// ------------------------------------------------------------------ the site (overridden in tests)

	protected function time(): int {
		return time();
	}

	protected function home(): string {
		return home_url();
	}

	/** @return array{url: string, environment: string, multisite: bool, blog_id: int} */
	protected function site(): array {
		return array(
			'url'         => $this->home(),
			'environment' => wp_get_environment_type(),
			'multisite'   => is_multisite(),
			'blog_id'     => get_current_blog_id(),
		);
	}

	protected function plugin_version(): string {
		return UpdateChecker::installed();
	}

	/** @return array{plugin: string, wp: string, php: string} */
	protected function versions(): array {
		return array(
			'plugin' => $this->plugin_version(),
			'wp'     => (string) preg_replace( '/[^0-9A-Za-z.+_~-]/', '', (string) get_bloginfo( 'version' ) ),
			'php'    => (string) preg_replace( '/[^0-9A-Za-z.+_~-]/', '', PHP_VERSION ),
		);
	}

	/** A date as the site writes it, in its timezone. */
	protected function date_text( string $iso ): string {
		$ts = strtotime( $iso );
		return false === $ts ? $iso : wp_date( (string) get_option( 'date_format', 'j F Y' ), $ts );
	}
}
