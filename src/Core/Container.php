<?php
/**
 * Tiny service container: lazy factories, one shared instance per id.
 *
 * Deliberately simple so any WordPress developer can read it in minutes.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class Container {

	/** @var array<string, callable(Container): object> */
	private array $factories = array();

	/** @var array<string, object> */
	private array $instances = array();

	/**
	 * @param callable(Container): object $factory
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
	}

	/**
	 * @template T of object
	 * @param class-string<T>|string $id
	 * @return T|object
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \OutOfBoundsException( sprintf( 'Service "%s" is not registered.', esc_html( $id ) ) );
		}
		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		return $this->instances[ $id ];
	}
}
