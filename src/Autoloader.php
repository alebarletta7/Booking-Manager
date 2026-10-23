<?php
/**
 * Minimal PSR-4 autoloader (no Composer runtime dependency on customer sites).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	public static function register( string $prefix, string $base_dir ): void {
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';
		spl_autoload_register(
			static function ( string $class ) use ( $prefix, $base_dir ): void {
				if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
					return;
				}
				$relative = substr( $class, strlen( $prefix ) );
				// Reject anything that could escape the base directory.
				if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
					return;
				}
				$file = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
