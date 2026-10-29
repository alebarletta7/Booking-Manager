<?php
/**
 * Links between our records and Channex's (in channel_refs, channel "channex"):
 *
 *   property   our property      ↔ Channex property
 *   room_type  our room type     ↔ Channex room type
 *   rate_plan  our type × plan   ↔ Channex rate plan (entity_id = our plan, 0 for the
 *              base price of a property without plans; meta.room_type_id = our type,
 *              meta.per_person = prices sent per occupancy)
 *
 * In Booking Manager a rate plan can serve several types, in Channex a rate plan belongs
 * to one room type: hence one link per type and plan.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

use BookingManager\Channels\ChannelRefRepository;

defined( 'ABSPATH' ) || exit;

class ChannexMap {

	public const PROPERTY  = 'property';
	public const ROOM_TYPE = 'room_type';
	public const RATE_PLAN = 'rate_plan';

	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/** @var array<int, array<int, array<string, mixed>>> */
	private array $cache = array();

	public function __construct( private ChannelRefRepository $refs ) {}

	public static function is_uuid( string $value ): bool {
		return (bool) preg_match( self::UUID, $value );
	}

	/** @return array<int, array<string, mixed>> */
	private function links( int $property_id ): array {
		if ( ! isset( $this->cache[ $property_id ] ) ) {
			$this->cache[ $property_id ] = $this->refs->links( $property_id, ChannexSettings::CHANNEL );
		}
		return $this->cache[ $property_id ];
	}

	public function flush(): void {
		$this->cache = array();
	}

	public function property_uuid( int $property_id ): ?string {
		foreach ( $this->links( $property_id ) as $link ) {
			if ( self::PROPERTY === $link['entity_type'] ) {
				return $link['external_id'];
			}
		}
		return null;
	}

	/** @return array<int, string> our property id => Channex property, for every property linked */
	public function properties(): array {
		return $this->refs->by_property( ChannexSettings::CHANNEL, self::PROPERTY );
	}

	public function property_for( string $uuid ): ?int {
		$link = $this->refs->find_external( ChannexSettings::CHANNEL, self::PROPERTY, strtolower( $uuid ) );
		return $link ? $link['property_id'] : null;
	}

	/** @return array<int, string> our room type id => Channex room type */
	public function room_types( int $property_id ): array {
		$out = array();
		foreach ( $this->links( $property_id ) as $link ) {
			if ( self::ROOM_TYPE === $link['entity_type'] ) {
				$out[ $link['entity_id'] ] = $link['external_id'];
			}
		}
		return $out;
	}

	/**
	 * @return array<int, array{room_type_id: int, rate_plan_id: int, channex_id: string, per_person: bool}>
	 */
	public function rate_plans( int $property_id ): array {
		$out = array();
		foreach ( $this->links( $property_id ) as $link ) {
			if ( self::RATE_PLAN === $link['entity_type'] ) {
				$out[] = array(
					'room_type_id' => (int) ( $link['meta']['room_type_id'] ?? 0 ),
					'rate_plan_id' => $link['entity_id'],
					'channex_id'   => $link['external_id'],
					'per_person'   => ! empty( $link['meta']['per_person'] ),
				);
			}
		}
		return $out;
	}

	public function local_room_type( string $uuid ): ?int {
		$link = $this->refs->find_external( ChannexSettings::CHANNEL, self::ROOM_TYPE, strtolower( $uuid ) );
		return $link ? $link['entity_id'] : null;
	}

	/** @return array{room_type_id: int, rate_plan_id: int}|null */
	public function local_rate_plan( string $uuid ): ?array {
		$link = $this->refs->find_external( ChannexSettings::CHANNEL, self::RATE_PLAN, strtolower( $uuid ) );
		return $link ? array(
			'room_type_id' => (int) ( $link['meta']['room_type_id'] ?? 0 ),
			'rate_plan_id' => $link['entity_id'],
		) : null;
	}

	/**
	 * Replaces the mapping of a property.
	 *
	 * @param array<int, string>                                                                             $room_types our type id => Channex uuid
	 * @param array<int, array{room_type_id: int, rate_plan_id: int, channex_id: string, per_person?: bool}> $rate_plans
	 */
	public function save( int $property_id, string $property_uuid, array $room_types, array $rate_plans ): void {
		$this->refs->unlink_types( $property_id, ChannexSettings::CHANNEL, array( self::PROPERTY, self::ROOM_TYPE, self::RATE_PLAN ) );
		$this->refs->link( $property_id, ChannexSettings::CHANNEL, self::PROPERTY, $property_id, strtolower( $property_uuid ) );
		foreach ( $room_types as $type_id => $uuid ) {
			$this->refs->link( $property_id, ChannexSettings::CHANNEL, self::ROOM_TYPE, (int) $type_id, strtolower( $uuid ) );
		}
		foreach ( $rate_plans as $plan ) {
			$this->refs->link(
				$property_id,
				ChannexSettings::CHANNEL,
				self::RATE_PLAN,
				(int) $plan['rate_plan_id'],
				strtolower( $plan['channex_id'] ),
				array(
					'room_type_id' => (int) $plan['room_type_id'],
					'per_person'   => ! empty( $plan['per_person'] ),
				)
			);
		}
		unset( $this->cache[ $property_id ] );
	}

	/** Removes the whole mapping of a property (reservations already linked stay linked). */
	public function clear( int $property_id ): void {
		$this->refs->unlink_types( $property_id, ChannexSettings::CHANNEL, array( self::PROPERTY, self::ROOM_TYPE, self::RATE_PLAN ) );
		unset( $this->cache[ $property_id ] );
	}
}
