<?php
/**
 * What the public booking form can do: show the properties that take online bookings,
 * search free rooms with prices, hold rooms while the guest fills in the form, create the
 * booking, and let the guest see or cancel it from the link in the e-mail.
 *
 * Every rule is checked again by BookingService under the database locks: what the form
 * showed is never trusted. Anti-spam: hidden honeypot field, signed minimum filling time
 * (FormToken), optional Turnstile, per-IP limits.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Frontend;

use BookingManager\Availability\AvailabilityService;
use BookingManager\Availability\HoldService;
use BookingManager\Bookings\BookingCodes;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingService;
use BookingManager\Bookings\BookingStatus;
use BookingManager\Bookings\ManageLink;
use BookingManager\Core\Clock;
use BookingManager\Core\Dates;
use BookingManager\Core\RateLimiter;
use BookingManager\Core\Validation;
use BookingManager\Inventory\InventoryService;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Payments\PaymentAccountService;
use BookingManager\Payments\PaymentService;
use BookingManager\Payments\PaymentStatus;
use BookingManager\Rates\Policies;
use BookingManager\Rates\RateRepository;

defined( 'ABSPATH' ) || exit;

class PublicBookingService {

	/** Booking attempts per IP per hour. */
	public const CREATE_LIMIT = 10;
	/** Holds per IP per hour. */
	public const HOLD_LIMIT = 30;
	/** Searches per IP per 10 minutes. */
	public const READ_LIMIT = 300;
	/** Manage-link attempts per IP per hour (guessing codes and tokens). */
	public const MANAGE_LIMIT = 60;

	public function __construct(
		private PropertyRepository $properties,
		private RoomTypeRepository $types,
		private AvailabilityService $availability,
		private HoldService $holds,
		private BookingService $bookings,
		private BookingRepository $repo,
		private ManageLink $manage,
		private Captcha $captcha,
		private Clock $clock,
		private PaymentService $payments,
		private PaymentAccountService $accounts,
		private RateRepository $rates
	) {}

	/**
	 * Properties that take online bookings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function properties(): array {
		$out = array();
		foreach ( $this->properties->all( null, false ) as $property ) {
			if ( self::online( $property ) ) {
				$out[] = array(
					'slug' => (string) $property['slug'],
					'name' => (string) $property['name'],
					'kind' => (string) $property['kind'],
					'city' => $property['city'],
					// Set to false by the licence gate when online bookings are closed.
					'available' => true,
				);
			}
		}
		return $out;
	}

	/**
	 * A property for the form: what it is, its rules and its bookable room types.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function property( string $slug ): array|\WP_Error {
		$property = $this->find( $slug );
		if ( $property instanceof \WP_Error ) {
			return $property;
		}
		$s       = $property['settings'];
		$today   = $this->clock->today_in( (string) $property['timezone'] );
		$privacy = trim( (string) $s['privacy_url'] );
		if ( '' === $privacy && function_exists( 'get_privacy_policy_url' ) ) {
			$privacy = (string) get_privacy_policy_url();
		}
		$types = array();
		foreach ( $this->types->for_property( (int) $property['id'] ) as $type ) {
			if ( $type['online_enabled'] ) {
				$types[] = self::public_type( InventoryService::type_payload( $type ) );
			}
		}
		return array(
			'slug'              => (string) $property['slug'],
			'name'              => (string) $property['name'],
			'kind'              => (string) $property['kind'],
			'address'           => trim( (string) $property['address'] . ', ' . trim( $property['postcode'] . ' ' . $property['city'] ), ', ' ),
			'phone'             => $property['phone'],
			'email'             => $property['email'],
			'currency'          => (string) $property['currency'],
			'checkin_from'      => (string) $property['checkin_from'],
			'checkin_to'        => (string) $property['checkin_to'],
			'checkout_until'    => (string) $property['checkout_until'],
			'today'             => $today,
			'first_day'         => Dates::add_days( $today, (int) $s['min_advance_days'] ),
			'last_day'          => Dates::add_days( $today, (int) $s['max_advance_days'] ),
			'min_stay'          => (int) $s['min_stay'],
			'max_stay'          => (int) $s['max_stay'],
			'max_rooms'         => (int) $s['max_rooms_per_booking'],
			'children_max_age'  => (int) $s['children_max_age'],
			'show_prices'       => (bool) $s['show_prices'],
			'color'             => (string) $s['form_color'],
			'request_only'      => 'direct' !== $property['booking_mode'],
			'terms_text'        => (string) $s['terms_text'],
			'privacy_url'       => $privacy,
			'marketing_consent' => (bool) $s['marketing_consent'],
			'room_types'        => $types,
			'extras'            => $this->extras( (int) $property['id'], (bool) $s['show_prices'] ),
			'available'         => (bool) $types,
		);
	}

	/**
	 * Optional extras the guest can add (breakfast, parking, late check-out…): the public
	 * optional supplements of the property. The price shown is the one written in the
	 * rate; the total of the stay comes from the search, which applies them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extras( int $property_id, bool $show_prices ): array {
		$out = array();
		foreach ( $this->rates->adjustments( $property_id ) as $adj ) {
			if ( ! $adj['is_optional'] || ! $adj['is_public'] || 'supplement' !== $adj['kind'] ) {
				continue;
			}
			$out[] = array(
				'id'            => (int) $adj['id'],
				'name'          => (string) $adj['name'],
				'description'   => (string) ( $adj['description'] ?? '' ),
				'amount'        => $show_prices ? $adj['amount'] : null,
				'percent_bp'    => $show_prices ? $adj['percent_bp'] : null,
				'per'           => (string) $adj['per'],
				// Days of free cancellation it includes (null: a plain supplement).
				'free_cancel_days' => $adj['free_cancel_days'] ?? null,
				'rate_plan_id'  => $adj['rate_plan_id'],
				'room_type_ids' => $adj['room_type_ids'],
			);
		}
		return $out;
	}

	/**
	 * Free room types and rates for a stay.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function search( string $slug, array $q, string $ip ): array|\WP_Error {
		$property = $this->find( $slug );
		if ( $property instanceof \WP_Error ) {
			return $property;
		}
		if ( ! RateLimiter::hit( 'public_read', RateLimiter::ip_key( $ip ), self::READ_LIMIT, 10 * MINUTE_IN_SECONDS ) ) {
			return self::too_many();
		}
		$arrival   = Validation::date( $q['arrival'] ?? null );
		$departure = Validation::date( $q['departure'] ?? null );
		if ( null === $arrival || null === $departure || ! AvailabilityService::valid_range( $arrival, $departure, Dates::MAX_NIGHTS ) ) {
			return Validation::error( array( 'dates' => __( 'Scegli date di arrivo e partenza valide.', 'booking-manager' ) ) );
		}
		$adults   = Validation::int_between( $q['adults'] ?? 2, 1, 50 );
		$children = Validation::int_between( $q['children'] ?? 0, 0, 50 );
		if ( null === $adults || null === $children ) {
			return Validation::error( array( 'guests' => __( 'Numero di ospiti non valido.', 'booking-manager' ) ) );
		}
		$extras = array_values( array_filter( array_map( 'intval', (array) ( $q['extras'] ?? array() ) ) ) );
		$found  = $this->availability->search( $property, $arrival, $departure, $adults, $children, true, $extras );
		$show   = (bool) $property['settings']['show_prices'];
		$today  = $this->clock->today_in( (string) $property['timezone'] );
		$offers = array();
		foreach ( $found['offers'] as $offer ) {
			$rates = array();
			foreach ( $offer['rates'] as $rate ) {
				$rates[] = array(
					'rate_plan_id' => $rate['rate_plan_id'],
					'name'         => $rate['name'],
					'description'  => $rate['description'],
					'meal_plan'    => $rate['meal_plan'],
					'cancellation' => $rate['cancellation'],
					// Cancelling today, for these dates, costs nothing (the "free cancellation" filter).
					'free_cancel'  => is_array( $rate['cancellation'] ) && 0 === Policies::cancellation_penalty( $rate['cancellation'], $arrival, $today, 100, 100 ),
					'deposit'      => $rate['deposit'],
					'options'      => $show ? ( $rate['options'] ?? array() ) : array(),
					'total'        => $show ? (int) $rate['quote']['total'] : null,
					'tax'          => $show ? (int) $rate['quote']['tax'] : null,
					'nights'       => count( $rate['quote']['nightly'] ),
				);
			}
			$offers[] = array(
				'room_type_id' => $offer['room_type_id'],
				// "Only 2 left" is shown only when few remain; the exact number of free rooms is not public.
				'few_left'     => $offer['free'] <= 2,
				'rates'        => $rates,
			);
		}
		return array(
			'arrival'   => $arrival,
			'departure' => $departure,
			'adults'    => $adults,
			'children'  => $children,
			'offers'    => $offers,
			// Why nothing is offered (minimum stay, closed to arrival…), in words for the guest.
			'rules'     => array_values( $found['rules'] ),
		);
	}

	/**
	 * Holds the chosen rooms while the guest fills in the details.
	 *
	 * @param array<string, mixed> $input room_type_id, arrival, departure, quantity
	 * @return array<string, mixed>|\WP_Error
	 */
	public function hold( string $slug, array $input, string $ip ): array|\WP_Error {
		$property = $this->find( $slug );
		if ( $property instanceof \WP_Error ) {
			return $property;
		}
		if ( ! RateLimiter::hit( 'public_hold', RateLimiter::ip_key( $ip ), self::HOLD_LIMIT, HOUR_IN_SECONDS ) ) {
			return self::too_many();
		}
		$type      = $this->types->find( (int) ( $input['room_type_id'] ?? 0 ) );
		$arrival   = Validation::date( $input['arrival'] ?? null );
		$departure = Validation::date( $input['departure'] ?? null );
		$quantity  = Validation::int_between( $input['quantity'] ?? 1, 1, (int) $property['settings']['max_rooms_per_booking'] );
		if ( ! $type || (int) $type['property_id'] !== (int) $property['id'] || 'active' !== $type['status'] || ! $type['online_enabled'] ) {
			return Validation::error( array( 'room_type_id' => __( 'Camera non valida.', 'booking-manager' ) ) );
		}
		if ( null === $arrival || null === $departure || ! AvailabilityService::valid_range( $arrival, $departure, Dates::MAX_NIGHTS ) || null === $quantity ) {
			return Validation::error( array( 'dates' => __( 'Scegli date di arrivo e partenza valide.', 'booking-manager' ) ) );
		}
		$minutes = (int) $property['settings']['hold_minutes'];
		$hold    = $this->holds->create( (int) $property['id'], (int) $type['id'], $arrival, $departure, $quantity, $minutes );
		if ( $hold instanceof \WP_Error ) {
			return $hold;
		}
		return array(
			'token'      => $hold['token'],
			'expires_at' => Clock::iso( $hold['expires_at'] ),
			'minutes'    => $minutes,
		);
	}

	/** Lets the rooms go when the guest goes back. Unknown tokens are ignored. */
	public function release( string $token ): array {
		$this->holds->release( $token );
		return array( 'released' => true );
	}

	/**
	 * A booking from the form.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( string $slug, array $input, string $ip ): array|\WP_Error {
		$property = $this->find( $slug );
		if ( $property instanceof \WP_Error ) {
			return $property;
		}
		// Honeypot: a person never fills the hidden "website" field. Same answer as a
		// validation error, so a bot learns nothing.
		if ( '' !== trim( (string) ( $input['website'] ?? '' ) ) ) {
			return Validation::error( array( 'form' => __( 'Non è stato possibile inviare la richiesta. Ricarica la pagina e riprova.', 'booking-manager' ) ) );
		}
		$token = FormToken::check( (string) ( $input['form_token'] ?? '' ), $this->clock->now_utc()->getTimestamp() );
		if ( 'too_fast' === $token ) {
			return Validation::error( array( 'form' => __( 'Hai inviato il modulo troppo in fretta. Controlla i dati e riprova.', 'booking-manager' ) ) );
		}
		if ( 'ok' !== $token ) {
			return Validation::error( array( 'form' => __( 'Il modulo è scaduto. Ricarica la pagina e riprova.', 'booking-manager' ) ) );
		}
		if ( ! RateLimiter::hit( 'public_booking', RateLimiter::ip_key( $ip ), self::CREATE_LIMIT, HOUR_IN_SECONDS ) ) {
			return self::too_many();
		}
		$captcha = $this->captcha->verify( (string) ( $input['captcha_token'] ?? '' ), $ip );
		if ( true !== $captcha ) {
			return $captcha;
		}
		$guest = is_array( $input['guest'] ?? null ) ? $input['guest'] : array();
		$rooms = array();
		foreach ( array_slice( (array) ( $input['rooms'] ?? array() ), 0, 50 ) as $room ) {
			if ( is_array( $room ) ) {
				// Only what a guest may choose: never a room number or a price.
				$rooms[] = array_intersect_key( $room, array_flip( array( 'room_type_id', 'rate_plan_id', 'adults', 'children' ) ) );
			}
		}
		$data   = array(
			'property_id'       => (int) $property['id'],
			'arrival'           => $input['arrival'] ?? null,
			'departure'         => $input['departure'] ?? null,
			'rooms'             => $rooms,
			'guest'             => array(
				'first_name' => $guest['first_name'] ?? '',
				'last_name'  => $guest['last_name'] ?? '',
				'email'      => $guest['email'] ?? '',
				'phone'      => $guest['phone'] ?? '',
				'country'    => $guest['country'] ?? null,
				'language'   => substr( (string) determine_locale(), 0, 2 ),
			),
			'extras'            => (array) ( $input['extras'] ?? array() ),
			'customer_notes'    => $input['notes'] ?? null,
			'arrival_time'      => $input['arrival_time'] ?? null,
			'hold_token'        => $input['hold_token'] ?? null,
			'idempotency_key'   => isset( $input['request_id'] ) ? 'pub:' . substr( (string) $input['request_id'], 0, 56 ) : null,
			'privacy_accepted'  => rest_sanitize_boolean( $input['privacy'] ?? false ),
		);
		if ( ! empty( $property['settings']['marketing_consent'] ) ) {
			$data['marketing_consent'] = rest_sanitize_boolean( $input['marketing_consent'] ?? false );
		}
		$pay    = $this->online_payment( $property );
		$result = $this->bookings->create(
			$data,
			array(
				'channel'                  => 'online',
				// Kept as an option for as long as a payment page lives (Stripe: 30 minutes at least).
				'awaiting_payment_minutes' => $pay ? 40 : 0,
			)
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$booking  = (array) $this->repo->find( (int) $result['id'] );
		$checkout = null;
		if ( BookingStatus::OPTION === $booking['status'] && $pay ) {
			$checkout = $this->checkout( $booking );
			if ( $checkout instanceof \WP_Error ) {
				// The guest could not reach the payment page: the rooms go back on sale.
				$this->bookings->set_status(
					(int) $booking['id'],
					(int) $booking['version'],
					BookingStatus::CANCELLED,
					array(
						'user_id' => 0,
						'actor'   => 'system',
						'reason'  => __( 'Pagamento online non avviato.', 'booking-manager' ),
						// The guest sees the error on the page: no e-mail for a booking never made.
						'notify'  => false,
					)
				);
				return $checkout;
			}
			$booking = (array) $this->repo->find( (int) $booking['id'] );
		}
		return array(
			'booking'    => $this->public_payload( $booking ),
			'manage_url' => $this->manage->url( $booking ),
			'token'      => $this->manage->token( (string) $booking['uuid'] ),
			'payment'    => $checkout,
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function manage( string $code, string $token, string $ip ): array|\WP_Error {
		if ( ! RateLimiter::hit( 'public_manage', RateLimiter::ip_key( $ip ), self::MANAGE_LIMIT, HOUR_IN_SECONDS ) ) {
			return self::too_many();
		}
		$booking = $this->find_for_guest( $code, $token );
		return $booking instanceof \WP_Error ? $booking : $this->public_payload( $this->refresh_payments( $booking ) );
	}

	/**
	 * The guest pays from the booking's page: what the booking still waits for (the
	 * option's payment, the deposit, or the balance).
	 *
	 * @return array<string, mixed>|\WP_Error checkout_url and amount
	 */
	public function pay( string $code, string $token, string $ip ): array|\WP_Error {
		if ( ! RateLimiter::hit( 'public_manage', RateLimiter::ip_key( $ip ), self::MANAGE_LIMIT, HOUR_IN_SECONDS ) ) {
			return self::too_many();
		}
		$booking = $this->find_for_guest( $code, $token );
		if ( $booking instanceof \WP_Error ) {
			return $booking;
		}
		if ( ! $this->can_pay( $booking ) ) {
			return new \WP_Error( 'bkng_nothing_to_pay', __( 'Per questa prenotazione non c\'è nulla da pagare online.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		return $this->checkout( $booking );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function cancel( string $code, string $token, string $ip ): array|\WP_Error {
		if ( ! RateLimiter::hit( 'public_manage', RateLimiter::ip_key( $ip ), self::MANAGE_LIMIT, HOUR_IN_SECONDS ) ) {
			return self::too_many();
		}
		$booking = $this->find_for_guest( $code, $token );
		if ( $booking instanceof \WP_Error ) {
			return $booking;
		}
		$terms = $this->cancellation( $booking );
		if ( ! $terms['online'] ) {
			return new \WP_Error( 'bkng_not_cancellable', __( 'Questa prenotazione non si può annullare online. Contatta la struttura.', 'booking-manager' ), array( 'status' => 409 ) );
		}
		$result = $this->bookings->set_status(
			(int) $booking['id'],
			(int) $booking['version'],
			BookingStatus::CANCELLED,
			array(
				'user_id' => 0,
				'actor'   => 'guest',
				'reason'  => __( 'Annullata dall\'ospite con il link della prenotazione.', 'booking-manager' ),
			)
		);
		return $result instanceof \WP_Error ? $result : $this->public_payload( (array) $this->repo->find( (int) $booking['id'] ) );
	}

	/**
	 * Online payment of a property: 'deposit' or 'full' when a verified account is chosen
	 * and the property asks for it, null otherwise.
	 *
	 * @param array<string, mixed> $property
	 */
	private function online_payment( array $property ): ?string {
		$mode = (string) ( $property['settings']['online_payment'] ?? 'none' );
		if ( 'none' === $mode || 'direct' !== $property['booking_mode'] ) {
			return null;
		}
		return $this->accounts->active_online_account( (int) $property['id'] ) ? $mode : null;
	}

	/**
	 * What the guest pays now: the whole amount due, or the deposit still missing.
	 *
	 * @param array<string, mixed> $booking
	 */
	public static function amount_to_pay( array $booking, string $mode ): int {
		$t = PaymentService::totals( $booking );
		return 'deposit' === $mode && $t['deposit_missing'] > 0 ? $t['deposit_missing'] : $t['due'];
	}

	/** @param array<string, mixed> $booking */
	private function can_pay( array $booking ): bool {
		$property = $this->properties->find( (int) $booking['property_id'] );
		if ( ! $property || ! in_array( $booking['status'], array( BookingStatus::OPTION, BookingStatus::CONFIRMED, BookingStatus::IN_HOUSE ), true ) ) {
			return false;
		}
		$mode = (string) ( $property['settings']['online_payment'] ?? 'none' );
		return 'none' !== $mode
			&& null !== $this->accounts->active_online_account( (int) $property['id'] )
			&& self::amount_to_pay( $booking, $mode ) >= PaymentService::MIN_ONLINE_CENTS;
	}

	/**
	 * Opens (or reuses) the payment page for what the booking waits for.
	 *
	 * @param array<string, mixed> $booking
	 * @return array{checkout_url: string, amount: int}|\WP_Error
	 */
	private function checkout( array $booking ): array|\WP_Error {
		$property = (array) $this->properties->find( (int) $booking['property_id'] );
		$amount   = self::amount_to_pay( $booking, (string) ( $property['settings']['online_payment'] ?? 'full' ) );
		$back     = $this->manage->url( $booking );
		$result   = $this->payments->start_online_payment( $booking, $amount, add_query_arg( 'bkng_paid', '1', $back ), add_query_arg( 'bkng_paid', '0', $back ), $booking['email'] ?? null );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		return array(
			'checkout_url' => (string) $result['checkout_url'],
			'amount'       => $amount,
		);
	}

	/**
	 * Back from the payment page, the guest's browser proves nothing: while the booking
	 * waits for a payment, the provider is asked directly (at most every 10 seconds).
	 *
	 * @param array<string, mixed> $booking
	 */
	private function refresh_payments( array $booking ): array {
		if ( BookingStatus::OPTION !== $booking['status'] ) {
			return $booking;
		}
		$key = 'bkng_pay_check_' . (int) $booking['id'];
		if ( get_transient( $key ) ) {
			return $booking;
		}
		set_transient( $key, 1, 10 );
		foreach ( $this->payments->repository()->for_booking( (int) $booking['id'] ) as $payment ) {
			if ( in_array( $payment['status'], PaymentStatus::OPEN, true ) ) {
				$this->payments->reconcile( $payment );
			}
		}
		return (array) $this->repo->find( (int) $booking['id'] );
	}

	public function captcha_config(): ?array {
		return $this->captcha->public_config();
	}

	/**
	 * What the guest sees of a booking: no internal notes, no ids, no other people's data.
	 *
	 * @param array<string, mixed> $b
	 * @return array<string, mixed>
	 */
	public function public_payload( array $b ): array {
		$property = (array) $this->properties->find( (int) $b['property_id'] );
		$rooms    = array();
		foreach ( $this->repo->rooms( (int) $b['id'], true ) as $room ) {
			$type    = $this->types->find( (int) $room['room_type_id'] );
			$rooms[] = array(
				'room_type' => $type ? (string) $type['name'] : '',
				'arrival'   => $room['arrival'],
				'departure' => $room['departure'],
				'adults'    => $room['adults'],
				'children'  => $room['children'],
			);
		}
		$terms = $this->cancellation( $b );
		return array(
			'code'           => (string) $b['code'],
			'property'       => array(
				'name'           => (string) ( $property['name'] ?? '' ),
				'address'        => trim( (string) ( $property['address'] ?? '' ) . ', ' . (string) ( $property['city'] ?? '' ), ', ' ),
				'phone'          => $property['phone'] ?? null,
				'email'          => $property['email'] ?? null,
				'checkin_from'   => (string) ( $property['checkin_from'] ?? '' ),
				'checkout_until' => (string) ( $property['checkout_until'] ?? '' ),
				'color'          => (string) ( $property['settings']['form_color'] ?? '' ),
			),
			'first_name'     => (string) $b['first_name'],
			'status'         => (string) $b['status'],
			'payment_status' => (string) $b['payment_status'],
			'arrival'        => (string) $b['arrival'],
			'departure'      => (string) $b['departure'],
			'nights'         => (int) $b['nights'],
			'adults'         => (int) $b['adults'],
			'children'       => (int) $b['children'],
			'rooms'          => $rooms,
			'currency'       => (string) $b['currency'],
			'total'          => (int) $b['total'],
			'paid'           => (int) $b['paid'],
			'balance'        => (int) $b['balance'],
			'deposit_due'    => (int) $b['deposit_due'],
			'terms'          => $b['terms_snapshot'],
			'can_cancel'     => $terms['online'],
			// Waiting for the online payment: the booking holds the rooms until option_expires_at.
			'awaiting_payment' => BookingStatus::OPTION === $b['status'],
			'option_expires_at' => \BookingManager\Core\Clock::iso( $b['option_expires_at'] ),
			'can_pay'        => $this->can_pay( $b ),
			'cancel_penalty' => $terms['penalty'],
		);
	}

	/**
	 * Whether the guest can cancel online now, and what the rate's terms would keep.
	 *
	 * @param array<string, mixed> $b
	 * @return array{online: bool, penalty: int}
	 */
	public function cancellation( array $b ): array {
		$property = $this->properties->find( (int) $b['property_id'] );
		if ( ! $property ) {
			return array(
				'online'  => false,
				'penalty' => 0,
			);
		}
		$today = $this->clock->today_in( (string) $property['timezone'] );
		$plans = is_array( $b['terms_snapshot']['plans'] ?? null ) ? $b['terms_snapshot']['plans'] : array();
		$by_id = array();
		foreach ( $this->repo->rooms( (int) $b['id'], true ) as $room ) {
			$by_id[] = $room;
		}
		return self::cancellation_terms( $b, $by_id, $plans, $today, ! empty( $property['settings']['guest_cancel_online'] ) );
	}

	/**
	 * Pure part of cancellation() (unit-tested): online only for bookings still to come,
	 * with the option on, and never for a non-refundable rate.
	 *
	 * @param array<string, mixed>             $b
	 * @param array<int, array<string, mixed>> $rooms with rate_plan_id, arrival, price_total, nightly
	 * @param array<string|int, mixed>          $plans terms_snapshot plans, keyed by rate plan id
	 * @return array{online: bool, penalty: int}
	 */
	public static function cancellation_terms( array $b, array $rooms, array $plans, string $today, bool $enabled ): array {
		$penalty    = 0;
		$refundable = true;
		// Keyed by rate plan id; a list carries the id in each entry.
		$by_plan = array();
		foreach ( $plans as $key => $entry ) {
			if ( is_array( $entry ) ) {
				$by_plan[ (string) ( $entry['rate_plan_id'] ?? $key ) ] = $entry;
			}
		}
		foreach ( $rooms as $room ) {
			$terms  = $by_plan[ (string) $room['rate_plan_id'] ] ?? null;
			$policy = Policies::cancellation( is_array( $terms ) ? ( $terms['cancellation'] ?? null ) : null );
			$nights = is_array( $room['nightly'] ) ? array_values( $room['nightly'] ) : array();
			$first  = (int) ( is_array( $nights[0] ?? null ) ? ( $nights[0]['price'] ?? 0 ) : ( $nights[0] ?? 0 ) );
			$refundable = $refundable && $policy['refundable'];
			$penalty   += Policies::cancellation_penalty( $policy, (string) $room['arrival'], $today, (int) $room['price_total'], $first );
		}
		$online = $enabled && $refundable
			&& in_array( $b['status'], array( BookingStatus::PENDING, BookingStatus::OPTION, BookingStatus::CONFIRMED ), true )
			&& (string) $b['arrival'] > $today;
		return array(
			'online'  => $online,
			'penalty' => $penalty,
		);
	}

	/**
	 * The booking of a "manage" link, or an error that says nothing about other bookings.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function find_for_guest( string $code, string $token ): array|\WP_Error {
		$booking = $this->repo->find_by_code( BookingCodes::clean( $code ) );
		if ( ! $booking || empty( $booking['manage_token_hash'] ) || '' === $token || ! hash_equals( (string) $booking['manage_token_hash'], BookingCodes::hash_token( $token ) ) ) {
			return new \WP_Error( 'bkng_booking_not_found', __( 'Prenotazione non trovata. Controlla il link ricevuto.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		return $booking;
	}

	/* ------------------------------------------------------------------ Internals */

	/** @param array<string, mixed> $property */
	private static function online( array $property ): bool {
		return 'active' === $property['status'] && ! empty( $property['settings']['online_enabled'] );
	}

	/** @return array<string, mixed>|\WP_Error */
	private function find( string $slug ): array|\WP_Error {
		$property = $this->properties->find_by_slug( sanitize_title( $slug ) );
		if ( ! $property || ! self::online( $property ) ) {
			return new \WP_Error( 'bkng_property_not_found', __( 'Le prenotazioni online non sono disponibili per questa struttura.', 'booking-manager' ), array( 'status' => 404 ) );
		}
		return $property;
	}

	/**
	 * A room type as the guest sees it: no prices of the internal list, no status.
	 *
	 * @param array<string, mixed> $t
	 * @return array<string, mixed>
	 */
	private static function public_type( array $t ): array {
		return array_intersect_key( $t, array_flip( array( 'id', 'name', 'description', 'max_adults', 'max_children', 'max_occupancy', 'size_sqm', 'beds', 'amenities', 'images' ) ) );
	}

	private static function too_many(): \WP_Error {
		return new \WP_Error( 'bkng_rate_limited', __( 'Troppe richieste in poco tempo. Riprova più tardi.', 'booking-manager' ), array( 'status' => 429 ) );
	}
}
