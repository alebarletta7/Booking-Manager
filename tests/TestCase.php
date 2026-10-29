<?php
/**
 * Base class of the integration tests: WordPress loaded, Booking Manager tables
 * created, every test inside a transaction rolled back at the end.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tests;

use BookingManager\Core\Plugin;
use BookingManager\Database\Transaction;
use BookingManager\Licensing\LicenseGate;

/**
 * Licence gate for tests: allows or refuses everything, no server involved.
 */
final class FakeGate implements LicenseGate {

	public function __construct( public bool $allow = true ) {}

	public function status(): string {
		return $this->allow ? 'active' : 'expired';
	}

	public function allows( string $operation ): bool {
		return self::OP_NOTIFICATIONS === $operation || $this->allow;
	}

	public function describe(): array {
		return array(
			'status'    => $this->status(),
			'label'     => $this->status(),
			'message'   => '',
			'product'   => 'booking-manager',
			'connected' => true,
		);
	}
}

abstract class TestCase extends \WP_UnitTestCase {

	protected FakeGate $gate;

	public function set_up(): void {
		parent::set_up();
		Transaction::$use_savepoints = true;
		$this->gate                  = new FakeGate( true );
		$this->reset_services();
	}

	/** Fresh service instances (no cached rows between tests) with the fake licence gate. */
	protected function reset_services(): void {
		$container = Plugin::instance()->container();
		$rebuild   = new \ReflectionMethod( Plugin::class, 'register_services' );
		$rebuild->setAccessible( true );
		$rebuild->invoke( Plugin::instance(), $container );
		$gate = $this->gate;
		$container->set( LicenseGate::class, fn() => $gate );
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	protected function service( string $id ): object {
		return Plugin::instance()->get( $id );
	}

	protected function admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * A property with settings that do not get in the way of tests.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	protected function property( array $data = array() ): array {
		$result = $this->service( \BookingManager\Properties\PropertyService::class )->create(
			$data + array(
				'name'     => 'Villa Test',
				'kind'     => 'hotel',
				'timezone' => 'Europe/Rome',
				'currency' => 'EUR',
				'settings' => array(
					'min_stay'         => 1,
					'max_advance_days' => 1095,
					'auto_assign_unit' => true,
				),
			),
			0
		);
		$this->assertIsArray( $result );
		return $result;
	}

	/**
	 * A room type with $units rooms.
	 *
	 * @param array<string, mixed> $data
	 * @return array{type: array<string, mixed>, units: array<int, array<string, mixed>>}
	 */
	protected function room_type( int $property_id, int $units = 1, array $data = array() ): array {
		$inventory = $this->service( \BookingManager\Inventory\InventoryService::class );
		$type      = $inventory->create_type(
			$property_id,
			$data + array(
				'name'              => 'Doppia ' . wp_generate_password( 4, false ),
				'max_adults'        => 2,
				'max_children'      => 1,
				'max_occupancy'     => 3,
				'base_occupancy'    => 2,
				'base_price'        => 10000,
				'extra_adult_price' => 2000,
				'extra_child_price' => 1000,
			)
		);
		$this->assertIsArray( $type );
		$list = array();
		for ( $i = 1; $i <= $units; $i++ ) {
			$unit = $inventory->create_unit(
				$property_id,
				array(
					'room_type_id' => $type['id'],
					'code'         => $type['id'] . '-' . $i,
				)
			);
			$this->assertIsArray( $unit );
			$list[] = $unit;
		}
		return array(
			'type'  => $type,
			'units' => $list,
		);
	}

	/** A date N days from today (property timezone Europe/Rome). */
	protected static function day( int $offset ): string {
		$today = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Europe/Rome' ) ) )->format( 'Y-m-d' );
		return \BookingManager\Core\Dates::add_days( $today, $offset );
	}

	/**
	 * Books one room of a type.
	 *
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function book( int $property_id, int $type_id, int $from, int $to, array $extra = array(), array $ctx = array( 'channel' => 'staff' ) ) {
		$room = array(
			'room_type_id' => $type_id,
			'adults'       => 2,
		);
		if ( isset( $extra['unit_id'] ) ) {
			$room['unit_id'] = $extra['unit_id'];
			unset( $extra['unit_id'] );
		}
		return $this->service( \BookingManager\Bookings\BookingService::class )->create(
			$extra + array(
				'property_id' => $property_id,
				'arrival'     => self::day( $from ),
				'departure'   => self::day( $to ),
				'rooms'       => array( $room ),
				'guest'       => array(
					'first_name' => 'Mario',
					'last_name'  => 'Rossi',
					'email'      => 'mario.rossi@example.org',
				),
			),
			$ctx
		);
	}
}
