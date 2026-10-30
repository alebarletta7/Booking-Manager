<?php
/**
 * Warnings the staff must see: unmapped rooms or rates, overbookings, reservations not
 * acknowledged, channel disconnected, key refused, data refused by Channex.
 *
 * Kept in the option bkng_channex_alerts (latest 30, not autoloaded), shown in the
 * Channex screen and as a notice in the WordPress admin until read; the same warning is
 * e-mailed to the site administrator at most once an hour.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

defined( 'ABSPATH' ) || exit;

class ChannexAlerts {

	public const OPTION = 'bkng_channex_alerts';

	public const MAX = 30;

	public function raise( ?int $property_id, string $code, string $message ): void {
		$alerts = $this->all();
		array_unshift(
			$alerts,
			array(
				'id'          => substr( md5( uniqid( $code, true ) ), 0, 12 ),
				'property_id' => $property_id,
				'code'        => substr( sanitize_key( $code ), 0, 40 ),
				'message'     => mb_substr( wp_strip_all_tags( $message ), 0, 300 ),
				'at'          => gmdate( 'c' ),
				'read'        => false,
			)
		);
		$alerts = array_slice( $alerts, 0, self::MAX );
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $alerts, '', false );
		} else {
			update_option( self::OPTION, $alerts, false );
		}
		$this->mail( $property_id, $code, $message );
	}

	/** @return array<int, array<string, mixed>> newest first */
	public function all(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
	}

	public function unread(): int {
		return count( array_filter( $this->all(), static fn( array $a ) => empty( $a['read'] ) ) );
	}

	public function mark_read(): void {
		$alerts = array_map(
			static fn( array $a ) => array( 'read' => true ) + $a,
			$this->all()
		);
		update_option( self::OPTION, $alerts, false );
	}

	/** Shown on WordPress admin pages (not inside Booking Manager, which has its own list). */
	public function admin_notice(): void {
		if ( ! current_user_can( 'bkng_manage_integrations' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && str_contains( (string) $screen->id, 'booking-manager' ) ) {
			return;
		}
		$count = $this->unread();
		if ( 0 === $count ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Booking Manager · Channex:', 'booking-manager' ),
			/* translators: %d: number of warnings. */
			esc_html( sprintf( _n( '%d avviso da controllare sulla sincronizzazione con i portali.', '%d avvisi da controllare sulla sincronizzazione con i portali.', $count, 'booking-manager' ), $count ) ),
			esc_url( admin_url( 'admin.php?page=booking-manager#/channex' ) ),
			esc_html__( 'Apri', 'booking-manager' )
		);
	}

	private function mail( ?int $property_id, string $code, string $message ): void {
		$key = 'bkng_cx_mail_' . substr( md5( $code . '|' . (int) $property_id ), 0, 16 );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );
		$to = (string) get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		wp_mail(
			$to,
			/* translators: %s: site name. */
			sprintf( __( '[%s] Channex: serve un controllo', 'booking-manager' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
			wp_strip_all_tags( $message ) . "\n\n" . admin_url( 'admin.php?page=booking-manager#/channex' )
		);
	}
}
