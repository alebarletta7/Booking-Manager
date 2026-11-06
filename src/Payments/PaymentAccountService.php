<?php
/**
 * Which provider each property uses and how it is connected.
 *
 * A property has at most one account per provider and mode (test/live) and chooses one
 * of them for its online payments. Only active (verified) accounts can be chosen.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

use BookingManager\Audit\AuditLogger;
use BookingManager\Core\SecretBox;
use BookingManager\Frontend\PublicFrontend;
use BookingManager\Database\Schema;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Payments\Providers\PaymentProvider;
use BookingManager\Rest\RestController;

defined( 'ABSPATH' ) || exit;

class PaymentAccountService {

	public const NONE  = 'none';
	public const MODES = array( 'test', 'live' );

	/** Providers announced for a next release: shown, not selectable. */
	public const COMING = array();

	/**
	 * Credentials that do not tell whose provider account it is: the notification secret,
	 * and secrets that can be rotated for the same app.
	 */
	private const NOT_IDENTITY = array( 'webhook_secret', 'client_secret' );

	public function __construct(
		private PaymentAccountRepository $repo,
		private ProviderRegistry $providers,
		private PropertyRepository $properties,
		private AuditLogger $audit
	) {}

	public function find( int $id ): ?PaymentAccount {
		return $this->repo->find( $id );
	}

	/** The account the property uses for online payments, if it is ready. */
	public function active_online_account( int $property_id ): ?PaymentAccount {
		$property = $this->properties->find( $property_id );
		$provider = (string) ( $property['settings']['payment_provider'] ?? self::NONE );
		if ( self::NONE === $provider || ! $this->providers->get( $provider ) ) {
			return null;
		}
		$account = $this->repo->find_for( $property_id, $provider, (string) ( $property['settings']['payment_mode'] ?? 'test' ) );
		return $account && $account->is_active() ? $account : null;
	}

	/**
	 * Everything the settings screen shows. Secrets are never included.
	 *
	 * @return array<string, mixed>
	 */
	public function overview( int $property_id ): array {
		$property = (array) $this->properties->find( $property_id );
		$rows     = array();
		foreach ( $this->repo->rows_for( $property_id ) as $row ) {
			$rows[ $row['provider'] ][ $row['mode'] ] = $row;
		}
		$providers = array();
		foreach ( $this->providers->online() as $id => $provider ) {
			$accounts = array();
			foreach ( self::MODES as $mode ) {
				$row               = $rows[ $id ][ $mode ] ?? null;
				$account           = $row ? $this->repo->find( (int) $row['id'] ) : null;
				$accounts[ $mode ] = $account ? $this->present_account( $account, $row ) : null;
			}
			$probe       = new PaymentAccount( 0, $property_id, $id, 'test', PaymentAccount::STATUS_INCOMPLETE, array(), array() );
			$providers[] = array(
				'id'           => $id,
				'label'        => $provider->label(),
				'available'    => true,
				'fields'       => $provider->config_fields(),
				'capabilities' => $provider->capabilities( $probe ),
				'accounts'     => $accounts,
			);
		}
		foreach ( self::COMING as $id => $label ) {
			if ( ! isset( $this->providers->online()[ $id ] ) ) {
				$providers[] = array(
					'id'        => $id,
					'label'     => $label,
					'available' => false,
				);
			}
		}
		return array(
			'property_id'       => $property_id,
			'provider'          => (string) ( $property['settings']['payment_provider'] ?? self::NONE ),
			'mode'              => (string) ( $property['settings']['payment_mode'] ?? 'test' ),
			'online_ready'      => null !== $this->active_online_account( $property_id ),
			'providers'         => $providers,
			'dedicated_key'     => SecretBox::has_dedicated_key(),
			'last_checkout_error' => $this->last_checkout_error( $property_id ),
			'shortcode'         => PublicFrontend::shortcode_for( (string) ( $property['slug'] ?? '' ) ),
			'online_payment'    => (string) ( $property['settings']['online_payment'] ?? 'none' ),
		);
	}

	/**
	 * Connects (or reconnects) a provider account: validates the data, checks it with the
	 * provider and registers the notification endpoint.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error the account as presented
	 */
	public function save( int $property_id, string $provider_id, string $mode, array $input ): array|\WP_Error {
		$provider = $this->online_provider( $provider_id, $mode );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$current = $this->repo->find_for( $property_id, $provider_id, $mode )
			?? new PaymentAccount( 0, $property_id, $provider_id, $mode, PaymentAccount::STATUS_INCOMPLETE, array(), array() );

		$config = $provider->configure( $input, $current );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$key_changed = self::identity( $config['credentials'] ) !== self::identity( $current->credentials );
		$credentials = $config['credentials'];
		$settings    = $config['settings'];
		if ( ! $key_changed ) {
			// Same key: keep what the provider gave us (webhook endpoint and its secret).
			$credentials = array_merge( $current->credentials, $credentials );
			$settings    = array_merge( $current->settings, $settings );
		}

		$account = $this->repo->save( $property_id, $provider_id, $mode, PaymentAccount::STATUS_INCOMPLETE, $credentials, $settings, null, false );
		$checked = $provider->verify( $account );
		if ( is_wp_error( $checked ) ) {
			$this->repo->save( $property_id, $provider_id, $mode, PaymentAccount::STATUS_ERROR, null, null, $checked->get_error_message(), false );
			$this->log( 'payment_account.error', $account, array( 'error' => $checked->get_error_message() ) );
			return new \WP_Error( $checked->get_error_code(), $checked->get_error_message(), array( 'status' => 422 ) );
		}
		$settings = array_merge( $settings, $checked );
		$account  = $account->with_settings( $checked );
		if ( $key_changed && $current->id && $current->credentials ) {
			// Only now that the new key works: the old endpoint belongs to the old key's account.
			$provider->remove_webhook( $current );
		}

		$hook = $provider->setup_webhook( $account, self::webhook_url( $account ) );
		if ( is_wp_error( $hook ) && ! $account->is_live() && ! self::publicly_reachable() ) {
			// Test mode on a local site (localhost…): the provider cannot reach us. Usable for
			// trying things out — payments are confirmed by asking the provider directly.
			$message = __( 'Sito locale: il sistema di pagamento non può inviare le notifiche (webhook). In modalità prova i pagamenti vengono confermati chiedendo lo stato al sistema di pagamento, con qualche secondo di ritardo. Per la modalità reale il sito deve essere online.', 'booking-manager' );
			$account = $this->repo->save( $property_id, $provider_id, $mode, PaymentAccount::STATUS_ACTIVE, $credentials, array_merge( $settings, array( 'webhook' => 'local' ) ), $message, true );
			$this->log( 'payment_account.connected', $account, array( 'key' => $settings['key_hint'] ?? null, 'webhook' => 'local' ) );
			return $this->present_account( $account, null );
		}
		if ( is_wp_error( $hook ) ) {
			$message = sprintf(
				/* translators: %s: provider error */
				__( 'Collegamento riuscito ma il webhook non è stato registrato (%s). Senza webhook i pagamenti non vengono confermati: controlla i permessi della chiave o inserisci il segreto del webhook a mano.', 'booking-manager' ),
				$hook->get_error_message()
			);
			$this->repo->save( $property_id, $provider_id, $mode, PaymentAccount::STATUS_INCOMPLETE, null, $settings, $message, true );
			$this->log( 'payment_account.incomplete', $account, array( 'error' => $hook->get_error_message() ) );
			return new \WP_Error( 'bkng_webhook_setup', $message, array( 'status' => 422 ) );
		}
		$credentials = array_merge( $credentials, $hook['credentials'] ?? array() );
		$settings    = array_merge( $settings, $hook['settings'] ?? array() );
		$account     = $this->repo->save( $property_id, $provider_id, $mode, PaymentAccount::STATUS_ACTIVE, $credentials, $settings, null, true );
		$this->log( 'payment_account.connected', $account, array( 'key' => $settings['key_hint'] ?? null, 'webhook' => $settings['webhook'] ?? null ) );
		return $this->present_account( $account, null );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function verify_now( int $property_id, string $provider_id, string $mode ): array|\WP_Error {
		$provider = $this->online_provider( $provider_id, $mode );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$account = $this->repo->find_for( $property_id, $provider_id, $mode );
		if ( ! $account || ! $account->credentials ) {
			return new \WP_Error( 'bkng_not_found', __( 'Questo sistema di pagamento non è ancora collegato.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$checked = $provider->verify( $account );
		if ( is_wp_error( $checked ) ) {
			$this->repo->save( $property_id, $provider_id, $mode, PaymentAccount::STATUS_ERROR, null, null, $checked->get_error_message(), false );
			return new \WP_Error( $checked->get_error_code(), $checked->get_error_message(), array( 'status' => 422 ) );
		}
		$local   = 'local' === ( $account->settings['webhook'] ?? '' ) && ! $account->is_live();
		$status  = 'missing' === self::webhook_state( $account ) && ! $local ? PaymentAccount::STATUS_INCOMPLETE : PaymentAccount::STATUS_ACTIVE;
		$account = $this->repo->save( $property_id, $provider_id, $mode, $status, null, array_merge( $account->settings, $checked ), null, true );
		return $this->present_account( $account, null );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function disconnect( int $property_id, string $provider_id, string $mode ): array|\WP_Error {
		$provider = $this->online_provider( $provider_id, $mode );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$account = $this->repo->find_for( $property_id, $provider_id, $mode );
		if ( ! $account ) {
			return array( 'disconnected' => true );
		}
		$provider->remove_webhook( $account );
		$this->repo->save( $property_id, $provider_id, $mode, PaymentAccount::STATUS_INCOMPLETE, array(), array(), null, false );
		$property = (array) $this->properties->find( $property_id );
		if ( $provider_id === ( $property['settings']['payment_provider'] ?? '' ) && $mode === ( $property['settings']['payment_mode'] ?? '' ) ) {
			$this->set_property( $property_id, self::NONE, $mode );
		}
		$this->log( 'payment_account.disconnected', $account, array() );
		return array( 'disconnected' => true );
	}

	/**
	 * Chooses the provider (or none) and the mode for online payments.
	 *
	 * @return array<string, mixed>|\WP_Error the overview
	 */
	public function select( int $property_id, string $provider_id, string $mode ): array|\WP_Error {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new \WP_Error( 'bkng_validation', __( 'Modalità non valida.', 'booking-manager' ), array( 'status' => 400 ) );
		}
		if ( self::NONE !== $provider_id ) {
			$provider = $this->online_provider( $provider_id, $mode );
			if ( is_wp_error( $provider ) ) {
				return $provider;
			}
			$account = $this->repo->find_for( $property_id, $provider_id, $mode );
			if ( ! $account || ! $account->is_active() ) {
				return new \WP_Error( 'bkng_payment_account_not_ready', __( 'Collega e verifica prima questo sistema di pagamento.', 'booking-manager' ), array( 'status' => 409 ) );
			}
		}
		$before = (array) $this->properties->find( $property_id );
		$this->set_property( $property_id, $provider_id, $mode );
		$this->audit->log(
			array(
				'action'      => 'payment_settings.provider_selected',
				'entity_type' => 'property',
				'entity_id'   => $property_id,
				'property_id' => $property_id,
				'old'         => array(
					'provider' => $before['settings']['payment_provider'] ?? null,
					'mode'     => $before['settings']['payment_mode'] ?? null,
				),
				'new'         => array(
					'provider' => $provider_id,
					'mode'     => $mode,
				),
			)
		);
		return $this->overview( $property_id );
	}

	/**
	 * The provider's answer when a payment page could not be opened recently (shown to
	 * admins only; visitors see a generic message).
	 *
	 * @return array{message: string, at: string}|null
	 */
	private function last_checkout_error( int $property_id ): ?array {
		global $wpdb;
		$t   = Schema::table( 'payments' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT failure_message, created_at FROM {$t} WHERE property_id = %d AND failure_code = 'checkout_error' AND created_at >= %s ORDER BY id DESC LIMIT 1", $property_id, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok  = $row ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE property_id = %d AND external_id IS NOT NULL AND created_at > %s", $property_id, $row['created_at'] ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row && ! $ok ? array(
			'message' => (string) $row['failure_message'],
			'at'      => \BookingManager\Core\Clock::iso( (string) $row['created_at'] ),
		) : null;
	}

	/** Can a provider on the Internet reach this site? (localhost, private networks: no) */
	public static function publicly_reachable(): bool {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.test' ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return true;
	}

	public static function webhook_url( PaymentAccount $account ): string {
		return rest_url( RestController::REST_NAMESPACE . '/webhooks/' . $account->provider . '/' . $account->id );
	}

	/**
	 * @param array<string, mixed>|null $row raw row (for timestamps)
	 * @return array<string, mixed>
	 */
	private function present_account( PaymentAccount $account, ?array $row ): array {
		$row = $row ?? array();
		if ( ! $row ) {
			global $wpdb;
			$t   = Schema::table( 'payment_accounts' );
			$row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT last_verified_at, last_error FROM {$t} WHERE id = %d", $account->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$provider = $this->providers->get( $account->provider );
		return array(
			'id'               => $account->id,
			'provider'         => $account->provider,
			'mode'             => $account->mode,
			'status'           => $account->status,
			'configured'       => ! empty( $account->credentials ),
			'key_hint'         => $account->settings['key_hint'] ?? null,
			'webhook'          => self::webhook_state( $account ),
			'webhook_url'      => self::webhook_url( $account ),
			'capabilities'     => $provider ? $provider->capabilities( $account ) : array(),
			'last_verified_at' => ! empty( $row['last_verified_at'] ) ? \BookingManager\Core\Clock::iso( (string) $row['last_verified_at'] ) : null,
			'last_error'       => $row['last_error'] ?? null,
		);
	}

	/**
	 * How the provider's notifications reach us:
	 *   local     — test mode on a local site: no notifications, the provider is asked directly
	 *   per_order — the address travels with each payment (Nexi): nothing to register
	 *   auto      — registered by the plugin;  manual — entered by the administrator
	 *   missing   — not set up: payments would never be confirmed
	 */
	public static function webhook_state( PaymentAccount $account ): string {
		$mode = (string) ( $account->settings['webhook'] ?? '' );
		if ( in_array( $mode, array( 'local', 'per_order' ), true ) ) {
			return $mode;
		}
		if ( empty( $account->credentials['webhook_secret'] ) && empty( $account->settings['webhook_endpoint_id'] ) ) {
			return 'missing';
		}
		return 'manual' === $mode ? 'manual' : 'auto';
	}

	/**
	 * Fingerprint of whose provider account the credentials open (changes → the old
	 * notification endpoint belongs to someone else and is removed).
	 *
	 * @param array<string, mixed> $credentials
	 */
	private static function identity( array $credentials ): string {
		$credentials = array_diff_key( $credentials, array_flip( self::NOT_IDENTITY ) );
		ksort( $credentials );
		return hash( 'sha256', (string) wp_json_encode( $credentials ) );
	}

	private function online_provider( string $provider_id, string $mode ): PaymentProvider|\WP_Error {
		$provider = $this->providers->online()[ $provider_id ] ?? null;
		if ( ! $provider ) {
			return new \WP_Error( 'bkng_not_found', __( 'Sistema di pagamento non disponibile.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new \WP_Error( 'bkng_validation', __( 'Modalità non valida.', 'booking-manager' ), array( 'status' => 400 ) );
		}
		return $provider;
	}

	private function set_property( int $property_id, string $provider, string $mode ): void {
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return;
		}
		$settings                     = (array) $property['settings'];
		$settings['payment_provider'] = $provider;
		$settings['payment_mode']     = $mode;
		$this->properties->save_settings( $property_id, $settings );
	}

	/** @param array<string, mixed> $extra */
	private function log( string $action, PaymentAccount $account, array $extra ): void {
		$this->audit->log(
			array(
				'action'      => $action,
				'entity_type' => 'payment_account',
				'entity_id'   => $account->id,
				'property_id' => $account->property_id,
				'new'         => array(
					'provider' => $account->provider,
					'mode'     => $account->mode,
				) + array_filter( $extra, fn( $v ) => null !== $v ),
			)
		);
	}
}
