<?php
/**
 * Room types and rooms (units): creation, changes, archive; out-of-service periods.
 *
 * Every unit belongs to a room type and bookings always book a type. In a B&B, a holiday
 * home or a set of apartments each room is sold on its own: creating a room without a
 * type creates a hidden type just for it, so the staff never has to think about types.
 *
 * Creating, changing and archiving types and rooms needs a licence that allows it
 * (LicenseGate::OP_ROOMS_MANAGE). Out-of-service periods and housekeeping are daily
 * operations and are never stopped by the licence.
 *
 * Changes that lower the number of rooms of a type (archive, move to another type) are
 * checked under the type lock against every future night: they are refused when the
 * remaining rooms would not be enough for the stays already sold.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Inventory;

use BookingManager\Audit\AuditLogger;
use BookingManager\Availability\Ledger;
use BookingManager\Core\Clock;
use BookingManager\Core\Dates;
use BookingManager\Core\Hooks;
use BookingManager\Core\Validation;
use BookingManager\Database\Abort;
use BookingManager\Database\Schema;
use BookingManager\Licensing\LicenseGate;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Properties\PropertyService;

defined( 'ABSPATH' ) || exit;

class InventoryService {

	public const BLOCK_KINDS = array( 'out_of_service', 'maintenance', 'owner_use' );

	public const MAX_BATCH_UNITS = 100;

	public function __construct(
		private RoomTypeRepository $types,
		private UnitRepository $units,
		private PropertyRepository $properties,
		private Ledger $ledger,
		private AuditLogger $audit,
		private Clock $clock,
		private LicenseGate $license
	) {}

	/* ------------------------------------------------------------------ Room types */

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_type( int $property_id, array $input ): array|\WP_Error {
		$gate = $this->gate();
		if ( $gate ) {
			return $gate;
		}
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return PropertyService::not_found();
		}
		$data = $this->validate_type( $input, null );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		$id = $this->types->create( $property_id, $data );
		$this->log( 'room_type.created', 'room_type', $id, $property_id, $data );
		return self::type_payload( (array) $this->types->find( $id ) );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_type( int $id, array $input ): array|\WP_Error {
		$gate = $this->gate();
		if ( $gate ) {
			return $gate;
		}
		$type = $this->types->find( $id );
		if ( ! $type ) {
			return self::type_not_found();
		}
		if ( isset( $input['version'] ) && (int) $input['version'] !== $type['version'] ) {
			return PropertyService::conflict();
		}
		$data = $this->validate_type( $input, $type );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		if ( isset( $data['name'] ) && ! isset( $input['slug'] ) && $data['name'] !== $type['name'] ) {
			$data['slug'] = $this->types->unique_slug( (int) $type['property_id'], (string) $data['name'], $id );
		}
		$this->types->update( $id, $data );
		$this->log( 'room_type.updated', 'room_type', $id, (int) $type['property_id'], $data );
		// Base price, extra guests: the rates a channel shows for this type.
		Hooks::rates_changed( (int) $type['property_id'], array( $id ) );
		return self::type_payload( (array) $this->types->find( $id ) );
	}

	/**
	 * Archives a type and its rooms, when no future stay uses it.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function archive_type( int $id ): array|\WP_Error {
		$gate = $this->gate();
		if ( $gate ) {
			return $gate;
		}
		$type = $this->types->find( $id );
		if ( ! $type ) {
			return self::type_not_found();
		}
		$property = (array) $this->properties->find( (int) $type['property_id'] );
		$today    = $this->clock->today_in( (string) $property['timezone'] );
		$result   = Abort::run(
			function () use ( $id, $today ) {
				$this->ledger->lock_types( array( $id ) );
				if ( $this->future_stays( $id, $today ) > 0 ) {
					throw new Abort( new \WP_Error( 'bkng_type_in_use', __( 'Ci sono prenotazioni future per questa tipologia: spostale prima di archiviarla.', 'booking-manager' ), array( 'status' => 409 ) ) );
				}
				foreach ( $this->units->for_type( $id ) as $unit ) {
					$this->cancel_future_blocks( (int) $unit['id'], $today );
					$this->units->set_status( (int) $unit['id'], 'archived' );
				}
				$this->types->set_status( $id, 'archived' );
				return true;
			}
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$this->log( 'room_type.archived', 'room_type', $id, (int) $type['property_id'] );
		Hooks::availability_changed( (int) $type['property_id'], array( $id ) );
		return self::type_payload( (array) $this->types->find( $id ) );
	}

	/**
	 * @param array<string, mixed>      $input
	 * @param array<string, mixed>|null $current
	 * @return array<string, mixed>|\WP_Error
	 */
	public function validate_type( array $input, ?array $current ): array|\WP_Error {
		$errors = array();
		$data   = array();
		if ( null === $current || array_key_exists( 'name', $input ) ) {
			$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
			if ( '' === $name ) {
				$errors['name'] = __( 'Indica il nome della tipologia.', 'booking-manager' );
			} else {
				$data['name'] = mb_substr( $name, 0, 190 );
			}
		}
		if ( array_key_exists( 'description', $input ) ) {
			$data['description'] = wp_kses_post( (string) $input['description'] );
		}
		$limits = array(
			'max_adults'     => array( 1, 50 ),
			'max_children'   => array( 0, 50 ),
			'max_occupancy'  => array( 1, 50 ),
			'base_occupancy' => array( 1, 50 ),
			'sort_order'     => array( -10000, 10000 ),
		);
		foreach ( $limits as $key => [ $min, $max ] ) {
			if ( array_key_exists( $key, $input ) ) {
				$value = Validation::int_between( $input[ $key ], $min, $max );
				if ( null === $value ) {
					$errors[ $key ] = __( 'Valore non valido.', 'booking-manager' );
				} else {
					$data[ $key ] = $value;
				}
			}
		}
		foreach ( array( 'base_price', 'extra_adult_price', 'extra_child_price' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$value = Validation::int_between( $input[ $key ], 0, 100000000 );
				if ( null === $value ) {
					$errors[ $key ] = __( 'Prezzo non valido.', 'booking-manager' );
				} else {
					$data[ $key ] = $value;
				}
			}
		}
		if ( array_key_exists( 'size_sqm', $input ) ) {
			$data['size_sqm'] = Validation::blank( $input['size_sqm'] ) ? null : Validation::int_between( $input['size_sqm'], 1, 10000 );
		}
		if ( array_key_exists( 'beds', $input ) ) {
			$beds         = sanitize_text_field( (string) $input['beds'] );
			$data['beds'] = '' !== $beds ? mb_substr( $beds, 0, 190 ) : null;
		}
		if ( array_key_exists( 'images', $input ) ) {
			$images = array();
			foreach ( array_slice( (array) $input['images'], 0, 20 ) as $image ) {
				$image = (int) $image;
				if ( Validation::public_image( $image ) ) {
					$images[] = $image;
				} else {
					$errors['images'] = __( 'Una delle immagini non è valida o non è pubblica.', 'booking-manager' );
				}
			}
			$data['images'] = $images;
		}
		if ( array_key_exists( 'amenities', $input ) ) {
			$data['amenities'] = array_values( array_filter( array_map( static fn( $a ) => mb_substr( sanitize_text_field( (string) $a ), 0, 60 ), array_slice( (array) $input['amenities'], 0, 40 ) ) ) );
		}
		foreach ( array( 'online_enabled', 'is_hidden' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$data[ $key ] = rest_sanitize_boolean( $input[ $key ] ) ? 1 : 0;
			}
		}
		$merged = array_merge( (array) $current, $data );
		if ( ! $errors && isset( $merged['max_adults'], $merged['max_occupancy'] ) ) {
			if ( (int) $merged['max_occupancy'] < 1 || (int) $merged['max_adults'] > (int) $merged['max_occupancy'] ) {
				$errors['max_occupancy'] = __( 'Il numero massimo di ospiti deve essere almeno pari agli adulti.', 'booking-manager' );
			}
			if ( isset( $merged['base_occupancy'] ) && (int) $merged['base_occupancy'] > (int) $merged['max_occupancy'] ) {
				$errors['base_occupancy'] = __( 'Gli ospiti inclusi nel prezzo non possono superare il massimo.', 'booking-manager' );
			}
		}
		if ( null === $current ) {
			$data += array(
				'max_adults'     => 2,
				'max_children'   => 0,
				'max_occupancy'  => max( 2, (int) ( $data['max_adults'] ?? 2 ) ),
				'base_occupancy' => min( 2, (int) ( $data['max_occupancy'] ?? 2 ) ),
			);
		}
		return $errors ? Validation::error( $errors ) : $data;
	}

	/* ------------------------------------------------------------------ Units */

	/**
	 * Creates a room. Without room_type_id, in properties where each room is sold on its
	 * own, a hidden type is created for it from the same fields (capacity, price).
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_unit( int $property_id, array $input ): array|\WP_Error {
		$gate = $this->gate();
		if ( $gate ) {
			return $gate;
		}
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return PropertyService::not_found();
		}
		$data = $this->validate_unit( $property_id, $input, null );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		$own_type = empty( $data['room_type_id'] );
		if ( $own_type ) {
			$type_input = array_merge(
				$input,
				array(
					'name'      => '' !== (string) ( $data['name'] ?? '' ) ? $data['name'] : $data['code'],
					'is_hidden' => in_array( $property['kind'], PropertyRepository::ROOM_IS_TYPE, true ),
				)
			);
			$type_data = $this->validate_type( $type_input, null );
			if ( $type_data instanceof \WP_Error ) {
				return $type_data;
			}
			$data['room_type_id'] = $this->types->create( $property_id, $type_data );
		}
		$id = $this->units->create( $property_id, $data );
		$this->log( 'unit.created', 'unit', $id, $property_id, $data + array( 'own_type' => $own_type ) );
		Hooks::availability_changed( $property_id, array( (int) $data['room_type_id'] ) );
		return self::unit_payload( (array) $this->units->find( $id ) );
	}

	/**
	 * Creates several rooms of the same type at once (e.g. 101 … 110): either all of them
	 * or none. Codes already used, or repeated in the list, are named in the error.
	 *
	 * @param array<string, mixed> $input room_type_id, codes (string[]), floor (optional)
	 * @return array<int, array<string, mixed>>|\WP_Error the rooms created
	 */
	public function create_units( int $property_id, array $input ): array|\WP_Error {
		$gate = $this->gate();
		if ( $gate ) {
			return $gate;
		}
		if ( ! $this->properties->find( $property_id ) ) {
			return PropertyService::not_found();
		}
		$errors = array();
		$type   = $this->types->find( (int) ( $input['room_type_id'] ?? 0 ) );
		if ( ! $type || (int) $type['property_id'] !== $property_id || 'active' !== $type['status'] ) {
			$errors['room_type_id'] = __( 'Tipologia non valida.', 'booking-manager' );
		}
		$codes = array();
		foreach ( (array) ( $input['codes'] ?? array() ) as $raw ) {
			$code = strtoupper( mb_substr( sanitize_text_field( (string) $raw ), 0, 32 ) );
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}
		if ( ! $codes ) {
			$errors['codes'] = __( 'Indica i numeri delle camere.', 'booking-manager' );
		} elseif ( count( $codes ) > self::MAX_BATCH_UNITS ) {
			/* translators: %d: maximum number of rooms. */
			$errors['codes'] = sprintf( __( 'Al massimo %d camere per volta.', 'booking-manager' ), self::MAX_BATCH_UNITS );
		} else {
			$repeated = array_keys( array_filter( array_count_values( $codes ), static fn( $n ) => $n > 1 ) );
			$taken    = array_values( array_filter( array_unique( $codes ), fn( $c ) => $this->units->code_taken( $property_id, (string) $c ) ) );
			if ( $repeated ) {
				/* translators: %s: room codes. */
				$errors['codes'] = sprintf( __( 'Numeri ripetuti nell\'elenco: %s.', 'booking-manager' ), implode( ', ', $repeated ) );
			} elseif ( $taken ) {
				/* translators: %s: room codes. */
				$errors['codes'] = sprintf( __( 'Esistono già camere con questi numeri: %s.', 'booking-manager' ), implode( ', ', $taken ) );
			}
		}
		if ( $errors ) {
			return Validation::error( $errors );
		}
		$floor = sanitize_text_field( (string) ( $input['floor'] ?? '' ) );
		$ids   = Abort::run(
			function () use ( $property_id, $type, $codes, $floor ) {
				$ids = array();
				foreach ( $codes as $code ) {
					$data = array(
						'code'         => $code,
						'room_type_id' => (int) $type['id'],
						'floor'        => '' !== $floor ? mb_substr( $floor, 0, 20 ) : null,
					);
					$id   = $this->units->create( $property_id, $data );
					if ( ! $id ) {
						throw new Abort( new \WP_Error( 'bkng_db_error', __( 'Le camere non sono state create: errore del database.', 'booking-manager' ), array( 'status' => 500 ) ) );
					}
					$this->log( 'unit.created', 'unit', $id, $property_id, $data );
					$ids[] = $id;
				}
				return $ids;
			}
		);
		if ( $ids instanceof \WP_Error ) {
			return $ids;
		}
		Hooks::availability_changed( $property_id, array( (int) $type['id'] ) );
		return array_map( fn( $id ) => self::unit_payload( (array) $this->units->find( $id ) ), $ids );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_unit( int $id, array $input ): array|\WP_Error {
		$gate = $this->gate();
		if ( $gate ) {
			return $gate;
		}
		$unit = $this->units->find( $id );
		if ( ! $unit ) {
			return self::unit_not_found();
		}
		if ( isset( $input['version'] ) && (int) $input['version'] !== $unit['version'] ) {
			return PropertyService::conflict();
		}
		$data = $this->validate_unit( (int) $unit['property_id'], $input, $unit );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		$new_type = isset( $data['room_type_id'] ) ? (int) $data['room_type_id'] : (int) $unit['room_type_id'];
		if ( $new_type !== (int) $unit['room_type_id'] ) {
			$moved = $this->move_unit( $unit, $new_type );
			if ( $moved instanceof \WP_Error ) {
				return $moved;
			}
		}
		unset( $data['room_type_id'] );
		$this->units->update( $id, $data );
		// A hidden type follows the name of its only room.
		$type = $this->types->find( $new_type );
		if ( $type && $type['is_hidden'] && isset( $data['name'] ) && '' !== $data['name'] ) {
			$this->types->update( $new_type, array( 'name' => $data['name'] ) );
		}
		$this->log( 'unit.updated', 'unit', $id, (int) $unit['property_id'], $data + array( 'room_type_id' => $new_type ) );
		if ( $new_type !== (int) $unit['room_type_id'] ) {
			Hooks::availability_changed( (int) $unit['property_id'], array( (int) $unit['room_type_id'], $new_type ) );
		}
		return self::unit_payload( (array) $this->units->find( $id ) );
	}

	/**
	 * Archives a room when it has no future stays and the other rooms of its type are
	 * enough for the stays not yet assigned.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function archive_unit( int $id ): array|\WP_Error {
		$gate = $this->gate();
		if ( $gate ) {
			return $gate;
		}
		$unit = $this->units->find( $id );
		if ( ! $unit ) {
			return self::unit_not_found();
		}
		$result = Abort::run(
			function () use ( $unit ) {
				$type_id = (int) $unit['room_type_id'];
				$today   = $this->today( (int) $unit['property_id'] );
				$this->ledger->lock_types( array( $type_id ) );
				$this->guard_unit_leaving( $unit, $today );
				$this->cancel_future_blocks( (int) $unit['id'], $today );
				$this->units->set_status( (int) $unit['id'], 'archived' );
				$type = $this->types->find( $type_id );
				if ( $type && $type['is_hidden'] && 0 === $this->units->count_active( $type_id ) ) {
					$this->types->set_status( $type_id, 'archived' );
				}
				return true;
			}
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$this->log( 'unit.archived', 'unit', $id, (int) $unit['property_id'] );
		Hooks::availability_changed( (int) $unit['property_id'], array( (int) $unit['room_type_id'] ) );
		return self::unit_payload( (array) $this->units->find( $id ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function restore_unit( int $id ): array|\WP_Error {
		$gate = $this->gate();
		if ( $gate ) {
			return $gate;
		}
		$unit = $this->units->find( $id );
		if ( ! $unit ) {
			return self::unit_not_found();
		}
		$type = $this->types->find( (int) $unit['room_type_id'] );
		if ( $type && 'active' !== $type['status'] ) {
			$this->types->set_status( (int) $type['id'], 'active' );
		}
		$this->units->set_status( $id, 'active' );
		$this->log( 'unit.restored', 'unit', $id, (int) $unit['property_id'] );
		Hooks::availability_changed( (int) $unit['property_id'], array( (int) $unit['room_type_id'] ) );
		return self::unit_payload( (array) $this->units->find( $id ) );
	}

	/**
	 * @param array<string, mixed>      $input
	 * @param array<string, mixed>|null $current
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validate_unit( int $property_id, array $input, ?array $current ): array|\WP_Error {
		$errors = array();
		$data   = array();
		if ( null === $current || array_key_exists( 'code', $input ) ) {
			$code = strtoupper( mb_substr( sanitize_text_field( (string) ( $input['code'] ?? '' ) ), 0, 32 ) );
			if ( '' === $code ) {
				$errors['code'] = __( 'Indica il numero o il codice della camera.', 'booking-manager' );
			} elseif ( $this->units->code_taken( $property_id, $code, (int) ( $current['id'] ?? 0 ) ) ) {
				$errors['code'] = __( 'Esiste già una camera con questo codice.', 'booking-manager' );
			} else {
				$data['code'] = $code;
			}
		}
		if ( array_key_exists( 'name', $input ) ) {
			$data['name'] = mb_substr( sanitize_text_field( (string) $input['name'] ), 0, 190 );
		}
		if ( array_key_exists( 'floor', $input ) ) {
			$floor         = sanitize_text_field( (string) $input['floor'] );
			$data['floor'] = '' !== $floor ? mb_substr( $floor, 0, 20 ) : null;
		}
		if ( array_key_exists( 'notes', $input ) ) {
			$data['notes'] = sanitize_textarea_field( (string) $input['notes'] );
		}
		if ( array_key_exists( 'sort_order', $input ) ) {
			$data['sort_order'] = (int) $input['sort_order'];
		}
		if ( ! empty( $input['room_type_id'] ) ) {
			$type = $this->types->find( (int) $input['room_type_id'] );
			if ( ! $type || (int) $type['property_id'] !== $property_id || 'active' !== $type['status'] ) {
				$errors['room_type_id'] = __( 'Tipologia non valida.', 'booking-manager' );
			} else {
				$data['room_type_id'] = (int) $type['id'];
			}
		}
		return $errors ? Validation::error( $errors ) : $data;
	}

	/**
	 * Moves a room to another type: same rules as archiving it for the old type.
	 *
	 * @param array<string, mixed> $unit
	 * @return true|\WP_Error
	 */
	private function move_unit( array $unit, int $new_type ): bool|\WP_Error {
		return Abort::run(
			function () use ( $unit, $new_type ) {
				global $wpdb;
				$this->ledger->lock_types( array( (int) $unit['room_type_id'], $new_type ) );
				$today = $this->today( (int) $unit['property_id'] );
				$this->guard_unit_leaving( $unit, $today );
				$this->units->update( (int) $unit['id'], array( 'room_type_id' => $new_type ) );
				$wpdb->update( Schema::table( 'unit_blocks' ), array( 'room_type_id' => $new_type ), array( 'unit_id' => (int) $unit['id'] ) );
				return true;
			}
		);
	}

	/**
	 * Throws Abort when a room cannot leave its type: it has future stays, or the other
	 * rooms would not be enough for the stays of the type. Inside a transaction, with
	 * the type locked.
	 *
	 * @param array<string, mixed> $unit
	 */
	private function guard_unit_leaving( array $unit, string $today ): void {
		global $wpdb;
		$nights = Schema::table( 'unit_nights' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$stays = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$nights} WHERE unit_id = %d AND night >= %s AND booking_room_id IS NOT NULL", (int) $unit['id'], $today ) );
		if ( $stays > 0 ) {
			throw new Abort( new \WP_Error( 'bkng_unit_in_use', __( 'La camera ha soggiorni futuri: spostali prima su un\'altra camera.', 'booking-manager' ), array( 'status' => 409 ) ) );
		}
		$type_id = (int) $unit['room_type_id'];
		$horizon = $this->horizon( $type_id, $today );
		if ( null === $horizon ) {
			return;
		}
		// Blocks of this room stop counting with it: compare without them.
		$own_blocks = $this->units->future_blocks( (int) $unit['id'], $today );
		foreach ( $this->ledger->usage( $type_id, $today, $horizon ) as $night => $u ) {
			$own = 0;
			foreach ( $own_blocks as $block_id ) {
				$block = $this->units->find_block( $block_id );
				if ( $block && $block['date_from'] <= $night && $block['date_to'] > $night ) {
					++$own;
				}
			}
			if ( $u['booked'] + $u['blocked'] - $own + $u['held'] > $this->ledger->capacity( $type_id ) - 1 ) {
				throw new Abort(
					new \WP_Error(
						'bkng_type_overbooked',
						/* translators: %s: date. */
						sprintf( __( 'Senza questa camera non ci sarebbero abbastanza camere per le prenotazioni del %s.', 'booking-manager' ), wp_date( 'j F Y', strtotime( $night . ' 12:00:00 UTC' ) ) ),
						array( 'status' => 409 )
					)
				);
			}
		}
	}

	/** Last departure of the stays and blocks of a type after $today (null when none). */
	private function horizon( int $type_id, string $today ): ?string {
		global $wpdb;
		$rooms  = Schema::table( 'booking_rooms' );
		$blocks = Schema::table( 'unit_blocks' );
		$holds  = Schema::table( 'holds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$max = $wpdb->get_var( $wpdb->prepare( "SELECT GREATEST(COALESCE((SELECT MAX(departure) FROM {$rooms} WHERE room_type_id = %d AND occupies = 1 AND departure > %s), ''), COALESCE((SELECT MAX(date_to) FROM {$blocks} WHERE room_type_id = %d AND status = 'active' AND date_to > %s), ''), COALESCE((SELECT MAX(departure) FROM {$holds} WHERE room_type_id = %d AND status = 'active' AND departure > %s), ''))", $type_id, $today, $type_id, $today, $type_id, $today ) );
		return '' !== (string) $max ? (string) $max : null;
	}

	private function future_stays( int $type_id, string $today ): int {
		global $wpdb;
		$rooms = Schema::table( 'booking_rooms' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rooms} WHERE room_type_id = %d AND occupies = 1 AND departure > %s", $type_id, $today ) );
	}

	private function cancel_future_blocks( int $unit_id, string $today ): void {
		foreach ( $this->units->future_blocks( $unit_id, $today ) as $block_id ) {
			$this->ledger->release_block( $block_id );
			$this->units->cancel_block( $block_id );
		}
	}

	/* ------------------------------------------------------------------ Out of service */

	/**
	 * Takes a room out of sale from $from to $to (the day $to the room is available again).
	 *
	 * @param array<string, mixed> $input date_from, date_to, kind, reason
	 * @return array<string, mixed>|\WP_Error
	 */
	public function add_block( int $unit_id, array $input, int $user_id ): array|\WP_Error {
		$unit = $this->units->find( $unit_id );
		if ( ! $unit || 'active' !== $unit['status'] ) {
			return self::unit_not_found();
		}
		$from = Validation::date( $input['date_from'] ?? null );
		$to   = Validation::date( $input['date_to'] ?? null );
		if ( null === $from || null === $to || Dates::count( $from, $to ) < 1 || Dates::count( $from, $to ) > Dates::MAX_NIGHTS ) {
			return Validation::error( array( 'date_to' => __( 'Il periodo non è valido: la data di fine deve essere successiva all\'inizio.', 'booking-manager' ) ) );
		}
		$kind   = in_array( $input['kind'] ?? '', self::BLOCK_KINDS, true ) ? (string) $input['kind'] : 'out_of_service';
		$reason = mb_substr( sanitize_text_field( (string) ( $input['reason'] ?? '' ) ), 0, 190 );
		$result = Abort::run(
			function () use ( $unit, $from, $to, $kind, $reason, $user_id ) {
				$type_id = (int) $unit['room_type_id'];
				$this->ledger->lock_types( array( $type_id ) );
				if ( $this->ledger->free( $type_id, $from, $to ) < 1 ) {
					throw new Abort( new \WP_Error( 'bkng_unavailable', __( 'In questo periodo tutte le camere di questa tipologia sono già vendute: non si può togliere dalla vendita.', 'booking-manager' ), array( 'status' => 409 ) ) );
				}
				$block_id = $this->units->create_block(
					array(
						'property_id'  => (int) $unit['property_id'],
						'unit_id'      => (int) $unit['id'],
						'room_type_id' => $type_id,
						'date_from'    => $from,
						'date_to'      => $to,
						'kind'         => $kind,
						'reason'       => '' !== $reason ? $reason : null,
						'created_by'   => $user_id ? $user_id : null,
					)
				);
				if ( ! $this->ledger->occupy( (int) $unit['id'], (int) $unit['property_id'], $from, $to, null, $block_id ) ) {
					throw new Abort( new \WP_Error( 'bkng_unit_taken', __( 'La camera ha già un soggiorno o un blocco in queste date.', 'booking-manager' ), array( 'status' => 409 ) ) );
				}
				return $block_id;
			}
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$this->log(
			'unit.blocked',
			'unit',
			(int) $unit['id'],
			(int) $unit['property_id'],
			array(
				'from' => $from,
				'to'   => $to,
				'kind' => $kind,
			)
		);
		Hooks::availability_changed( (int) $unit['property_id'], array( (int) $unit['room_type_id'] ), $from, $to );
		return (array) $this->units->find_block( (int) $result );
	}

	/** @return true|\WP_Error */
	public function remove_block( int $block_id ): bool|\WP_Error {
		$block = $this->units->find_block( $block_id );
		if ( ! $block || 'active' !== $block['status'] ) {
			return new \WP_Error( 'bkng_block_not_found', __( 'Periodo non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$this->ledger->release_block( $block_id );
		$this->units->cancel_block( $block_id );
		$this->log( 'unit.unblocked', 'unit', (int) $block['unit_id'], (int) $block['property_id'], array( 'block_id' => $block_id ) );
		Hooks::availability_changed( (int) $block['property_id'], array( (int) $block['room_type_id'] ), (string) $block['date_from'], (string) $block['date_to'] );
		return true;
	}

	/* ------------------------------------------------------------------ Payloads */

	/**
	 * @param array<string, mixed> $t
	 * @return array<string, mixed>
	 */
	public static function type_payload( array $t ): array {
		$images = array();
		foreach ( $t['images'] as $image_id ) {
			$url = wp_get_attachment_image_url( (int) $image_id, 'large' );
			if ( $url ) {
				$thumb    = wp_get_attachment_image_url( (int) $image_id, 'medium' );
				$images[] = array(
					'id'    => (int) $image_id,
					'url'   => $url,
					'thumb' => $thumb ? $thumb : $url,
					'alt'   => (string) get_post_meta( (int) $image_id, '_wp_attachment_image_alt', true ),
				);
			}
		}
		return array_intersect_key( $t, array_flip( array( 'id', 'uuid', 'property_id', 'name', 'slug', 'description', 'max_adults', 'max_children', 'max_occupancy', 'base_occupancy', 'base_price', 'extra_adult_price', 'extra_child_price', 'size_sqm', 'beds', 'amenities', 'is_hidden', 'online_enabled', 'sort_order', 'status', 'version' ) ) ) + array( 'images' => $images );
	}

	/**
	 * @param array<string, mixed> $u
	 * @return array<string, mixed>
	 */
	public static function unit_payload( array $u ): array {
		return array_intersect_key( $u, array_flip( array( 'id', 'uuid', 'property_id', 'room_type_id', 'code', 'name', 'floor', 'notes', 'op_status', 'op_note', 'op_updated_at', 'sort_order', 'status', 'version' ) ) );
	}

	public static function type_not_found(): \WP_Error {
		return new \WP_Error( 'bkng_room_type_not_found', __( 'Tipologia non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
	}

	public static function unit_not_found(): \WP_Error {
		return new \WP_Error( 'bkng_unit_not_found', __( 'Camera non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
	}

	/* ------------------------------------------------------------------ Internals */

	private function gate(): ?\WP_Error {
		if ( $this->license->allows( LicenseGate::OP_ROOMS_MANAGE ) ) {
			return null;
		}
		return new \WP_Error( 'bkng_license_required', __( 'La licenza non permette di modificare camere e tipologie.', 'booking-manager' ), array( 'status' => 403 ) );
	}

	private function today( int $property_id ): string {
		$property = $this->properties->find( $property_id );
		return $this->clock->today_in( $property ? (string) $property['timezone'] : 'Europe/Rome' );
	}

	/** @param array<string, mixed> $new */
	private function log( string $action, string $entity, int $id, int $property_id, array $new = array() ): void {
		$this->audit->log(
			array(
				'action'      => $action,
				'entity_type' => $entity,
				'entity_id'   => $id,
				'property_id' => $property_id,
				'new'         => $new ? $new : null,
			)
		);
	}
}
