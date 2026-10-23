<?php
/**
 * Uninstall: removes data ONLY if the administrator explicitly enabled "Elimina tutti i
 * dati alla disinstallazione" in Impostazioni. Otherwise properties, rooms, rates,
 * bookings, guests, payments, logs and the licence activation are preserved, so a
 * reinstall (or an accidental deletion) loses nothing.
 *
 * Scheduled tasks are always removed (they would only call code that is gone).
 * Only bkng_* tables, options, roles, user meta and the plugin's uploads folder are
 * touched: Night, Beach and Restaurant Manager keep everything they own.
 *
 * @package BookingManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';
\BookingManager\Autoloader::register( 'BookingManager\\', __DIR__ . '/src/' );

/**
 * Removes every plugin table, option and role for the current site (when allowed).
 */
function bkng_uninstall_site() {
	global $wpdb;

	foreach ( array( 'bkng_daily', 'bkng_hourly', 'bkng_notifications', 'bkng_reminders', 'bkng_payments_reconcile', 'bkng_license_check', 'bkng_license_retry' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	$settings = get_option( 'bkng_settings', array() );
	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// The licence goes with the data: its place on the licence server is freed if the
	// server answers in time. A failure never stops the uninstall.
	try {
		\BookingManager\Licensing\LicenseManager::standalone()->forget();
	} catch ( \Throwable $e ) {
		delete_option( \BookingManager\Licensing\LicenseStore::OPTION );
	}

	foreach ( array_reverse( \BookingManager\Database\Schema::tables() ) as $short ) {
		$table = \BookingManager\Database\Schema::table( $short );
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	( new \BookingManager\Access\RoleManager() )->remove_all();

	$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'bkng\\_%' OR option_name LIKE '\\_transient\\_bkng\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bkng\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $names as $option ) {
		delete_option( $option );
	}
	delete_metadata( 'user', 0, 'bkng_prefs', '', true );

	// Files written by the plugin (uploads/booking-manager), with every subfolder.
	$uploads = wp_upload_dir( null, false );
	$root    = trailingslashit( (string) $uploads['basedir'] ) . 'booking-manager';
	if ( empty( $uploads['error'] ) && is_dir( $root ) ) {
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		rmdir( $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $bkng_blog_id ) {
		switch_to_blog( (int) $bkng_blog_id );
		bkng_uninstall_site();
		restore_current_blog();
	}
} else {
	bkng_uninstall_site();
}
