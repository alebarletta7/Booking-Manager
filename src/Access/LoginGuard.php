<?php
/**
 * Limits failed logins (0.2.7): on a platform shared by many customers one weak password
 * must not be guessable by trying. Counted per account and per client address, in a
 * 15-minute window:
 *
 *   per account  5 failures  → that account is locked for the rest of the window
 *   per address 20 failures  → that address is locked for the rest of the window
 *
 * A locked attempt is refused even with the right password, with the same message for
 * known and unknown accounts. A successful login clears the account's counter. Applies
 * to every WordPress login (wp-login.php, XML-RPC, the platform's staff entry), since it
 * hooks wp_authenticate.
 *
 * Off with define( 'BKNG_LOGIN_PROTECTION', false ) in wp-config.php (e.g. when another
 * security plugin already does it); limits via the bkng_login_limits filter.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Access;

use BookingManager\Audit\AuditLogger;
use BookingManager\Core\ClientIp;
use BookingManager\Core\RateLimiter;

defined( 'ABSPATH' ) || exit;

class LoginGuard {

	public const ERROR_CODE = 'bkng_login_locked';

	public const WINDOW = 15 * MINUTE_IN_SECONDS;

	public function __construct( private ?AuditLogger $audit = null ) {}

	public function hooks(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'authenticate', array( $this, 'authenticate' ), 99, 2 );
		add_action( 'wp_login_failed', array( $this, 'failed' ), 10, 1 );
		add_action( 'wp_login', array( $this, 'succeeded' ), 10, 2 );
	}

	public static function enabled(): bool {
		return ! defined( 'BKNG_LOGIN_PROTECTION' ) || false !== constant( 'BKNG_LOGIN_PROTECTION' );
	}

	/** @return array{account: int, address: int} */
	public static function limits(): array {
		/**
		 * Failed logins allowed per account and per address in 15 minutes.
		 *
		 * @param array{account: int, address: int} $limits
		 */
		$limits = (array) apply_filters(
			'bkng_login_limits',
			array(
				'account' => 5,
				'address' => 20,
			)
		);
		return array(
			'account' => max( 1, (int) ( $limits['account'] ?? 5 ) ),
			'address' => max( 1, (int) ( $limits['address'] ?? 20 ) ),
		);
	}

	/**
	 * Runs after WordPress has checked the password: a locked account or address is
	 * refused whatever the result.
	 *
	 * @param \WP_User|\WP_Error|null $user
	 * @return \WP_User|\WP_Error|null
	 */
	public function authenticate( $user, $username ) {
		$username = is_string( $username ) ? $username : '';
		if ( '' === $username && ! $user instanceof \WP_User ) {
			return $user; // Nothing typed yet: the empty form.
		}
		if ( ! $this->locked( $username ) ) {
			return $user;
		}
		return new \WP_Error(
			self::ERROR_CODE,
			sprintf(
				/* translators: %d: minutes */
				__( '<strong>Errore</strong>: troppi tentativi di accesso non riusciti. Riprova tra %d minuti.', 'booking-manager' ),
				(int) ( self::WINDOW / MINUTE_IN_SECONDS )
			)
		);
	}

	public function locked( string $username ): bool {
		$limits = self::limits();
		return RateLimiter::count( 'login_acct', self::account_key( $username ) ) >= $limits['account']
			|| RateLimiter::count( 'login_ip', self::address_key() ) >= $limits['address'];
	}

	/** @param string $username what was typed (login or e-mail) */
	public function failed( $username ): void {
		$username = is_string( $username ) ? $username : '';
		$limits   = self::limits();
		RateLimiter::hit( 'login_ip', self::address_key(), $limits['address'], self::WINDOW );
		$counted  = RateLimiter::hit( 'login_acct', self::account_key( $username ), $limits['account'], self::WINDOW );
		$user     = self::user( $username );
		// One line in the log when an existing account gets locked (never the password).
		if ( $user && $counted && RateLimiter::count( 'login_acct', self::account_key( $username ) ) === $limits['account'] ) {
			$this->audit?->log(
				array(
					'action'      => 'security.login_locked',
					'entity_type' => 'user',
					'entity_id'   => $user->ID,
					'new'         => array( 'minutes' => (int) ( self::WINDOW / MINUTE_IN_SECONDS ) ),
				)
			);
		}
	}

	/** @param string $login */
	public function succeeded( $login, $user = null ): void {
		RateLimiter::reset( 'login_acct', self::account_key( is_string( $login ) ? $login : '' ) );
	}

	/** The same counter whether the login or the e-mail was typed. */
	private static function account_key( string $username ): string {
		$user = self::user( $username );
		return $user ? 'u' . $user->ID : 'n' . strtolower( trim( $username ) );
	}

	private static function user( string $username ): ?\WP_User {
		$username = trim( $username );
		if ( '' === $username ) {
			return null;
		}
		$user = get_user_by( 'login', $username );
		if ( ! $user && is_email( $username ) ) {
			$user = get_user_by( 'email', $username );
		}
		return $user instanceof \WP_User ? $user : null;
	}

	private static function address_key(): string {
		return RateLimiter::ip_key( ClientIp::current() );
	}
}
