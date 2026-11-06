<?php
/**
 * Sales channels, prepared for connectors supplied by add-ons (see ChannelConnector).
 *
 * In: import_reservation() turns a channel's reservation into a booking (source
 * "import", no e-mail to the guest: the channel sends its own), once per message
 * (idempotency key) and once per reservation (channel_refs). A reservation that does not
 * fit (no room free, room type not mapped) is never dropped: the sync log records the
 * error for the staff to handle.
 *
 * Out: after every booking change of a property, each connector set up for it receives
 * the room types and dates whose availability changed.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels;

use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingService;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Core\Hooks;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class ChannelService {

	/** @var array<string, ChannelConnector>|null */
	private ?array $connectors = null;

	public function __construct(
		private ChannelRefRepository $refs,
		private SyncLogRepository $log,
		private BookingService $bookings,
		private BookingRepository $booking_repo,
		private PropertyRepository $properties
	) {}

	public function hooks(): void {
		foreach ( array( Hooks::BOOKING_CREATED, Hooks::BOOKING_UPDATED, Hooks::BOOKING_CANCELLED, Hooks::BOOKING_NO_SHOW, Hooks::BOOKING_CONFIRMED ) as $hook ) {
			add_action( $hook, array( $this, 'on_booking_changed' ), 20, 2 );
		}
	}

	/** @return array<string, ChannelConnector> */
	public function connectors(): array {
		if ( null === $this->connectors ) {
			/**
			 * Adds channel connectors (objects implementing ChannelConnector).
			 *
			 * @param ChannelConnector[] $connectors
			 */
			$list             = (array) apply_filters( 'bkng_channel_connectors', array() );
			$this->connectors = array();
			foreach ( $list as $connector ) {
				if ( $connector instanceof ChannelConnector ) {
					$this->connectors[ $connector->key() ] = $connector;
				}
			}
		}
		return $this->connectors;
	}

	/**
	 * A reservation from a channel.
	 *
	 * @param array<string, mixed> $r external_id, modified (version or date of the message),
	 *        status (confirmed|cancelled), arrival, departure, guest{first_name, last_name, email?, phone?},
	 *        rooms[{external_room_type_id, adults, children?, price_total?}], customer_notes?
	 * @return array{booking_id: int|null, result: string}|\WP_Error result: created | cancelled | duplicate | unchanged
	 */
	public function import_reservation( string $channel, int $property_id, array $r ): array|\WP_Error {
		$external = substr( trim( (string) ( $r['external_id'] ?? '' ) ), 0, 190 );
		if ( '' === $external || ! preg_match( '/^[a-z0-9_-]{2,40}$/', $channel ) ) {
			return new \WP_Error( 'bkng_invalid_import', __( 'Prenotazione del canale senza identificativo.', 'booking-manager' ), array( 'status' => 400 ) );
		}
		$key    = substr( $channel . ':' . $external . ':' . md5( (string) ( $r['modified'] ?? '' ) . '|' . (string) ( $r['status'] ?? '' ) ), 0, 120 );
		$row_id = $this->log->log(
			array(
				'property_id'     => $property_id,
				'channel'         => $channel,
				'direction'       => SyncLogRepository::IN,
				'entity_type'     => ChannelRefRepository::BOOKING,
				'external_id'     => $external,
				'idempotency_key' => $key,
				'status'          => 'processing',
			)
		);
		$known = $this->refs->entity_id( $channel, ChannelRefRepository::BOOKING, $external );
		if ( 0 === $row_id ) {
			// The same message again (the channel retried): what it produced the first time.
			$first = $this->log->by_key( $key );
			return array(
				'booking_id' => $known,
				'result'     => 'duplicate',
				'status'     => $first ? (string) $first['status'] : 'unknown',
			);
		}

		$cancel = 'cancelled' === ( $r['status'] ?? 'confirmed' );
		if ( null !== $known ) {
			$booking = $this->booking_repo->find( $known );
			if ( $cancel && $booking && BookingStatus::CANCELLED !== $booking['status'] ) {
				$done = $this->bookings->set_status(
					$known,
					(int) $booking['version'],
					BookingStatus::CANCELLED,
					array(
						'user_id' => 0,
						'actor'   => 'system',
						'reason'  => __( 'Annullata dal canale', 'booking-manager' ),
						'notify'  => false,
					)
				);
				return $this->outcome( $row_id, $known, $done, 'cancelled' );
			}
			// Changes of an existing reservation are shown to the staff, never applied blindly.
			$this->log->finish( $row_id, $cancel ? 'ok' : 'review', $known, $cancel ? null : __( 'Modifica dal canale: controlla date e camere della prenotazione.', 'booking-manager' ) );
			return array(
				'booking_id' => $known,
				'result'     => 'unchanged',
			);
		}
		if ( $cancel ) {
			$this->log->finish( $row_id, 'ok', null, __( 'Annullamento di una prenotazione mai importata.', 'booking-manager' ) );
			return array(
				'booking_id' => null,
				'result'     => 'unchanged',
			);
		}

		$rooms = array();
		foreach ( (array) ( $r['rooms'] ?? array() ) as $room ) {
			$type = $this->refs->entity_id( $channel, ChannelRefRepository::ROOM_TYPE, (string) ( $room['external_room_type_id'] ?? '' ) );
			if ( null === $type ) {
				return $this->outcome( $row_id, null, new \WP_Error( 'bkng_unmapped_room', __( 'Tipologia del canale non collegata a una tipologia della struttura.', 'booking-manager' ), array( 'status' => 409 ) ), 'created' );
			}
			$rooms[] = array_filter(
				array(
					'room_type_id' => $type,
					'adults'       => max( 1, (int) ( $room['adults'] ?? 1 ) ),
					'children'     => max( 0, (int) ( $room['children'] ?? 0 ) ),
					'price_total'  => isset( $room['price_total'] ) ? (int) $room['price_total'] : null,
				),
				static fn( $v ) => null !== $v
			);
		}
		$created = $this->bookings->create(
			array(
				'property_id'           => $property_id,
				'arrival'               => (string) ( $r['arrival'] ?? '' ),
				'departure'             => (string) ( $r['departure'] ?? '' ),
				'rooms'                 => $rooms,
				'guest'                 => (array) ( $r['guest'] ?? array() ),
				'customer_notes'        => $r['customer_notes'] ?? null,
				'status'                => BookingStatus::CONFIRMED,
				'external_source'       => $channel,
				'external_ref'          => $external,
				'override_restrictions' => true,
				'idempotency_key'       => substr( 'ch:' . $channel . ':' . md5( $external ), 0, 64 ),
			),
			array(
				'channel'      => 'import',
				'user_id'      => 0,
				'can_override' => true,
				'can_price'    => true,
			)
		);
		if ( is_array( $created ) ) {
			$this->refs->link( $property_id, $channel, ChannelRefRepository::BOOKING, (int) $created['id'], $external );
		}
		return $this->outcome( $row_id, is_array( $created ) ? (int) $created['id'] : null, $created, 'created' );
	}

	/** Links a channel's room code to one of our room types. */
	public function map_room_type( int $property_id, string $channel, string $external_room_type_id, int $room_type_id ): void {
		$this->refs->link( $property_id, $channel, ChannelRefRepository::ROOM_TYPE, $room_type_id, $external_room_type_id );
	}

	/**
	 * Overview for the admin: connectors with their state for the property, latest exchanges.
	 *
	 * @return array<string, mixed>
	 */
	public function overview( int $property_id ): array {
		$property   = (array) $this->properties->find( $property_id );
		$connectors = array();
		foreach ( $this->connectors() as $key => $connector ) {
			$connectors[] = array(
				'key'        => $key,
				'label'      => $connector->label(),
				'ready'      => null === $connector->unavailable( $property ),
				'reason'     => $connector->unavailable( $property ),
				'room_types' => $this->refs->for_property( $property_id, $key, ChannelRefRepository::ROOM_TYPE ),
			);
		}
		return array(
			'connectors' => $connectors,
			'log'        => array_map(
				static fn( array $row ) => array(
					'channel'     => (string) $row['channel'],
					'direction'   => (string) $row['direction'],
					'entity_type' => $row['entity_type'],
					'entity_id'   => null === $row['entity_id'] ? null : (int) $row['entity_id'],
					'external_id' => $row['external_id'],
					'status'      => (string) $row['status'],
					'message'     => $row['message'],
					'at'          => \BookingManager\Core\Clock::iso( (string) $row['created_at'] ),
				),
				$this->log->recent( $property_id )
			),
		);
	}

	/**
	 * After a booking change: connectors set up for the property get the room types and
	 * dates to send again. Without connectors nothing happens.
	 *
	 * @param array<string, mixed> $ctx
	 */
	public function on_booking_changed( int $booking_id, array $ctx ): void {
		if ( array() === $this->connectors() ) {
			return;
		}
		$property = $this->properties->find( (int) ( $ctx['property_id'] ?? 0 ) );
		$booking  = $this->booking_repo->find( $booking_id );
		if ( ! $property || ! $booking ) {
			return;
		}
		$types = array_values( array_unique( array_map( 'intval', array_column( $this->booking_repo->rooms( $booking_id ), 'room_type_id' ) ) ) );
		foreach ( $this->connectors() as $key => $connector ) {
			if ( null !== $connector->unavailable( $property ) ) {
				continue;
			}
			try {
				$done = $connector->push_availability( (int) $property['id'], $types, (string) $booking['arrival'], (string) $booking['departure'] );
			} catch ( \Throwable $e ) {
				$done = new \WP_Error( 'bkng_channel_error', $e->getMessage() );
			}
			$this->log->log(
				array(
					'property_id' => (int) $property['id'],
					'channel'     => $key,
					'direction'   => SyncLogRepository::OUT,
					'entity_type' => ChannelRefRepository::BOOKING,
					'entity_id'   => $booking_id,
					'status'      => true === $done ? 'ok' : 'error',
					'message'     => $done instanceof \WP_Error ? wp_strip_all_tags( $done->get_error_message() ) : null,
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>|\WP_Error $done
	 * @return array{booking_id: int|null, result: string}|\WP_Error
	 */
	private function outcome( int $row_id, ?int $booking_id, array|\WP_Error $done, string $result ): array|\WP_Error {
		if ( $done instanceof \WP_Error ) {
			$this->log->finish( $row_id, 'error', $booking_id, wp_strip_all_tags( $done->get_error_message() ) );
			return $done;
		}
		$this->log->finish( $row_id, 'ok', $booking_id, null );
		return array(
			'booking_id' => $booking_id,
			'result'     => $result,
		);
	}
}
