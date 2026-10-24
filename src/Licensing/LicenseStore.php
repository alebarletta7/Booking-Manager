<?php
/**
 * What the licence leaves on the site: one option, bkng_license, never autoloaded.
 *
 *   key              licence key, encrypted (SecretBox)              key_hint  last 4 chars
 *   secret           installation private key, encrypted                public_key its public half
 *   activation_id    act_… given by the server
 *   auth             the last signed authorization, as received (it can be checked again)
 *   signed_error     a signed site_changed / recovery_required, as received
 *   last_attempt_at, last_ok_at (Unix time), last_error (a code, never a message or a body)
 *   last_error_data  the sites of an activation_limit_reached (domains only)
 *   activation_error the last refused «Attiva» (+ activation_error_data): shown, never
 *                    changes the licence in use
 *   clock_offset     seconds to add to the site's clock, learnt from the server
 *   max_seen_time    the latest time ever seen (site clock or signed server_time): a clock
 *                    put back does not stretch the grace period
 *   fail_count       checks failed in a row, for the growing retries
 *   reported_version the plugin version the server last heard (a heartbeat follows an update)
 *   ever_activated   this site got an activation and was not deactivated here since: without
 *                    it, and with no activation, Booking Manager is locked (fresh install, or
 *                    «Disattiva»). An activation lost on the server side keeps it: that site
 *                    only stops new bookings (LicenseManager::is_locked)
 *
 * No tables, no migration. Removed in full at uninstall.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

use BookingManager\Core\SecretBox;

defined( 'ABSPATH' ) || exit;

class LicenseStore {

	public const OPTION = 'bkng_license';

	private const DEFAULTS = array(
		'key'              => '',
		'key_hint'         => '',
		'secret'           => '',
		'public_key'       => '',
		'activation_id'    => '',
		'auth'             => '',
		'signed_error'     => '',
		'last_attempt_at'  => 0,
		'last_ok_at'       => 0,
		'last_error'       => '',
		'last_error_data'  => array(),
		'activation_error' => '',
		'activation_error_data' => array(),
		'clock_offset'     => 0,
		'max_seen_time'    => 0,
		'fail_count'       => 0,
		'reported_version' => '',
		'ever_activated'   => false,
	);

	/** @return array<string, mixed> */
	public function all(): array {
		$saved = $this->read();
		return array_merge( self::DEFAULTS, is_array( $saved ) ? array_intersect_key( $saved, self::DEFAULTS ) : array() );
	}

	/** @param array<string, mixed> $changes */
	public function update( array $changes ): void {
		$this->write( array_merge( $this->all(), array_intersect_key( $changes, self::DEFAULTS ) ) );
	}

	public function delete(): void {
		delete_option( self::OPTION );
	}

	public function has_key(): bool {
		return '' !== $this->all()['key'];
	}

	/** The licence key, null when it cannot be opened any more (WordPress salts changed). */
	public function key(): ?string {
		$sealed = (string) $this->all()['key'];
		if ( '' === $sealed ) {
			return null;
		}
		return self::open( $sealed );
	}

	/** @return array<string, string> key and key_hint, ready for update() */
	public function seal_key( string $key ): array {
		return array(
			'key'      => self::seal( $key ),
			'key_hint' => substr( (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $key ) ), -4 ),
		);
	}

	/** The installation private key (base64), null when missing or unreadable. */
	public function secret(): ?string {
		$sealed = (string) $this->all()['secret'];
		if ( '' === $sealed ) {
			return null;
		}
		return self::open( $sealed );
	}

	public function seal_secret( string $secret_b64 ): string {
		return self::seal( $secret_b64 );
	}

	/** Encrypted with the plugin's SecretBox (WordPress salts or BKNG_SECRET_KEY). */
	private static function seal( string $value ): string {
		return ( new SecretBox() )->seal( array( 'v' => $value ) );
	}

	/** null when it cannot be opened any more (salts or BKNG_SECRET_KEY changed). */
	private static function open( string $sealed ): ?string {
		$value = ( new SecretBox() )->open( $sealed )['v'] ?? '';
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/** @return mixed the stored option (overridden in tests) */
	protected function read() {
		return get_option( self::OPTION, array() );
	}

	/** @param array<string, mixed> $data */
	protected function write( array $data ): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $data, '', false );
			return;
		}
		update_option( self::OPTION, $data, false );
	}
}
