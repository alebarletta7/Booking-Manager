<?php
/**
 * Properties (strutture): creation, changes, archive and restore, per-property settings.
 *
 * A property is never deleted: archiving hides it from the public form and the
 * operators' pickers, and keeps its bookings and statistics.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Properties;

use BookingManager\Access\PropertyScope;
use BookingManager\Audit\AuditLogger;
use BookingManager\Core\Hooks;
use BookingManager\Core\Validation;

defined( 'ABSPATH' ) || exit;

class PropertyService {

	public function __construct(
		private PropertyRepository $repo,
		private PropertyScope $scope,
		private AuditLogger $audit
	) {}

	/** @return array<string, string> */
	public static function kind_labels(): array {
		return array(
			'bnb'          => __( 'B&B', 'booking-manager' ),
			'rooms'        => __( 'Affittacamere', 'booking-manager' ),
			'holiday_home' => __( 'Casa vacanze', 'booking-manager' ),
			'apartments'   => __( 'Appartamenti', 'booking-manager' ),
			'residence'    => __( 'Residence', 'booking-manager' ),
			'guesthouse'   => __( 'Guest house', 'booking-manager' ),
			'hotel'        => __( 'Hotel', 'booking-manager' ),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( array $input, int $user_id ): array|\WP_Error {
		$data = $this->validate( $input, null );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$data['settings'] = $input['settings'];
		}
		$id = $this->repo->create( $data );
		if ( $id <= 0 ) {
			return new \WP_Error( 'bkng_db_error', __( 'Non è stato possibile salvare la struttura.', 'booking-manager' ), array( 'status' => 500 ) );
		}
		// The creator keeps access even without bkng_access_all_properties.
		if ( $user_id > 0 && ! $this->scope->has_all( $user_id ) ) {
			$this->scope->assign( $user_id, $id, $user_id );
		}
		$property = (array) $this->repo->find( $id );
		$this->audit->log(
			array(
				'action'      => 'property.created',
				'entity_type' => 'property',
				'entity_id'   => $id,
				'property_id' => $id,
				'new'         => array_intersect_key( $property, array_flip( PropertyRepository::FIELDS ) ),
			)
		);
		Hooks::dispatch( Hooks::PROPERTY_CREATED, $id, array( 'property_uuid' => $property['uuid'] ) );
		return self::payload( $property );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( int $id, array $input ): array|\WP_Error {
		$current = $this->repo->find( $id );
		if ( ! $current ) {
			return self::not_found();
		}
		if ( isset( $input['version'] ) && (int) $input['version'] !== (int) $current['version'] ) {
			return self::conflict();
		}
		$data = $this->validate( $input, $current );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		$changed = array();
		foreach ( $data as $key => $value ) {
			if ( (string) ( $current[ $key ] ?? '' ) !== (string) ( $value ?? '' ) ) {
				$changed[ $key ] = $value;
			}
		}
		if ( $changed ) {
			$this->repo->update( $id, $changed );
			$this->audit->log(
				array(
					'action'      => 'property.updated',
					'entity_type' => 'property',
					'entity_id'   => $id,
					'property_id' => $id,
					'old'         => array_intersect_key( $current, $changed ),
					'new'         => $changed,
				)
			);
		}
		return self::payload( (array) $this->repo->find( $id ) );
	}

	/**
	 * Partial update of the per-property settings.
	 *
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_settings( int $id, array $changes ): array|\WP_Error {
		$current = $this->repo->find( $id );
		if ( ! $current ) {
			return self::not_found();
		}
		$merged = PropertySettings::merge( $current['settings'], $changes );
		$diff   = array();
		foreach ( $merged as $key => $value ) {
			if ( $value !== $current['settings'][ $key ] ) {
				$diff[ $key ] = array( $current['settings'][ $key ], $value );
			}
		}
		if ( $diff ) {
			$this->repo->save_settings( $id, $merged );
			$this->audit->log(
				array(
					'action'      => 'property.settings_updated',
					'entity_type' => 'property',
					'entity_id'   => $id,
					'property_id' => $id,
					'new'         => $diff,
				)
			);
		}
		return self::payload( (array) $this->repo->find( $id ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function archive( int $id ): array|\WP_Error {
		return $this->set_status( $id, 'archived' );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function restore( int $id ): array|\WP_Error {
		return $this->set_status( $id, 'active' );
	}

	/** @return array<string, mixed>|\WP_Error */
	private function set_status( int $id, string $status ): array|\WP_Error {
		$current = $this->repo->find( $id );
		if ( ! $current ) {
			return self::not_found();
		}
		if ( $current['status'] !== $status ) {
			$this->repo->set_status( $id, $status );
			$this->audit->log(
				array(
					'action'      => 'archived' === $status ? 'property.archived' : 'property.restored',
					'entity_type' => 'property',
					'entity_id'   => $id,
					'property_id' => $id,
				)
			);
			if ( 'archived' === $status ) {
				Hooks::dispatch( Hooks::PROPERTY_ARCHIVED, $id, array( 'property_uuid' => $current['uuid'] ) );
			}
		}
		return self::payload( (array) $this->repo->find( $id ) );
	}

	/**
	 * Validates and normalizes the editable columns. On update only the fields sent are
	 * checked; on create name is required.
	 *
	 * @param array<string, mixed>      $input
	 * @param array<string, mixed>|null $current
	 * @return array<string, mixed>|\WP_Error
	 */
	public function validate( array $input, ?array $current ): array|\WP_Error {
		$errors = array();
		$data   = array();

		if ( null === $current || array_key_exists( 'name', $input ) ) {
			$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
			if ( '' === $name ) {
				$errors['name'] = __( 'Indica il nome della struttura.', 'booking-manager' );
			} else {
				$data['name'] = mb_substr( $name, 0, 190 );
			}
		}
		if ( array_key_exists( 'slug', $input ) && ! Validation::blank( $input['slug'] ) ) {
			$slug = sanitize_title( (string) $input['slug'] );
			if ( '' === $slug ) {
				$errors['slug'] = __( 'Indirizzo non valido.', 'booking-manager' );
			} elseif ( $this->repo->slug_taken( $slug, (int) ( $current['id'] ?? 0 ) ) ) {
				$errors['slug'] = __( 'Questo indirizzo è già usato da un\'altra struttura.', 'booking-manager' );
			} else {
				$data['slug'] = $slug;
			}
		}
		if ( array_key_exists( 'kind', $input ) ) {
			if ( in_array( $input['kind'], PropertyRepository::KINDS, true ) ) {
				$data['kind'] = $input['kind'];
			} else {
				$errors['kind'] = __( 'Tipo di struttura non valido.', 'booking-manager' );
			}
		}
		if ( array_key_exists( 'timezone', $input ) ) {
			if ( is_string( $input['timezone'] ) && in_array( $input['timezone'], timezone_identifiers_list(), true ) ) {
				$data['timezone'] = $input['timezone'];
			} else {
				$errors['timezone'] = __( 'Fuso orario non valido.', 'booking-manager' );
			}
		}
		if ( array_key_exists( 'currency', $input ) ) {
			$currency = Validation::currency( $input['currency'] );
			if ( null === $currency ) {
				$errors['currency'] = __( 'Valuta non valida (codice di tre lettere, es. EUR).', 'booking-manager' );
			} else {
				$data['currency'] = $currency;
			}
		}
		if ( array_key_exists( 'booking_mode', $input ) ) {
			if ( in_array( $input['booking_mode'], array( 'request', 'direct' ), true ) ) {
				$data['booking_mode'] = $input['booking_mode'];
			} else {
				$errors['booking_mode'] = __( 'Modalità di prenotazione non valida.', 'booking-manager' );
			}
		}
		foreach ( array( 'checkin_from', 'checkin_to', 'checkout_until' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$value = is_string( $input[ $key ] ) ? $input[ $key ] : '';
				if ( preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value ) ) {
					$data[ $key ] = substr( $value, 0, 5 ) . ':00';
				} else {
					$errors[ $key ] = __( 'Orario non valido (HH:MM).', 'booking-manager' );
				}
			}
		}
		if ( array_key_exists( 'email', $input ) ) {
			$email = sanitize_email( (string) $input['email'] );
			if ( '' !== (string) $input['email'] && ! is_email( $email ) ) {
				$errors['email'] = __( 'Indirizzo e-mail non valido.', 'booking-manager' );
			} else {
				$data['email'] = '' !== $email ? $email : null;
			}
		}
		if ( array_key_exists( 'website', $input ) ) {
			$data['website'] = Validation::blank( $input['website'] ) ? null : esc_url_raw( (string) $input['website'] );
		}
		if ( array_key_exists( 'country', $input ) ) {
			$country = strtoupper( (string) $input['country'] );
			if ( '' !== $country && ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$errors['country'] = __( 'Paese non valido (codice di due lettere, es. IT).', 'booking-manager' );
			} else {
				$data['country'] = '' !== $country ? $country : null;
			}
		}
		foreach ( array( 'address' => 255, 'city' => 120, 'postcode' => 16, 'province' => 60, 'phone' => 32, 'company_name' => 190, 'vat_number' => 32 ) as $key => $max ) {
			if ( array_key_exists( $key, $input ) ) {
				$value        = sanitize_text_field( (string) $input[ $key ] );
				$data[ $key ] = '' !== $value ? mb_substr( $value, 0, $max ) : null;
			}
		}
		return $errors ? Validation::error( $errors ) : $data;
	}

	/**
	 * What the staff app receives about a property.
	 *
	 * @param array<string, mixed> $p
	 * @return array<string, mixed>
	 */
	public static function payload( array $p ): array {
		return array(
			'id'             => (int) $p['id'],
			'uuid'           => (string) $p['uuid'],
			'name'           => (string) $p['name'],
			'slug'           => (string) $p['slug'],
			'kind'           => (string) $p['kind'],
			'room_is_type'   => in_array( $p['kind'], PropertyRepository::ROOM_IS_TYPE, true ),
			'timezone'       => (string) $p['timezone'],
			'currency'       => (string) $p['currency'],
			'booking_mode'   => (string) $p['booking_mode'],
			'checkin_from'   => (string) $p['checkin_from'],
			'checkin_to'     => (string) $p['checkin_to'],
			'checkout_until' => (string) $p['checkout_until'],
			'address'        => $p['address'],
			'city'           => $p['city'],
			'postcode'       => $p['postcode'],
			'province'       => $p['province'],
			'country'        => $p['country'],
			'phone'          => $p['phone'],
			'email'          => $p['email'],
			'website'        => $p['website'],
			'company_name'   => $p['company_name'],
			'vat_number'     => $p['vat_number'],
			'settings'       => $p['settings'],
			'status'         => (string) $p['status'],
			'version'        => (int) $p['version'],
		);
	}

	public static function not_found(): \WP_Error {
		return new \WP_Error( 'bkng_property_not_found', __( 'Struttura non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
	}

	public static function conflict(): \WP_Error {
		return new \WP_Error( 'bkng_conflict', __( 'I dati sono stati modificati da qualcun altro. Ricarica e riprova.', 'booking-manager' ), array( 'status' => 409 ) );
	}
}
