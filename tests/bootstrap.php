<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with Booking Manager active.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );
require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
$tests_dir = $root . '/vendor/wp-phpunit/wp-phpunit';

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $root ): void {
		require $root . '/booking-manager.php';
	}
);

// Tables are created once, like an activation; every test then runs in a transaction.
tests_add_filter(
	'plugins_loaded',
	static function (): void {
		\BookingManager\Core\Installer::install_site();
	},
	20
);

require $tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/FakeProvider.php';
