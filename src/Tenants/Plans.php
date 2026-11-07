<?php
/**
 * The platform's plans for its customers (option bkng_plans, not a table): name, limits
 * and functions. A customer takes the plan's values; the platform administrator can
 * override single limits or functions for one customer (bkng_tenants.limits / features).
 *
 * Limits are enforced from 0.2.2 (TenantGate: properties and rooms). The functions are
 * stored now and enforced by each function when it becomes per customer (custom SMTP in
 * 0.2.5, WhatsApp Meta and Channex in 0.2.6). No subscription is charged here: a future
 * billing module can change the customer's status and expiry.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

use BookingManager\Core\Validation;

defined( 'ABSPATH' ) || exit;

class Plans {

	public const OPTION = 'bkng_plans';

	/** The plan of the customers created by the data migration. */
	public const DEFAULT_PLAN = 'unlimited';

	/** Numeric limits; 0 = no limit. */
	public const LIMITS = array( 'max_properties', 'max_units' );

	public const FEATURES = array( 'online_payment', 'whatsapp_meta', 'channex', 'custom_smtp' );

	/** @return array<string, string> */
	public static function feature_labels(): array {
		return array(
			'online_payment' => __( 'Pagamenti online', 'booking-manager' ),
			'whatsapp_meta'  => __( 'WhatsApp (Meta)', 'booking-manager' ),
			'channex'        => __( 'Channex (portali)', 'booking-manager' ),
			'custom_smtp'    => __( 'Server email (SMTP) proprio', 'booking-manager' ),
		);
	}

	/** @return array<string, string> */
	public static function limit_labels(): array {
		return array(
			'max_properties' => __( 'Strutture', 'booking-manager' ),
			'max_units'      => __( 'Camere', 'booking-manager' ),
		);
	}

	/**
	 * Every plan, by key. The «Senza limiti» plan always exists.
	 *
	 * @return array<string, array{key: string, name: string, limits: array<string, int>, features: array<string, bool>}>
	 */
	public function all(): array {
		$saved = get_option( self::OPTION, array() );
		$plans = array();
		foreach ( is_array( $saved ) ? $saved : array() as $key => $plan ) {
			if ( is_string( $key ) && is_array( $plan ) ) {
				$plans[ $key ] = self::normalize( $key, $plan );
			}
		}
		if ( ! isset( $plans[ self::DEFAULT_PLAN ] ) ) {
			$plans = array( self::DEFAULT_PLAN => self::unlimited() ) + $plans;
		}
		return $plans;
	}

	/** @return array{key: string, name: string, limits: array<string, int>, features: array<string, bool>}|null */
	public function find( string $key ): ?array {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Replaces the list of plans. A plan still used by a customer cannot be removed.
	 *
	 * @param mixed    $input list of {key, name, limits, features}
	 * @param string[] $in_use keys used by at least one customer
	 * @return array<string, array<string, mixed>>|\WP_Error
	 */
	public function save( $input, array $in_use ): array|\WP_Error {
		$plans = array();
		foreach ( is_array( $input ) ? $input : array() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$key  = sanitize_key( (string) ( $raw['key'] ?? '' ) );
			$name = trim( sanitize_text_field( (string) ( $raw['name'] ?? '' ) ) );
			if ( '' === $key || strlen( $key ) > 40 ) {
				return Validation::error( array( 'key' => __( 'Ogni piano ha bisogno di un codice (lettere minuscole, numeri, - e _).', 'booking-manager' ) ) );
			}
			if ( '' === $name ) {
				return Validation::error( array( 'name' => __( 'Ogni piano ha bisogno di un nome.', 'booking-manager' ) ) );
			}
			if ( isset( $plans[ $key ] ) ) {
				/* translators: %s: plan code */
				return Validation::error( array( 'key' => sprintf( __( 'Il codice «%s» è usato due volte.', 'booking-manager' ), $key ) ) );
			}
			foreach ( self::LIMITS as $limit ) {
				$value = $raw['limits'][ $limit ] ?? 0;
				if ( null === Validation::int_between( Validation::blank( $value ) ? 0 : $value, 0, 100000 ) ) {
					return Validation::error( array( $limit => __( 'Limite non valido: usa un numero intero, 0 = nessun limite.', 'booking-manager' ) ) );
				}
			}
			$plans[ $key ] = self::normalize( $key, $raw + array( 'name' => $name ) );
		}
		$missing = array_diff( $in_use, array_keys( $plans ), array( self::DEFAULT_PLAN ) );
		if ( $missing ) {
			/* translators: %s: plan codes */
			return Validation::error( array( 'plans' => sprintf( __( 'Questi piani sono usati da almeno un cliente e non si possono togliere: %s.', 'booking-manager' ), implode( ', ', $missing ) ) ) );
		}
		update_option( self::OPTION, $plans, false );
		return $this->all();
	}

	/**
	 * Limits of a customer: those of its plan, with its own overrides.
	 *
	 * @param array<string, mixed> $tenant
	 * @return array<string, int>
	 */
	public function limits_of( array $tenant ): array {
		$plan   = $this->find( (string) ( $tenant['plan'] ?? '' ) ) ?? self::unlimited();
		$limits = $plan['limits'];
		foreach ( self::LIMITS as $limit ) {
			if ( isset( $tenant['limits'][ $limit ] ) && is_numeric( $tenant['limits'][ $limit ] ) ) {
				$limits[ $limit ] = max( 0, (int) $tenant['limits'][ $limit ] );
			}
		}
		return $limits;
	}

	/**
	 * @param array<string, mixed> $tenant
	 * @return array<string, bool>
	 */
	public function features_of( array $tenant ): array {
		$plan     = $this->find( (string) ( $tenant['plan'] ?? '' ) ) ?? self::unlimited();
		$features = $plan['features'];
		foreach ( self::FEATURES as $feature ) {
			if ( isset( $tenant['features'][ $feature ] ) ) {
				$features[ $feature ] = (bool) $tenant['features'][ $feature ];
			}
		}
		return $features;
	}

	/** @return array{key: string, name: string, limits: array<string, int>, features: array<string, bool>} */
	private static function unlimited(): array {
		return self::normalize(
			self::DEFAULT_PLAN,
			array(
				'name'     => __( 'Senza limiti', 'booking-manager' ),
				'features' => array_fill_keys( self::FEATURES, true ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $plan
	 * @return array{key: string, name: string, limits: array<string, int>, features: array<string, bool>}
	 */
	private static function normalize( string $key, array $plan ): array {
		$limits   = array();
		$features = array();
		foreach ( self::LIMITS as $limit ) {
			$limits[ $limit ] = max( 0, (int) ( $plan['limits'][ $limit ] ?? 0 ) );
		}
		foreach ( self::FEATURES as $feature ) {
			$features[ $feature ] = ! empty( $plan['features'][ $feature ] );
		}
		return array(
			'key'      => $key,
			'name'     => mb_substr( (string) ( $plan['name'] ?? $key ), 0, 100 ),
			'limits'   => $limits,
			'features' => $features,
		);
	}
}
