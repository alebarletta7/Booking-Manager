<?php
/**
 * Mounts the admin single-page app (React via @wordpress/element) under one menu item.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Admin;

use BookingManager\Licensing\LicenseManager;
use BookingManager\Rest\MeController;
use BookingManager\Rest\RestController;
use BookingManager\Rest\SetupController;

defined( 'ABSPATH' ) || exit;

class AdminApp {

	public const SLUG   = 'booking-manager';
	public const HANDLE = 'bkng-admin';

	/** Set on activation: the next admin page opens the app. */
	public const REDIRECT = 'bkng_activation_redirect';

	private string $hook_suffix = '';

	public function __construct( private MeController $me, private LicenseManager $license, private SetupController $setup ) {}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'admin_init', array( $this, 'after_activation' ) );
	}

	/**
	 * Right after «Attiva» in Plugin, an administrator lands in Booking Manager. Not on
	 * bulk activation, network admin, AJAX.
	 */
	public function after_activation(): void {
		if ( ! get_transient( self::REDIRECT ) ) {
			return;
		}
		delete_transient( self::REDIRECT );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads whether WordPress activated several plugins.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'bkng_access' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	public function register_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Booking Manager', 'booking-manager' ),
			__( 'Booking Manager', 'booking-manager' ),
			'bkng_access',
			self::SLUG,
			array( $this, 'render' ),
			self::menu_icon(),
			4
		);
	}

	/**
	 * The logo as a base64 SVG: WordPress paints it in the colours of the admin scheme
	 * (svg-painter). A dashicon if the file is missing.
	 */
	public static function menu_icon(): string {
		$svg = is_readable( BKNG_DIR . 'assets/images/menu-icon.svg' ) ? (string) file_get_contents( BKNG_DIR . 'assets/images/menu-icon.svg' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file of the plugin.
		return '' === $svg ? 'dashicons-food' : 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the format WordPress asks for.
	}

	public function is_app_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return null !== $screen && '' !== $this->hook_suffix && $screen->id === $this->hook_suffix;
	}

	public function render(): void {
		if ( ! current_user_can( 'bkng_access' ) ) {
			wp_die( esc_html__( 'Non hai il permesso di accedere a questa pagina.', 'booking-manager' ), 403 );
		}
		if ( ! file_exists( BKNG_DIR . 'assets/build/admin.js' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'I file dell\'interfaccia non sono presenti. Esegui "npm run build" oppure reinstalla il plugin dallo zip ufficiale.', 'booking-manager' ) . '</p></div>';
			return;
		}
		echo '<div id="bkng-app" class="bkng-root"><div class="bkng-boot">' . esc_html__( 'Caricamento…', 'booking-manager' ) . '</div></div>';
		echo '<noscript>' . esc_html__( 'Booking Manager richiede JavaScript attivo.', 'booking-manager' ) . '</noscript>';
	}

	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}
		$asset_file = BKNG_DIR . 'assets/build/admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		// Media library for the cover picture of the public form (Strutture > Modulo online).
		if ( current_user_can( 'upload_files' ) && current_user_can( 'bkng_manage_property_settings' ) ) {
			wp_enqueue_media();
		}
		wp_enqueue_script( self::HANDLE, BKNG_URL . 'assets/build/admin.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'booking-manager', BKNG_DIR . 'languages' );

		if ( file_exists( BKNG_DIR . 'assets/build/style-admin.css' ) ) {
			wp_enqueue_style( self::HANDLE, BKNG_URL . 'assets/build/style-admin.css', array( 'wp-components' ), $asset['version'] );
		}

		$admin = current_user_can( 'manage_options' ) && current_user_can( 'bkng_manage_settings' );
		$boot  = array(
			'version'         => BKNG_VERSION,
			'restNamespace'   => RestController::REST_NAMESPACE,
			'me'              => $this->me->payload( get_current_user_id() ),
			// «Esci dall'account»: logs out and opens the login page, ready for the next
			// person on a shared tablet. wp_logout_url() is HTML-escaped (&amp;): the app
			// needs the plain address.
			'logoutUrl'       => html_entity_decode( wp_logout_url( wp_login_url() ), ENT_QUOTES ),
			'profileUrl'      => admin_url( 'profile.php' ),
			'wpAdminUrl'      => admin_url(),
			'logoUrl'         => BKNG_URL . 'assets/images/logo.svg',
			'locale'          => str_replace( '_', '-', determine_locale() ),
			'license'         => $this->license_boot( $admin ),
			'shortcode'       => '[restaurant_manager_prenota struttura="…"]',
			// First installation: the guided setup opens by itself for an administrator.
			'setupPending'    => $admin && SetupController::PENDING === $this->setup->state(),
		);
		wp_add_inline_script( self::HANDLE, 'window.rmgrBoot = ' . wp_json_encode( $boot ) . ';', 'before' );
	}

	/**
	 * What the app needs to know about the licence: whether Booking Manager is locked
	 * (no activation on this site: only the Licenza screen, or a message for who cannot
	 * manage the licence), whether new bookings are stopped, the band to show (warnings
	 * before the block only for administrators) and whether this user may open Licenza.
	 *
	 * @return array{locked: bool, lockedText: string, readOnly: bool, reason: string, configured: bool, status: string, notice: ?array<string, mixed>, canManage: bool}
	 */
	private function license_boot( bool $admin ): array {
		$locked = $this->license->is_locked();
		return array(
			'locked'     => $locked,
			'lockedText' => $locked ? $this->license->locked_text( $admin ) : '',
			'readOnly'   => ! $this->license->can_write(),
			'reason'     => $this->license->read_only_reason(),
			'configured' => $this->license->is_configured(),
			'status'     => $this->license->get_status(),
			'notice'     => $this->license->notice( $admin ),
			'canManage'  => $admin,
		);
	}

	public function body_class( string $classes ): string {
		if ( ! $this->is_app_screen() ) {
			return $classes;
		}
		$classes .= ' bkng-screen';
		if ( $this->me->preferences( get_current_user_id() )['operational_mode'] ) {
			$classes .= ' bkng-operational';
		}
		return $classes;
	}
}
