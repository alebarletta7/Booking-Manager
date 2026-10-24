<?php
/**
 * One competitor of ConcurrencyTest: a separate PHP process with its own database
 * connection that loads WordPress (test database) and Booking Manager, waits for the
 * common start time, then tries to book the given room type. Prints the outcome as JSON.
 *
 * Arguments: property_id room_type_id arrival departure start_at_microtime
 *
 * @package BookingManager
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- bootstraps WordPress.

if ( 'cli' !== PHP_SAPI || $argc < 6 ) {
	exit( 1 );
}

$root                   = dirname( __DIR__, 2 );
$_SERVER['HTTP_HOST']   = 'example.org';
$_SERVER['SERVER_NAME'] = 'example.org';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_USE_THEMES', false );
require $root . '/tests/wp-tests-config.php';

// Booking Manager loaded like a must-use plugin, before WordPress starts.
$GLOBALS['wp_filter'] = array(
	'muplugins_loaded' => array(
		10 => array(
			array(
				'function'      => static function () use ( $root ): void {
					require $root . '/booking-manager.php';
				},
				'accepted_args' => 0,
			),
		),
	),
);
require ABSPATH . 'wp-settings.php';

// No licence server here: the gate allows everything, as in the test suite.
$gate = new class() implements \BookingManager\Licensing\LicenseGate {
	public function status(): string {
		return 'active';
	}
	public function allows( string $operation ): bool {
		return true;
	}
	public function describe(): array {
		return array(
			'status'    => 'active',
			'label'     => 'active',
			'message'   => '',
			'product'   => 'booking-manager',
			'connected' => true,
		);
	}
};
$container = \BookingManager\Core\Plugin::instance()->container();
$rebuild   = new \ReflectionMethod( \BookingManager\Core\Plugin::class, 'register_services' );
$rebuild->setAccessible( true );
$rebuild->invoke( \BookingManager\Core\Plugin::instance(), $container );
$container->set( \BookingManager\Licensing\LicenseGate::class, fn() => $gate );

list( , $property_id, $type_id, $arrival, $departure, $start_at ) = $argv;

// Everyone ready: all processes leave together.
$wait = (int) ( ( (float) $start_at - microtime( true ) ) * 1000000 );
if ( $wait > 0 ) {
	usleep( $wait );
}

$result = \BookingManager\Core\Plugin::instance()->get( \BookingManager\Bookings\BookingService::class )->create(
	array(
		'property_id' => (int) $property_id,
		'arrival'     => $arrival,
		'departure'   => $departure,
		'rooms'       => array(
			array(
				'room_type_id' => (int) $type_id,
				'adults'       => 2,
			),
		),
		'guest'       => array(
			'first_name' => 'Ospite',
			'last_name'  => 'Concorrente' . getmypid(),
			'email'      => 'concorrente' . getmypid() . '@example.org',
		),
	),
	array( 'channel' => 'staff' )
);

echo wp_json_encode(
	$result instanceof \WP_Error
		? array(
			'ok'   => false,
			'code' => $result->get_error_code(),
		)
		: array(
			'ok' => true,
			'id' => (int) $result['id'],
		)
);
