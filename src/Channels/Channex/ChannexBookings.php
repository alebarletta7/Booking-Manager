<?php
/**
 * Reservations from Channex (Booking.com and the other portals connected there).
 *
 * - The webhook (signed with HMAC-SHA256 on "{timestamp}.{raw body}") is only a trigger:
 *   it is logged and the feed of the property is read soon after; the answer is
 *   immediate.
 * - The feed (GET /booking_revisions/feed, also every 2 minutes as a safety net) gives
 *   the revisions not yet acknowledged. Each one is applied in ONE transaction through
 *   BookingService (same checks, locks and hooks as a booking taken at the desk):
 *     new        → a booking (source "import", no e-mail to the guest: the portal sends
 *                  its own); if the booking already exists, as modified;
 *     modified   → dates, rooms and prices replaced, contacts updated;
 *     cancelled  → the booking cancelled.
 *   A reservation the portal already confirmed is never refused: without a free room it
 *   is saved anyway, flagged as overbooking and announced to the staff.
 *   A room or rate not linked to ours: the revision is kept (without card data), flagged
 *   and announced; after the mapping «Importa di nuovo» creates the booking.
 * - Only after the booking is saved the revision is acknowledged (POST …/ack). A failed
 *   ack is retried at the next read; an error leaves the revision unacknowledged, and
 *   Channex proposes it again.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingService;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Channels\ChannelRefRepository;
use BookingManager\Core\DbLock;
use BookingManager\Database\Transaction;
use BookingManager\Inventory\RoomTypeRepository;

defined( 'ABSPATH' ) || exit;

class ChannexBookings {

	/** Right after a webhook: read the feed of a property. */
	public const PULL_HOOK = 'bkng_channex_pull';

	/** Webhook timestamps older (or newer) than this are refused. */
	public const TOLERANCE = 300;

	/** Events that only call for a warning to the staff. */
	public const ALERT_EVENTS = array( 'booking_unmapped_room', 'booking_unmapped_rate', 'non_acked_booking', 'sync_error', 'disconnect_channel' );

	/** Events subscribed when the webhook is created on Channex. */
	public const EVENT_MASK = 'booking;booking_unmapped_room;booking_unmapped_rate;non_acked_booking;sync_error;disconnect_channel';

	public function __construct(
		private ChannexSettings $settings,
		private ChannexClient $client,
		private ChannexMap $map,
		private ChannexRevisions $revisions,
		private ChannexLog $log,
		private ChannexAlerts $alerts,
		private ChannelRefRepository $refs,
		private BookingService $bookings,
		private BookingRepository $booking_repo,
		private RoomTypeRepository $types
	) {}

	public function hooks(): void {
		add_action( self::PULL_HOOK, array( $this, 'pull' ), 10, 1 );
	}

	/* ================================================================ webhook */

	/**
	 * Signature of a webhook (unit-tested): HMAC-SHA256 of "{timestamp}.{raw body}" with
	 * the secret, equal to at least one of the signatures sent (two during a key
	 * rotation), timestamp within 5 minutes.
	 */
	public static function verify( string $raw, string $timestamp, string $signatures, string $secret, int $now ): bool {
		if ( '' === $secret || '' === $signatures || ! ctype_digit( $timestamp ) ) {
			return false;
		}
		$ts = (int) $timestamp;
		if ( $ts > 9999999999 ) {
			$ts = intdiv( $ts, 1000 ); // Milliseconds.
		}
		if ( abs( $now - $ts ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw, $secret );
		foreach ( explode( ',', $signatures ) as $signature ) {
			$signature = strtolower( trim( $signature ) );
			if ( str_starts_with( $signature, 'sha256=' ) ) {
				$signature = substr( $signature, 7 );
			}
			if ( '' !== $signature && hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A call from Channex: checked, logged, the work scheduled. Nothing heavy here.
	 *
	 * @return int HTTP status to answer (200, or 401 when the signature is wrong)
	 */
	public function webhook( string $raw, string $timestamp, string $signatures ): int {
		$valid       = self::verify( $raw, $timestamp, $signatures, $this->settings->webhook_secret(), time() );
		$data        = json_decode( $raw, true );
		$data        = is_array( $data ) ? $data : array();
		$event       = substr( sanitize_key( (string) ( $data['event'] ?? '' ) ), 0, 64 );
		$cx_property = (string) ( $data['property_id'] ?? '' );
		$property_id = ChannexMap::is_uuid( $cx_property ) ? $this->map->property_for( $cx_property ) : null;
		$this->log->webhook( $event, $property_id, ChannexMap::is_uuid( $cx_property ) ? $cx_property : null, $valid, $raw );
		if ( ! $valid ) {
			return 401;
		}
		if ( null === $property_id || ! $this->settings->active() ) {
			return 200;
		}
		if ( in_array( $event, self::ALERT_EVENTS, true ) ) {
			$this->alerts->raise( $property_id, $event, self::event_message( $event ) );
		}
		if ( str_starts_with( $event, 'booking' ) || 'non_acked_booking' === $event ) {
			$this->soon( $property_id );
		}
		return 200;
	}

	public static function event_message( string $event ): string {
		return match ( $event ) {
			'booking_unmapped_room' => __( 'È arrivata una prenotazione per una camera non collegata su Channex: controlla la mappatura.', 'booking-manager' ),
			'booking_unmapped_rate' => __( 'È arrivata una prenotazione per una tariffa non collegata su Channex: controlla la mappatura.', 'booking-manager' ),
			'non_acked_booking'     => __( 'Channex segnala una prenotazione non confermata da più di 30 minuti: controlla la schermata Channex.', 'booking-manager' ),
			'sync_error'            => __( 'Channex segnala un errore di sincronizzazione con un portale.', 'booking-manager' ),
			'disconnect_channel'    => __( 'Un portale si è scollegato da Channex: le vendite su quel portale potrebbero essere ferme.', 'booking-manager' ),
			default                 => $event,
		};
	}

	public function soon( int $property_id ): void {
		$args = array( $property_id );
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::PULL_HOOK, $args, 'booking-manager' ) ) {
				as_enqueue_async_action( self::PULL_HOOK, $args, 'booking-manager' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::PULL_HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::PULL_HOOK, $args );
		}
	}

	/* ================================================================ feed */

	/**
	 * Reads the feed of a property until it gives nothing new, then acknowledges what a
	 * previous run saved but could not acknowledge.
	 *
	 * @return array{processed: int, errors: int}|\WP_Error
	 */
	public function pull( int $property_id ): array|\WP_Error {
		$uuid = $this->map->property_uuid( $property_id );
		if ( ! $this->settings->active() || null === $uuid ) {
			return array(
				'processed' => 0,
				'errors'    => 0,
			);
		}
		$lock = 'cx_feed_' . $property_id;
		if ( ! DbLock::acquire( $lock, 0 ) ) {
			return array(
				'processed' => 0,
				'errors'    => 0,
			);
		}
		$done = 0;
		$errs = 0;
		try {
			foreach ( $this->revisions->unacked( $property_id ) as $revision_id ) {
				$this->ack( $revision_id );
			}
			$seen = array();
			// Acknowledged revisions leave the feed: the first page is read again until it
			// has nothing new (at most 20 pages a run).
			for ( $round = 0; $round < 20; $round++ ) {
				$res = $this->client->get(
					'booking_revisions/feed',
					array(
						'filter' => array( 'property_id' => $uuid ),
						'order'  => array( 'inserted_at' => 'asc' ),
						'page'   => array(
							'number' => 1,
							'limit'  => 25,
						),
					)
				);
				if ( null !== $res['error'] ) {
					$this->log->set_state( $property_id, array( 'last_error' => $res['error'] ) );
					if ( 401 === $res['status'] ) {
						$this->settings->save( array( 'suspended' => (string) $res['error'] ) );
						$this->alerts->raise( $property_id, 'unauthorized', __( 'Channex ha rifiutato la chiave API: la sincronizzazione è sospesa. Inserisci di nuovo la chiave nella schermata Channex.', 'booking-manager' ) );
					}
					return new \WP_Error( 'bkng_channex_failed', $res['error'], array( 'status' => 502 ) );
				}
				$items = array_values( array_filter( (array) ( $res['body']['data'] ?? array() ), 'is_array' ) );
				$new   = array_values( array_filter( $items, static fn( array $i ) => ! isset( $seen[ (string) ( $i['id'] ?? '' ) ] ) ) );
				if ( ! $new ) {
					break;
				}
				foreach ( $new as $item ) {
					$seen[ (string) ( $item['id'] ?? '' ) ] = true;
					$result = $this->handle( $property_id, $item );
					if ( $result instanceof \WP_Error ) {
						++$errs;
					} else {
						++$done;
					}
				}
				if ( count( $items ) < 25 ) {
					break;
				}
			}
			$this->log->set_state( $property_id, array( 'last_feed_poll_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		} finally {
			DbLock::release( $lock );
		}
		return array(
			'processed' => $done,
			'errors'    => $errs,
		);
	}

	/**
	 * One revision of the feed: applied once, then acknowledged.
	 *
	 * @param array<string, mixed> $item {id, type, attributes}
	 * @return array<string, mixed>|\WP_Error
	 */
	public function handle( int $property_id, array $item ): array|\WP_Error {
		$attributes  = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : array();
		$revision_id = (string) ( $item['id'] ?? $attributes['id'] ?? '' );
		if ( ! ChannexMap::is_uuid( $revision_id ) ) {
			return new \WP_Error( 'bkng_channex_invalid', __( 'Revisione senza identificativo.', 'booking-manager' ) );
		}
		$known = $this->revisions->find( $revision_id );
		if ( $known && null !== $known['processed_at'] ) {
			// Saved before, the acknowledgement failed: only acknowledge.
			$this->ack( $revision_id );
			return array( 'outcome' => 'duplicate' );
		}
		$this->revisions->receive( $revision_id, $property_id, $attributes );
		$result = $this->apply( $property_id, $revision_id, $attributes );
		if ( $result instanceof \WP_Error ) {
			$this->revisions->failed( $revision_id, wp_strip_all_tags( $result->get_error_message() ) );
			$row = $this->revisions->find( $revision_id );
			if ( $row && 3 === (int) $row['attempts'] ) {
				/* translators: 1: portal reservation code, 2: error. */
				$this->alerts->raise( $property_id, 'import_failed', sprintf( __( 'La prenotazione %1$s non è stata salvata: %2$s', 'booking-manager' ), (string) ( $attributes['ota_reservation_code'] ?? $revision_id ), wp_strip_all_tags( $result->get_error_message() ) ) );
			}
			return $result;
		}
		$this->ack( $revision_id );
		return $result;
	}

	/**
	 * A revision already acknowledged, applied again from its stored payload (e.g. after
	 * the missing room was mapped).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function retry( string $revision_id ): array|\WP_Error {
		$row = $this->revisions->find( $revision_id );
		if ( ! $row || ! $row['property_id'] ) {
			return new \WP_Error( 'bkng_not_found', __( 'Prenotazione ricevuta non trovata.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		$attributes = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $attributes ) ) {
			return new \WP_Error( 'bkng_channex_invalid', __( 'I dati di questa prenotazione non sono leggibili.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$this->revisions->reopen( $revision_id );
		$result = $this->apply( (int) $row['property_id'], $revision_id, $attributes );
		if ( $result instanceof \WP_Error ) {
			$this->revisions->failed( $revision_id, wp_strip_all_tags( $result->get_error_message() ) );
			return $result;
		}
		if ( null === $row['acked_at'] ) {
			$this->ack( $revision_id );
		}
		return $result;
	}

	private function ack( string $revision_id ): bool {
		$res = $this->client->post( 'booking_revisions/' . rawurlencode( $revision_id ) . '/ack' );
		if ( null === $res['error'] ) {
			$this->revisions->acked( $revision_id );
			return true;
		}
		return false;
	}

	/* ================================================================ applying */

	/**
	 * Applies a revision in one transaction.
	 *
	 * @param array<string, mixed> $a attributes
	 * @return array<string, mixed>|\WP_Error outcome
	 */
	private function apply( int $property_id, string $revision_id, array $a ): array|\WP_Error {
		$booking_uuid = (string) ( $a['booking_id'] ?? '' );
		if ( ! ChannexMap::is_uuid( $booking_uuid ) ) {
			return new \WP_Error( 'bkng_channex_invalid', __( 'Prenotazione di Channex senza identificativo.', 'booking-manager' ) );
		}
		$lock = 'cx_bk_' . substr( md5( $booking_uuid ), 0, 20 );
		if ( ! DbLock::acquire( $lock, 10 ) ) {
			return new \WP_Error( 'bkng_channex_busy', __( 'Prenotazione già in lavorazione: riprovo al prossimo giro.', 'booking-manager' ) );
		}
		try {
			return Transaction::run(
				function () use ( $property_id, $revision_id, $a, $booking_uuid ) {
					$result = $this->apply_in_transaction( $property_id, $a, $booking_uuid );
					if ( $result instanceof \WP_Error ) {
						throw new ChannexAbort( $result );
					}
					$this->revisions->processed( $revision_id, $result );
					return $result;
				}
			);
		} catch ( ChannexAbort $e ) {
			return $e->error;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'bkng_channex_error', $e->getMessage() );
		} finally {
			DbLock::release( $lock );
		}
	}

	/**
	 * @param array<string, mixed> $a
	 * @return array<string, mixed>|\WP_Error
	 */
	private function apply_in_transaction( int $property_id, array $a, string $booking_uuid ): array|\WP_Error {
		$status   = (string) ( $a['status'] ?? 'new' );
		$local_id = $this->refs->entity_id( ChannexSettings::CHANNEL, ChannelRefRepository::BOOKING, $booking_uuid );
		$booking  = null !== $local_id ? $this->booking_repo->find( $local_id ) : null;
		$label    = self::ota_label( $a );

		if ( 'cancelled' === $status ) {
			if ( ! $booking ) {
				return array(
					'outcome'          => 'ignored',
					'local_booking_id' => null,
					'error'            => __( 'Annullamento di una prenotazione mai ricevuta.', 'booking-manager' ),
				);
			}
			if ( BookingStatus::CANCELLED === $booking['status'] ) {
				return array(
					'outcome'          => 'cancelled',
					'local_booking_id' => (int) $booking['id'],
				);
			}
			$done = $this->bookings->set_status(
				(int) $booking['id'],
				(int) $booking['version'],
				BookingStatus::CANCELLED,
				array(
					'user_id' => 0,
					'actor'   => 'system',
					/* translators: %s: portal name. */
					'reason'  => sprintf( __( 'Annullata da %s', 'booking-manager' ), $label ),
					'notify'  => false,
				)
			);
			if ( $done instanceof \WP_Error ) {
				// Already in house or completed: the staff decides, the revision is kept.
				$this->alerts->raise( $property_id, 'cancel_refused', sprintf( '%s %s: %s', $label, (string) ( $a['ota_reservation_code'] ?? '' ), wp_strip_all_tags( $done->get_error_message() ) ) );
				return array(
					'outcome'          => 'cancelled',
					'local_booking_id' => (int) $booking['id'],
					'needs_attention'  => true,
					'error'            => wp_strip_all_tags( $done->get_error_message() ),
				);
			}
			return array(
				'outcome'          => 'cancelled',
				'local_booking_id' => (int) $booking['id'],
			);
		}

		$mapped = self::rooms( $a, fn( string $uuid ) => $this->map->local_room_type( $uuid ), fn( string $uuid ) => $this->map->local_rate_plan( $uuid ), fn( int $id ) => $this->types->find( $id ) );
		if ( $mapped['unmapped'] ) {
			/* translators: 1: portal name, 2: reservation code. */
			$this->alerts->raise( $property_id, 'unmapped', sprintf( __( 'Prenotazione %1$s %2$s per una camera o tariffa non collegata: collega la camera in Channex › Mappatura, poi «Importa di nuovo».', 'booking-manager' ), $label, (string) ( $a['ota_reservation_code'] ?? '' ) ) );
			return array(
				'outcome'          => 'unmapped',
				'local_booking_id' => $booking ? (int) $booking['id'] : null,
				'needs_attention'  => true,
				'error'            => __( 'Camera o tariffa non collegata.', 'booking-manager' ),
			);
		}
		$guest = self::guest( (array) ( $a['customer'] ?? array() ) );
		$notes = self::internal_note( $a, $label, $mapped['notes'] );

		if ( $booking && BookingStatus::CANCELLED === $booking['status'] ) {
			$this->alerts->raise( $property_id, 'reopened', sprintf( '%s %s: %s', $label, (string) ( $a['ota_reservation_code'] ?? '' ), __( 'il portale ha modificato una prenotazione già annullata qui: controllala.', 'booking-manager' ) ) );
			return array(
				'outcome'          => 'modified',
				'local_booking_id' => (int) $booking['id'],
				'needs_attention'  => true,
				'error'            => __( 'Prenotazione annullata qui ma ancora attiva sul portale.', 'booking-manager' ),
			);
		}

		if ( $booking ) {
			$current = $this->booking_repo->rooms( (int) $booking['id'], true );
			$lines   = $mapped['lines'];
			foreach ( $lines as $i => $line ) {
				if ( isset( $current[ $i ] ) ) {
					$lines[ $i ]['id'] = (int) $current[ $i ]['id'];
				}
			}
			$changed = $this->bookings->change_stay(
				(int) $booking['id'],
				array(
					'version'               => (int) $booking['version'],
					'rooms'                 => $lines,
					'override_restrictions' => true,
					'notify_guest'          => false,
				),
				array(
					'user_id'            => 0,
					'can_override'       => true,
					'can_price'          => true,
					'accept_overbooking' => true,
				)
			);
			if ( $changed instanceof \WP_Error ) {
				if ( 'bkng_not_editable' !== $changed->get_error_code() ) {
					return $changed;
				}
				// Guests already in house or gone: the staff applies the change by hand.
				$this->alerts->raise( $property_id, 'change_refused', sprintf( '%s %s: %s', $label, (string) ( $a['ota_reservation_code'] ?? '' ), wp_strip_all_tags( $changed->get_error_message() ) ) );
				return array(
					'outcome'          => 'modified',
					'local_booking_id' => (int) $booking['id'],
					'needs_attention'  => true,
					'error'            => wp_strip_all_tags( $changed->get_error_message() ),
				);
			}
			$fresh = $this->booking_repo->find( (int) $booking['id'] );
			$this->bookings->update_details(
				(int) $booking['id'],
				array(
					'version'        => (int) ( $fresh['version'] ?? 0 ),
					'guest'          => array_filter( $guest, static fn( $v ) => null !== $v && '' !== $v ),
					'customer_notes' => self::text( $a['notes'] ?? null, 2000 ),
				),
				0
			);
			$over = ! empty( $changed['overbooked'] );
			if ( $over ) {
				$this->overbooking_alert( $property_id, $label, $a );
			}
			return array(
				'outcome'          => 'modified',
				'local_booking_id' => (int) $booking['id'],
				'overbooked'       => $over,
				'needs_attention'  => $over,
			);
		}

		$created = $this->bookings->create(
			array(
				'property_id'           => $property_id,
				'rooms'                 => $mapped['lines'],
				'guest'                 => $guest,
				'customer_notes'        => self::text( $a['notes'] ?? null, 2000 ),
				'internal_notes'        => $notes,
				'status'                => BookingStatus::CONFIRMED,
				'external_source'       => $label,
				'external_ref'          => mb_substr( (string) ( $a['ota_reservation_code'] ?? $a['unique_id'] ?? $booking_uuid ), 0, 190 ),
				'override_restrictions' => true,
				'notify_guest'          => false,
				'idempotency_key'       => 'cx:' . md5( $booking_uuid ),
			),
			array(
				'channel'            => 'import',
				'user_id'            => 0,
				'can_override'       => true,
				'can_price'          => true,
				'accept_overbooking' => true,
			)
		);
		if ( $created instanceof \WP_Error ) {
			return $created;
		}
		$this->refs->link( $property_id, ChannexSettings::CHANNEL, ChannelRefRepository::BOOKING, (int) $created['id'], $booking_uuid );
		$over = ! empty( $created['overbooked'] );
		if ( $over ) {
			$this->overbooking_alert( $property_id, $label, $a );
		}
		return array(
			'outcome'          => 'created',
			'local_booking_id' => (int) $created['id'],
			'overbooked'       => $over,
			'needs_attention'  => $over,
		);
	}

	/** @param array<string, mixed> $a */
	private function overbooking_alert( int $property_id, string $label, array $a ): void {
		/* translators: 1: portal name, 2: reservation code, 3: arrival, 4: departure. */
		$this->alerts->raise( $property_id, 'overbooking', sprintf( __( 'Overbooking: la prenotazione %1$s %2$s (%3$s → %4$s) è arrivata senza camere libere. È salvata senza camera assegnata: trova una sistemazione.', 'booking-manager' ), $label, (string) ( $a['ota_reservation_code'] ?? '' ), (string) ( $a['arrival_date'] ?? '' ), (string) ( $a['departure_date'] ?? '' ) ) );
	}

	/* ================================================================ conversions (pure) */

	/**
	 * Rooms of a revision as BookingService lines (unit-tested). Guests above what our room
	 * type hosts are capped (and written in the notes): a confirmed reservation is never
	 * refused for that.
	 *
	 * @param array<string, mixed>                          $a
	 * @param callable(string): ?int                        $room_type room type of a Channex room type
	 * @param callable(string): ?array                      $rate_plan {room_type_id, rate_plan_id} of a Channex rate plan
	 * @param callable(int): ?array                         $type_row  our room type row
	 * @return array{lines: array<int, array<string, mixed>>, unmapped: bool, notes: string[]}
	 */
	public static function rooms( array $a, callable $room_type, callable $rate_plan, callable $type_row ): array {
		$lines    = array();
		$notes    = array();
		$unmapped = false;
		$rooms    = array_values( array_filter( (array) ( $a['rooms'] ?? array() ), 'is_array' ) );
		foreach ( $rooms as $room ) {
			$type_id = ChannexMap::is_uuid( (string) ( $room['room_type_id'] ?? '' ) ) ? $room_type( (string) $room['room_type_id'] ) : null;
			$plan    = null;
			foreach ( array( $room['rate_plan_id'] ?? null, $room['meta']['parent_rate_plan_id'] ?? null ) as $candidate ) {
				if ( null === $plan && ChannexMap::is_uuid( (string) $candidate ) ) {
					$plan = $rate_plan( (string) $candidate );
				}
			}
			if ( null === $type_id && null !== $plan ) {
				$type_id = $plan['room_type_id'];
			}
			$type = null !== $type_id ? $type_row( $type_id ) : null;
			if ( null === $type_id || ! $type ) {
				$unmapped = true;
				continue;
			}
			$occupancy = is_array( $room['occupancy'] ?? null ) ? $room['occupancy'] : array();
			$adults    = max( 1, (int) ( $occupancy['adults'] ?? 1 ) );
			$children  = max( 0, (int) ( $occupancy['children'] ?? 0 ) ) + max( 0, (int) ( $occupancy['infants'] ?? 0 ) );
			$fit_ad    = min( $adults, max( 1, (int) $type['max_adults'] ) );
			$fit_ch    = min( $children, max( 0, (int) $type['max_children'] ), max( 0, (int) $type['max_occupancy'] - $fit_ad ) );
			if ( $fit_ad !== $adults || $fit_ch !== $children ) {
				/* translators: 1: room type, 2: adults, 3: children. */
				$notes[] = sprintf( __( '%1$s: dal portale %2$d adulti e %3$d bambini (oltre la capienza della camera).', 'booking-manager' ), (string) $type['name'], $adults, $children );
			}
			$amount = self::cents( $room['amount'] ?? null );
			$line   = array(
				'room_type_id' => $type_id,
				'arrival'      => substr( (string) ( $room['checkin_date'] ?? $a['arrival_date'] ?? '' ), 0, 10 ),
				'departure'    => substr( (string) ( $room['checkout_date'] ?? $a['departure_date'] ?? '' ), 0, 10 ),
				'adults'       => $fit_ad,
				'children'     => $fit_ch,
			);
			if ( null !== $plan && $plan['rate_plan_id'] > 0 ) {
				$line['rate_plan_id'] = $plan['rate_plan_id'];
			}
			if ( null !== $amount && $amount >= 0 ) {
				$line['price_total'] = $amount;
			}
			$lines[] = $line;
		}
		if ( ! $rooms ) {
			$unmapped = true;
		}
		return array(
			'lines'    => $lines,
			'unmapped' => $unmapped,
			'notes'    => $notes,
		);
	}

	/**
	 * A decimal amount ("76.50", 76.5, "1234") in integer cents, with string arithmetic
	 * (never floats); half up beyond the cents. Null when not a number (unit-tested).
	 */
	public static function cents( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value * 100;
		}
		if ( is_float( $value ) ) {
			$value = number_format( $value, 4, '.', '' );
		}
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(-)?(\d+)(?:\.(\d+))?$/', $value, $m ) ) {
			return null;
		}
		$sign     = '-' === $m[1] ? -1 : 1;
		$decimals = str_pad( $m[3] ?? '', 3, '0' );
		$cents    = (int) $m[2] * 100 + (int) substr( $decimals, 0, 2 );
		if ( (int) $decimals[2] >= 5 ) {
			++$cents;
		}
		return $sign * $cents;
	}

	/**
	 * The holder from the revision's customer: invalid contacts are left out (the
	 * reservation must be saved anyway).
	 *
	 * @param array<string, mixed> $c
	 * @return array<string, string|null>
	 */
	public static function guest( array $c ): array {
		$first = trim( (string) ( $c['name'] ?? '' ) );
		$last  = trim( (string) ( $c['surname'] ?? '' ) );
		if ( '' === $last ) {
			$last  = '' !== $first ? $first : __( 'Ospite', 'booking-manager' );
			$first = '' !== $first && $last !== $first ? $first : '';
		}
		$email = trim( (string) ( $c['mail'] ?? $c['email'] ?? '' ) );
		$phone = trim( (string) ( $c['phone'] ?? '' ) );
		$phone = (string) preg_replace( '/[^\d+ ]/', '', $phone );
		$lang  = strtolower( substr( (string) ( $c['language'] ?? '' ), 0, 2 ) );
		$ctry  = strtoupper( (string) ( $c['country'] ?? '' ) );
		return array(
			'first_name' => mb_substr( $first, 0, 100 ),
			'last_name'  => mb_substr( $last, 0, 100 ),
			'email'      => is_email( $email ) ? $email : null,
			'phone'      => strlen( (string) preg_replace( '/\D/', '', $phone ) ) >= 6 ? mb_substr( $phone, 0, 32 ) : null,
			'country'    => preg_match( '/^[A-Z]{2}$/', $ctry ) ? $ctry : null,
			'language'   => preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : null,
		);
	}

	/** "Booking.com" for "BookingCom", the OTA name as it comes otherwise, "Channex" without one. */
	public static function ota_label( array $a ): string {
		$name = (string) ( $a['ota_name'] ?? '' );
		$map  = array(
			'BookingCom' => 'Booking.com',
			'Airbnb'     => 'Airbnb',
			'Expedia'    => 'Expedia',
		);
		return $map[ $name ] ?? ( '' !== $name ? mb_substr( $name, 0, 60 ) : 'Channex' );
	}

	/**
	 * Note for the staff: where the reservation comes from, payment collected by the
	 * portal or at the property, guests above capacity.
	 *
	 * @param array<string, mixed> $a
	 * @param string[]             $extra
	 */
	private static function internal_note( array $a, string $label, array $extra ): string {
		$parts = array();
		/* translators: 1: portal name, 2: reservation code. */
		$parts[] = sprintf( __( 'Prenotazione %1$s n. %2$s (via Channex).', 'booking-manager' ), $label, (string) ( $a['ota_reservation_code'] ?? $a['unique_id'] ?? '' ) );
		$collect = (string) ( $a['payment_collect'] ?? '' );
		if ( 'ota' === $collect ) {
			$parts[] = __( 'Pagamento incassato dal portale.', 'booking-manager' );
		} elseif ( 'property' === $collect ) {
			$parts[] = __( 'Pagamento da incassare in struttura.', 'booking-manager' );
		}
		if ( isset( $a['amount'] ) ) {
			/* translators: 1: amount, 2: currency. */
			$parts[] = sprintf( __( 'Totale del portale: %1$s %2$s.', 'booking-manager' ), (string) $a['amount'], (string) ( $a['currency'] ?? '' ) );
		}
		return mb_substr( implode( ' ', array_merge( $parts, $extra ) ), 0, 4000 );
	}

	private static function text( $value, int $max ): ?string {
		$value = trim( sanitize_textarea_field( (string) $value ) );
		return '' === $value ? null : mb_substr( $value, 0, $max );
	}
}
