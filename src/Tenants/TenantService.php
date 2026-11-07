<?php
/**
 * The platform's work on its customers (Piattaforma panel): create, change, suspend,
 * reactivate and archive a customer, its authorised domains and its people, the plans,
 * and the platform's aggregated numbers. Every call comes from a platform administrator
 * (the REST routes require bkng_platform_manage); every change is written to the
 * activity log as a platform entry (no tenant: only the platform reads it).
 *
 * Nothing is ever deleted: an archived customer keeps its data.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

use BookingManager\Access\Capabilities;
use BookingManager\Audit\AuditLogger;
use BookingManager\Core\Validation;
use BookingManager\Database\Schema;
use BookingManager\Staff\StaffService;

defined( 'ABSPATH' ) || exit;

class TenantService {

	public function __construct(
		private TenantRepository $tenants,
		private Plans $plans,
		private TenantGate $gate,
		private StaffService $staff,
		private AuditLogger $audit
	) {}

	/** @return array<string, string> */
	public static function business_types(): array {
		return (array) apply_filters(
			'bkng_tenant_business_types',
			array(
				'hotel'        => __( 'Hotel', 'booking-manager' ),
				'bnb'          => __( 'B&B', 'booking-manager' ),
				'casa_vacanze' => __( 'Casa vacanze', 'booking-manager' ),
				'affittacamere' => __( 'Affittacamere', 'booking-manager' ),
				'resort'       => __( 'Resort', 'booking-manager' ),
				'agriturismo'  => __( 'Agriturismo', 'booking-manager' ),
				'residence'    => __( 'Residence', 'booking-manager' ),
				'altro'        => __( 'Altro', 'booking-manager' ),
			)
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function list( string $term = '', string $status = '' ): array {
		$status = in_array( $status, TenantStatus::ALL, true ) ? $status : '';
		return array_map( array( $this, 'format' ), $this->tenants->search( $term, $status ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function get( int $id ): array|\WP_Error {
		$tenant = $this->tenants->find( $id );
		if ( ! $tenant ) {
			return self::not_found();
		}
		return $this->format( $tenant ) + array(
			'domains'          => $this->tenants->domains( $id ),
			'usage'            => $this->gate->usage( $id ),
			'limits'           => $this->plans->limits_of( $tenant ),
			'features'         => $this->plans->features_of( $tenant ),
			'limits_override'  => (object) $tenant['limits'],
			'features_override' => (object) $tenant['features'],
		);
	}

	/**
	 * A new customer, optionally with its first administrator (who receives the standard
	 * WordPress e-mail to choose a password). Properties are created by the customer's
	 * administrator (or by the platform from the Struttura screen).
	 *
	 * @param array<string, mixed> $input name, business_type, plan, status (active|trial), expires_at, admin{email, first_name, last_name}
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( array $input ): array|\WP_Error {
		$data = $this->validate( $input, null );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		$status = (string) ( $input['status'] ?? TenantStatus::ACTIVE );
		if ( ! in_array( $status, TenantStatus::CAN_SELL, true ) ) {
			return Validation::error( array( 'status' => __( 'Un cliente nuovo è attivo o in prova.', 'booking-manager' ) ) );
		}
		$admin = is_array( $input['admin'] ?? null ) ? $input['admin'] : array();
		$email = strtolower( trim( (string) ( $admin['email'] ?? '' ) ) );
		if ( '' !== $email ) {
			if ( ! is_email( $email ) ) {
				return Validation::error( array( 'admin_email' => __( 'Indirizzo email non valido.', 'booking-manager' ) ) );
			}
			if ( email_exists( $email ) ) {
				return Validation::error( array( 'admin_email' => __( 'Esiste già un utente con questa email: crea il cliente senza amministratore e aggiungilo poi da «Persone».', 'booking-manager' ) ) );
			}
			if ( '' === trim( (string) ( $admin['first_name'] ?? '' ) ) ) {
				return Validation::error( array( 'admin_first_name' => __( 'Nome dell\'amministratore obbligatorio.', 'booking-manager' ) ) );
			}
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$id  = $this->tenants->create(
			$data + array(
				'status'       => $status,
				'activated_at' => $now,
				'is_default'   => 0,
				'created_by'   => get_current_user_id() ?: null,
			)
		);
		if ( $id <= 0 ) {
			return new \WP_Error( 'bkng_db_error', __( 'Non è stato possibile salvare il cliente.', 'booking-manager' ), array( 'status' => 500 ) );
		}
		$this->log( 'tenant.created', $id, null, $data + array( 'status' => $status ) );
		if ( '' !== $email ) {
			$added = $this->add_user(
				$id,
				array(
					'email'      => $email,
					'first_name' => $admin['first_name'] ?? '',
					'last_name'  => $admin['last_name'] ?? '',
					'role'       => Capabilities::ROLE_ADMIN,
				)
			);
			if ( $added instanceof \WP_Error ) {
				return $added;
			}
		}
		return $this->get( $id );
	}

	/**
	 * @param array<string, mixed> $input name, business_type, plan, expires_at, limits{}, features{}, version
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( int $id, array $input ): array|\WP_Error {
		$tenant = $this->tenants->find( $id );
		if ( ! $tenant ) {
			return self::not_found();
		}
		if ( isset( $input['version'] ) && (int) $input['version'] !== (int) $tenant['version'] ) {
			return new \WP_Error( 'bkng_conflict', __( 'Il cliente è stato modificato da qualcun altro: ricarica la pagina.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$data = $this->validate( $input, $tenant );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		// Expired by date and the date moved forward: active again (as it was before).
		if ( TenantStatus::EXPIRED === $tenant['status'] && array_key_exists( 'expires_at', $data )
			&& ( null === $data['expires_at'] || strtotime( $data['expires_at'] . ' UTC' ) > time() ) ) {
			$data['status'] = TenantStatus::ACTIVE;
		}
		$this->tenants->update( $id, $data );
		$this->log( 'tenant.updated', $id, array_intersect_key( $tenant, $data ), $data );
		return $this->get( $id );
	}

	/**
	 * suspend | activate | archive, with a reason (written in the log; for a suspension
	 * also kept on the customer).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_status( int $id, string $action, string $reason ): array|\WP_Error {
		$tenant = $this->tenants->find( $id );
		if ( ! $tenant ) {
			return self::not_found();
		}
		$reason = mb_substr( sanitize_text_field( $reason ), 0, 255 );
		$now    = gmdate( 'Y-m-d H:i:s' );
		switch ( $action ) {
			case 'suspend':
				if ( '' === $reason ) {
					return Validation::error( array( 'reason' => __( 'Scrivi il motivo della sospensione.', 'booking-manager' ) ) );
				}
				$data = array(
					'status'         => TenantStatus::SUSPENDED,
					'suspended_at'   => $now,
					'suspend_reason' => $reason,
				);
				break;
			case 'activate':
				if ( ! empty( $tenant['expires_at'] ) && strtotime( $tenant['expires_at'] . ' UTC' ) <= time() ) {
					return Validation::error( array( 'expires_at' => __( 'La scadenza è già passata: prima sposta la data di scadenza (o toglila).', 'booking-manager' ) ) );
				}
				$data = array(
					'status'         => TenantStatus::ACTIVE,
					'suspended_at'   => null,
					'suspend_reason' => null,
				);
				break;
			case 'archive':
				if ( $tenant['is_default'] ) {
					return Validation::error( array( 'status' => __( 'Il cliente principale della piattaforma non si archivia: sospendilo, se serve.', 'booking-manager' ) ) );
				}
				$data = array( 'status' => TenantStatus::ARCHIVED );
				break;
			default:
				return Validation::error( array( 'action' => __( 'Azione non valida.', 'booking-manager' ) ) );
		}
		$this->tenants->update( $id, $data );
		$this->log( 'tenant.' . ( 'activate' === $action ? 'activated' : ( 'suspend' === $action ? 'suspended' : 'archived' ) ), $id, array( 'status' => $tenant['status'] ), $data + array( 'reason' => $reason ) );
		return $this->get( $id );
	}

	/** @return string[]|\WP_Error the customer's domains */
	public function add_domain( int $id, string $raw ): array|\WP_Error {
		if ( ! $this->tenants->find( $id ) ) {
			return self::not_found();
		}
		$host = Domains::normalize( $raw );
		if ( null === $host ) {
			return Validation::error( array( 'host' => __( 'Scrivi solo il nome del dominio, per esempio hotel-esempio.it o www.hotel-esempio.it (niente percorsi, porte, indirizzi IP o asterischi).', 'booking-manager' ) ) );
		}
		$owner = $this->tenants->owner_of_domain( $host );
		if ( $owner && $owner !== $id ) {
			return Validation::error( array( 'host' => __( 'Questo dominio è già di un altro cliente.', 'booking-manager' ) ) );
		}
		if ( ! $owner ) {
			$this->tenants->add_domain( $id, $host, get_current_user_id() ?: null );
			$this->log( 'tenant.domain_added', $id, null, array( 'host' => $host ) );
		}
		return $this->tenants->domains( $id );
	}

	/** @return string[]|\WP_Error */
	public function remove_domain( int $id, string $raw ): array|\WP_Error {
		if ( ! $this->tenants->find( $id ) ) {
			return self::not_found();
		}
		$host = Domains::normalize( $raw ) ?? strtolower( trim( $raw ) );
		if ( $this->tenants->remove_domain( $id, $host ) ) {
			$this->log( 'tenant.domain_removed', $id, array( 'host' => $host ), null );
		}
		return $this->tenants->domains( $id );
	}

	/** @return array<int, array<string, mixed>>|\WP_Error */
	public function users( int $id ): array|\WP_Error {
		if ( ! $this->tenants->find( $id ) ) {
			return self::not_found();
		}
		return $this->staff->members_of( $id, wp_get_current_user() );
	}

	/**
	 * A person for the customer: a new WordPress user (email, names) or an existing one
	 * not yet in another customer (user_id), with a role and properties of the customer.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function add_user( int $id, array $input ): array|\WP_Error {
		if ( ! $this->tenants->find( $id ) ) {
			return self::not_found();
		}
		return $this->staff->add( wp_get_current_user(), array( 'tenant_id' => $id ) + $input );
	}

	/** @return array<string, mixed> */
	public function plans(): array {
		return array(
			'plans'          => array_values( $this->plans->all() ),
			'features'       => Plans::feature_labels(),
			'limits'         => Plans::limit_labels(),
			'in_use'         => $this->tenants->plans_in_use(),
			'business_types' => self::business_types(),
			'statuses'       => TenantStatus::labels(),
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function save_plans( $input ): array|\WP_Error {
		$before = $this->plans->all();
		$saved  = $this->plans->save( $input, $this->tenants->plans_in_use() );
		if ( $saved instanceof \WP_Error ) {
			return $saved;
		}
		$this->audit->log(
			array(
				'action'      => 'platform.plans_saved',
				'entity_type' => 'platform',
				'old'         => array( 'plans' => array_keys( $before ) ),
				'new'         => array( 'plans' => array_keys( $saved ) ),
			)
		);
		return $this->plans();
	}

	/**
	 * Aggregated numbers for the platform dashboard: no personal data.
	 *
	 * @return array<string, mixed>
	 */
	public function stats( string $from, string $to ): array {
		global $wpdb;
		$by_status = array_fill_keys( TenantStatus::ALL, 0 );
		$expiring  = array();
		$soon      = time() + 30 * DAY_IN_SECONDS;
		foreach ( $this->tenants->all() as $tenant ) {
			$state = TenantGate::state( $tenant );
			++$by_status[ $state ];
			if ( in_array( $state, TenantStatus::CAN_SELL, true ) && ! empty( $tenant['expires_at'] ) && strtotime( $tenant['expires_at'] . ' UTC' ) <= $soon ) {
				$expiring[] = array(
					'id'         => (int) $tenant['id'],
					'name'       => (string) $tenant['name'],
					'expires_at' => (string) $tenant['expires_at'],
				);
			}
		}
		$tenants    = Schema::table( 'tenants' );
		$properties = Schema::table( 'properties' );
		$bookings   = Schema::table( 'bookings' );
		$payments   = Schema::table( 'payments' );
		$start      = $from . ' 00:00:00';
		$end        = $to . ' 23:59:59';
		$rows       = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
				"SELECT t.id, t.name, b.currency, COUNT(b.id) AS bookings, COALESCE(SUM(b.total), 0) AS booked
				FROM {$tenants} t
				JOIN {$properties} p ON p.tenant_id = t.id
				JOIN {$bookings} b ON b.property_id = p.id AND b.created_at BETWEEN %s AND %s AND b.status <> 'cancelled'
				GROUP BY t.id, t.name, b.currency",
				$start,
				$end
			),
			ARRAY_A
		);
		$paid = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
				"SELECT p.tenant_id AS id, y.currency, COALESCE(SUM(y.amount - y.refunded), 0) AS cashed
				FROM {$payments} y JOIN {$properties} p ON p.id = y.property_id
				WHERE y.status IN ('paid','partially_refunded','refunded') AND y.received_at BETWEEN %s AND %s
				GROUP BY p.tenant_id, y.currency",
				$start,
				$end
			),
			ARRAY_A
		);
		$cashed = array();
		foreach ( $paid as $row ) {
			$cashed[ (int) $row['id'] . '|' . $row['currency'] ] = (int) $row['cashed'];
		}
		$per_tenant = array();
		foreach ( $rows as $row ) {
			$key          = (int) $row['id'] . '|' . $row['currency'];
			$per_tenant[] = array(
				'id'       => (int) $row['id'],
				'name'     => (string) $row['name'],
				'currency' => (string) $row['currency'],
				'bookings' => (int) $row['bookings'],
				'booked'   => (int) $row['booked'],
				'cashed'   => $cashed[ $key ] ?? 0,
			);
			unset( $cashed[ $key ] );
		}
		foreach ( $cashed as $key => $amount ) {
			[ $id, $currency ] = explode( '|', $key );
			$tenant            = $this->tenants->find( (int) $id );
			$per_tenant[]      = array(
				'id'       => (int) $id,
				'name'     => $tenant ? (string) $tenant['name'] : '',
				'currency' => $currency,
				'bookings' => 0,
				'booked'   => 0,
				'cashed'   => $amount,
			);
		}
		usort( $per_tenant, static fn( array $a, array $b ) => $b['booked'] <=> $a['booked'] ?: strcasecmp( $a['name'], $b['name'] ) );
		return array(
			'from'       => $from,
			'to'         => $to,
			'by_status'  => $by_status,
			'expiring'   => $expiring,
			'per_tenant' => $per_tenant,
		);
	}

	/**
	 * The customer of the user, as the customer's people see it (read only).
	 *
	 * @return array<string, mixed>|null
	 */
	public function own( int $tenant_id ): ?array {
		$tenant = $tenant_id > 0 ? $this->tenants->find( $tenant_id ) : null;
		if ( ! $tenant ) {
			return null;
		}
		$plan = $this->plans->find( (string) $tenant['plan'] );
		return array(
			'id'           => (int) $tenant['id'],
			'name'         => (string) $tenant['name'],
			'status'       => TenantGate::state( $tenant ),
			'status_label' => TenantStatus::labels()[ TenantGate::state( $tenant ) ] ?? '',
			'plan'         => $plan ? $plan['name'] : (string) $tenant['plan'],
			'expires_at'   => $tenant['expires_at'],
			'limits'       => $this->plans->limits_of( $tenant ),
			'usage'        => $this->gate->usage( (int) $tenant['id'] ),
			'domains'      => $this->tenants->domains( (int) $tenant['id'] ),
		);
	}

	/**
	 * @param array<string, mixed>      $input
	 * @param array<string, mixed>|null $current
	 * @return array<string, mixed>|\WP_Error columns to write
	 */
	private function validate( array $input, ?array $current ): array|\WP_Error {
		$data   = array();
		$errors = array();
		if ( null === $current || array_key_exists( 'name', $input ) ) {
			$name = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
			if ( '' === $name ) {
				$errors['name'] = __( 'Scrivi il nome del cliente (attività o azienda).', 'booking-manager' );
			}
			$data['name'] = mb_substr( $name, 0, 190 );
		}
		if ( null === $current || array_key_exists( 'business_type', $input ) ) {
			$type = sanitize_key( (string) ( $input['business_type'] ?? 'hotel' ) );
			if ( ! array_key_exists( $type, self::business_types() ) ) {
				$errors['business_type'] = __( 'Tipologia di attività non valida.', 'booking-manager' );
			}
			$data['business_type'] = $type;
		}
		if ( null === $current || array_key_exists( 'plan', $input ) ) {
			$plan = sanitize_key( (string) ( $input['plan'] ?? Plans::DEFAULT_PLAN ) );
			if ( ! $this->plans->find( $plan ) ) {
				$errors['plan'] = __( 'Piano inesistente.', 'booking-manager' );
			}
			$data['plan'] = $plan;
		}
		if ( array_key_exists( 'expires_at', $input ) ) {
			if ( Validation::blank( $input['expires_at'] ) ) {
				$data['expires_at'] = null;
			} else {
				$day = Validation::date( substr( (string) $input['expires_at'], 0, 10 ) );
				if ( null === $day ) {
					$errors['expires_at'] = __( 'Data di scadenza non valida.', 'booking-manager' );
				}
				// Valid for the whole day (UTC).
				$data['expires_at'] = $day . ' 23:59:59';
			}
		}
		foreach ( array( 'limits' => Plans::LIMITS, 'features' => Plans::FEATURES ) as $field => $keys ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$clean = array();
			foreach ( $keys as $key ) {
				$value = $input[ $field ][ $key ] ?? null;
				if ( null === $value || '' === $value ) {
					continue; // As the plan says.
				}
				if ( 'limits' === $field ) {
					$number = Validation::int_between( $value, 0, 100000 );
					if ( null === $number ) {
						$errors[ $key ] = __( 'Limite non valido: usa un numero intero, 0 = nessun limite.', 'booking-manager' );
						continue;
					}
					$clean[ $key ] = $number;
				} else {
					$clean[ $key ] = rest_sanitize_boolean( $value );
				}
			}
			$data[ $field ] = $clean;
		}
		return $errors ? Validation::error( $errors ) : $data;
	}

	/**
	 * @param array<string, mixed> $tenant
	 * @return array<string, mixed>
	 */
	private function format( array $tenant ): array {
		$state = TenantGate::state( $tenant );
		$plan  = $this->plans->find( (string) $tenant['plan'] );
		$out   = array(
			'id'             => (int) $tenant['id'],
			'uuid'           => (string) $tenant['uuid'],
			'name'           => (string) $tenant['name'],
			'business_type'  => (string) $tenant['business_type'],
			'status'         => $state,
			'status_label'   => TenantStatus::labels()[ $state ] ?? $state,
			'plan'           => (string) $tenant['plan'],
			'plan_name'      => $plan ? $plan['name'] : (string) $tenant['plan'],
			'is_default'     => (bool) $tenant['is_default'],
			'activated_at'   => $tenant['activated_at'],
			'expires_at'     => $tenant['expires_at'],
			'suspended_at'   => $tenant['suspended_at'],
			'suspend_reason' => $tenant['suspend_reason'],
			'created_at'     => $tenant['created_at'],
			'version'        => (int) $tenant['version'],
		);
		foreach ( array( 'properties_count', 'users_count', 'domains_count' ) as $count ) {
			if ( isset( $tenant[ $count ] ) ) {
				$out[ $count ] = (int) $tenant[ $count ];
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed>|null $old
	 * @param array<string, mixed>|null $new
	 */
	private function log( string $action, int $id, ?array $old, ?array $new ): void {
		$this->audit->log(
			array(
				'action'      => $action,
				'entity_type' => 'tenant',
				'entity_id'   => $id,
				'old'         => $old,
				'new'         => $new,
			)
		);
	}

	public static function not_found(): \WP_Error {
		return new \WP_Error( 'bkng_tenant_not_found', __( 'Cliente non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
	}
}
