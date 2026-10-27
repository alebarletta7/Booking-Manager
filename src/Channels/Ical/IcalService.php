<?php
/**
 * Booking.com, Airbnb and the other portals through their iCal calendars, the link every
 * portal offers without a partner contract.
 *
 * Out: every room has a private calendar address (/?bkng_ical=TOKEN) that the portal
 * reads every few hours: one «Non disponibile» event per period the room cannot be sold
 * (its stays and blocks, and the nights its room type is sold out). Dates only, never a
 * guest's name.
 *
 * In: the portal's calendar of the room (one or more per room) is read every 15 minutes
 * (and on «Sincronizza ora»): each event closes the room on those nights with a block of
 * kind "channel", which the booking form, the staff app and the other calendars then see
 * as taken. An event the portal drops frees its nights again. Nights already taken by us
 * are skipped: they are mostly our own closures coming back through the portal (we export
 * them, the portal shows them in its calendar). An Airbnb «Reserved» on such nights is a
 * reservation of a guest, so a possible double booking is written in the sync log.
 *
 * A calendar that cannot be read keeps its blocks: a portal down for an hour never
 * reopens the rooms it sold.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Ical;

use BookingManager\Audit\AuditLogger;
use BookingManager\Availability\Ledger;
use BookingManager\Channels\SyncLogRepository;
use BookingManager\Core\Clock;
use BookingManager\Core\Dates;
use BookingManager\Database\Abort;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Inventory\UnitRepository;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class IcalService {

	/** Query var of the export address. */
	public const QUERY_VAR = 'bkng_ical';

	/** Option holding the key of the export addresses. */
	public const KEY_OPTION = 'bkng_ical_key';

	/** Export window: from 30 days ago to two years ahead. */
	public const PAST_DAYS   = 30;
	public const FUTURE_DAYS = 730;

	/** Calendars of the portals: at most this big, this many per room. */
	public const MAX_BYTES    = 2097152;
	public const MAX_PER_UNIT = 10;

	/** Calendars read per cron run (the oldest synchronisations first). */
	public const BATCH = 40;

	public function __construct(
		private IcalFeedRepository $feeds,
		private UnitRepository $units,
		private RoomTypeRepository $types,
		private PropertyRepository $properties,
		private Ledger $ledger,
		private SyncLogRepository $log,
		private AuditLogger $audit,
		private Clock $clock
	) {}

	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'serve' ), 0 );
	}

	/* ------------------------------------------------------------------ Export */

	public function export_url( int $unit_id ): string {
		return add_query_arg( self::QUERY_VAR, $this->token( $unit_id ), home_url( '/' ) );
	}

	public function token( int $unit_id ): string {
		return $unit_id . '.' . substr( hash_hmac( 'sha256', 'ical|' . $unit_id, self::key() ), 0, 32 );
	}

	/** The room of an export token, null when the token is wrong. */
	public function unit_for_token( string $token ): ?int {
		if ( ! preg_match( '/^(\d{1,10})\.([a-f0-9]{32})$/', $token, $m ) ) {
			return null;
		}
		$unit_id = (int) $m[1];
		return hash_equals( $this->token( $unit_id ), $token ) ? $unit_id : null;
	}

	/**
	 * New addresses for every room: the old ones stop working (the links must be given to
	 * the portals again).
	 */
	public function rotate_key(): void {
		update_option( self::KEY_OPTION, wp_generate_password( 64, false, false ), false );
	}

	private static function key(): string {
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( strlen( $key ) < 32 ) {
			$key = wp_generate_password( 64, false, false );
			update_option( self::KEY_OPTION, $key, false );
		}
		return $key;
	}

	/** template_redirect: answers /?bkng_ical=TOKEN with the room's calendar. */
	public function serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the authorisation.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against the HMAC.
		$token = is_string( $_GET[ self::QUERY_VAR ] ) ? wp_unslash( $_GET[ self::QUERY_VAR ] ) : '';
		$ics   = $this->export_for_token( (string) $token );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( null === $ics ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found';
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="calendar.ics"' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar built by IcalCalendar::build().
		exit;
	}

	/** The calendar of a token, null when the token or its room is not valid. */
	public function export_for_token( string $token ): ?string {
		$unit_id = $this->unit_for_token( $token );
		return null === $unit_id ? null : $this->export( $unit_id );
	}

	/** A room's calendar for the portals, null when the room is not active. */
	public function export( int $unit_id ): ?string {
		$unit = $this->units->find( $unit_id );
		if ( ! $unit || 'active' !== $unit['status'] ) {
			return null;
		}
		$property = $this->properties->find( (int) $unit['property_id'] );
		if ( ! $property ) {
			return null;
		}
		$today = $this->clock->today_in( (string) $property['timezone'] );
		$from  = Dates::add_days( $today, -self::PAST_DAYS );
		$to    = Dates::add_days( $today, self::FUTURE_DAYS );
		$name  = (string) $property['name'] . ' - ' . (string) ( '' !== (string) ( $unit['name'] ?? '' ) ? $unit['name'] : $unit['code'] );
		return IcalCalendar::build( IcalCalendar::ranges( $this->closed_nights( $unit, $from, $to ) ), 'bkng-' . $unit_id, $name, time() );
	}

	/**
	 * Nights of [from, to) on which a room cannot be sold: its own stays and blocks, and
	 * the nights its room type has fewer free rooms than empty rooms (stays not assigned to
	 * a room yet, holds of the booking form): those nights close the empty rooms from the
	 * last in display order, so each portal sells only what is really free.
	 *
	 * @param array<string, mixed> $unit
	 * @return string[] sorted nights
	 */
	public function closed_nights( array $unit, string $from, string $to ): array {
		$type_id = (int) $unit['room_type_id'];
		$units   = array_values( array_filter( $this->units->for_type( $type_id ), static fn( $u ) => 'active' === $u['status'] ) );
		$ids     = array_map( static fn( $u ) => (int) $u['id'], $units );
		if ( ! in_array( (int) $unit['id'], $ids, true ) ) {
			$ids[] = (int) $unit['id'];
		}
		$taken  = $this->feeds->nights_of_units( $ids, $from, $to );
		$free   = $this->ledger->free_by_night( $type_id, $from, $to );
		$closed = array();
		foreach ( $free as $night => $free_rooms ) {
			if ( isset( $taken[ (int) $unit['id'] ][ $night ] ) ) {
				$closed[] = $night;
				continue;
			}
			// Empty rooms of the type this night, in display order: the first $free_rooms stay open.
			$position = 0;
			foreach ( $ids as $id ) {
				if ( isset( $taken[ $id ][ $night ] ) ) {
					continue;
				}
				if ( $id === (int) $unit['id'] ) {
					break;
				}
				++$position;
			}
			if ( $position >= $free_rooms ) {
				$closed[] = $night;
			}
		}
		return $closed;
	}

	/* ------------------------------------------------------------------ Calendars of the portals */

	/**
	 * Rooms of a property with their export address and the portals' calendars.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function overview( int $property_id ): array|\WP_Error {
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return self::not_found();
		}
		$names  = IcalCalendar::channel_names();
		$counts = $this->feeds->block_counts( $property_id );
		$feeds  = array();
		foreach ( $this->feeds->for_property( $property_id ) as $feed ) {
			$feeds[ (int) $feed['unit_id'] ][] = $this->feed_payload( $feed, $counts[ (int) $feed['id'] ] ?? 0 );
		}
		$types = array();
		foreach ( $this->types->for_property( $property_id ) as $type ) {
			$types[ (int) $type['id'] ] = (string) $type['name'];
		}
		$rooms = array();
		foreach ( $this->units->for_property( $property_id ) as $unit ) {
			$rooms[] = array(
				'id'         => (int) $unit['id'],
				'code'       => (string) $unit['code'],
				'name'       => (string) ( $unit['name'] ?? '' ),
				'room_type'  => $types[ (int) $unit['room_type_id'] ] ?? '',
				'export_url' => $this->export_url( (int) $unit['id'] ),
				'feeds'      => $feeds[ (int) $unit['id'] ] ?? array(),
			);
		}
		$log = array();
		foreach ( $this->log->recent( $property_id, 100 ) as $row ) {
			if ( ! isset( $names[ (string) $row['channel'] ] ) ) {
				continue;
			}
			$log[] = array(
				'id'         => (int) $row['id'],
				'channel'    => (string) $row['channel'],
				'status'     => (string) $row['status'],
				'message'    => (string) $row['message'],
				'unit_id'    => null !== $row['entity_id'] ? (int) $row['entity_id'] : null,
				'created_at' => Clock::iso( (string) $row['created_at'] ),
			);
			if ( count( $log ) >= 30 ) {
				break;
			}
		}
		$next = wp_next_scheduled( \BookingManager\Core\Installer::ICAL_HOOK );
		return array(
			'channels'  => array_map( static fn( $key, $name ) => array( 'value' => $key, 'label' => $name ), array_keys( $names ), $names ),
			'rooms'     => $rooms,
			'log'       => $log,
			'next_sync' => $next ? gmdate( 'c', (int) $next ) : null,
		);
	}

	/**
	 * Adds a portal's calendar to a room and reads it at once.
	 *
	 * @param array<string, mixed> $input unit_id, channel, url
	 * @return array<string, mixed>|\WP_Error the calendar and the outcome of the first reading
	 */
	public function add_feed( int $property_id, array $input, int $user_id ): array|\WP_Error {
		$unit = $this->units->find( (int) ( $input['unit_id'] ?? 0 ) );
		if ( ! $unit || (int) $unit['property_id'] !== $property_id || 'active' !== $unit['status'] ) {
			return new \WP_Error( 'bkng_validation', __( 'Scegli la camera.', 'booking-manager' ), array( 'status' => 400, 'fields' => array( 'unit_id' => __( 'Scegli la camera.', 'booking-manager' ) ) ) );
		}
		$channel = (string) ( $input['channel'] ?? '' );
		if ( ! in_array( $channel, IcalCalendar::CHANNELS, true ) ) {
			return new \WP_Error( 'bkng_validation', __( 'Scegli il portale.', 'booking-manager' ), array( 'status' => 400, 'fields' => array( 'channel' => __( 'Scegli il portale.', 'booking-manager' ) ) ) );
		}
		$url = self::clean_url( (string) ( $input['url'] ?? '' ) );
		if ( null === $url ) {
			return new \WP_Error( 'bkng_validation', __( 'Incolla l\'indirizzo del calendario (inizia con https://).', 'booking-manager' ), array( 'status' => 400, 'fields' => array( 'url' => __( 'Incolla l\'indirizzo del calendario (inizia con https://).', 'booking-manager' ) ) ) );
		}
		if ( 0 === strpos( $url, rtrim( home_url( '/' ), '/' ) ) && str_contains( $url, self::QUERY_VAR . '=' ) ) {
			return new \WP_Error( 'bkng_validation', __( 'Questo è il calendario di Booking Manager: va incollato sul portale, non qui.', 'booking-manager' ), array( 'status' => 400, 'fields' => array( 'url' => __( 'Questo è il calendario di Booking Manager: va incollato sul portale, non qui.', 'booking-manager' ) ) ) );
		}
		if ( $this->feeds->url_taken( (int) $unit['id'], $url ) ) {
			return new \WP_Error( 'bkng_validation', __( 'Questo calendario è già collegato alla camera.', 'booking-manager' ), array( 'status' => 409, 'fields' => array( 'url' => __( 'Questo calendario è già collegato alla camera.', 'booking-manager' ) ) ) );
		}
		if ( $this->feeds->count_for_unit( (int) $unit['id'] ) >= self::MAX_PER_UNIT ) {
			return new \WP_Error( 'bkng_validation', __( 'Questa camera ha già il numero massimo di calendari collegati.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$id = $this->feeds->insert(
			array(
				'property_id' => $property_id,
				'unit_id'     => (int) $unit['id'],
				'channel'     => $channel,
				'url'         => $url,
				'created_by'  => $user_id ? $user_id : null,
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'bkng_db_error', __( 'Il calendario non è stato salvato. Riprova.', 'booking-manager' ), array( 'status' => 500 ) );
		}
		$this->audit->log(
			array(
				'action'      => 'ical.feed_added',
				'entity_type' => 'unit',
				'entity_id'   => (int) $unit['id'],
				'property_id' => $property_id,
				'new'         => array(
					'feed_id' => $id,
					'channel' => $channel,
				),
			)
		);
		$result = $this->sync_feed( $id );
		$feed   = $this->feeds->find( $id );
		return array(
			'feed'   => $feed ? $this->feed_payload( $feed, count( $this->feeds->blocks( $id ) ) ) : null,
			'result' => $result,
		);
	}

	/** Removes a calendar and frees the nights it closed. @return true|\WP_Error */
	public function delete_feed( int $id ): bool|\WP_Error {
		$feed = $this->feeds->find( $id );
		if ( ! $feed ) {
			return self::not_found();
		}
		$unit   = $this->units->find( (int) $feed['unit_id'] );
		$result = Abort::run(
			function () use ( $feed, $unit ) {
				if ( $unit ) {
					$this->ledger->lock_types( array( (int) $unit['room_type_id'] ) );
				}
				foreach ( $this->feeds->blocks( (int) $feed['id'] ) as $block ) {
					$this->ledger->release_block( (int) $block['id'] );
					$this->feeds->delete_block( (int) $block['id'] );
				}
				$this->feeds->delete( (int) $feed['id'] );
				return true;
			}
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$this->audit->log(
			array(
				'action'      => 'ical.feed_removed',
				'entity_type' => 'unit',
				'entity_id'   => (int) $feed['unit_id'],
				'property_id' => (int) $feed['property_id'],
				'old'         => array(
					'feed_id' => (int) $feed['id'],
					'channel' => (string) $feed['channel'],
				),
			)
		);
		return true;
	}

	/** Cron: reads the calendars synchronised longest ago. */
	public function sync_all(): void {
		$deadline = microtime( true ) + 40;
		foreach ( $this->feeds->due_ids( self::BATCH ) as $id ) {
			$this->sync_feed( $id );
			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
	}

	/**
	 * «Sincronizza ora» for a whole property.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function sync_property( int $property_id ): array|\WP_Error {
		if ( ! $this->properties->find( $property_id ) ) {
			return self::not_found();
		}
		$out = array(
			'feeds'   => 0,
			'errors'  => 0,
			'created' => 0,
			'removed' => 0,
		);
		foreach ( $this->feeds->for_property( $property_id ) as $feed ) {
			$r = $this->sync_feed( (int) $feed['id'] );
			++$out['feeds'];
			if ( 'ok' !== $r['status'] ) {
				++$out['errors'];
			}
			$out['created'] += (int) $r['created'];
			$out['removed'] += (int) $r['removed'];
		}
		return $out;
	}

	/**
	 * Reads one calendar and brings the room's blocks in line with it.
	 *
	 * @return array{status: string, error: string|null, events: int, created: int, removed: int, skipped: int, conflicts: int}
	 */
	public function sync_feed( int $id ): array {
		$out  = array(
			'status'    => 'error',
			'error'     => null,
			'events'    => 0,
			'created'   => 0,
			'removed'   => 0,
			'skipped'   => 0,
			'conflicts' => 0,
		);
		$feed = $this->feeds->find( $id );
		$unit = $feed ? $this->units->find( (int) $feed['unit_id'] ) : null;
		if ( ! $feed || ! $unit ) {
			$out['error'] = __( 'Calendario non trovato.', 'booking-manager' );
			return $out;
		}
		$property = $this->properties->find( (int) $feed['property_id'] );
		$today    = $this->clock->today_in( $property ? (string) $property['timezone'] : 'Europe/Rome' );

		$ics = $this->fetch( (string) $feed['url'] );
		if ( $ics instanceof \WP_Error ) {
			$out['error'] = $ics->get_error_message();
			$this->finish( $feed, $out, $today );
			return $out;
		}
		$horizon = Dates::add_days( $today, self::FUTURE_DAYS );
		$events  = array();
		foreach ( IcalCalendar::parse( $ics ) as $event ) {
			if ( $event['to'] <= $today || $event['from'] >= $horizon ) {
				continue;
			}
			$event['to'] = min( $event['to'], $horizon );
			$events[]    = $event;
		}
		$out['events'] = count( $events );

		if ( 'active' !== $unit['status'] ) {
			$out['status'] = 'ok';
			$this->finish( $feed, $out, $today );
			return $out;
		}
		$result = Abort::run( fn() => $this->apply( $feed, $unit, $events, $today ) );
		if ( $result instanceof \WP_Error ) {
			$out['error'] = $result->get_error_message();
			$this->finish( $feed, $out, $today );
			return $out;
		}
		$out = array_merge( $out, $result, array( 'status' => 'ok' ) );
		foreach ( $result['conflict_events'] as $event ) {
			$this->log->log(
				array(
					'property_id'     => (int) $feed['property_id'],
					'channel'         => (string) $feed['channel'],
					'direction'       => SyncLogRepository::IN,
					'entity_type'     => 'unit',
					'entity_id'       => (int) $unit['id'],
					'external_id'     => $event['uid'],
					'idempotency_key' => 'ical:' . (int) $feed['id'] . ':' . md5( $event['uid'] . '|' . $event['from'] . '|' . $event['to'] ),
					'status'          => 'conflict',
					/* translators: 1: room code, 2: portal name, 3: arrival date, 4: departure date */
					'message'         => sprintf( __( 'Camera %1$s: %2$s ha una prenotazione dal %3$s al %4$s, ma la camera è già occupata in alcune di queste notti. Controlla: possibile doppia prenotazione.', 'booking-manager' ), (string) $unit['code'], IcalCalendar::channel_names()[ (string) $feed['channel'] ] ?? (string) $feed['channel'], wp_date( 'd/m/Y', (int) strtotime( $event['from'] . ' 12:00:00 UTC' ) ), wp_date( 'd/m/Y', (int) strtotime( $event['to'] . ' 12:00:00 UTC' ) ) ),
				)
			);
		}
		unset( $out['conflict_events'] );
		$this->finish( $feed, $out, $today );
		return $out;
	}

	/**
	 * Inside the transaction: the room's blocks of this calendar become its events.
	 *
	 * @param array<string, mixed>                                                   $feed
	 * @param array<string, mixed>                                                   $unit
	 * @param array<int, array{uid: string, from: string, to: string, summary: string}> $events
	 * @return array{created: int, removed: int, skipped: int, conflicts: int, conflict_events: array<int, array<string, string>>}
	 */
	private function apply( array $feed, array $unit, array $events, string $today ): array {
		$type_id = (int) $unit['room_type_id'];
		$this->ledger->lock_types( array( $type_id ) );

		// This calendar's blocks that still matter (those ended before today are history).
		$mine = array();
		foreach ( $this->feeds->blocks( (int) $feed['id'] ) as $block ) {
			if ( (string) $block['date_to'] > $today ) {
				$mine[ (int) $block['id'] ] = $block;
			}
		}

		$from = $today;
		$to   = $today;
		foreach ( $events as $event ) {
			$from = min( $from, $event['from'] );
			$to   = max( $to, $event['to'] );
		}
		foreach ( $mine as $block ) {
			$from = min( $from, (string) $block['date_from'] );
		}
		$taken = $from < $to ? $this->feeds->taken_nights( (int) $unit['id'], $from, $to ) : array();
		$free  = $from < $to ? $this->ledger->free_by_night( $type_id, $from, $to ) : array();
		// This calendar's own blocks do not count against it.
		foreach ( $mine as $block ) {
			foreach ( Dates::nights( (string) $block['date_from'], (string) $block['date_to'] ) as $night ) {
				if ( isset( $free[ $night ] ) ) {
					++$free[ $night ];
				}
			}
		}

		// Night by night: the first event wins (portals may repeat a night in two events).
		$wanted    = array();
		$skipped   = 0;
		$conflicts = array();
		foreach ( $events as $event ) {
			$lost = 0;
			foreach ( Dates::nights( $event['from'], $event['to'] ) as $night ) {
				if ( isset( $wanted[ $night ] ) ) {
					continue;
				}
				$owner = array_key_exists( $night, $taken ) ? $taken[ $night ] : false;
				$ours  = false === $owner || ( null !== $owner && isset( $mine[ $owner ] ) );
				if ( ! $ours || ( $free[ $night ] ?? 0 ) < 1 ) {
					++$lost;
					continue;
				}
				$wanted[ $night ] = $event['uid'];
			}
			if ( $lost ) {
				$skipped += $lost;
				if ( IcalCalendar::is_reservation( $event['summary'] ) ) {
					$conflicts[] = $event;
				}
			}
		}
		ksort( $wanted );

		// Contiguous nights of one event make one block.
		$want_blocks = array();
		$summaries   = array();
		foreach ( $events as $event ) {
			$summaries[ $event['uid'] ] = $event['summary'];
		}
		foreach ( IcalCalendar::ranges( array_keys( $wanted ) ) as [ $a, $b ] ) {
			$start = $a;
			$uid   = $wanted[ $a ];
			foreach ( Dates::nights( $a, $b ) as $night ) {
				if ( $wanted[ $night ] !== $uid ) {
					$want_blocks[ $uid . '|' . $start . '|' . $night ] = array( $uid, $start, $night );
					$start = $night;
					$uid   = $wanted[ $night ];
				}
			}
			$want_blocks[ $uid . '|' . $start . '|' . $b ] = array( $uid, $start, $b );
		}

		$have = array();
		foreach ( $mine as $block ) {
			$have[ (string) $block['external_uid'] . '|' . (string) $block['date_from'] . '|' . (string) $block['date_to'] ] = (int) $block['id'];
		}
		$removed = 0;
		foreach ( array_diff_key( $have, $want_blocks ) as $block_id ) {
			$this->ledger->release_block( $block_id );
			$this->feeds->delete_block( $block_id );
			++$removed;
		}
		$created = 0;
		$reason  = IcalCalendar::channel_names()[ (string) $feed['channel'] ] ?? (string) $feed['channel'];
		foreach ( array_diff_key( $want_blocks, $have ) as [ $uid, $a, $b ] ) {
			$block_id = $this->feeds->create_block(
				array(
					'property_id'  => (int) $unit['property_id'],
					'unit_id'      => (int) $unit['id'],
					'room_type_id' => $type_id,
					'date_from'    => $a,
					'date_to'      => $b,
					'reason'       => IcalCalendar::is_reservation( (string) ( $summaries[ $uid ] ?? '' ) ) ? $reason . ' · ' . __( 'prenotazione', 'booking-manager' ) : $reason,
					'feed_id'      => (int) $feed['id'],
					'external_uid' => $uid,
				)
			);
			if ( ! $block_id ) {
				continue;
			}
			if ( ! $this->ledger->occupy( (int) $unit['id'], (int) $unit['property_id'], $a, $b, null, $block_id ) ) {
				$this->feeds->delete_block( $block_id );
				$skipped += Dates::count( $a, $b );
				continue;
			}
			++$created;
		}
		return array(
			'created'         => $created,
			'removed'         => $removed,
			'skipped'         => $skipped,
			'conflicts'       => count( $conflicts ),
			'conflict_events' => array_map( static fn( $e ) => array( 'uid' => $e['uid'], 'from' => $e['from'], 'to' => $e['to'] ), $conflicts ),
		);
	}

	/**
	 * @param array<string, mixed> $feed
	 * @param array<string, mixed> $out
	 */
	private function finish( array $feed, array $out, string $today ): void {
		$this->feeds->update(
			(int) $feed['id'],
			array(
				'last_sync_at' => gmdate( 'Y-m-d H:i:s' ),
				'last_status'  => (string) $out['status'],
				'last_error'   => null === $out['error'] ? null : mb_substr( (string) $out['error'], 0, 255 ),
				'events'       => 'ok' === $out['status'] ? (int) $out['events'] : (int) $feed['events'],
			)
		);
		if ( 'ok' !== $out['status'] ) {
			// Once a day per calendar: the panel shows the last error anyway.
			$this->log->log(
				array(
					'property_id'     => (int) $feed['property_id'],
					'channel'         => (string) $feed['channel'],
					'direction'       => SyncLogRepository::IN,
					'entity_type'     => 'unit',
					'entity_id'       => (int) $feed['unit_id'],
					'idempotency_key' => 'ical-error:' . (int) $feed['id'] . ':' . $today,
					'status'          => 'error',
					'message'         => mb_substr( (string) $out['error'], 0, 255 ),
				)
			);
		}
	}

	/** The calendar's text, or why it could not be read. */
	private function fetch( string $url ): string|\WP_Error {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'BookingManager/' . BKNG_VERSION . '; ' . home_url( '/' ),
				'headers'             => array( 'Accept' => 'text/calendar, */*;q=0.5' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'bkng_ical_unreachable', __( 'Il portale non risponde: riproverò fra 15 minuti. Le date già chiuse restano chiuse.', 'booking-manager' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'bkng_ical_http', sprintf( __( 'Il portale ha risposto con l\'errore %d: controlla che l\'indirizzo del calendario sia ancora valido.', 'booking-manager' ), $code ) );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( ! str_contains( strtoupper( substr( $body, 0, 2000 ) ), 'BEGIN:VCALENDAR' ) ) {
			return new \WP_Error( 'bkng_ical_invalid', __( 'L\'indirizzo non è un calendario iCal: copia dal portale il link «Esporta calendario».', 'booking-manager' ) );
		}
		return $body;
	}

	/** http(s) or webcal address of a calendar, null when it is not one. */
	public static function clean_url( string $url ): ?string {
		$url = trim( $url );
		if ( 0 === stripos( $url, 'webcal://' ) ) {
			$url = 'https://' . substr( $url, 9 );
		}
		if ( strlen( $url ) > 2000 || ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}
		$url   = esc_url_raw( $url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( '' === $url || ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * @param array<string, mixed> $feed
	 * @return array<string, mixed>
	 */
	private function feed_payload( array $feed, int $blocks ): array {
		return array(
			'id'           => (int) $feed['id'],
			'unit_id'      => (int) $feed['unit_id'],
			'channel'      => (string) $feed['channel'],
			'channel_name' => IcalCalendar::channel_names()[ (string) $feed['channel'] ] ?? (string) $feed['channel'],
			'url'          => (string) $feed['url'],
			'last_sync_at' => Clock::iso( $feed['last_sync_at'] ?? null ),
			'last_status'  => $feed['last_status'] ?? null,
			'last_error'   => $feed['last_error'] ?? null,
			'events'       => (int) $feed['events'],
			'blocks'       => $blocks,
		);
	}

	private static function not_found(): \WP_Error {
		return new \WP_Error( 'bkng_not_found', __( 'Elemento non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
	}
}
