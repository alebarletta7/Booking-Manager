<?php
/**
 * Rate plans, price periods, adjustments and stay restrictions: validation and changes.
 *
 * Prices already sold never change: every booking keeps a copy of its price and terms.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rates;

use BookingManager\Audit\AuditLogger;
use BookingManager\Availability\RestrictionRepository;
use BookingManager\Core\Dates;
use BookingManager\Core\Hooks;
use BookingManager\Core\Validation;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Properties\PropertyService;

defined( 'ABSPATH' ) || exit;

class RateService {

	public const MEAL_PLANS = array( 'room_only', 'breakfast', 'half_board', 'full_board', 'all_inclusive' );

	public function __construct(
		private RateRepository $repo,
		private RestrictionRepository $restrictions,
		private RoomTypeRepository $types,
		private PropertyRepository $properties,
		private AuditLogger $audit
	) {}

	/* ------------------------------------------------------------------ Plans */

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_plan( int $property_id, array $input ): array|\WP_Error {
		if ( ! $this->properties->find( $property_id ) ) {
			return PropertyService::not_found();
		}
		$data = $this->validate_plan( $property_id, $input, null );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		if ( ! $this->repo->plans( $property_id ) ) {
			$data['is_default'] = 1;
		}
		$id = $this->repo->create_plan( $property_id, $data );
		if ( ! empty( $data['is_default'] ) ) {
			$this->repo->clear_default( $property_id, $id );
		}
		$this->log( 'rate_plan.created', 'rate_plan', $id, $property_id );
		Hooks::rates_changed( $property_id, array(), array( $id ) );
		return (array) $this->repo->find_plan( $id );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_plan( int $id, array $input ): array|\WP_Error {
		$plan = $this->repo->find_plan( $id );
		if ( ! $plan ) {
			return self::not_found();
		}
		if ( isset( $input['version'] ) && (int) $input['version'] !== $plan['version'] ) {
			return PropertyService::conflict();
		}
		$data = $this->validate_plan( (int) $plan['property_id'], $input, $plan );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		$this->repo->update_plan( $id, $data );
		if ( ! empty( $data['is_default'] ) ) {
			$this->repo->clear_default( (int) $plan['property_id'], $id );
		}
		$this->log( 'rate_plan.updated', 'rate_plan', $id, (int) $plan['property_id'] );
		Hooks::rates_changed( (int) $plan['property_id'], array(), array( $id ) );
		return (array) $this->repo->find_plan( $id );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function archive_plan( int $id ): array|\WP_Error {
		$plan = $this->repo->find_plan( $id );
		if ( ! $plan ) {
			return self::not_found();
		}
		$this->repo->set_plan_status( $id, 'archived' );
		$this->log( 'rate_plan.archived', 'rate_plan', $id, (int) $plan['property_id'] );
		Hooks::rates_changed( (int) $plan['property_id'], array(), array( $id ) );
		return (array) $this->repo->find_plan( $id );
	}

	/**
	 * @param array<string, mixed>      $input
	 * @param array<string, mixed>|null $current
	 * @return array<string, mixed>|\WP_Error
	 */
	private function validate_plan( int $property_id, array $input, ?array $current ): array|\WP_Error {
		$errors = array();
		$data   = array();
		if ( null === $current || array_key_exists( 'name', $input ) ) {
			$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
			if ( '' === $name ) {
				$errors['name'] = __( 'Indica il nome della tariffa.', 'booking-manager' );
			} else {
				$data['name'] = mb_substr( $name, 0, 190 );
			}
		}
		if ( array_key_exists( 'description', $input ) ) {
			$data['description'] = sanitize_textarea_field( (string) $input['description'] );
		}
		if ( array_key_exists( 'room_type_ids', $input ) ) {
			$ids = $this->own_types( $property_id, (array) $input['room_type_ids'] );
			if ( null === $ids ) {
				$errors['room_type_ids'] = __( 'Una delle tipologie non è valida.', 'booking-manager' );
			} else {
				$data['room_type_ids'] = $ids;
			}
		}
		if ( array_key_exists( 'modifier_bp', $input ) ) {
			$value = Validation::int_between( $input['modifier_bp'], -9000, 10000 );
			if ( null === $value ) {
				$errors['modifier_bp'] = __( 'Variazione non valida (da −90% a +100%).', 'booking-manager' );
			} else {
				$data['modifier_bp'] = $value;
			}
		}
		if ( array_key_exists( 'meal_plan', $input ) ) {
			if ( in_array( $input['meal_plan'], self::MEAL_PLANS, true ) ) {
				$data['meal_plan'] = $input['meal_plan'];
			} else {
				$errors['meal_plan'] = __( 'Trattamento non valido.', 'booking-manager' );
			}
		}
		if ( array_key_exists( 'cancellation_policy', $input ) ) {
			$data['cancellation_policy'] = Policies::cancellation( $input['cancellation_policy'] );
		}
		if ( array_key_exists( 'deposit_policy', $input ) ) {
			$data['deposit_policy'] = Policies::deposit( $input['deposit_policy'] );
		}
		foreach ( array( 'is_default', 'is_public' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$data[ $flag ] = rest_sanitize_boolean( $input[ $flag ] ) ? 1 : 0;
			}
		}
		if ( array_key_exists( 'sort_order', $input ) ) {
			$data['sort_order'] = (int) $input['sort_order'];
		}
		return $errors ? Validation::error( $errors ) : $data;
	}

	/* ------------------------------------------------------------------ Prices */

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save_price( int $property_id, array $input, int $id = 0 ): array|\WP_Error {
		$current = $id ? $this->repo->find_price( $id ) : null;
		if ( $id && ( ! $current || (int) $current['property_id'] !== $property_id ) ) {
			return new \WP_Error( 'bkng_price_not_found', __( 'Periodo non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$input  = array_merge( (array) $current, $input );
		$errors = array();
		$type   = $this->types->find( (int) ( $input['room_type_id'] ?? 0 ) );
		if ( ! $type || (int) $type['property_id'] !== $property_id ) {
			$errors['room_type_id'] = __( 'Tipologia non valida.', 'booking-manager' );
		}
		$plan_id = ! empty( $input['rate_plan_id'] ) ? (int) $input['rate_plan_id'] : null;
		if ( null !== $plan_id ) {
			$plan = $this->repo->find_plan( $plan_id );
			if ( ! $plan || (int) $plan['property_id'] !== $property_id ) {
				$errors['rate_plan_id'] = __( 'Tariffa non valida.', 'booking-manager' );
			}
		}
		$from = Validation::date( $input['date_from'] ?? null );
		$to   = Validation::date( $input['date_to'] ?? null );
		if ( null === $from || null === $to || $to < $from ) {
			$errors['date_to'] = __( 'Periodo non valido.', 'booking-manager' );
		}
		$price = Validation::int_between( $input['price_night'] ?? null, 0, 100000000 );
		if ( null === $price ) {
			$errors['price_night'] = __( 'Prezzo non valido.', 'booking-manager' );
		}
		$extras = array();
		foreach ( array( 'extra_adult_price', 'extra_child_price' ) as $key ) {
			if ( Validation::blank( $input[ $key ] ?? null ) ) {
				$extras[ $key ] = null;
				continue;
			}
			$extras[ $key ] = Validation::int_between( $input[ $key ], 0, 100000000 );
			if ( null === $extras[ $key ] ) {
				$errors[ $key ] = __( 'Prezzo non valido.', 'booking-manager' );
			}
		}
		$weekdays = Dates::weekdays( $input['weekdays'] ?? '1,2,3,4,5,6,7' );
		if ( null === $weekdays ) {
			$errors['weekdays'] = __( 'Scegli almeno un giorno della settimana.', 'booking-manager' );
		}
		if ( $errors ) {
			return Validation::error( $errors );
		}
		$data = array(
			'rate_plan_id'      => $plan_id,
			'room_type_id'      => (int) $type['id'],
			'label'             => mb_substr( sanitize_text_field( (string) ( $input['label'] ?? '' ) ), 0, 190 ),
			'date_from'         => $from,
			'date_to'           => $to,
			'weekdays'          => $weekdays,
			'price_night'       => $price,
			'extra_adult_price' => $extras['extra_adult_price'],
			'extra_child_price' => $extras['extra_child_price'],
			'priority'          => (int) ( $input['priority'] ?? 0 ),
		);
		if ( $id ) {
			$this->repo->update_price( $id, $data );
		} else {
			$id = $this->repo->create_price( $property_id, $data );
		}
		$this->log( 'rate_price.saved', 'rate_price', $id, $property_id, $data );
		// A period without plan prices every plan of the type; a changed period frees its old dates too.
		if ( $current ) {
			Hooks::rates_changed( $property_id, array( (int) $current['room_type_id'] ), array(), (string) $current['date_from'], Dates::add_days( (string) $current['date_to'], 1 ) );
		}
		Hooks::rates_changed( $property_id, array( (int) $type['id'] ), array(), $from, Dates::add_days( $to, 1 ) );
		return (array) $this->repo->find_price( $id );
	}

	/** @return true|\WP_Error */
	public function delete_price( int $id ): bool|\WP_Error {
		$price = $this->repo->find_price( $id );
		if ( ! $price ) {
			return new \WP_Error( 'bkng_price_not_found', __( 'Periodo non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$this->repo->delete_price( $id );
		$this->log( 'rate_price.deleted', 'rate_price', $id, (int) $price['property_id'], $price );
		Hooks::rates_changed( (int) $price['property_id'], array( (int) $price['room_type_id'] ), array(), (string) $price['date_from'], Dates::add_days( (string) $price['date_to'], 1 ) );
		return true;
	}

	/* ------------------------------------------------------------------ Adjustments */

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save_adjustment( int $property_id, array $input, int $id = 0 ): array|\WP_Error {
		$current = $id ? $this->repo->find_adjustment( $id ) : null;
		if ( $id && ( ! $current || (int) $current['property_id'] !== $property_id ) ) {
			return new \WP_Error( 'bkng_adjustment_not_found', __( 'Supplemento o sconto non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$input  = array_merge( (array) $current, $input );
		$errors = array();
		$name   = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			$errors['name'] = __( 'Indica un nome.', 'booking-manager' );
		}
		if ( ! in_array( $input['kind'] ?? '', PricingEngine::KINDS, true ) ) {
			$errors['kind'] = __( 'Tipo non valido.', 'booking-manager' );
		}
		$per = in_array( $input['per'] ?? 'stay', PricingEngine::PER, true ) ? (string) ( $input['per'] ?? 'stay' ) : null;
		if ( null === $per ) {
			$errors['per'] = __( 'Modalità di calcolo non valida.', 'booking-manager' );
		}
		$amount  = Validation::blank( $input['amount'] ?? null ) ? null : Validation::int_between( $input['amount'], 0, 100000000 );
		$percent = Validation::blank( $input['percent_bp'] ?? null ) ? null : Validation::int_between( $input['percent_bp'], 0, 10000 );
		if ( null === $amount && null === $percent ) {
			$errors['amount'] = __( 'Indica un importo o una percentuale.', 'booking-manager' );
		}
		$plan_id = ! empty( $input['rate_plan_id'] ) ? (int) $input['rate_plan_id'] : null;
		if ( null !== $plan_id ) {
			$plan = $this->repo->find_plan( $plan_id );
			if ( ! $plan || (int) $plan['property_id'] !== $property_id ) {
				$errors['rate_plan_id'] = __( 'Tariffa non valida.', 'booking-manager' );
			}
		}
		$types = $this->own_types( $property_id, (array) ( $input['room_type_ids'] ?? array() ) );
		if ( null === $types ) {
			$errors['room_type_ids'] = __( 'Una delle tipologie non è valida.', 'booking-manager' );
		}
		$from = Validation::blank( $input['date_from'] ?? null ) ? null : Validation::date( $input['date_from'] );
		$to   = Validation::blank( $input['date_to'] ?? null ) ? null : Validation::date( $input['date_to'] );
		if ( null !== $from && null !== $to && $to < $from ) {
			$errors['date_to'] = __( 'Periodo non valido.', 'booking-manager' );
		}
		// An optional supplement may include free cancellation up to N days before arrival
		// (e.g. «Cancellazione gratuita» for 15 € or 10%); only a supplement the guest chooses.
		$free_days = Validation::blank( $input['free_cancel_days'] ?? null ) ? null : Validation::int_between( $input['free_cancel_days'], 1, 365 );
		if ( ! Validation::blank( $input['free_cancel_days'] ?? null ) && null === $free_days ) {
			$errors['free_cancel_days'] = __( 'Giorni non validi (da 1 a 365).', 'booking-manager' );
		}
		if ( null !== $free_days && ( 'supplement' !== ( $input['kind'] ?? '' ) || ! rest_sanitize_boolean( $input['is_optional'] ?? false ) ) ) {
			$errors['free_cancel_days'] = __( 'La cancellazione gratuita si offre solo come supplemento facoltativo.', 'booking-manager' );
		}
		if ( $errors ) {
			return Validation::error( $errors );
		}
		$data = array(
			'rate_plan_id'  => $plan_id,
			'kind'          => (string) $input['kind'],
			'name'          => mb_substr( $name, 0, 190 ),
			'description'   => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			'amount'        => $amount,
			'percent_bp'    => $percent,
			'per'           => $per,
			'min_nights'    => Validation::blank( $input['min_nights'] ?? null ) ? null : Validation::int_between( $input['min_nights'], 1, 365 ),
			'max_nights'    => Validation::blank( $input['max_nights'] ?? null ) ? null : Validation::int_between( $input['max_nights'], 1, 365 ),
			'date_from'     => $from,
			'date_to'       => $to,
			'room_type_ids' => $types,
			'is_optional'   => rest_sanitize_boolean( $input['is_optional'] ?? false ) ? 1 : 0,
			'is_public'     => rest_sanitize_boolean( $input['is_public'] ?? true ) ? 1 : 0,
			'free_cancel_days' => $free_days,
			'sort_order'    => (int) ( $input['sort_order'] ?? 0 ),
		);
		if ( $id ) {
			$this->repo->update_adjustment( $id, $data );
		} else {
			$id = $this->repo->create_adjustment( $property_id, $data );
		}
		$this->log( 'rate_adjustment.saved', 'rate_adjustment', $id, $property_id );
		return (array) $this->repo->find_adjustment( $id );
	}

	/** @return true|\WP_Error */
	public function archive_adjustment( int $id ): bool|\WP_Error {
		$adj = $this->repo->find_adjustment( $id );
		if ( ! $adj ) {
			return new \WP_Error( 'bkng_adjustment_not_found', __( 'Supplemento o sconto non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$this->repo->set_adjustment_status( $id, 'archived' );
		$this->log( 'rate_adjustment.archived', 'rate_adjustment', $id, (int) $adj['property_id'] );
		return true;
	}

	/* ------------------------------------------------------------------ Restrictions */

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save_restriction( int $property_id, array $input, int $user_id, int $id = 0 ): array|\WP_Error {
		$current = $id ? $this->restrictions->find( $id ) : null;
		if ( $id && ( ! $current || (int) $current['property_id'] !== $property_id ) ) {
			return new \WP_Error( 'bkng_restriction_not_found', __( 'Regola non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$input  = array_merge( (array) $current, $input );
		$errors = array();
		$from   = Validation::date( $input['date_from'] ?? null );
		$to     = Validation::date( $input['date_to'] ?? null );
		if ( null === $from || null === $to || $to < $from ) {
			$errors['date_to'] = __( 'Periodo non valido.', 'booking-manager' );
		}
		$type_id = ! empty( $input['room_type_id'] ) ? (int) $input['room_type_id'] : null;
		if ( null !== $type_id && null === $this->own_types( $property_id, array( $type_id ) ) ) {
			$errors['room_type_id'] = __( 'Tipologia non valida.', 'booking-manager' );
		}
		$plan_id = ! empty( $input['rate_plan_id'] ) ? (int) $input['rate_plan_id'] : null;
		if ( null !== $plan_id ) {
			$plan = $this->repo->find_plan( $plan_id );
			if ( ! $plan || (int) $plan['property_id'] !== $property_id ) {
				$errors['rate_plan_id'] = __( 'Tariffa non valida.', 'booking-manager' );
			}
		}
		$data = array(
			'room_type_id'        => $type_id,
			'rate_plan_id'        => $plan_id,
			'date_from'           => $from,
			'date_to'             => $to,
			'arrival_weekdays'    => Validation::blank( $input['arrival_weekdays'] ?? null ) ? null : Dates::weekdays( $input['arrival_weekdays'] ),
			'departure_weekdays'  => Validation::blank( $input['departure_weekdays'] ?? null ) ? null : Dates::weekdays( $input['departure_weekdays'] ),
			'closed'              => rest_sanitize_boolean( $input['closed'] ?? false ) ? 1 : 0,
			'closed_to_arrival'   => rest_sanitize_boolean( $input['closed_to_arrival'] ?? false ) ? 1 : 0,
			'closed_to_departure' => rest_sanitize_boolean( $input['closed_to_departure'] ?? false ) ? 1 : 0,
			'note'                => mb_substr( sanitize_text_field( (string) ( $input['note'] ?? '' ) ), 0, 190 ),
		);
		foreach ( array( 'min_stay' => array( 1, 365 ), 'max_stay' => array( 1, 365 ), 'min_advance_days' => array( 0, 730 ), 'max_advance_days' => array( 0, 1095 ) ) as $key => [ $min, $max ] ) {
			if ( Validation::blank( $input[ $key ] ?? null ) ) {
				$data[ $key ] = null;
				continue;
			}
			$data[ $key ] = Validation::int_between( $input[ $key ], $min, $max );
			if ( null === $data[ $key ] ) {
				$errors[ $key ] = __( 'Valore non valido.', 'booking-manager' );
			}
		}
		if ( null !== ( $data['min_stay'] ?? null ) && null !== ( $data['max_stay'] ?? null ) && $data['max_stay'] < $data['min_stay'] ) {
			$errors['max_stay'] = __( 'Il soggiorno massimo deve essere almeno pari al minimo.', 'booking-manager' );
		}
		if ( $errors ) {
			return Validation::error( $errors );
		}
		if ( $id ) {
			$this->restrictions->update( $id, $data );
		} else {
			$id = $this->restrictions->create( $property_id, $data, $user_id );
		}
		$this->log( 'restriction.saved', 'restriction', $id, $property_id, $data );
		if ( $current ) {
			self::restriction_changed( $property_id, $current );
		}
		self::restriction_changed( $property_id, $data );
		return (array) $this->restrictions->find( $id );
	}

	/** @return true|\WP_Error */
	public function delete_restriction( int $id ): bool|\WP_Error {
		$row = $this->restrictions->find( $id );
		if ( ! $row ) {
			return new \WP_Error( 'bkng_restriction_not_found', __( 'Regola non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$this->restrictions->delete( $id );
		$this->log( 'restriction.deleted', 'restriction', $id, (int) $row['property_id'], $row );
		self::restriction_changed( (int) $row['property_id'], $row );
		return true;
	}

	/**
	 * A rule changed: its dates (the departure rules act on date_to, so one day more),
	 * for its type and plan or for all of them.
	 *
	 * @param array<string, mixed> $rule
	 */
	private static function restriction_changed( int $property_id, array $rule ): void {
		Hooks::rates_changed(
			$property_id,
			empty( $rule['room_type_id'] ) ? array() : array( (int) $rule['room_type_id'] ),
			empty( $rule['rate_plan_id'] ) ? array() : array( (int) $rule['rate_plan_id'] ),
			(string) $rule['date_from'],
			Dates::add_days( (string) $rule['date_to'], 1 )
		);
	}

	/**
	 * @param array<int, mixed> $ids
	 * @return int[]|null null when one id is not a type of the property
	 */
	private function own_types( int $property_id, array $ids ): ?array {
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$found = $this->types->find_many( $ids );
		foreach ( $ids as $id ) {
			if ( ! isset( $found[ $id ] ) || (int) $found[ $id ]['property_id'] !== $property_id ) {
				return null;
			}
		}
		return $ids;
	}

	public static function not_found(): \WP_Error {
		return new \WP_Error( 'bkng_rate_plan_not_found', __( 'Tariffa non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
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
