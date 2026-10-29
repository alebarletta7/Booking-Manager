<?php
/**
 * Staff land straight in the app, in operational mode, and stay there: after logging in,
 * and whatever page of the WordPress dashboard they open (profile included), they are
 * taken to Booking Manager; they leave only with «Esci dall'account». WordPress and
 * Booking Manager administrators keep the usual WordPress behaviour.
 *
 * Who counts as staff: MeController::operational_by_default().
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Admin;

use BookingManager\Rest\MeController;

defined( 'ABSPATH' ) || exit;

class StaffEntry {

	public function hooks(): void {
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 20, 3 );
		// admin_menu runs before WordPress refuses a page the user may not open (menu.php).
		add_action( 'admin_menu', array( $this, 'confine' ), 0 );
		add_filter( 'show_admin_bar', array( $this, 'show_admin_bar' ), 20 );
	}

	/**
	 * @param string             $redirect_to Where WordPress would send the user.
	 * @param string             $requested   The redirect_to the login form asked for.
	 * @param \WP_User|\WP_Error $user
	 */
	public function login_redirect( $redirect_to, $requested, $user ): string {
		if ( ! $user instanceof \WP_User || ! MeController::operational_by_default( (int) $user->ID ) ) {
			return (string) $redirect_to;
		}
		// A link into the app (e.g. the check-in screen) is kept.
		return self::is_app_url( (string) $requested ) ? (string) $requested : self::app_url();
	}

	/** Staff see only the app: any other page of the dashboard opens the app instead. */
	public function confine(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || ! MeController::operational_by_default( get_current_user_id() ) ) {
			return;
		}
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which page is open.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::staff_may_open( (string) $pagenow, $page ) ) {
			return;
		}
		wp_safe_redirect( self::app_url() );
		exit;
	}

	/**
	 * The dashboard pages staff may load (unit-tested): the app, and the endpoints WordPress
	 * posts to in the background (forms and uploads), which check their own permissions.
	 */
	public static function staff_may_open( string $pagenow, string $page ): bool {
		if ( in_array( $pagenow, array( 'admin-post.php', 'async-upload.php' ), true ) ) {
			return true;
		}
		return 'admin.php' === $pagenow && AdminApp::SLUG === $page;
	}

	/** No WordPress toolbar on the site for staff (door phones, cash desk). */
	public function show_admin_bar( $show ): bool {
		return MeController::operational_by_default( get_current_user_id() ) ? false : (bool) $show;
	}

	public static function app_url(): string {
		return admin_url( 'admin.php?page=' . AdminApp::SLUG );
	}

	/** Same-site link to the app page (unit-tested). */
	public static function is_app_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$app  = wp_parse_url( self::app_url() );
		$link = wp_parse_url( $url );
		if ( ! is_array( $app ) || ! is_array( $link ) ) {
			return false;
		}
		// Same scheme, host, port and path: an http:// link must not pass for the https:// app.
		foreach ( array( 'scheme', 'host' ) as $part ) {
			if ( strtolower( (string) ( $link[ $part ] ?? '' ) ) !== strtolower( (string) ( $app[ $part ] ?? '' ) ) ) {
				return false;
			}
		}
		if ( ( $link['port'] ?? null ) !== ( $app['port'] ?? null ) || ( $link['path'] ?? '' ) !== ( $app['path'] ?? '' ) ) {
			return false;
		}
		parse_str( (string) ( $link['query'] ?? '' ), $query );
		return ( $query['page'] ?? '' ) === AdminApp::SLUG;
	}
}
