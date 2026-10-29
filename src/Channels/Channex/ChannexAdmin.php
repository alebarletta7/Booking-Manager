<?php
/**
 * What the Channex screen does: connection (key, environment, webhook), mapping of each
 * property, buttons (send now, full sync, read reservations), registers and warnings.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

use BookingManager\Audit\AuditLogger;
use BookingManager\Core\Validation;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Rates\RateRepository;
use BookingManager\Rest\PublicController;

defined( 'ABSPATH' ) || exit;

class ChannexAdmin {

	/** Full sync of a property in the background (after the mapping or the switch-on). */
	public const FULL_SYNC_HOOK = 'bkng_channex_full_sync';

	public function __construct(
		private ChannexSettings $settings,
		private ChannexClient $client,
		private ChannexMap $map,
		private ChannexSync $sync,
		private ChannexBookings $inbound,
		private ChannexRevisions $revisions,
		private ChannexLog $log,
		private ChannexAlerts $alerts,
		private PropertyRepository $properties,
		private RoomTypeRepository $types,
		private RateRepository $rates,
		private AuditLogger $audit
	) {}

	public static function webhook_url(): string {
		return rest_url( PublicController::REST_NAMESPACE . '/channex/webhook' );
	}

	/** @return array<string, mixed> */
	public function overview(): array {
		$linked = $this->map->properties();
		return array(
			'settings'    => $this->settings->view(),
			'webhook_url' => self::webhook_url(),
			'linked'      => array_map( 'strval', $linked ),
			'alerts'      => $this->alerts->all(),
			'unread'      => $this->alerts->unread(),
			'next_tick'   => self::next( ChannexSync::TICK_HOOK ),
			'action_scheduler' => function_exists( 'as_enqueue_async_action' ),
		);
	}

	/**
	 * @param array<string, mixed> $input active, environment, base_url, nightly, api_key, webhook_secret
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save_settings( array $input ): array|\WP_Error {
		$errors  = array();
		$changes = array();
		if ( array_key_exists( 'environment', $input ) ) {
			if ( in_array( $input['environment'], array( 'staging', 'production' ), true ) ) {
				$changes['environment'] = (string) $input['environment'];
			} else {
				$errors['environment'] = __( 'Ambiente non valido.', 'booking-manager' );
			}
		}
		if ( array_key_exists( 'base_url', $input ) ) {
			$url = trim( (string) $input['base_url'] );
			if ( '' !== $url && ( ! wp_http_validate_url( $url ) || ! str_starts_with( $url, 'https://' ) ) ) {
				$errors['base_url'] = __( 'Indirizzo non valido: deve iniziare con https://', 'booking-manager' );
			} else {
				$changes['base_url'] = esc_url_raw( rtrim( $url, '/' ) );
			}
		}
		$production = ( $changes['environment'] ?? $this->settings->config()['environment'] ) === 'production';
		if ( $production && '' === (string) ( $changes['base_url'] ?? $this->settings->config()['base_url'] ) && ! $this->settings->view()['base_url_constant'] ) {
			$errors['base_url'] = __( 'Per la produzione indica l\'indirizzo che Channex ti ha dato dopo la certificazione.', 'booking-manager' );
		}
		foreach ( array( 'active', 'nightly' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$changes[ $flag ] = (bool) rest_sanitize_boolean( $input[ $flag ] );
			}
		}
		$secrets = array();
		foreach ( array( 'api_key', 'webhook_secret' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ) {
				$value = trim( (string) $input[ $key ] );
				if ( strlen( $value ) < 16 || strlen( $value ) > 200 || preg_match( '/\s/', $value ) ) {
					$errors[ $key ] = __( 'Valore non valido: copialo di nuovo da Channex.', 'booking-manager' );
				} else {
					$secrets[ $key ] = $value;
				}
			}
		}
		if ( $errors ) {
			return Validation::error( $errors );
		}
		$was_active = $this->settings->active();
		if ( $secrets ) {
			$this->settings->save_credentials( $secrets );
		}
		if ( isset( $secrets['api_key'] ) || isset( $changes['environment'] ) || isset( $changes['base_url'] ) ) {
			// A new key or address: try again after a 401.
			$changes['suspended'] = '';
		}
		$this->settings->save( $changes );
		$this->audit->log(
			array(
				'action'      => 'channex.settings_updated',
				'entity_type' => 'integration',
				'new'         => array_intersect_key( $changes, array_flip( array( 'active', 'environment', 'base_url', 'nightly' ) ) ) + array( 'secrets' => array_keys( $secrets ) ),
			)
		);
		if ( ! $was_active && $this->settings->active() ) {
			// Going online: everything once, then only the changes.
			foreach ( array_keys( $this->map->properties() ) as $property_id ) {
				$this->schedule_full_sync( $property_id );
			}
		}
		return $this->overview();
	}

	/** @return array<string, mixed>|\WP_Error the properties the key can see */
	public function test(): array|\WP_Error {
		$res = $this->client->get( 'properties/options' );
		if ( null !== $res['error'] ) {
			return new \WP_Error( 'bkng_channex_failed', $res['error'], array( 'status' => 502 ) );
		}
		if ( '' !== (string) $this->settings->config()['suspended'] ) {
			$this->settings->save( array( 'suspended' => '' ) );
		}
		return array(
			'ok'         => true,
			'properties' => ChannexClient::options( $res['body'] ),
		);
	}

	/**
	 * Everything the screen shows for a property: our types and plans, the mapping,
	 * Channex's options (when connected), sync state, reservations received, webhooks.
	 *
	 * @return array<string, mixed>
	 */
	public function property( int $property_id ): array {
		$uuid   = $this->map->property_uuid( $property_id );
		$plans  = $this->rates->plans( $property_id );
		$types  = $this->types->for_property( $property_id );
		$pairs  = array();
		foreach ( $types as $type ) {
			$own = array_values( array_filter( $plans, static fn( array $p ) => ! $p['room_type_ids'] || in_array( (int) $type['id'], $p['room_type_ids'], true ) ) );
			if ( ! $plans ) {
				$pairs[] = array(
					'room_type_id' => (int) $type['id'],
					'rate_plan_id' => 0,
					'name'         => __( 'Tariffa base', 'booking-manager' ),
				);
			}
			foreach ( $own as $plan ) {
				$pairs[] = array(
					'room_type_id' => (int) $type['id'],
					'rate_plan_id' => (int) $plan['id'],
					'name'         => (string) $plan['name'],
				);
			}
		}
		$remote = array(
			'properties' => array(),
			'room_types' => array(),
			'rate_plans' => array(),
			'error'      => null,
		);
		if ( $this->settings->connected() ) {
			$res = $this->client->get( 'properties/options' );
			if ( null === $res['error'] ) {
				$remote['properties'] = ChannexClient::options( $res['body'] );
				if ( null !== $uuid ) {
					foreach ( array( 'room_types', 'rate_plans' ) as $kind ) {
						$res = $this->client->get( $kind . '/options', array( 'filter' => array( 'property_id' => $uuid ) ) );
						if ( null === $res['error'] ) {
							$remote[ $kind ] = ChannexClient::options( $res['body'] );
						} else {
							$remote['error'] = $res['error'];
						}
					}
				}
			} else {
				$remote['error'] = $res['error'];
			}
		}
		return array(
			'property_id' => $property_id,
			'linked'      => null !== $uuid,
			'mapping'     => array(
				'property'   => $uuid,
				'room_types' => (object) $this->map->room_types( $property_id ),
				'rate_plans' => $this->map->rate_plans( $property_id ),
			),
			'local'       => array(
				'room_types' => array_map(
					static fn( array $t ) => array(
						'id'   => (int) $t['id'],
						'name' => (string) $t['name'],
					),
					$types
				),
				'rate_plans' => $pairs,
			),
			'remote'      => $remote,
			'status'      => $this->sync->status( $property_id ),
			'revisions'   => $this->revisions->recent( $property_id, 50 ),
			'attention'   => $this->revisions->attention_count( $property_id ),
			'webhooks'    => $this->log->webhooks( $property_id, 20 ),
		);
	}

	/**
	 * Saves the mapping of a property. An empty Channex property unlinks it.
	 *
	 * @param array<string, mixed> $input channex_property_id, room_types {our id: uuid}, rate_plans [{room_type_id, rate_plan_id, channex_rate_plan_id, per_person}]
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save_mapping( int $property_id, array $input ): array|\WP_Error {
		$uuid = strtolower( trim( (string) ( $input['channex_property_id'] ?? '' ) ) );
		if ( '' === $uuid ) {
			$this->map->clear( $property_id );
			$this->sync->forget( $property_id );
			$this->log_mapping( $property_id, null, 0, 0 );
			return $this->property( $property_id );
		}
		$errors = array();
		if ( ! ChannexMap::is_uuid( $uuid ) ) {
			$errors['channex_property_id'] = __( 'Struttura di Channex non valida.', 'booking-manager' );
		}
		$other = ChannexMap::is_uuid( $uuid ) ? $this->map->property_for( $uuid ) : null;
		if ( null !== $other && $other !== $property_id ) {
			$errors['channex_property_id'] = __( 'Questa struttura di Channex è già collegata a un\'altra struttura.', 'booking-manager' );
		}
		$own_types = array();
		foreach ( $this->types->for_property( $property_id ) as $type ) {
			$own_types[ (int) $type['id'] ] = $type;
		}
		$room_types = array();
		$used       = array();
		foreach ( (array) ( $input['room_types'] ?? array() ) as $type_id => $remote ) {
			$remote = strtolower( trim( (string) $remote ) );
			if ( '' === $remote ) {
				continue;
			}
			if ( ! isset( $own_types[ (int) $type_id ] ) || ! ChannexMap::is_uuid( $remote ) ) {
				$errors[ 'room_types.' . (int) $type_id ] = __( 'Tipologia non valida.', 'booking-manager' );
				continue;
			}
			if ( isset( $used[ $remote ] ) ) {
				$errors[ 'room_types.' . (int) $type_id ] = __( 'La stessa tipologia di Channex è collegata due volte.', 'booking-manager' );
				continue;
			}
			$used[ $remote ]                = true;
			$room_types[ (int) $type_id ] = $remote;
		}
		$plans      = array();
		foreach ( $this->rates->plans( $property_id ) as $plan ) {
			$plans[ (int) $plan['id'] ] = $plan;
		}
		$rate_plans = array();
		$used       = array();
		foreach ( array_values( (array) ( $input['rate_plans'] ?? array() ) ) as $i => $row ) {
			$row     = is_array( $row ) ? $row : array();
			$remote  = strtolower( trim( (string) ( $row['channex_rate_plan_id'] ?? $row['channex_id'] ?? '' ) ) );
			$type_id = (int) ( $row['room_type_id'] ?? 0 );
			$plan_id = (int) ( $row['rate_plan_id'] ?? 0 );
			if ( '' === $remote ) {
				continue;
			}
			$plan_ok = 0 === $plan_id ? ! $plans : ( isset( $plans[ $plan_id ] ) && ( ! $plans[ $plan_id ]['room_type_ids'] || in_array( $type_id, $plans[ $plan_id ]['room_type_ids'], true ) ) );
			if ( ! isset( $room_types[ $type_id ] ) || ! $plan_ok || ! ChannexMap::is_uuid( $remote ) ) {
				$errors[ 'rate_plans.' . $i ] = __( 'Tariffa non valida, o tipologia non collegata.', 'booking-manager' );
				continue;
			}
			if ( isset( $used[ $remote ] ) || isset( $used[ $type_id . ':' . $plan_id ] ) ) {
				$errors[ 'rate_plans.' . $i ] = __( 'Ogni tariffa si collega una volta sola.', 'booking-manager' );
				continue;
			}
			$used[ $remote ]                   = true;
			$used[ $type_id . ':' . $plan_id ] = true;
			$rate_plans[]                      = array(
				'room_type_id' => $type_id,
				'rate_plan_id' => $plan_id,
				'channex_id'   => $remote,
				'per_person'   => ! empty( $row['per_person'] ) && rest_sanitize_boolean( $row['per_person'] ),
			);
		}
		if ( $errors ) {
			return Validation::error( $errors );
		}
		$this->map->save( $property_id, $uuid, $room_types, $rate_plans );
		$this->sync->forget( $property_id );
		$this->log_mapping( $property_id, $uuid, count( $room_types ), count( $rate_plans ) );
		if ( $this->settings->active() ) {
			$this->schedule_full_sync( $property_id );
		}
		return $this->property( $property_id );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function full_sync( int $property_id ): array|\WP_Error {
		$done = $this->sync->full_sync( $property_id );
		return $done instanceof \WP_Error ? $done : $this->property( $property_id );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function push_now( int $property_id ): array|\WP_Error {
		$this->sync->push_all( $property_id );
		return $this->property( $property_id );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function pull_now( int $property_id ): array|\WP_Error {
		$done = $this->inbound->pull( $property_id );
		return $done instanceof \WP_Error ? $done : $this->property( $property_id ) + array( 'pulled' => $done );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function retry( string $revision_id ): array|\WP_Error {
		$done = $this->inbound->retry( $revision_id );
		if ( $done instanceof \WP_Error ) {
			return $done;
		}
		$row = $this->revisions->find( $revision_id );
		return $this->property( (int) ( $row['property_id'] ?? 0 ) );
	}

	/** @return array<string, mixed> */
	public function resolve( string $revision_id ): array {
		$this->revisions->resolve( $revision_id );
		$row = $this->revisions->find( $revision_id );
		return $this->property( (int) ( $row['property_id'] ?? 0 ) );
	}

	/** @return array<string, mixed> */
	public function read_alerts(): array {
		$this->alerts->mark_read();
		return $this->overview();
	}

	/**
	 * Creates the webhook on Channex (all properties of the account, no data in the call:
	 * it is only a signal, signed).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function register_webhook(): array|\WP_Error {
		if ( '' === $this->settings->webhook_secret() ) {
			return new \WP_Error( 'bkng_channex_secret', __( 'Prima genera o inserisci la chiave di firma dei webhook.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$res = $this->client->post(
			'webhooks',
			array(
				'webhook' => array(
					'callback_url'   => self::webhook_url(),
					'event_mask'     => ChannexBookings::EVENT_MASK,
					'is_active'      => true,
					'is_global'      => true,
					'send_data'      => false,
					'is_signed'      => true,
					'headers'        => (object) array(),
					'request_params' => (object) array(),
				),
			)
		);
		if ( null !== $res['error'] ) {
			return new \WP_Error( 'bkng_channex_failed', $res['error'], array( 'status' => 502 ) );
		}
		$id = (string) ( $res['body']['data']['id'] ?? '' );
		$this->settings->save( array( 'webhook_id' => substr( $id, 0, 36 ) ) );
		return $this->overview();
	}

	/**
	 * Asks Channex for a signing key and stores its secret (sealed).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_signing_key(): array|\WP_Error {
		$res = $this->client->post( 'signing_keys', array( 'signing_key' => (object) array() ) );
		if ( null !== $res['error'] ) {
			return new \WP_Error( 'bkng_channex_failed', $res['error'], array( 'status' => 502 ) );
		}
		$attributes = (array) ( $res['body']['data']['attributes'] ?? $res['body']['data'] ?? array() );
		$secret     = '';
		foreach ( array( 'secret', 'key', 'signing_key', 'value' ) as $field ) {
			if ( isset( $attributes[ $field ] ) && is_string( $attributes[ $field ] ) && strlen( $attributes[ $field ] ) >= 16 ) {
				$secret = $attributes[ $field ];
				break;
			}
		}
		if ( '' === $secret ) {
			return new \WP_Error( 'bkng_channex_secret', __( 'Channex non ha restituito la chiave: copiala dal pannello di Channex e incollala qui.', 'booking-manager' ), array( 'status' => 502 ) );
		}
		$this->settings->save_credentials( array( 'webhook_secret' => $secret ) );
		return $this->overview();
	}

	/** Sends a test call to our webhook through Channex. */
	public function test_webhook(): array|\WP_Error {
		$res = $this->client->post(
			'webhooks/test',
			array(
				'webhook' => array(
					'callback_url' => self::webhook_url(),
					'event_mask'   => ChannexBookings::EVENT_MASK,
					'send_data'    => false,
					'is_signed'    => true,
				),
			)
		);
		if ( null !== $res['error'] ) {
			return new \WP_Error( 'bkng_channex_failed', $res['error'], array( 'status' => 502 ) );
		}
		return $this->overview() + array( 'tested' => true );
	}

	public function schedule_full_sync( int $property_id ): void {
		$args = array( $property_id );
		if ( ! wp_next_scheduled( self::FULL_SYNC_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 10, self::FULL_SYNC_HOOK, $args );
		}
	}

	private function log_mapping( int $property_id, ?string $uuid, int $types, int $plans ): void {
		$this->audit->log(
			array(
				'action'      => 'channex.mapping_saved',
				'entity_type' => 'property',
				'entity_id'   => $property_id,
				'property_id' => $property_id,
				'new'         => array(
					'channex_property' => $uuid,
					'room_types'       => $types,
					'rate_plans'       => $plans,
				),
			)
		);
	}

	private static function next( string $hook ): ?string {
		$next = wp_next_scheduled( $hook );
		return $next ? gmdate( 'c', (int) $next ) : null;
	}
}
