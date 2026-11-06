<?php
/**
 * The «WhatsApp» section, like Night Manager's: the switch and the country code, the
 * connection to the provider, the texts of the messages with their approval, the test
 * send, the log with «Invia di nuovo», and Meta's delivery updates.
 *
 * Texts: each message has a default text, editable with {variables}. Saving a new text
 * sends it for approval as a new template version (bm_guest_confirmed_v2…): an approved text
 * cannot be changed in place, so the previous approved version keeps being used until
 * the new one is approved. Approvals are checked every hour and with «Aggiorna lo stato
 * delle approvazioni».
 *
 * Credentials are encrypted, never returned by the API (secret fields only say whether
 * they are set) and never logged.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\WhatsApp;

use BookingManager\Audit\AuditLogger;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Core\Clock;
use BookingManager\Core\Installer;
use BookingManager\Core\Validation;
use BookingManager\Notifications\Channels\WhatsAppChannel;
use BookingManager\Notifications\NotificationRepository;
use BookingManager\Notifications\NotificationService;
use BookingManager\Notifications\SendResult;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Rest\PublicController;

defined( 'ABSPATH' ) || exit;

class WhatsAppService {

	/** Tries with the next version name when the provider already has one. */
	private const NAME_TRIES = 5;

	public function __construct(
		private WhatsAppSettings $settings,
		private NotificationService $notifications,
		private NotificationRepository $messages,
		private BookingRepository $bookings,
		private PropertyRepository $properties,
		private AuditLogger $audit,
		private Clock $clock
	) {}

	public function hooks(): void {
		// Every hour: the texts waiting for approval are checked with the provider.
		add_action( Installer::HOURLY_HOOK, array( $this, 'refresh_all' ) );
	}

	/* ========================================================== settings */

	/**
	 * Everything the section shows (never a secret).
	 *
	 * @return array<string, mixed>
	 */
	public function view(): array {
		$variables = array();
		foreach ( WhatsAppTemplates::variables() as $tag => $label ) {
			$variables[] = array(
				'tag'   => '{' . $tag . '}',
				'label' => $label,
			);
		}
		return array(
			'settings'   => $this->settings_view(),
			'templates'  => $this->templates(),
			'variables'  => $variables,
			'max_length' => WhatsAppTemplates::MAX_LENGTH,
		);
	}

	/** @return array<string, mixed> */
	public function settings_view(): array {
		$config    = $this->settings->config();
		$provider  = $this->settings->provider_for( $config );
		$creds     = $provider ? ( $this->settings->credentials( $config ) ?? array() ) : array();
		$connected = $this->settings->connected( $config );
		$fields    = array();
		foreach ( $provider ? $provider->fields() : array() as $field ) {
			$value                     = (string) ( $creds[ $field['name'] ] ?? '' );
			$fields[ $field['name'] ] = $field['secret'] ? ( '' !== $value ) : $value;
		}
		return array(
			'provider'             => $provider ? $provider->id() : '',
			'provider_label'       => $provider ? $provider->label() : '',
			'connected'            => $connected,
			// Credentials no longer readable (the site's keys changed): connect again.
			'unreadable'           => $provider && '' !== (string) $config['account'] && null === $this->settings->credentials( $config ),
			'account'              => (string) $config['account'],
			'active'               => (bool) $config['active'] && $connected,
			'country_code'         => (string) $config['country_code'],
			'credentials'          => $fields,
			'webhook_url'          => rest_url( PublicController::REST_NAMESPACE . '/whatsapp/webhook' ),
			'verify_token'         => (string) ( $creds['verify_token'] ?? '' ),
			'encryption_available' => true,
			'next_run'             => self::next( Installer::NOTIFICATIONS_HOOK ),
			'providers'            => array_values(
				array_map(
					static fn( $p ) => array(
						'id'     => $p->id(),
						'label'  => $p->label(),
						'fields' => $p->fields(),
					),
					$this->settings->providers()
				)
			),
		);
	}

	private static function next( string $hook ): ?string {
		$next = wp_next_scheduled( $hook );
		return $next ? gmdate( 'c', (int) $next ) : null;
	}

	/**
	 * The switch and the default country code.
	 *
	 * @param array<string, mixed> $input active?, country_code?
	 * @return array<string, mixed>|\WP_Error the section
	 */
	public function save_settings( array $input ): array|\WP_Error {
		$config = $this->settings->config();
		$before = array(
			'active'       => (bool) $config['active'],
			'country_code' => (string) $config['country_code'],
		);
		if ( array_key_exists( 'country_code', $input ) ) {
			$code = ltrim( trim( (string) $input['country_code'] ), '+' );
			if ( ! WhatsAppSettings::valid_country_code( $code ) ) {
				return Validation::error( array( 'country_code' => __( 'Prefisso internazionale non valido (per l’Italia 39).', 'booking-manager' ) ) );
			}
			$config['country_code'] = $code;
		}
		if ( array_key_exists( 'active', $input ) ) {
			$active = (bool) rest_sanitize_boolean( $input['active'] );
			if ( $active && ! $this->settings->connected( $config ) ) {
				return new \WP_Error( 'bkng_whatsapp_not_connected', __( 'Prima collega WhatsApp (scheda «Collegamento»).', 'booking-manager' ), array( 'status' => 409 ) );
			}
			$config['active'] = $active;
		}
		$this->settings->save( $config );
		$after = array(
			'active'       => (bool) $config['active'],
			'country_code' => (string) $config['country_code'],
		);
		if ( $after !== $before ) {
			$this->audit->log(
				array(
					'action'      => 'whatsapp.settings_updated',
					'entity_type' => 'settings',
					'old'         => $before,
					'new'         => $after,
				)
			);
		}
		return $this->view();
	}

	/**
	 * Connects (or updates) the provider account. Secret fields left empty keep their
	 * saved value. The credentials are checked with the provider first; then every text
	 * not yet approved by this account is sent for approval.
	 *
	 * @param array<string, mixed> $input provider + the provider's fields
	 * @return array<string, mixed>|\WP_Error the section
	 */
	public function connect( array $input ): array|\WP_Error {
		$config   = $this->settings->config();
		$provider = $this->settings->providers()[ (string) ( $input['provider'] ?? '' ) ] ?? null;
		if ( ! $provider ) {
			return Validation::error( array( 'provider' => __( 'Scegli il servizio WhatsApp.', 'booking-manager' ) ) );
		}
		$same     = $provider->id() === (string) $config['provider'];
		$previous = $same ? ( $this->settings->credentials( $config ) ?? array() ) : array();
		$next     = array();
		$errors   = array();
		foreach ( $provider->fields() as $field ) {
			$name  = $field['name'];
			$value = array_key_exists( $name, $input ) ? trim( sanitize_text_field( (string) $input[ $name ] ) ) : '';
			if ( '' === $value && $field['secret'] ) {
				$value = (string) ( $previous[ $name ] ?? '' );
			}
			if ( '' === $value && $field['required'] ) {
				$errors[ $name ] = __( 'Campo obbligatorio.', 'booking-manager' );
			}
			$next[ $name ] = mb_substr( $value, 0, 1000 );
		}
		if ( $errors ) {
			return Validation::error( $errors );
		}
		$checked = $provider->check( $next );
		if ( is_wp_error( $checked ) ) {
			return new \WP_Error( $checked->get_error_code(), $checked->get_error_message(), array( 'status' => 422 ) );
		}
		$next['verify_token'] = (string) ( $previous['verify_token'] ?? '' ) ?: wp_generate_password( 32, false, false );

		// Another provider or another business account: its approvals do not carry over.
		$identity = $provider->id() . '|' . (string) ( $next['business_account_id'] ?? '' );
		if ( (string) $config['identity'] !== $identity ) {
			$config['templates'] = array();
		}
		$config['identity']    = $identity;
		$config['provider']    = $provider->id();
		$config['account']     = mb_substr( (string) $checked['account'], 0, 190 );
		$config['credentials'] = $this->settings->seal( $next );
		$this->settings->save( $config );
		$this->audit->log(
			array(
				'action'      => 'whatsapp.connected',
				'entity_type' => 'settings',
				'new'         => array(
					'provider' => $provider->id(),
					'account'  => $config['account'],
				),
			)
		);
		foreach ( WhatsAppTemplates::KEYS as $key ) {
			if ( 'none' === $this->template( $key )['approval']['status'] ) {
				$this->submit( $key );
			}
		}
		return $this->view();
	}

	/**
	 * Forgets the credentials and switches the messages off. The texts and their
	 * approvals stay: connecting the same account again finds them.
	 *
	 * @return array<string, mixed> the section
	 */
	public function disconnect(): array {
		$config = $this->settings->config();
		$this->settings->save(
			array_merge(
				$config,
				array(
					'active'      => false,
					'account'     => '',
					'credentials' => '',
				)
			)
		);
		$this->audit->log(
			array(
				'action'      => 'whatsapp.disconnected',
				'entity_type' => 'settings',
				'old'         => array( 'provider' => (string) $config['provider'] ),
			)
		);
		return $this->view();
	}

	/* ========================================================== texts */

	/**
	 * The text in use and its approval.
	 *
	 * @return array<string, mixed>
	 */
	public function template( string $key ): array {
		return self::describe( $key, $this->settings->config() );
	}

	/**
	 * A message as the section shows it (unit-tested).
	 *
	 * A text counts as sent for approval only with the same button: a button switched on or
	 * off, or the site's address changed, means a new approval.
	 *
	 * @param array<string, mixed> $config WhatsAppSettings::config()
	 * @return array<string, mixed>
	 */
	public static function describe( string $key, array $config ): array {
		$default  = WhatsAppTemplates::all()[ $key ];
		$custom   = (string) ( $config['texts'][ $key ]['body'] ?? '' );
		$body     = '' !== $custom ? $custom : $default['body'];
		$button   = WhatsAppTemplates::has_button( $config, $key );
		$state    = (array) ( $config['templates'][ $key ] ?? array() );
		$latest   = (array) ( $state['latest'] ?? array() );
		$approved = (array) ( $state['approved'] ?? array() );
		$current  = $latest && ( $latest['text'] ?? '' ) === $body && (string) ( $latest['button'] ?? '' ) === self::button_url( $button );
		return array(
			'key'          => $key,
			'label'        => $default['label'],
			'when'         => $default['when'],
			'body'         => $body,
			'default_body' => $default['body'],
			'button'       => $button,
			'button_text'  => WhatsAppTemplates::button_text( $key ),
			'is_active'    => WhatsAppTemplates::is_active( $config, $key ),
			'customized'   => '' !== $custom || $button !== $default['button'],
			'approval'     => array(
				// Status of the current text: none (never sent for approval), pending,
				// approved, rejected, paused, disabled.
				'status'        => $current ? (string) $latest['status'] : 'none',
				'reason'        => $current ? (string) ( $latest['reason'] ?? '' ) : '',
				'in_use'        => $approved ? (string) $approved['text'] : null,
				'in_use_button' => $approved && '' !== (string) ( $approved['button'] ?? '' ),
				'approved_at'   => $approved ? Clock::iso( (string) ( $approved['at'] ?? '' ) ) : null,
			),
		);
	}

	/** The fixed part of the button's link, "" without a button. */
	public static function button_url( bool $button ): string {
		return $button ? WhatsAppVariables::button_base( home_url( '/' ) ) : '';
	}

	/** @return array<int, array<string, mixed>> */
	public function templates(): array {
		$config = $this->settings->config();
		return array_map( static fn( $key ) => self::describe( $key, $config ), WhatsAppTemplates::KEYS );
	}

	/**
	 * Changes a message: its text, whether it is sent, or back to the default text.
	 *
	 * @param array<string, mixed> $input body?, button?, is_active?, reset?
	 * @return array<string, mixed>|\WP_Error the message (with warning when the approval
	 *                                         request could not be sent)
	 */
	public function save_template( string $key, array $input ): array|\WP_Error {
		if ( ! WhatsAppTemplates::exists( $key ) ) {
			return new \WP_Error( 'bkng_not_found', __( 'Messaggio sconosciuto.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$default = WhatsAppTemplates::all()[ $key ];
		$config  = $this->settings->config();
		$before  = self::describe( $key, $config );
		$body    = (string) ( $config['texts'][ $key ]['body'] ?? '' );
		$active  = (bool) $before['is_active'];
		$button  = (bool) $before['button'];
		if ( ! empty( $input['reset'] ) ) {
			$body   = '';
			$button = $default['button'];
		}
		if ( array_key_exists( 'button', $input ) ) {
			$button = (bool) rest_sanitize_boolean( $input['button'] );
		}
		if ( array_key_exists( 'body', $input ) ) {
			$body     = WhatsAppTemplates::normalize( sanitize_textarea_field( (string) $input['body'] ) );
			$problems = WhatsAppTemplates::problems( $body );
			if ( $problems ) {
				return Validation::error( array( 'body' => implode( ' ', $problems ) ) );
			}
		}
		if ( array_key_exists( 'is_active', $input ) ) {
			$active = (bool) rest_sanitize_boolean( $input['is_active'] );
		}
		// Same as the default: keep following the default.
		if ( $body === $default['body'] ) {
			$body = '';
		}
		$config['texts'][ $key ] = array(
			'body'      => $body,
			'is_active' => $active,
		);
		if ( $button !== $default['button'] ) {
			$config['texts'][ $key ]['button'] = $button;
		}
		$this->settings->save( $config );
		$this->audit->log(
			array(
				'action'      => 'whatsapp.template_updated',
				'entity_type' => 'settings',
				'old'         => array(
					'key'        => $key,
					'is_active'  => $before['is_active'],
					'customized' => $before['customized'],
				),
				'new'         => array(
					'key'        => $key,
					'is_active'  => $active,
					'customized' => '' !== $body || $button !== $default['button'],
				),
			)
		);
		$next = $this->template( $key );
		if ( 'none' === $next['approval']['status'] ) {
			$sent = $this->submit( $key );
			if ( is_wp_error( $sent ) ) {
				return $this->template( $key ) + array( 'warning' => $sent->get_error_message() );
			}
			$next = $this->template( $key );
		}
		return $next;
	}

	/**
	 * Sends the current text for approval as a new version (the previous approved one
	 * stays in use meanwhile).
	 */
	public function submit( string $key ): bool|\WP_Error {
		$config   = $this->settings->config();
		$provider = $this->settings->provider_for( $config );
		$creds    = $this->settings->credentials( $config );
		if ( ! $provider || '' === (string) $config['account'] || null === $creds ) {
			return new \WP_Error( 'bkng_whatsapp_not_connected', __( 'Testo salvato. Sarà inviato per l’approvazione quando WhatsApp sarà collegato.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$view     = self::describe( $key, $config );
		$text     = $view['body'];
		$url      = self::button_url( (bool) $view['button'] );
		$numbered = WhatsAppTemplates::numbered( $text );
		$state    = (array) ( $config['templates'][ $key ] ?? array() );
		$version  = (int) ( $state['version'] ?? 0 );
		// Example values for Meta's reviewers: always the made-up booking, never a guest's.
		$sample   = WhatsAppVariables::sample( $this->first_property() );
		$examples = WhatsAppTemplates::params( $numbered['params'], $sample );
		$button   = '' === $url ? null : array(
			'text'    => WhatsAppTemplates::button_text( $key ),
			'url'     => $url . '{{1}}',
			'example' => $url . WhatsAppVariables::button_suffix( $sample['link'], $url, home_url( '/' ) ),
		);
		for ( $try = 0; $try < self::NAME_TRIES; $try++ ) {
			++$version;
			$name   = self::template_name( $key, $version );
			$result = $provider->submit_template( $creds, $name, (string) $config['language'], $numbered['body'], $examples, $button );
			// The name is taken (a previous install on the same account): the next version.
			if ( ! is_wp_error( $result ) || empty( $result->get_error_data()['duplicate'] ) ) {
				break;
			}
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$latest           = array(
			'name'   => $name,
			'text'   => $text,
			'params' => $numbered['params'],
			'button' => $url,
			'status' => (string) $result['status'],
			'reason' => (string) $result['reason'],
			'at'     => $this->clock->now_mysql(),
		);
		$state['version'] = $version;
		$state['latest']  = $latest;
		if ( 'approved' === $latest['status'] ) {
			$state['approved'] = $latest;
		}
		$config                      = $this->settings->config();
		$config['templates'][ $key ] = $state;
		$this->settings->save( $config );
		return true;
	}

	/** "bm_guest_confirmed_v3": Meta wants lowercase letters, digits and "_" (unit-tested). */
	public static function template_name( string $key, int $version ): string {
		return sprintf( 'bm_%s_v%d', $key, $version );
	}

	/**
	 * Asks the provider about the texts waiting for approval.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error the messages
	 */
	public function refresh(): array|\WP_Error {
		$config   = $this->settings->config();
		$provider = $this->settings->provider_for( $config );
		$creds    = $this->settings->credentials( $config );
		if ( ! $provider || '' === (string) $config['account'] || null === $creds ) {
			return new \WP_Error( 'bkng_whatsapp_not_connected', __( 'Prima collega WhatsApp (scheda «Collegamento»).', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$changed = false;
		foreach ( $config['templates'] as $key => $state ) {
			$latest = (array) ( $state['latest'] ?? array() );
			if ( ! $latest || in_array( $latest['status'], array( 'rejected', 'disabled' ), true ) ) {
				continue;
			}
			$now = $provider->template_status( $creds, (string) $latest['name'], (string) $config['language'] );
			if ( is_wp_error( $now ) ) {
				continue;
			}
			$next = self::apply_status( $state, $now, $this->clock->now_mysql() );
			if ( $next !== $state ) {
				$config['templates'][ $key ] = $next;
				$changed                     = true;
			}
		}
		if ( $changed ) {
			$this->settings->save( $config );
		}
		return $this->templates();
	}

	/**
	 * A text's state after the provider's answer (unit-tested): approved becomes the
	 * version in use; the version in use paused or disabled is no longer usable.
	 *
	 * @param array<string, mixed>                $state
	 * @param array{status: string, reason: string} $now
	 * @return array<string, mixed>
	 */
	public static function apply_status( array $state, array $now, string $at ): array {
		$latest = (array) ( $state['latest'] ?? array() );
		if ( ! $latest || ( $now['status'] === $latest['status'] && $now['reason'] === (string) ( $latest['reason'] ?? '' ) ) ) {
			return $state;
		}
		$latest['status'] = $now['status'];
		$latest['reason'] = $now['reason'];
		$state['latest']  = $latest;
		if ( 'approved' === $now['status'] ) {
			$state['approved'] = array_merge( $latest, array( 'at' => $at ) );
		} elseif ( ! empty( $state['approved'] ) && $state['approved']['name'] === $latest['name'] ) {
			unset( $state['approved'] );
		}
		return $state;
	}

	/**
	 * WP-Cron, every hour: texts never sent for approval in their current form (a default
	 * text changed by an update of the plugin) are sent; the pending ones are checked.
	 */
	public function refresh_all(): void {
		if ( ! $this->settings->connected() ) {
			return;
		}
		$config = $this->settings->config();
		foreach ( $this->templates() as $template ) {
			$state = $config['templates'][ $template['key'] ] ?? null;
			// A message that failed to be sent once waits for somebody to save it.
			if ( ! $template['is_active'] || 'none' !== $template['approval']['status'] || ( null !== $state && empty( $state['latest'] ) ) ) {
				continue;
			}
			if ( is_wp_error( $this->submit( $template['key'] ) ) && null === $state ) {
				$config                                  = $this->settings->config();
				$config['templates'][ $template['key'] ] = array( 'failed_at' => $this->clock->now_mysql() );
				$this->settings->save( $config );
			}
		}
		foreach ( $this->settings->config()['templates'] as $state ) {
			if ( in_array( $state['latest']['status'] ?? '', array( 'pending', 'approved', 'paused' ), true ) ) {
				$this->refresh();
				return;
			}
		}
	}

	/* ========================================================== preview, test */

	/**
	 * The message as the guest would read it, with a made-up booking.
	 *
	 * @return array{text: string, button: string|null, sample: bool, problems: string[]}|\WP_Error
	 */
	public function preview( string $key, ?string $body = null, ?bool $button = null ): array|\WP_Error {
		if ( ! WhatsAppTemplates::exists( $key ) ) {
			return new \WP_Error( 'bkng_not_found', __( 'Messaggio sconosciuto.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$view   = $this->template( $key );
		$text   = null === $body ? $view['body'] : WhatsAppTemplates::normalize( sanitize_textarea_field( $body ) );
		$button = $button ?? $view['button'];
		return array(
			'text'     => WhatsAppTemplates::fill( $text, WhatsAppVariables::sample( $this->first_property() ) ),
			'button'   => $button ? WhatsAppTemplates::button_text( $key ) : null,
			'sample'   => true,
			'problems' => WhatsAppTemplates::problems( $text ),
		);
	}

	/**
	 * Sends the approved message, filled with the made-up booking, to a number (usually the
	 * operator's own phone).
	 *
	 * @return array{sent_to: string, id: string}|\WP_Error
	 */
	public function send_test( string $key, string $to ): array|\WP_Error {
		if ( ! WhatsAppTemplates::exists( $key ) ) {
			return new \WP_Error( 'bkng_not_found', __( 'Messaggio sconosciuto.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$config   = $this->settings->config();
		$provider = $this->settings->provider_for( $config );
		$creds    = $this->settings->credentials( $config );
		if ( ! $provider || '' === (string) $config['account'] || null === $creds ) {
			return new \WP_Error( 'bkng_whatsapp_not_connected', __( 'Prima collega WhatsApp (scheda «Collegamento»).', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$number = WhatsAppSettings::wa_phone( $to, (string) $config['country_code'] );
		if ( null === $number ) {
			return Validation::error( array( 'to' => __( 'Numero non valido: scrivilo con il prefisso, per esempio +39 333 1234567.', 'booking-manager' ) ) );
		}
		$approved = (array) ( $config['templates'][ $key ]['approved'] ?? array() );
		if ( ! $approved ) {
			return new \WP_Error( 'bkng_whatsapp_not_approved', __( 'Il testo del messaggio non è ancora approvato da WhatsApp: la prova si può fare dopo l’approvazione.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$values = WhatsAppVariables::sample( $this->first_property() );
		$button = WhatsAppChannel::button_param( $approved, $values );
		if ( false === $button ) {
			return new \WP_Error( 'bkng_whatsapp_failed', WhatsAppChannel::button_error(), array( 'status' => 409 ) );
		}
		$result = $provider->send( $creds, $number, (string) $approved['name'], (string) $config['language'], WhatsAppTemplates::params( (array) $approved['params'], $values ), $button );
		$this->audit->log(
			array(
				'action'      => 'whatsapp.test_sent',
				'entity_type' => 'settings',
				'new'         => array(
					'key'     => $key,
					'to'      => self::mask( $number ),
					'outcome' => $result->outcome,
				),
			)
		);
		if ( SendResult::SENT !== $result->outcome ) {
			return new \WP_Error( 'bkng_whatsapp_failed', $result->error, array( 'status' => 502 ) );
		}
		return array(
			'sent_to' => '+' . $number,
			'id'      => $result->id,
		);
	}

	/** @return array<string, mixed> the property whose name and address the examples show */
	private function first_property(): array {
		$all = $this->properties->all( null, false );
		return $all ? (array) $all[0] : array();
	}

	/* ========================================================== log */

	/**
	 * The WhatsApp messages of every property, newest first.
	 *
	 * @param array<string, mixed> $filters status?, page?, per_page?
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function log( array $filters ): array {
		$status = (string) ( $filters['status'] ?? '' );
		$log    = $this->messages->search(
			null,
			array(
				'channel'  => 'whatsapp',
				'status'   => in_array( $status, NotificationRepository::STATUSES, true ) ? $status : '',
				'page'     => (int) ( $filters['page'] ?? 1 ),
				'per_page' => (int) ( $filters['per_page'] ?? 50 ),
			)
		);
		$items  = array();
		foreach ( $log['items'] as $row ) {
			$booking = empty( $row['booking_id'] ) ? null : $this->bookings->find( (int) $row['booking_id'] );
			$items[] = self::format( $row, $booking );
		}
		return array(
			'items' => $items,
			'total' => $log['total'],
		);
	}

	/**
	 * «Invia di nuovo» / «Riprova»: the same message again, now, to the guest's current
	 * number, as a new row of the log.
	 *
	 * @return array<string, mixed>|\WP_Error the new log entry
	 */
	public function resend( int $id ): array|\WP_Error {
		$row = $this->messages->find( $id );
		if ( ! $row || 'whatsapp' !== $row['channel'] ) {
			return new \WP_Error( 'bkng_not_found', __( 'Messaggio non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		if ( ! $this->settings->active() ) {
			return new \WP_Error( 'bkng_whatsapp_not_connected', __( 'I messaggi WhatsApp non sono attivi: collegali e accendili prima.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$new = $this->notifications->resend( $id );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$this->audit->log(
			array(
				'action'      => 'whatsapp.resent',
				'entity_type' => 'notification',
				'entity_id'   => (int) $new['id'],
				'property_id' => (int) $row['property_id'],
				'new'         => array( 'of' => $id ),
			)
		);
		$fresh = $this->messages->find( (int) $new['id'] );
		return $fresh ? self::format( $fresh, $this->bookings->find( (int) $fresh['booking_id'] ) ) : $new;
	}

	/* ========================================================== webhook */

	/** Meta's subscription check: the challenge when the token matches. */
	public function verify_webhook( string $token ): bool {
		$creds = $this->settings->credentials( $this->settings->config() ) ?? array();
		$saved = (string) ( $creds['verify_token'] ?? '' );
		return '' !== $saved && '' !== $token && hash_equals( $saved, $token );
	}

	/**
	 * Delivery updates (sent, delivered, read, failed), signed with the app secret.
	 *
	 * @return int|\WP_Error messages updated
	 */
	public function webhook( string $raw, string $signature ): int|\WP_Error {
		$creds  = $this->settings->credentials( $this->settings->config() ) ?? array();
		$secret = (string) ( $creds['app_secret'] ?? '' );
		if ( ! self::signed( $raw, $signature, $secret ) ) {
			return new \WP_Error( 'bkng_whatsapp_signature', __( 'Firma non valida.', 'booking-manager' ), array( 'status' => 401 ) );
		}
		$payload = json_decode( $raw, true );
		$updated = 0;
		foreach ( self::statuses( is_array( $payload ) ? $payload : array() ) as $status ) {
			$row = $this->messages->find_by_provider_id( 'whatsapp', $status['id'] );
			if ( ! $row || ! self::advances( (string) ( $row['delivery_status'] ?? '' ), $status['status'] ) ) {
				continue;
			}
			$data = array(
				'delivery_status' => $status['status'],
				'delivery_at'     => gmdate( 'Y-m-d H:i:s', $status['timestamp'] ?: time() ),
			);
			if ( 'failed' === $status['status'] ) {
				$data['status'] = NotificationRepository::FAILED;
				/* translators: 1: WhatsApp error code, 2: error description */
				$data['last_error'] = mb_substr( sprintf( __( 'WhatsApp non ha consegnato il messaggio (errore %1$s: %2$s).', 'booking-manager' ), $status['code'], $status['detail'] ), 0, 500 );
			}
			$updated += $this->messages->update( (int) $row['id'], $data ) ? 1 : 0;
		}
		return $updated;
	}

	/** X-Hub-Signature-256 of the raw body with the app secret (unit-tested). */
	public static function signed( string $raw, string $signature, string $secret ): bool {
		return '' !== $secret && str_starts_with( $signature, 'sha256=' ) && hash_equals( hash_hmac( 'sha256', $raw, $secret ), substr( $signature, 7 ) );
	}

	/**
	 * The status updates in a webhook payload (unit-tested).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<int, array{id: string, status: string, timestamp: int, code: string, detail: string}>
	 */
	public static function statuses( array $payload ): array {
		$out = array();
		foreach ( (array) ( $payload['entry'] ?? array() ) as $entry ) {
			foreach ( (array) ( $entry['changes'] ?? array() ) as $change ) {
				foreach ( (array) ( $change['value']['statuses'] ?? array() ) as $status ) {
					$state = (string) ( $status['status'] ?? '' );
					$id    = (string) ( $status['id'] ?? '' );
					if ( '' === $id || ! in_array( $state, array( 'sent', 'delivered', 'read', 'failed' ), true ) ) {
						continue;
					}
					$error = (array) ( $status['errors'][0] ?? array() );
					$out[] = array(
						'id'        => mb_substr( $id, 0, 190 ),
						'status'    => $state,
						'timestamp' => (int) ( $status['timestamp'] ?? 0 ),
						'code'      => (string) ( $error['code'] ?? '?' ),
						'detail'    => (string) ( $error['error_data']['details'] ?? $error['title'] ?? '' ),
					);
				}
			}
		}
		return $out;
	}

	/** Updates only move forward: a late «delivered» never undoes «read» (unit-tested). */
	public static function advances( string $current, string $next ): bool {
		$rank = array(
			'sent'      => 1,
			'delivered' => 2,
			'read'      => 3,
			'failed'    => 4,
		);
		return ( $rank[ $next ] ?? 0 ) > ( $rank[ $current ] ?? 0 );
	}

	/* ========================================================== format */

	/** "393331234567" → "+39•••••••4567" (unit-tested). */
	public static function mask( ?string $phone ): ?string {
		$phone = (string) $phone;
		if ( '' === $phone ) {
			return null;
		}
		$len = strlen( $phone );
		return '+' . substr( $phone, 0, min( 2, $len ) ) . str_repeat( '•', max( 0, $len - 6 ) ) . substr( $phone, max( 2, $len - 4 ) );
	}

	/**
	 * A row of the log as the section shows it (unit-tested).
	 *
	 * @param array<string, mixed>      $m       row of bkng_notifications
	 * @param array<string, mixed>|null $booking
	 * @return array<string, mixed>
	 */
	public static function format( array $m, ?array $booking ): array {
		$name   = $booking ? trim( ( $booking['first_name'] ?? '' ) . ' ' . ( $booking['last_name'] ?? '' ) ) : '';
		$labels = WhatsAppTemplates::all();
		$to     = (string) ( $m['recipient'] ?? '' );
		return array(
			'id'              => (int) $m['id'],
			'kind'            => (string) $m['template_key'],
			'label'           => $labels[ $m['template_key'] ]['label'] ?? (string) $m['template_key'],
			'status'          => (string) $m['status'],
			'delivery_status' => $m['delivery_status'] ?? null,
			'to'              => '' !== $to ? '+' . $to : null,
			'guest'           => '' === $name ? null : $name,
			'property_id'     => (int) $m['property_id'],
			'booking_id'      => empty( $m['booking_id'] ) ? null : (int) $m['booking_id'],
			'booking_code'    => $booking['code'] ?? null,
			'provider_id'     => $m['provider_message_id'] ?? null,
			'attempts'        => (int) $m['attempts'],
			'error'           => $m['last_error'] ?? null,
			'manual'          => null === ( $m['dedupe_key'] ?? null ),
			'send_after'      => NotificationRepository::QUEUED === $m['status'] ? Clock::iso( $m['next_attempt_at'] ?? null ) : null,
			'sent_at'         => Clock::iso( $m['sent_at'] ?? null ),
			'delivery_at'     => Clock::iso( $m['delivery_at'] ?? null ),
			'created_at'      => Clock::iso( $m['created_at'] ?? null ),
		);
	}
}
