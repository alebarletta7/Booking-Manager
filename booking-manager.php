<?php
/**
 * Plugin Name:       Booking Manager
 * Plugin URI:        https://www.alessandrobarletta.it/
 * Description:       Gestionale per B&B, affittacamere, case vacanza, appartamenti e hotel: camere, tariffe, disponibilità, prenotazioni, pagamenti, check-in e housekeeping.
 * Version:           0.1.13
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Alessandro Barletta
 * Author URI:        https://www.alessandrobarletta.it/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://licenze.alessandrobarletta.it/booking-manager
 * Text Domain:       booking-manager
 * Domain Path:       /languages
 *
 * @package BookingManager
 */

/*
 * NOTE: this file must stay parseable by old PHP versions so that the
 * requirements check can show a friendly notice instead of a fatal error.
 * Do not use PHP 8 syntax here; everything modern lives in src/.
 *
 * Booking Manager is a standalone product: it never requires Night, Beach or Restaurant
 * Manager or any other plugin to be active. Optional integrations plug in through the
 * bkng_modules filter and the public hooks (docs/HOOKS.md).
 */

defined( 'ABSPATH' ) || exit;

define( 'BKNG_VERSION', '0.1.13' );
define( 'BKNG_FILE', __FILE__ );
define( 'BKNG_DIR', plugin_dir_path( __FILE__ ) );
define( 'BKNG_URL', plugin_dir_url( __FILE__ ) );
define( 'BKNG_MIN_PHP', '8.1' );
define( 'BKNG_MIN_WP', '6.6' );

/**
 * Returns a list of unmet requirements (empty when everything is fine).
 *
 * @return string[]
 */
function bkng_unmet_requirements() {
	global $wp_version;
	$errors = array();
	if ( version_compare( PHP_VERSION, BKNG_MIN_PHP, '<' ) ) {
		/* translators: 1: required PHP version, 2: current PHP version. */
		$errors[] = sprintf( __( 'Booking Manager richiede PHP %1$s o superiore (versione attuale: %2$s).', 'booking-manager' ), BKNG_MIN_PHP, PHP_VERSION );
	}
	if ( version_compare( $wp_version, BKNG_MIN_WP, '<' ) ) {
		/* translators: 1: required WordPress version, 2: current WordPress version. */
		$errors[] = sprintf( __( 'Booking Manager richiede WordPress %1$s o superiore (versione attuale: %2$s).', 'booking-manager' ), BKNG_MIN_WP, $wp_version );
	}
	return $errors;
}

if ( bkng_unmet_requirements() ) {
	add_action(
		'admin_notices',
		function () {
			foreach ( bkng_unmet_requirements() as $message ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
	);
	return;
}

require_once BKNG_DIR . 'src/Autoloader.php';

\BookingManager\Autoloader::register( 'BookingManager\\', BKNG_DIR . 'src/' );

register_activation_hook( __FILE__, array( \BookingManager\Core\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \BookingManager\Core\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \BookingManager\Core\Plugin::class, 'boot' ), 5 );

/**
 * Public entry point for integrations and custom code.
 *
 * @return \BookingManager\Core\Plugin
 */
function bkng() {
	return \BookingManager\Core\Plugin::instance();
}
