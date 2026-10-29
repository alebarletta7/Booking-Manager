<?php
/**
 * Outgoing sync with Channex: availability, rates and rules.
 *
 * - Every change of the plugin (bookings, holds, rooms, out-of-service periods, prices,
 *   rules) marks the nights to send in the queue (ChannexQueue): only deltas are sent.
 * - A worker per property and kind sends the queue in ONE call (consecutive nights with
 *   equal values become ranges), reading the current values, never the event's: at most
 *   one call every 6 seconds per property and kind (Channex allows 10 a minute), a pause
 *   that grows after 429 / 5xx / no answer (1, 2, 4, 8 … up to 30 minutes), a stop with
 *   a warning after 401, a warning and no retry of the same data after 4xx.
 * - Full sync: 500 days of availability and of rates in 2 calls, when a property goes
 *   online, from the button «Risincronizza tutto», and once a night if enabled.
 * - Reconciliation (nightly): Channex's availability compared with ours; what differs is
 *   queued again (ours wins).
 *
 * Jobs run on WP-Cron (every minute, plus an immediate run after a change) or on Action
 * Scheduler when the site has it (e.g. WooCommerce). A cron of the server that calls
 * wp-cron.php every minute is recommended: WP-Cron alone depends on visits.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

use BookingManager\Bookings\BookingRepository;
use BookingManager\Core\Clock;
use BookingManager\Core\Dates;
use BookingManager\Core\DbLock;
use BookingManager\Core\Hooks;
use BookingManager\Availability\HoldService;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class ChannexSync {

	/** Every minute: queues, expired holds, feed of reservations. */
	public const TICK_HOOK = 'bkng_channex_tick';

	/** Right after a change (debounced): send the queue of a property. */
	public const PUSH_HOOK = 'bkng_channex_push';

	/** Once a night: reconciliation and optional full sync. */
	public const NIGHTLY_HOOK = 'bkng_channex_nightly';

	/** Seconds between two calls of the same kind for a property (10 a minute). */
	public const SPACING = 6;

	/** Pause after failures, in seconds: 1, 2, 4, 8 … minutes, at most 30. */
	public const BACKOFF = array( 60, 120, 240, 480, 960, 1800 );

	public function __construct(
		private ChannexSettings $settings,
		private ChannexClient $client,
		private ChannexMap $map,
		private ChannexQueue $queue,
		private ChannexLog $log,
		private ChannexAlerts $alerts,
		private AriProjector $ari,
		private PropertyRepository $properties,
		private BookingRepository $bookings,
		private HoldService $holds,
		private Clock $clock
	) {}

	public function hooks(): void {
		add_action( Hooks::AVAILABILITY_CHANGED, array( $this, 'on_availability_changed' ), 10, 2 );
		add_action( Hooks::RATES_CHANGED, array( $this, 'on_rates_changed' ), 10, 2 );
		foreach ( array( Hooks::BOOKING_CREATED, Hooks::BOOKING_UPDATED, Hooks::BOOKING_CANCELLED, Hooks::BOOKING_NO_SHOW, Hooks::BOOKING_CONFIRMED ) as $hook ) {
			add_action( $hook, array( $this, 'on_booking_changed' ), 15, 2 );
		}
		add_action( self::PUSH_HOOK, array( $this, 'push_all' ), 10, 1 );
	}

	/* ================================================================ marking */

	/**
	 * @param array<string, mixed> $ctx Hooks::availability_changed()
	 */
	public function on_availability_changed( int $property_id, array $ctx = array() ): void {
		if ( ! $this->live( $property_id ) ) {
			return;
		}
		$mapped = array_keys( $this->map->room_types( $property_id ) );
		$types  = empty( $ctx['room_type_ids'] ) ? $mapped : array_intersect( $mapped, array_map( 'intval', (array) $ctx['room_type_ids'] ) );
		$dates  = $this->dates( $property_id, $ctx['from'] ?? null, $ctx['to'] ?? null );
		foreach ( $types as $type_id ) {
			$this->queue->mark( $property_id, ChannexQueue::AVAILABILITY, (int) $type_id, 0, $dates );
		}
		if ( $types && $dates ) {
			$this->soon( $property_id );
		}
	}

	/**
	 * @param array<string, mixed> $ctx Hooks::rates_changed()
	 */
	public function on_rates_changed( int $property_id, array $ctx = array() ): void {
		if ( ! $this->live( $property_id ) ) {
			return;
		}
		$types = array_map( 'intval', (array) ( $ctx['room_type_ids'] ?? array() ) );
		$plans = array_map( 'intval', (array) ( $ctx['rate_plan_ids'] ?? array() ) );
		$dates = $this->dates( $property_id, $ctx['from'] ?? null, $ctx['to'] ?? null );
		$any   = false;
		foreach ( $this->map->rate_plans( $property_id ) as $link ) {
			if ( ( $types && ! in_array( $link['room_type_id'], $types, true ) ) || ( $plans && ! in_array( $link['rate_plan_id'], $plans, true ) ) ) {
				continue;
			}
			$this->queue->mark( $property_id, ChannexQueue::RESTRICTIONS, $link['room_type_id'], $link['rate_plan_id'], $dates );
			$any = true;
		}
		if ( $any && $dates ) {
			$this->soon( $property_id );
		}
	}

	/**
	 * A booking created, changed or cancelled: its nights (and the old ones after a change).
	 *
	 * @param array<string, mixed> $ctx BookingService::context()
	 */
	public function on_booking_changed( int $booking_id, array $ctx = array() ): void {
		$property_id = (int) ( $ctx['property_id'] ?? 0 );
		if ( ! $this->live( $property_id ) ) {
			return;
		}
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return;
		}
		$by_type = array();
		foreach ( $this->bookings->rooms( $booking_id ) as $room ) {
			$by_type[ (int) $room['room_type_id'] ][] = array( (string) $room['arrival'], (string) $room['departure'] );
		}
		if ( ! empty( $ctx['previous_arrival'] ) && ! empty( $ctx['previous_departure'] ) ) {
			foreach ( (array) ( $ctx['previous_room_type_ids'] ?? array() ) as $type_id ) {
				$by_type[ (int) $type_id ][] = array( (string) $ctx['previous_arrival'], (string) $ctx['previous_departure'] );
			}
		}
		foreach ( $by_type as $type_id => $ranges ) {
			foreach ( $ranges as [ $from, $to ] ) {
				$this->on_availability_changed(
					$property_id,
					array(
						'room_type_ids' => array( $type_id ),
						'from'          => $from,
						'to'            => $to,
					)
				);
			}
		}
	}

	/**
	 * Dates of the horizon within [from, to) (null: from today / to the end), never past.
	 *
	 * @return string[]
	 */
	private function dates( int $property_id, ?string $from, ?string $to ): array {
		$today = $this->today( $property_id );
		$end   = Dates::add_days( $today, AriProjector::HORIZON_DAYS );
		$from  = null === $from || $from < $today ? $today : $from;
		$to    = null === $to || $to > $end ? $end : $to;
		return Dates::nights( $from, $to );
	}

	/** Property linked to Channex and sync switched on. */
	private function live( int $property_id ): bool {
		return $property_id > 0 && $this->settings->active() && null !== $this->map->property_uuid( $property_id );
	}

	/** Sends the queue of a property shortly (a few changes in a row go in one call). */
	public function soon( int $property_id, int $delay = 5 ): void {
		$args = array( $property_id );
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::PUSH_HOOK, $args, 'booking-manager' ) ) {
				as_schedule_single_action( time() + $delay, self::PUSH_HOOK, $args, 'booking-manager' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::PUSH_HOOK, $args ) ) {
			wp_schedule_single_event( time() + $delay, self::PUSH_HOOK, $args );
		}
	}

	/* ================================================================ workers */

	/** Every minute: holds that ran out, queues of every property, feed when due. */
	public function tick( ?callable $pull = null ): void {
		if ( ! $this->settings->active() ) {
			return;
		}
		foreach ( $this->holds->expire_due() as $hold ) {
			$this->on_availability_changed(
				$hold['property_id'],
				array(
					'room_type_ids' => array( $hold['room_type_id'] ),
					'from'          => $hold['arrival'],
					'to'            => $hold['departure'],
				)
			);
		}
		foreach ( array_keys( $this->map->properties() ) as $property_id ) {
			$this->push_all( $property_id );
			if ( null !== $pull ) {
				$state = $this->log->state( $property_id );
				// Safety net if a webhook is lost: the feed every 2 minutes.
				if ( null === $state['last_feed_poll_at'] || strtotime( (string) $state['last_feed_poll_at'] . ' UTC' ) <= time() - 110 ) {
					$pull( $property_id );
				}
			}
		}
	}

	/** Availability first (Channex puts it at the head of its queue), then rates. */
	public function push_all( int $property_id ): void {
		$this->push( $property_id, ChannexQueue::AVAILABILITY );
		$this->push( $property_id, ChannexQueue::RESTRICTIONS );
	}

	/**
	 * Sends the queue of one kind for a property.
	 *
	 * @return string sent | empty | inactive | locked | paused | wait | error
	 */
	public function push( int $property_id, string $kind ): string {
		if ( ! $this->live( $property_id ) ) {
			return 'inactive';
		}
		$lock = 'cx_' . substr( $kind, 0, 5 ) . '_' . $property_id;
		if ( ! DbLock::acquire( $lock, 0 ) ) {
			return 'locked';
		}
		try {
			$wait = $this->wait_for( $property_id, $kind );
			if ( $wait > 0 ) {
				$this->soon( $property_id, $wait );
				return $wait > self::SPACING ? 'paused' : 'wait';
			}
			$today = $this->today( $property_id );
			$this->queue->drop( $property_id, $kind, $today );
			$rows = $this->queue->snapshot( $property_id, $kind );
			if ( ! $rows ) {
				return 'empty';
			}
			$values = ChannexQueue::AVAILABILITY === $kind ? $this->availability_values( $property_id, $rows ) : $this->restriction_values( $property_id, $rows );
			if ( ! $values ) {
				// Types or plans no longer linked: nothing to send for them.
				$this->queue->remove_sent( $kind, $rows );
				return 'empty';
			}
			$ok = $this->send( $property_id, $kind, $values );
			if ( $ok ) {
				$this->queue->remove_sent( $kind, $rows );
				if ( count( $rows ) >= 20000 ) {
					$this->soon( $property_id, self::SPACING );
				}
				return 'sent';
			}
			return 'error';
		} finally {
			DbLock::release( $lock );
		}
	}

	/**
	 * Full sync of a property: 500 days of availability and of rates, two calls.
	 *
	 * @return true|\WP_Error
	 */
	public function full_sync( int $property_id ): bool|\WP_Error {
		if ( ! $this->live( $property_id ) ) {
			return new \WP_Error( 'bkng_channex_inactive', __( 'Collega la struttura a Channex e attiva la sincronizzazione.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$locks = array( 'cx_avail_' . $property_id, 'cx_restr_' . $property_id );
		foreach ( $locks as $i => $lock ) {
			if ( ! DbLock::acquire( $lock, 20 ) ) {
				for ( $j = 0; $j < $i; $j++ ) {
					DbLock::release( $locks[ $j ] );
				}
				return new \WP_Error( 'bkng_channex_busy', __( 'Un invio a Channex è in corso: riprova tra un minuto.', 'booking-manager' ), array( 'status' => 409 ) );
			}
		}
		try {
			$dates   = AriProjector::horizon( $this->today( $property_id ) );
			$results = array();
			foreach ( array( ChannexQueue::AVAILABILITY, ChannexQueue::RESTRICTIONS ) as $kind ) {
				$wait = $this->wait_for( $property_id, $kind );
				if ( $wait > self::SPACING ) {
					return new \WP_Error( 'bkng_channex_paused', __( 'Channex ha chiesto di aspettare: la sincronizzazione riprende da sola tra qualche minuto.', 'booking-manager' ), array( 'status' => 409 ) );
				}
				if ( $wait > 0 ) {
					sleep( $wait );
				}
				// What was queued before reading goes with the full sync; what changes while it
				// runs bumps dirty_seq and stays queued.
				$pending = $this->queue->snapshot( $property_id, $kind, 1000000 );
				$rows    = array();
				if ( ChannexQueue::AVAILABILITY === $kind ) {
					foreach ( array_keys( $this->map->room_types( $property_id ) ) as $type_id ) {
						foreach ( $dates as $date ) {
							$rows[] = array(
								'room_type_id' => (int) $type_id,
								'rate_plan_id' => 0,
								'stay_date'    => $date,
								'dirty_seq'    => 0,
							);
						}
					}
					$values = $this->availability_values( $property_id, $rows );
				} else {
					foreach ( $this->map->rate_plans( $property_id ) as $link ) {
						foreach ( $dates as $date ) {
							$rows[] = array(
								'room_type_id' => $link['room_type_id'],
								'rate_plan_id' => $link['rate_plan_id'],
								'stay_date'    => $date,
								'dirty_seq'    => 0,
							);
						}
					}
					$values = $this->restriction_values( $property_id, $rows );
				}
				if ( ! $values ) {
					continue;
				}
				$results[ $kind ] = $this->send( $property_id, 'full_' . $kind, $values );
				if ( ! $results[ $kind ] ) {
					return new \WP_Error( 'bkng_channex_failed', (string) ( $this->log->state( $property_id )['last_error'] ?? __( 'Invio non riuscito.', 'booking-manager' ) ), array( 'status' => 502 ) );
				}
				$this->queue->remove_sent( $kind, $pending );
			}
			if ( ! $results ) {
				return new \WP_Error( 'bkng_channex_unmapped', __( 'Collega almeno una tipologia o una tariffa a Channex.', 'booking-manager' ), array( 'status' => 409 ) );
			}
			$this->log->set_state( $property_id, array( 'last_full_sync_at' => gmdate( 'Y-m-d H:i:s' ) ) );
			return true;
		} finally {
			foreach ( $locks as $lock ) {
				DbLock::release( $lock );
			}
		}
	}

	/**
	 * Nightly: Channex's availability against ours for the next 500 days; what differs is
	 * queued again (ours wins). Returns the nights queued, or an error.
	 */
	public function reconcile( int $property_id ): int|\WP_Error {
		if ( ! $this->live( $property_id ) ) {
			return 0;
		}
		$uuid  = (string) $this->map->property_uuid( $property_id );
		$dates = AriProjector::horizon( $this->today( $property_id ) );
		$res   = $this->client->get(
			'availability',
			array(
				'filter' => array(
					'property_id' => $uuid,
					'date'        => array(
						'gte' => $dates[0],
						'lte' => end( $dates ),
					),
				),
			)
		);
		if ( null !== $res['error'] ) {
			$this->log->set_state( $property_id, array( 'last_error' => $res['error'] ) );
			return new \WP_Error( 'bkng_channex_failed', $res['error'] );
		}
		$remote = (array) ( $res['body']['data'] ?? array() );
		$queued = 0;
		foreach ( $this->map->room_types( $property_id ) as $type_id => $type_uuid ) {
			$theirs = (array) ( $remote[ $type_uuid ] ?? array() );
			$ours   = $this->ari->availability( (int) $type_id, $dates );
			$diff   = array();
			foreach ( $ours as $date => $free ) {
				if ( ! array_key_exists( $date, $theirs ) || (int) $theirs[ $date ] !== $free ) {
					$diff[] = $date;
				}
			}
			if ( $diff ) {
				$this->queue->mark( $property_id, ChannexQueue::AVAILABILITY, (int) $type_id, 0, $diff );
				$queued += count( $diff );
			}
		}
		$this->log->set_state( $property_id, array( 'last_reconcile_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		if ( $queued ) {
			$this->soon( $property_id );
		}
		return $queued;
	}

	/** Once a night for every property linked: reconciliation, then the full sync if chosen. */
	public function nightly(): void {
		if ( ! $this->settings->active() ) {
			return;
		}
		foreach ( array_keys( $this->map->properties() ) as $property_id ) {
			$this->reconcile( $property_id );
			if ( ! empty( $this->settings->config()['nightly'] ) ) {
				$this->full_sync( $property_id );
			}
		}
	}

	/* ================================================================ sending */

	/**
	 * Values for POST /availability.
	 *
	 * @param array<int, array{room_type_id: int, rate_plan_id: int, stay_date: string, dirty_seq: int}> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function availability_values( int $property_id, array $rows ): array {
		$uuid   = (string) $this->map->property_uuid( $property_id );
		$mapped = $this->map->room_types( $property_id );
		$dates  = array();
		foreach ( $rows as $row ) {
			if ( isset( $mapped[ $row['room_type_id'] ] ) ) {
				$dates[ $row['room_type_id'] ][] = $row['stay_date'];
			}
		}
		$values = array();
		foreach ( $dates as $type_id => $list ) {
			sort( $list );
			$nights = array();
			foreach ( $this->ari->availability( $type_id, $list ) as $date => $free ) {
				$nights[ $date ] = array( 'availability' => $free );
			}
			$values = array_merge(
				$values,
				AriProjector::compress(
					array(
						'property_id'  => $uuid,
						'room_type_id' => $mapped[ $type_id ],
					),
					$nights
				)
			);
		}
		return $values;
	}

	/**
	 * Values for POST /restrictions.
	 *
	 * @param array<int, array{room_type_id: int, rate_plan_id: int, stay_date: string, dirty_seq: int}> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function restriction_values( int $property_id, array $rows ): array {
		$property = $this->properties->find( $property_id );
		if ( ! $property ) {
			return array();
		}
		$uuid  = (string) $this->map->property_uuid( $property_id );
		$links = array();
		foreach ( $this->map->rate_plans( $property_id ) as $link ) {
			$links[ $link['room_type_id'] . ':' . $link['rate_plan_id'] ] = $link;
		}
		$dates = array();
		foreach ( $rows as $row ) {
			$key = $row['room_type_id'] . ':' . $row['rate_plan_id'];
			if ( isset( $links[ $key ] ) ) {
				$dates[ $key ][] = $row['stay_date'];
			}
		}
		$values = array();
		foreach ( $dates as $key => $list ) {
			sort( $list );
			$link   = $links[ $key ];
			$nights = $this->ari->restrictions( $property, $link['room_type_id'], $link['rate_plan_id'], $link['per_person'], $list );
			$values = array_merge(
				$values,
				AriProjector::compress(
					array(
						'property_id'  => $uuid,
						'rate_plan_id' => $link['channex_id'],
					),
					$nights
				)
			);
		}
		return $values;
	}

	/**
	 * One ARI call and what follows from its answer.
	 *
	 * @param array<int, array<string, mixed>> $values
	 */
	private function send( int $property_id, string $kind, array $values ): bool {
		$base   = str_ends_with( $kind, ChannexQueue::AVAILABILITY ) ? ChannexQueue::AVAILABILITY : ChannexQueue::RESTRICTIONS;
		$column = ChannexQueue::AVAILABILITY === $base ? 'last_avail_call_at' : 'last_restr_call_at';
		$res    = $this->client->post( $base, array( 'values' => $values ) );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$warn   = ChannexClient::warnings( $res['body'] );
		$this->log->task( $property_id, $kind, ChannexClient::task_id( $res['body'] ), count( $values ), $res['status'], $warn, $res['error'] );

		if ( null === $res['error'] ) {
			$this->log->set_state(
				$property_id,
				array(
					$column                => $now,
					'paused_until'         => null,
					'consecutive_failures' => 0,
					/* translators: %d: number of values refused. */
					'last_error'           => $warn ? sprintf( _n( 'Channex ha scartato %d valore: vedi il registro.', 'Channex ha scartato %d valori: vedi il registro.', count( $warn ), 'booking-manager' ), count( $warn ) ) : null,
				)
			);
			return true;
		}

		$status = $res['status'];
		if ( 401 === $status ) {
			$this->settings->save( array( 'suspended' => (string) $res['error'] ) );
			$this->log->set_state( $property_id, array( $column => $now, 'last_error' => $res['error'] ) );
			$this->alerts->raise( $property_id, 'unauthorized', __( 'Channex ha rifiutato la chiave API: la sincronizzazione è sospesa. Inserisci di nuovo la chiave nella schermata Channex.', 'booking-manager' ) );
			return false;
		}
		if ( 0 === $status || 429 === $status || $status >= 500 ) {
			$state    = $this->log->state( $property_id );
			$failures = (int) $state['consecutive_failures'] + 1;
			$delay    = self::BACKOFF[ min( $failures, count( self::BACKOFF ) ) - 1 ];
			if ( null !== $res['retry_after'] ) {
				$delay = max( $delay, min( 1800, $res['retry_after'] ) );
			}
			$this->log->set_state(
				$property_id,
				array(
					$column                => $now,
					'consecutive_failures' => $failures,
					'paused_until'         => gmdate( 'Y-m-d H:i:s', time() + $delay ),
					'last_error'           => $res['error'],
				)
			);
			if ( $failures >= 5 ) {
				$this->alerts->raise( $property_id, 'push_failing', (string) $res['error'] );
			}
			$this->soon( $property_id, $delay );
			return false;
		}
		// 400 / 422 and the like: the same data would be refused again. The nights leave
		// the queue (a new change or the nightly full sync sends them again) and the staff
		// is warned.
		$this->log->set_state( $property_id, array( $column => $now, 'last_error' => $res['error'] ) );
		$this->alerts->raise( $property_id, 'push_refused', (string) $res['error'] );
		return ! str_starts_with( $kind, 'full_' );
	}

	/** Seconds to wait before the next call of a kind (pause or spacing), 0 when free. */
	private function wait_for( int $property_id, string $kind ): int {
		$state = $this->log->state( $property_id );
		$now   = time();
		if ( null !== $state['paused_until'] ) {
			$until = strtotime( (string) $state['paused_until'] . ' UTC' );
			if ( $until > $now ) {
				return $until - $now;
			}
		}
		$last = $state[ ChannexQueue::AVAILABILITY === $kind ? 'last_avail_call_at' : 'last_restr_call_at' ];
		if ( null !== $last ) {
			$next = strtotime( (string) $last . ' UTC' ) + self::SPACING;
			if ( $next > $now ) {
				return $next - $now;
			}
		}
		return 0;
	}

	/* ================================================================ status */

	/**
	 * @return array<string, mixed>
	 */
	public function status( int $property_id ): array {
		$state = $this->log->state( $property_id );
		$iso   = static fn( $v ) => null === $v ? null : Clock::iso( (string) $v );
		return array(
			'queue'             => $this->queue->counts( $property_id ),
			'paused_until'      => $iso( $state['paused_until'] ),
			'failures'          => (int) $state['consecutive_failures'],
			'last_avail_call'   => $iso( $state['last_avail_call_at'] ),
			'last_restr_call'   => $iso( $state['last_restr_call_at'] ),
			'last_full_sync'    => $iso( $state['last_full_sync_at'] ),
			'last_feed_poll'    => $iso( $state['last_feed_poll_at'] ),
			'last_reconcile'    => $iso( $state['last_reconcile_at'] ),
			'last_error'        => $state['last_error'],
			'warnings_24h'      => $this->log->warnings_since( $property_id, 24 ),
			'tasks'             => $this->log->tasks( $property_id, 40 ),
		);
	}

	/** Queue removed when a property is unlinked. */
	public function forget( int $property_id ): void {
		$this->queue->clear( $property_id );
	}

	private function today( int $property_id ): string {
		$property = $this->properties->find( $property_id );
		return $this->clock->today_in( $property ? (string) $property['timezone'] : wp_timezone_string() );
	}
}
