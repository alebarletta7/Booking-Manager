<?php
/**
 * Booking.com, Airbnb and the other portals through their iCal calendars, the link every
 * portal offers without a partner contract. iCal is not real time: each portal reads our
 * link every few hours, we read theirs every 15 minutes.
 *
 * Out: every room has one private calendar address (/?bkng_ical=TOKEN), the same for every
 * portal: one «Non disponibile» event per period the room cannot be sold (its stays, its
 * closures, the closures imported from the other portals, the nights its room type is
 * sold out). Dates only, never a guest's name. Each reading by a portal is recorded
 * (ical_exports): those nights may come back from the portals as «not available».
 *
 * In: each portal calendar of a room is read (cron every 15 minutes, «Sincronizza ora»)
 * and its events are stored as they are (ical_events), per source. The room is then
 * rebuilt from the events of all its calendars together (reconcile): every night is closed
 * by at most one event, with a block of kind "channel" whose source is the portal. So:
 *
 * - a portal that changes or cancels a stay moves or frees its nights;
 * - two calendars with the same nights close them once, and when one drops them the other
 *   keeps them closed at once (no gap until its next reading);
 * - nights already taken here (direct bookings, manual closures) are skipped;
 * - a «not available» closure that is only our own dates coming back (nights our link
 *   exported in the last 72 hours, not a reservation, never closed by that calendar
 *   before) is an echo and is ignored: otherwise a Booking.com stay sent to Airbnb would
 *   come back from Airbnb and keep the room closed after Booking.com cancels it;
 * - an Airbnb «Reserved» on nights already taken is a possible double booking, written
 *   once in the sync log.
 *
 * Dates are never freed by a reading that failed: download error, HTTP error, a page
 * that is not a calendar, a calendar cut off or with unreadable events. A calendar that
 * suddenly comes back empty frees its dates only when the next reading confirms it.
 * Failed readings are retried later and later (15 minutes up to 4 hours).
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
use BookingManager\Core\Hooks;
use BookingManager\Core\Installer;
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

	/** Option holding the per-room part of the key (a room whose link was given a new one). */
	public const SALTS_OPTION = 'bkng_ical_unit_salts';

	/** Option with the time of the last automatic run. */
	public const CRON_OPTION = 'bkng_ical_cron_at';

	/** Rebuilds the rooms of a property from the stored events (after a booking change). */
	public const RECONCILE_HOOK = 'bkng_ical_reconcile';

	/** Export window: from 30 days ago to two years ahead. */
	public const PAST_DAYS   = 30;
	public const FUTURE_DAYS = 730;

	/** Calendars of the portals: at most this big, this many per room. */
	public const MAX_BYTES    = 2097152;
	public const MAX_PER_UNIT = 10;

	/** Calendars read per cron run (the ones due first). */
	public const BATCH = 40;

	/** Minutes between two readings of a calendar, and the longest wait after failures. */
	public const INTERVAL_MINUTES = 15;
	public const MAX_RETRY_MINUTES = 240;

	/** Hours a night told closed to the portals may come back from them as an echo. */
	public const ECHO_HOURS = 72;

	/** Seconds a reading holds its calendar. */
	public const LOCK_SECONDS = 120;

	/** Hours without a successful reading after which a calendar is shown as late. */
	public const STALE_HOURS = 6;

	public function __construct(
		private IcalFeedRepository $feeds,
		private IcalEventRepository $events,
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
		foreach ( array( Hooks::BOOKING_UPDATED, Hooks::BOOKING_CANCELLED, Hooks::BOOKING_NO_SHOW ) as $hook ) {
			add_action( $hook, array( $this, 'on_booking_changed' ), 30, 2 );
		}
		add_action( self::RECONCILE_HOOK, array( $this, 'reconcile_property' ) );
	}

	/**
	 * A booking changed or was cancelled: nights it frees may be closed by a portal event
	 * skipped before. Done right after the request (cron event), from the stored events.
	 *
	 * @param array<string, mixed> $ctx
	 */
	public function on_booking_changed( int $booking_id, array $ctx = array() ): void {
		unset( $booking_id );
		$property_id = (int) ( $ctx['property_id'] ?? 0 );
		if ( $property_id > 0 && ! wp_next_scheduled( self::RECONCILE_HOOK, array( $property_id ) ) ) {
			wp_schedule_single_event( time(), self::RECONCILE_HOOK, array( $property_id ) );
		}
	}

	/* ------------------------------------------------------------------ Export */

	public function export_url( int $unit_id ): string {
		return add_query_arg( self::QUERY_VAR, $this->token( $unit_id ), home_url( '/' ) );
	}

	public function token( int $unit_id ): string {
		$salts = (array) get_option( self::SALTS_OPTION, array() );
		$salt  = isset( $salts[ $unit_id ] ) ? (string) $salts[ $unit_id ] : '';
		return $unit_id . '.' . substr( hash_hmac( 'sha256', 'ical|' . $unit_id . ( '' !== $salt ? '|' . $salt : '' ), self::key() ), 0, 32 );
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

	/**
	 * A new address for one room (its link was shared by mistake): the old one stops
	 * working, the other rooms keep theirs.
	 *
	 * @return array{export_url: string}|\WP_Error
	 */
	public function rotate_unit( int $unit_id ): array|\WP_Error {
		$unit = $this->units->find( $unit_id );
		if ( ! $unit ) {
			return self::not_found();
		}
		$salts             = (array) get_option( self::SALTS_OPTION, array() );
		$salts[ $unit_id ] = wp_generate_password( 20, false, false );
		update_option( self::SALTS_OPTION, $salts, false );
		$this->audit->log(
			array(
				'action'      => 'ical.link_renewed',
				'entity_type' => 'unit',
				'entity_id'   => $unit_id,
				'property_id' => (int) $unit['property_id'],
			)
		);
		return array( 'export_url' => $this->export_url( $unit_id ) );
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
		header( 'Referrer-Policy: no-referrer' );
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

	/**
	 * The calendar of a token, as a portal reads it (the nights are recorded), null when
	 * the token or its room is not valid.
	 */
	public function export_for_token( string $token ): ?string {
		$unit_id = $this->unit_for_token( $token );
		return null === $unit_id ? null : $this->export( $unit_id, true );
	}

	/** A room's calendar for the portals, null when the room or its property is not active. */
	public function export( int $unit_id, bool $record = false ): ?string {
		$unit = $this->units->find( $unit_id );
		if ( ! $unit || 'active' !== $unit['status'] ) {
			return null;
		}
		$property = $this->properties->find( (int) $unit['property_id'] );
		if ( ! $property || 'active' !== (string) ( $property['status'] ?? 'active' ) ) {
			return null;
		}
		$today  = $this->clock->today_in( (string) $property['timezone'] );
		$from   = Dates::add_days( $today, -self::PAST_DAYS );
		$to     = Dates::add_days( $today, self::FUTURE_DAYS );
		$name   = (string) $property['name'] . ' - ' . (string) ( '' !== (string) ( $unit['name'] ?? '' ) ? $unit['name'] : $unit['code'] );
		$closed = $this->closed_nights( $unit, $from, $to );
		if ( $record ) {
			$this->events->exported( $unit_id, array_values( array_filter( $closed, static fn( $n ) => $n >= $today ) ), time() );
		}
		return IcalCalendar::build( IcalCalendar::ranges( $closed ), 'bkng-' . $unit_id, $name, time() );
	}

	/**
	 * Nights of [from, to) on which a room cannot be sold: its own stays and blocks
	 * (closures from the other portals included, so a stay on one portal closes the room on
	 * the others), and the nights its room type has fewer free rooms than empty rooms
	 * (stays not assigned to a room yet): those nights close the empty rooms from the last
	 * in display order, so each portal sells only what is really free. The holds of the
	 * booking form (a few minutes) are not exported.
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
		$free   = $this->free_without_holds( $type_id, $from, $to );
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

	/** @return array<string, int> night => rooms of the type not taken by stays or closures */
	private function free_without_holds( int $type_id, string $from, string $to ): array {
		$capacity = $this->ledger->capacity( $type_id );
		$out      = array();
		foreach ( $this->ledger->usage( $type_id, $from, $to ) as $night => $u ) {
			$out[ $night ] = max( 0, $capacity - $u['booked'] - $u['blocked'] );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Calendars of the portals */

	/**
	 * Rooms of a property with their export address, the portals' calendars, what occupies
	 * each room by source, the log and the state of the automatic readings.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function overview( int $property_id ): array|\WP_Error {
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return self::not_found();
		}
		$today  = $this->clock->today_in( (string) $property['timezone'] );
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
		$occupancy = $this->feeds->occupancy( $property_id, $today );
		$rooms     = array();
		foreach ( $this->units->for_property( $property_id ) as $unit ) {
			$periods = $occupancy[ (int) $unit['id'] ] ?? array();
			$last    = $this->events->last_export( (int) $unit['id'] );
			$rooms[] = array(
				'id'          => (int) $unit['id'],
				'code'        => (string) $unit['code'],
				'name'        => (string) ( $unit['name'] ?? '' ),
				'room_type'   => $types[ (int) $unit['room_type_id'] ] ?? '',
				'export_url'  => $this->export_url( (int) $unit['id'] ),
				'last_export' => Clock::iso( $last ),
				'feeds'       => $feeds[ (int) $unit['id'] ] ?? array(),
				'sources'     => self::sources_summary( $periods ),
				'upcoming'    => array_map(
					static fn( $p ) => $p + array( 'label' => self::source_label( $p['source'] ) ),
					array_slice( $periods, 0, 8 )
				),
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
		return array(
			'channels' => array_map( static fn( $key, $name ) => array( 'value' => $key, 'label' => $name ), array_keys( $names ), $names ),
			'rooms'    => $rooms,
			'log'      => $log,
			'cron'     => $this->cron_state(),
		);
	}

	/**
	 * Whether the automatic readings run: WordPress runs its cron on visits, so a quiet
	 * site (or DISABLE_WP_CRON without a system cron) may never read the calendars.
	 *
	 * @return array<string, mixed>
	 */
	public function cron_state(): array {
		$last = (int) get_option( self::CRON_OPTION, 0 );
		$next = wp_next_scheduled( Installer::ICAL_HOOK );
		return array(
			'interval_minutes' => self::INTERVAL_MINUTES,
			'last_run'         => $last ? gmdate( 'Y-m-d\TH:i:s\Z', $last ) : null,
			'next_run'         => $next ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $next ) : null,
			'healthy'          => $last > 0 && time() - $last < 3 * self::INTERVAL_MINUTES * MINUTE_IN_SECONDS,
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	/**
	 * Adds a portal's calendar to a room and, unless told otherwise, reads it at once.
	 *
	 * @param array<string, mixed> $input unit_id, url, channel (optional: found from the address)
	 * @return array<string, mixed>|\WP_Error the calendar and the outcome of the first reading
	 */
	public function add_feed( int $property_id, array $input, int $user_id, bool $read = true ): array|\WP_Error {
		$unit = $this->units->find( (int) ( $input['unit_id'] ?? 0 ) );
		if ( ! $unit || (int) $unit['property_id'] !== $property_id || 'active' !== $unit['status'] ) {
			return self::invalid( 'unit_id', __( 'Scegli la camera.', 'booking-manager' ) );
		}
		$url = self::clean_url( (string) ( $input['url'] ?? '' ) );
		if ( null === $url ) {
			return self::invalid( 'url', __( 'Incolla l\'indirizzo del calendario del portale: deve iniziare con https://.', 'booking-manager' ) );
		}
		$channel = (string) ( $input['channel'] ?? '' );
		if ( '' === $channel || 'auto' === $channel ) {
			$channel = IcalCalendar::detect_channel( $url );
		}
		if ( ! in_array( $channel, IcalCalendar::CHANNELS, true ) ) {
			return self::invalid( 'channel', __( 'Scegli il portale.', 'booking-manager' ) );
		}
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( $home ) && str_contains( $url, self::QUERY_VAR . '=' ) ) {
			return self::invalid( 'url', __( 'Questo è il calendario di Booking Manager: va incollato sul portale, non qui.', 'booking-manager' ) );
		}
		if ( $this->feeds->url_taken( (int) $unit['id'], $url ) ) {
			return self::invalid( 'url', __( 'Questo calendario è già collegato alla camera.', 'booking-manager' ), 409 );
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
		$result = $read ? $this->sync_feed( $id ) : null;
		$feed   = $this->feeds->find( $id );
		return array(
			'feed'   => $feed ? $this->feed_payload( $feed, count( $this->feeds->blocks( $id ) ) ) : null,
			'result' => $result,
		);
	}

	/**
	 * Several calendars at once («Collega i calendari di tutte le camere»): each row is
	 * checked on its own; the ones not read within the time of the request are read by
	 * the next automatic run.
	 *
	 * @param array<int, mixed> $items each {unit_id, url, channel?}
	 * @return array{items: array<int, array<string, mixed>>, added: int, errors: int}|\WP_Error
	 */
	public function add_feeds( int $property_id, array $items, int $user_id ): array|\WP_Error {
		if ( ! $this->properties->find( $property_id ) ) {
			return self::not_found();
		}
		if ( ! $items || count( $items ) > 200 ) {
			return new \WP_Error( 'bkng_validation', __( 'Incolla almeno un indirizzo (al massimo 200 alla volta).', 'booking-manager' ), array( 'status' => 400 ) );
		}
		$deadline = microtime( true ) + 25;
		$out      = array(
			'items'  => array(),
			'added'  => 0,
			'errors' => 0,
		);
		foreach ( array_values( $items ) as $index => $item ) {
			$item   = is_array( $item ) ? $item : array();
			$result = $this->add_feed( $property_id, $item, $user_id, microtime( true ) < $deadline );
			if ( $result instanceof \WP_Error ) {
				++$out['errors'];
				$out['items'][] = array(
					'index'   => $index,
					'unit_id' => (int) ( $item['unit_id'] ?? 0 ),
					'ok'      => false,
					'error'   => $result->get_error_message(),
				);
				continue;
			}
			++$out['added'];
			$out['items'][] = array(
				'index'   => $index,
				'unit_id' => (int) ( $item['unit_id'] ?? 0 ),
				'ok'      => true,
				'feed'    => $result['feed'],
				'result'  => $result['result'],
			);
		}
		return $out;
	}

	/** Removes a calendar: the nights only it closed are freed. @return true|\WP_Error */
	public function delete_feed( int $id ): bool|\WP_Error {
		$feed = $this->feeds->find( $id );
		if ( ! $feed ) {
			return self::not_found();
		}
		$unit   = $this->units->find( (int) $feed['unit_id'] );
		$today  = $this->today( (int) $feed['property_id'] );
		$result = Abort::run(
			function () use ( $feed, $unit, $today ) {
				$this->feeds->delete( (int) $feed['id'] );
				$this->events->delete_for_feed( (int) $feed['id'] );
				if ( $unit ) {
					$this->ledger->lock_types( array( (int) $unit['room_type_id'] ) );
					return $this->reconcile_locked( $unit, $today );
				}
				foreach ( $this->feeds->blocks( (int) $feed['id'] ) as $block ) {
					$this->ledger->release_block( (int) $block['id'] );
					$this->feeds->delete_block( (int) $block['id'] );
				}
				return null;
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

	/** Cron: reads the calendars that are due. */
	public function sync_all(): void {
		update_option( self::CRON_OPTION, time(), false );
		$this->events->purge_exports( time() - 7 * DAY_IN_SECONDS );
		$deadline = microtime( true ) + 40;
		foreach ( $this->feeds->due_ids( self::BATCH ) as $id ) {
			$this->sync_feed( $id );
			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
	}

	/**
	 * «Sincronizza ora» for a whole property: every calendar, due or not.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function sync_property( int $property_id ): array|\WP_Error {
		if ( ! $this->properties->find( $property_id ) ) {
			return self::not_found();
		}
		$out      = array(
			'feeds'   => 0,
			'errors'  => 0,
			'created' => 0,
			'removed' => 0,
			'later'   => 0,
		);
		$deadline = microtime( true ) + 40;
		foreach ( $this->feeds->for_property( $property_id ) as $feed ) {
			if ( microtime( true ) > $deadline ) {
				++$out['later'];
				continue;
			}
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
	 * Reads one calendar, stores its events and rebuilds its room.
	 *
	 * @return array{status: string, error: string|null, events: int, created: int, removed: int, skipped: int, echoes: int, conflicts: int}
	 *         status: ok | warning (empty calendar waiting for confirmation) | error | busy
	 */
	public function sync_feed( int $id ): array {
		$out  = array(
			'status'    => 'error',
			'error'     => null,
			'events'    => 0,
			'created'   => 0,
			'removed'   => 0,
			'skipped'   => 0,
			'echoes'    => 0,
			'conflicts' => 0,
		);
		$feed = $this->feeds->find( $id );
		$unit = $feed ? $this->units->find( (int) $feed['unit_id'] ) : null;
		if ( ! $feed || ! $unit ) {
			$out['error'] = __( 'Calendario non trovato.', 'booking-manager' );
			return $out;
		}
		if ( ! $this->feeds->claim( $id, self::LOCK_SECONDS ) ) {
			$out['status'] = 'busy';
			$out['error']  = __( 'Il calendario è già in lettura: riprova fra un minuto.', 'booking-manager' );
			return $out;
		}
		try {
			return $this->read_and_apply( $feed, $unit, $out );
		} finally {
			$this->feeds->unlock( $id );
		}
	}

	/**
	 * @param array<string, mixed> $feed
	 * @param array<string, mixed> $unit
	 * @param array<string, mixed> $out
	 * @return array<string, mixed>
	 */
	private function read_and_apply( array $feed, array $unit, array $out ): array {
		$today = $this->today( (int) $feed['property_id'] );
		$url   = $this->feeds->url( $feed );
		$ics   = null === $url
			? new \WP_Error( 'bkng_ical_url', __( 'L\'indirizzo del calendario non si può leggere (le chiavi del sito sono cambiate): scollegalo e collegalo di nuovo.', 'booking-manager' ) )
			: $this->fetch( $url );
		$read = is_string( $ics ) ? IcalCalendar::read( $ics ) : null;
		if ( is_string( $ics ) && ( ! $read['complete'] || $read['invalid'] > 0 ) ) {
			$ics = new \WP_Error( 'bkng_ical_broken', __( 'Il calendario del portale è arrivato incompleto o con eventi illeggibili: le date restano come sono, riprovo più tardi.', 'booking-manager' ) );
		}
		if ( $ics instanceof \WP_Error ) {
			$out['error'] = $ics->get_error_message();
			$this->failed( $feed, $out, $today );
			return $out;
		}

		$horizon = Dates::add_days( $today, self::FUTURE_DAYS );
		$events  = array();
		foreach ( $read['events'] as $event ) {
			if ( $event['to'] <= $today || $event['from'] >= $horizon ) {
				continue;
			}
			$event['to'] = min( $event['to'], $horizon );
			$events[]    = $event;
		}
		$out['events'] = count( $events );

		// A calendar that suddenly comes back empty frees nothing until the next reading says so too.
		if ( ! $events && $this->events->count_future( (int) $feed['id'], $today ) > 0 && (int) $feed['empty_reads'] < 1 ) {
			$out['status'] = 'warning';
			$out['error']  = __( 'Il calendario del portale è arrivato vuoto: le date restano chiuse finché la prossima lettura non lo conferma.', 'booking-manager' );
			$this->feeds->update(
				(int) $feed['id'],
				array(
					'last_sync_at' => gmdate( 'Y-m-d H:i:s' ),
					'last_status'  => 'warning',
					'last_error'   => $out['error'],
					'empty_reads'  => (int) $feed['empty_reads'] + 1,
					'next_sync_at' => gmdate( 'Y-m-d H:i:s', time() + self::INTERVAL_MINUTES * MINUTE_IN_SECONDS ),
				)
			);
			return $out;
		}

		$result = Abort::run(
			function () use ( $feed, $unit, $events, $today ) {
				$this->events->replace( $feed, $events );
				if ( 'active' !== $unit['status'] ) {
					return null;
				}
				$this->ledger->lock_types( array( (int) $unit['room_type_id'] ) );
				return $this->reconcile_locked( $unit, $today );
			}
		);
		if ( $result instanceof \WP_Error ) {
			$out['error'] = $result->get_error_message();
			$this->failed( $feed, $out, $today );
			return $out;
		}
		if ( is_array( $result ) ) {
			$this->log_conflicts( $unit, $result['conflict_events'] );
			unset( $result['conflict_events'] );
			$out = array_merge( $out, $result );
		}
		$out['status'] = 'ok';
		$now           = gmdate( 'Y-m-d H:i:s' );
		$this->feeds->update(
			(int) $feed['id'],
			array(
				'last_sync_at'    => $now,
				'last_success_at' => $now,
				'last_status'     => 'ok',
				'last_error'      => null,
				'failures'        => 0,
				'empty_reads'     => 0,
				'events'          => (int) $out['events'],
				'next_sync_at'    => gmdate( 'Y-m-d H:i:s', time() + self::INTERVAL_MINUTES * MINUTE_IN_SECONDS ),
			)
		);
		return $out;
	}

	/** Rebuilds the rooms of a property from the stored events (no download). */
	public function reconcile_property( int $property_id ): void {
		$today = $this->today( $property_id );
		$done  = array();
		foreach ( $this->feeds->for_property( $property_id ) as $feed ) {
			$unit_id = (int) $feed['unit_id'];
			if ( isset( $done[ $unit_id ] ) ) {
				continue;
			}
			$done[ $unit_id ] = true;
			$unit             = $this->units->find( $unit_id );
			if ( ! $unit || 'active' !== $unit['status'] ) {
				continue;
			}
			$result = Abort::run(
				function () use ( $unit, $today ) {
					$this->ledger->lock_types( array( (int) $unit['room_type_id'] ) );
					return $this->reconcile_locked( $unit, $today );
				}
			);
			if ( is_array( $result ) ) {
				$this->log_conflicts( $unit, $result['conflict_events'] );
			}
		}
	}

	/**
	 * Inside a transaction, with the room type locked: the room's portal closures become
	 * the events of all its calendars together. Every night goes to one event at most: the
	 * one already closing it, then reservations, then the calendar added first.
	 *
	 * @param array<string, mixed> $unit
	 * @return array{created: int, removed: int, skipped: int, echoes: int, conflicts: int, conflict_events: array<int, array<string, mixed>>}
	 */
	private function reconcile_locked( array $unit, string $today ): array {
		$unit_id  = (int) $unit['id'];
		$type_id  = (int) $unit['room_type_id'];
		$channels = array();
		foreach ( $this->feeds->for_unit( $unit_id ) as $feed_id ) {
			$feed = $this->feeds->find( $feed_id );
			if ( $feed ) {
				$channels[ $feed_id ] = (string) $feed['channel'];
			}
		}
		$events = $this->events->future( array_keys( $channels ), $today );

		// The room's portal closures that still matter (those ended before today are history).
		$mine        = array();
		$owned       = array();
		$feed_nights = array();
		foreach ( $this->feeds->unit_blocks( $unit_id ) as $block ) {
			if ( (string) $block['date_to'] > $today ) {
				$mine[ (int) $block['id'] ] = $block;
				$owned[ (int) $block['feed_id'] . '|' . (string) $block['external_uid'] ] = true;
				foreach ( Dates::nights( (string) $block['date_from'], (string) $block['date_to'] ) as $night ) {
					$feed_nights[ (int) $block['feed_id'] ][ $night ] = true;
				}
			}
		}

		$from = $today;
		$to   = $today;
		foreach ( $events as $e ) {
			$from = min( $from, $e['from'] );
			$to   = max( $to, $e['to'] );
		}
		foreach ( $mine as $block ) {
			$from = min( $from, (string) $block['date_from'] );
			$to   = max( $to, (string) $block['date_to'] );
		}
		$taken = $from < $to ? $this->feeds->taken_nights( $unit_id, $from, $to ) : array();
		$free  = $from < $to ? $this->free_without_holds( $type_id, $from, $to ) : array();
		// The portal closures being rebuilt do not count against themselves.
		foreach ( $mine as $block ) {
			foreach ( Dates::nights( (string) $block['date_from'], (string) $block['date_to'] ) as $night ) {
				if ( isset( $free[ $night ] ) ) {
					++$free[ $night ];
				}
			}
		}
		$exported = $this->events->exported_since( $unit_id, time() - self::ECHO_HOURS * HOUR_IN_SECONDS );

		usort(
			$events,
			static function ( $a, $b ) use ( $owned ) {
				$oa = isset( $owned[ $a['feed_id'] . '|' . $a['uid'] ] );
				$ob = isset( $owned[ $b['feed_id'] . '|' . $b['uid'] ] );
				return array( ! $oa, ! $a['reservation'], $a['feed_id'], $a['from'] ) <=> array( ! $ob, ! $b['reservation'], $b['feed_id'], $b['from'] );
			}
		);

		$wanted    = array();
		$skipped   = 0;
		$echoes    = 0;
		$conflicts = array();
		foreach ( $events as $e ) {
			$nights = Dates::nights( $e['from'], $e['to'] );
			if ( ! $nights ) {
				continue;
			}
			// Our own dates coming back: a closure (not a reservation) never held by this
			// calendar, only on nights our link told the portals were closed. A calendar that
			// already closes some of these nights keeps them (portals that change UIDs).
			$held = array_intersect_key( $feed_nights[ $e['feed_id'] ] ?? array(), array_flip( $nights ) );
			if ( ! isset( $owned[ $e['feed_id'] . '|' . $e['uid'] ] ) && ! $held && ! $e['reservation'] && ! array_diff( $nights, array_keys( $exported ) ) ) {
				++$echoes;
				continue;
			}
			$lost = 0;
			foreach ( $nights as $night ) {
				if ( isset( $wanted[ $night ] ) ) {
					// Already closed by another event: a duplicate, unless it is a reservation on a
					// night another portal holds (that closure cannot be an echo of a stay we did
					// not know yet).
					if ( $e['reservation'] && $wanted[ $night ]['feed_id'] !== $e['feed_id'] ) {
						++$lost;
					}
					continue;
				}
				$foreign = array_key_exists( $night, $taken ) && ( null === $taken[ $night ] || ! isset( $mine[ $taken[ $night ] ] ) );
				if ( $foreign || ( $free[ $night ] ?? 0 ) < 1 ) {
					++$lost;
					continue;
				}
				$wanted[ $night ] = $e;
			}
			$skipped += $lost;
			if ( $lost && $e['reservation'] ) {
				$conflicts[] = $e;
			}
		}
		ksort( $wanted );

		// Contiguous nights of one event make one closure.
		$want_blocks = array();
		foreach ( IcalCalendar::ranges( array_keys( $wanted ) ) as [ $a, $b ] ) {
			$start = $a;
			$event = $wanted[ $a ];
			foreach ( Dates::nights( $a, $b ) as $night ) {
				if ( $wanted[ $night ]['feed_id'] !== $event['feed_id'] || $wanted[ $night ]['uid'] !== $event['uid'] ) {
					$want_blocks[ $event['feed_id'] . '|' . $event['uid'] . '|' . $start . '|' . $night ] = array( $event, $start, $night );
					$start = $night;
					$event = $wanted[ $night ];
				}
			}
			$want_blocks[ $event['feed_id'] . '|' . $event['uid'] . '|' . $start . '|' . $b ] = array( $event, $start, $b );
		}

		$have = array();
		foreach ( $mine as $block ) {
			$have[ (int) $block['feed_id'] . '|' . (string) $block['external_uid'] . '|' . (string) $block['date_from'] . '|' . (string) $block['date_to'] ] = (int) $block['id'];
		}
		$removed = 0;
		foreach ( array_diff_key( $have, $want_blocks ) as $block_id ) {
			$this->ledger->release_block( $block_id );
			$this->feeds->delete_block( $block_id );
			++$removed;
		}
		$created = 0;
		$names   = IcalCalendar::channel_names();
		foreach ( array_diff_key( $want_blocks, $have ) as [ $event, $a, $b ] ) {
			$channel  = $channels[ $event['feed_id'] ] ?? 'other';
			$reason   = $names[ $channel ] ?? $channel;
			$block_id = $this->feeds->create_block(
				array(
					'property_id'  => (int) $unit['property_id'],
					'unit_id'      => $unit_id,
					'room_type_id' => $type_id,
					'date_from'    => $a,
					'date_to'      => $b,
					'source'       => $channel,
					'reason'       => $event['reservation'] ? $reason . ' · ' . __( 'prenotazione', 'booking-manager' ) : $reason,
					'feed_id'      => $event['feed_id'],
					'external_uid' => $event['uid'],
				)
			);
			if ( ! $block_id ) {
				continue;
			}
			if ( ! $this->ledger->occupy( $unit_id, (int) $unit['property_id'], $a, $b, null, $block_id ) ) {
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
			'echoes'          => $echoes,
			'conflicts'       => count( $conflicts ),
			'conflict_events' => $conflicts,
		);
	}

	/**
	 * A portal reservation on nights already taken: written once per reservation and dates.
	 *
	 * @param array<string, mixed>              $unit
	 * @param array<int, array<string, mixed>> $conflicts
	 */
	private function log_conflicts( array $unit, array $conflicts ): void {
		$names = IcalCalendar::channel_names();
		foreach ( $conflicts as $event ) {
			$feed    = $this->feeds->find( (int) $event['feed_id'] );
			$channel = $feed ? (string) $feed['channel'] : 'other';
			$this->log->log(
				array(
					'property_id'     => (int) $unit['property_id'],
					'channel'         => $channel,
					'direction'       => SyncLogRepository::IN,
					'entity_type'     => 'unit',
					'entity_id'       => (int) $unit['id'],
					'external_id'     => (string) $event['uid'],
					'idempotency_key' => 'ical:' . (int) $event['feed_id'] . ':' . md5( $event['uid'] . '|' . $event['from'] . '|' . $event['to'] ),
					'status'          => 'conflict',
					/* translators: 1: room code, 2: portal name, 3: arrival date, 4: departure date */
					'message'         => sprintf( __( 'Camera %1$s: %2$s ha una prenotazione dal %3$s al %4$s, ma la camera è già occupata in alcune di queste notti. Controlla: possibile doppia prenotazione.', 'booking-manager' ), (string) $unit['code'], $names[ $channel ] ?? $channel, wp_date( 'd/m/Y', (int) strtotime( $event['from'] . ' 12:00:00 UTC' ) ), wp_date( 'd/m/Y', (int) strtotime( $event['to'] . ' 12:00:00 UTC' ) ) ),
				)
			);
		}
	}

	/**
	 * A reading that failed: nothing changes in the rooms, the next attempt waits longer
	 * (15, 30, 60… minutes, at most 4 hours).
	 *
	 * @param array<string, mixed> $feed
	 * @param array<string, mixed> $out
	 */
	private function failed( array $feed, array $out, string $today ): void {
		$failures = (int) $feed['failures'] + 1;
		$this->feeds->update(
			(int) $feed['id'],
			array(
				'last_sync_at' => gmdate( 'Y-m-d H:i:s' ),
				'last_status'  => 'error',
				'last_error'   => mb_substr( (string) $out['error'], 0, 255 ),
				'failures'     => $failures,
				'next_sync_at' => gmdate( 'Y-m-d H:i:s', time() + self::retry_minutes( $failures ) * MINUTE_IN_SECONDS ),
			)
		);
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

	/** Minutes before the next attempt after $failures failed readings in a row. */
	public static function retry_minutes( int $failures ): int {
		return (int) min( self::MAX_RETRY_MINUTES, self::INTERVAL_MINUTES * 2 ** max( 0, min( 8, $failures - 1 ) ) );
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
			return new \WP_Error( 'bkng_ical_unreachable', __( 'Il portale non risponde: riprovo più tardi. Le date già chiuse restano chiuse.', 'booking-manager' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'bkng_ical_http', sprintf( __( 'Il portale ha risposto con l\'errore %d: controlla che l\'indirizzo del calendario sia ancora valido. Le date già chiuse restano chiuse.', 'booking-manager' ), $code ) );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( ! str_contains( strtoupper( substr( $body, 0, 2000 ) ), 'BEGIN:VCALENDAR' ) ) {
			return new \WP_Error( 'bkng_ical_invalid', __( 'L\'indirizzo non è un calendario iCal: copia dal portale il link «Esporta calendario».', 'booking-manager' ) );
		}
		return $body;
	}

	/**
	 * https (or webcal) address of a calendar, null when it is not one. Plain http is
	 * refused: the address carries the listing's private key.
	 */
	public static function clean_url( string $url ): ?string {
		$url = trim( $url );
		if ( 0 === stripos( $url, 'webcal://' ) ) {
			$url = 'https://' . substr( $url, 9 );
		}
		if ( strlen( $url ) > 2000 || ! preg_match( '#^https://#i', $url ) ) {
			return null;
		}
		$url   = esc_url_raw( $url, array( 'https' ) );
		$parts = wp_parse_url( $url );
		if ( '' === $url || ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * @param array<string, mixed> $feed
	 * @return array<string, mixed> without the address (it carries the listing's private key)
	 */
	private function feed_payload( array $feed, int $blocks ): array {
		return array(
			'id'              => (int) $feed['id'],
			'unit_id'         => (int) $feed['unit_id'],
			'channel'         => (string) $feed['channel'],
			'channel_name'    => IcalCalendar::channel_names()[ (string) $feed['channel'] ] ?? (string) $feed['channel'],
			'url_hint'        => (string) ( $feed['url_hint'] ?? '' ),
			'state'           => self::feed_state( $feed ),
			'last_sync_at'    => Clock::iso( $feed['last_sync_at'] ?? null ),
			'last_success_at' => Clock::iso( $feed['last_success_at'] ?? null ),
			'next_sync_at'    => Clock::iso( $feed['next_sync_at'] ?? null ),
			'last_status'     => $feed['last_status'] ?? null,
			'last_error'      => $feed['last_error'] ?? null,
			'failures'        => (int) $feed['failures'],
			'events'          => (int) $feed['events'],
			'blocks'          => $blocks,
		);
	}

	/**
	 * State of a calendar for the screen: pending (never read), ok, late (no good reading
	 * for STALE_HOURS), warning (empty, waiting for confirmation), error.
	 *
	 * @param array<string, mixed> $feed
	 */
	public static function feed_state( array $feed ): string {
		$status = (string) ( $feed['last_status'] ?? '' );
		if ( '' === $status ) {
			return 'pending';
		}
		if ( 'error' === $status || 'warning' === $status ) {
			return $status;
		}
		$success = isset( $feed['last_success_at'] ) ? strtotime( $feed['last_success_at'] . ' UTC' ) : false;
		return false !== $success && time() - $success > self::STALE_HOURS * HOUR_IN_SECONDS ? 'late' : 'ok';
	}

	/**
	 * Periods and nights from today on, by source.
	 *
	 * @param array<int, array{source: string, from: string, to: string}> $periods
	 * @return array<int, array{source: string, label: string, periods: int, nights: int}>
	 */
	private static function sources_summary( array $periods ): array {
		$out = array();
		foreach ( $periods as $p ) {
			if ( ! isset( $out[ $p['source'] ] ) ) {
				$out[ $p['source'] ] = array(
					'source'  => $p['source'],
					'label'   => self::source_label( $p['source'] ),
					'periods' => 0,
					'nights'  => 0,
				);
			}
			++$out[ $p['source'] ]['periods'];
			$out[ $p['source'] ]['nights'] += Dates::count( $p['from'], $p['to'] );
		}
		$order = array_flip( array( 'direct', 'airbnb', 'booking', 'vrbo', 'expedia', 'other', 'channel', 'manual' ) );
		uksort( $out, static fn( $a, $b ) => ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 ) );
		return array_values( $out );
	}

	private static function source_label( string $source ): string {
		$names = IcalCalendar::channel_names();
		if ( isset( $names[ $source ] ) ) {
			return $names[ $source ];
		}
		return match ( $source ) {
			'direct'  => __( 'Prenotazioni dirette', 'booking-manager' ),
			'channel' => __( 'Canale collegato', 'booking-manager' ),
			default   => __( 'Chiusure manuali', 'booking-manager' ),
		};
	}

	private function today( int $property_id ): string {
		$property = $this->properties->find( $property_id );
		return $this->clock->today_in( $property ? (string) $property['timezone'] : 'Europe/Rome' );
	}

	private static function invalid( string $field, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error(
			'bkng_validation',
			$message,
			array(
				'status' => $status,
				'fields' => array( $field => $message ),
			)
		);
	}

	private static function not_found(): \WP_Error {
		return new \WP_Error( 'bkng_not_found', __( 'Elemento non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
	}
}
