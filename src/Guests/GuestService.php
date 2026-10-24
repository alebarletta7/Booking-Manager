<?php
/**
 * Guests (ospiti): the guest card, cautious duplicate detection, consents, export and
 * anonymization.
 *
 * Duplicates are only ever suggested: two guest cards are never merged automatically.
 * A booking is linked to an existing card only when e-mail AND surname are the same; any
 * other likeness is shown to the staff as «possibile duplicato».
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Guests;

use BookingManager\Audit\AuditLogger;
use BookingManager\Core\Validation;
use BookingManager\Database\Schema;

defined( 'ABSPATH' ) || exit;

class GuestService {

	public function __construct( private GuestRepository $repo, private AuditLogger $audit ) {}

	/**
	 * Normalizes the guest fields of a form; errors keyed by field.
	 *
	 * @param array<string, mixed> $input
	 * @param bool                 $contact_required e-mail or phone needed (public form)
	 * @return array<string, mixed>|\WP_Error
	 */
	public function validate( array $input, bool $contact_required = false ): array|\WP_Error {
		$errors = array();
		$data   = array(
			'first_name' => mb_substr( sanitize_text_field( (string) ( $input['first_name'] ?? '' ) ), 0, 100 ),
			'last_name'  => mb_substr( sanitize_text_field( (string) ( $input['last_name'] ?? '' ) ), 0, 100 ),
			'email'      => null,
			'phone'      => null,
			'country'    => null,
			'language'   => null,
		);
		if ( '' === $data['last_name'] ) {
			$errors['last_name'] = __( 'Indica il cognome dell\'ospite.', 'booking-manager' );
		}
		$email = trim( (string) ( $input['email'] ?? '' ) );
		if ( '' !== $email ) {
			if ( ! is_email( $email ) ) {
				$errors['email'] = __( 'Indirizzo e-mail non valido.', 'booking-manager' );
			} else {
				$data['email'] = strtolower( sanitize_email( $email ) );
			}
		}
		$phone = trim( (string) ( $input['phone'] ?? '' ) );
		if ( '' !== $phone ) {
			$clean = (string) preg_replace( '/[^\d+ ]/', '', $phone );
			if ( strlen( (string) preg_replace( '/\D/', '', $clean ) ) < 6 ) {
				$errors['phone'] = __( 'Numero di telefono non valido.', 'booking-manager' );
			} else {
				$data['phone'] = mb_substr( $clean, 0, 32 );
			}
		}
		if ( $contact_required && null === $data['email'] && ! isset( $errors['email'] ) ) {
			$errors['email'] = __( 'Indica un indirizzo e-mail per ricevere la conferma.', 'booking-manager' );
		}
		$country = strtoupper( (string) ( $input['country'] ?? '' ) );
		if ( '' !== $country ) {
			if ( preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$data['country'] = $country;
			} else {
				$errors['country'] = __( 'Paese non valido.', 'booking-manager' );
			}
		}
		$language = strtolower( (string) ( $input['language'] ?? '' ) );
		if ( preg_match( '/^[a-z]{2}(_[a-z]{2})?$/', $language ) ) {
			$data['language'] = $language;
		}
		return $errors ? Validation::error( $errors ) : $data;
	}

	/**
	 * The guest card for a booking: the existing card with the same e-mail and surname,
	 * otherwise a new one.
	 *
	 * @param array<string, mixed> $data validated fields
	 */
	public function find_or_create( array $data ): int {
		if ( ! empty( $data['email'] ) ) {
			foreach ( $this->repo->by_email_hash( GuestRepository::hash_email( (string) $data['email'] ) ) as $guest ) {
				if ( 0 === strcasecmp( (string) $guest['last_name'], (string) $data['last_name'] ) ) {
					$fill = array();
					foreach ( array( 'first_name', 'phone', 'country', 'language' ) as $key ) {
						if ( empty( $guest[ $key ] ) && ! empty( $data[ $key ] ) ) {
							$fill[ $key ] = $data[ $key ];
						}
					}
					if ( $fill ) {
						$this->repo->update( (int) $guest['id'], $fill );
					}
					return (int) $guest['id'];
				}
			}
		}
		return $this->repo->create( $data );
	}

	/**
	 * Other guest cards that may be the same person (same e-mail or same phone).
	 *
	 * @return array<int, array{id: int, name: string, reason: string}>
	 */
	public function possible_duplicates( int $guest_id ): array {
		$guest = $this->repo->find( $guest_id );
		if ( ! $guest || null !== $guest['anonymized_at'] ) {
			return array();
		}
		$out = array();
		if ( ! empty( $guest['email_hash'] ) ) {
			foreach ( $this->repo->by_email_hash( (string) $guest['email_hash'] ) as $other ) {
				$out[ $other['id'] ] = array( $other, 'email' );
			}
		}
		if ( ! empty( $guest['phone_hash'] ) ) {
			foreach ( $this->repo->by_phone_hash( (string) $guest['phone_hash'] ) as $other ) {
				$out[ $other['id'] ] ??= array( $other, 'phone' );
			}
		}
		unset( $out[ $guest_id ] );
		return array_values(
			array_map(
				static fn( array $pair ) => array(
					'id'     => (int) $pair[0]['id'],
					'name'   => trim( $pair[0]['first_name'] . ' ' . $pair[0]['last_name'] ),
					'reason' => $pair[1],
				),
				$out
			)
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( int $id, array $input ): array|\WP_Error {
		$guest = $this->repo->find( $id );
		if ( ! $guest || null !== $guest['anonymized_at'] ) {
			return self::not_found();
		}
		if ( isset( $input['version'] ) && (int) $input['version'] !== $guest['version'] ) {
			return new \WP_Error( 'bkng_conflict', __( 'I dati sono stati modificati da qualcun altro. Ricarica e riprova.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$data = $this->validate( array_merge( $guest, $input ) );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		if ( array_key_exists( 'notes', $input ) ) {
			$data['notes'] = sanitize_textarea_field( (string) $input['notes'] );
		}
		$this->repo->update( $id, $data );
		$this->audit->log(
			array(
				'action'      => 'guest.updated',
				'entity_type' => 'guest',
				'entity_id'   => $id,
				'new'         => array( 'fields' => array_keys( $data ) ),
			)
		);
		return (array) $this->repo->find( $id );
	}

	/**
	 * Everything stored about a guest (right of access), without other guests' data.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function export( int $id ): array|\WP_Error {
		global $wpdb;
		$guest = $this->repo->find( $id );
		if ( ! $guest ) {
			return self::not_found();
		}
		$table = Schema::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$bookings = (array) $wpdb->get_results( $wpdb->prepare( "SELECT code, property_id, status, arrival, departure, adults, children, total, currency, customer_notes, created_at FROM {$table} WHERE guest_id = %d ORDER BY arrival", $id ), ARRAY_A );
		$this->audit->log(
			array(
				'action'      => 'guest.exported',
				'entity_type' => 'guest',
				'entity_id'   => $id,
			)
		);
		return array(
			'guest'    => array_intersect_key( $guest, array_flip( array( 'uuid', 'first_name', 'last_name', 'email', 'phone', 'country', 'language', 'notes', 'created_at' ) ) ),
			'consents' => $this->repo->consents( $id ),
			'bookings' => $bookings,
		);
	}

	/**
	 * Removes the personal data of a guest card and of its bookings (names, contacts,
	 * notes). Amounts, dates and statistics stay, without anybody's identity.
	 *
	 * @return true|\WP_Error
	 */
	public function anonymize( int $id ): bool|\WP_Error {
		global $wpdb;
		$guest = $this->repo->find( $id );
		if ( ! $guest ) {
			return self::not_found();
		}
		$this->repo->anonymize( $id );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'bookings' ) . " SET first_name = '', last_name = %s, email = NULL, phone = NULL, customer_notes = NULL WHERE guest_id = %d", __( 'Anonimo', 'booking-manager' ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'booking_guests' ) . " SET first_name = '', last_name = %s WHERE guest_id = %d", __( 'Anonimo', 'booking-manager' ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
		// The message log keeps who received what: the address goes too.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'notifications' ) . " SET recipient = '' WHERE booking_id IN (SELECT id FROM " . Schema::table( 'bookings' ) . ' WHERE guest_id = %d)', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- internal table names.
		$this->audit->log(
			array(
				'action'      => 'guest.anonymized',
				'entity_type' => 'guest',
				'entity_id'   => $id,
			)
		);
		return true;
	}

	/**
	 * Daily: guest cards with no stay in the last $months months (and none coming) are
	 * anonymized. 0 keeps everything (the default: the property decides).
	 *
	 * @return int guests anonymized
	 */
	public function anonymize_inactive( int $months, int $now ): int {
		global $wpdb;
		if ( $months <= 0 ) {
			return 0;
		}
		$cutoff   = gmdate( 'Y-m-d', strtotime( '-' . $months . ' months', $now ) );
		$guests   = Schema::table( 'guests' );
		$bookings = Schema::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT g.id FROM {$guests} g WHERE g.anonymized_at IS NULL AND g.created_at < %s AND NOT EXISTS (SELECT 1 FROM {$bookings} b WHERE b.guest_id = g.id AND (b.departure >= %s OR b.status IN ('pending','option','confirmed','in_house'))) LIMIT 200", $cutoff . ' 00:00:00', $cutoff ) ) );
		foreach ( $ids as $id ) {
			$this->anonymize( $id );
		}
		return count( $ids );
	}

	public static function not_found(): \WP_Error {
		return new \WP_Error( 'bkng_guest_not_found', __( 'Ospite non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
	}
}
