<?php
/**
 * When no single room can host the whole group, the rooms that together can: two
 * doubles for four people, a triple and a double for five, three doubles or two
 * triples for six…
 *
 * Pure and unit-tested: it gets the free rooms of each type and returns the smallest
 * combinations that fit, with the guests split room by room (at least one adult in
 * each room, never more than a room allows). Prices and rules are checked afterwards
 * by the normal search, one room at a time.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Availability;

defined( 'ABSPATH' ) || exit;

final class RoomCombiner {

	/** Combinations looked at, at most: keeps a property with many types fast. */
	private const MAX_CANDIDATES = 4000;

	/**
	 * @param array<int, array{id: int, max_adults: int, max_children: int, max_occupancy: int, free: int}> $types free rooms per type
	 * @param int                                                                                        $max_rooms rooms one booking may hold
	 * @param int                                                                                        $limit     combinations returned, at most
	 * @return array<int, array{rooms: array<int, array{room_type_id: int, adults: int, children: int}>, spare: int}>
	 *         fewest rooms first, then fewest empty beds
	 */
	public static function combine( array $types, int $adults, int $children, int $max_rooms, int $limit = 6 ): array {
		$guests = $adults + $children;
		$types  = array_values(
			array_filter(
				array_map(
					static fn( array $t ) => array(
						'id'            => (int) $t['id'],
						'max_adults'    => max( 0, (int) $t['max_adults'] ),
						'max_children'  => max( 0, (int) $t['max_children'] ),
						'max_occupancy' => max( 0, (int) $t['max_occupancy'] ),
						'free'          => max( 0, (int) $t['free'] ),
					),
					$types
				),
				static fn( array $t ) => $t['free'] > 0 && $t['max_adults'] > 0 && $t['max_occupancy'] > 0
			)
		);
		// Every room needs an adult: no more rooms than adults.
		$max_rooms = min( $max_rooms, $adults );
		if ( $guests < 2 || $max_rooms < 2 || ! $types ) {
			return array();
		}
		// Bigger rooms first: the split puts more people in them, and they are listed first.
		usort( $types, static fn( array $a, array $b ) => $b['max_occupancy'] <=> $a['max_occupancy'] ?: $a['id'] <=> $b['id'] );

		$found  = array();
		$fewest = 0;
		$seen   = 0;
		for ( $k = 2; $k <= $max_rooms; $k++ ) {
			// The smallest number of rooms that works, and one more (e.g. 3+3 and 2+2+2 for six).
			if ( $fewest && $k > $fewest + 1 ) {
				break;
			}
			foreach ( self::multisets( $types, $k ) as $set ) {
				if ( ++$seen > self::MAX_CANDIDATES ) {
					break 2;
				}
				$rooms = array();
				foreach ( $set as $i => $count ) {
					for ( $n = 0; $n < $count; $n++ ) {
						$rooms[] = $types[ $i ];
					}
				}
				if ( array_sum( array_column( $rooms, 'max_occupancy' ) ) < $guests ) {
					continue;
				}
				$split = self::split( $rooms, $adults, $children );
				if ( null === $split || self::has_spare_room( $rooms, $adults, $children ) ) {
					continue;
				}
				$fewest  = $fewest ? $fewest : $k;
				$found[] = array(
					'rooms' => $split,
					'spare' => array_sum( array_column( $rooms, 'max_occupancy' ) ) - $guests,
				);
			}
		}
		usort( $found, static fn( array $a, array $b ) => count( $a['rooms'] ) <=> count( $b['rooms'] ) ?: $a['spare'] <=> $b['spare'] );
		return array_slice( $found, 0, max( 1, $limit ) );
	}

	/**
	 * The guests split among the rooms, or null when they do not fit: at least one adult
	 * per room and the limits of each room. Exact (a small table over rooms × adults ×
	 * children), and it fills the first (bigger) rooms first.
	 *
	 * @param array<int, array{id: int, max_adults: int, max_children: int, max_occupancy: int}> $rooms
	 * @return array<int, array{room_type_id: int, adults: int, children: int}>|null
	 */
	public static function split( array $rooms, int $adults, int $children ): ?array {
		$n = count( $rooms );
		if ( 0 === $n || $adults < $n ) {
			return null;
		}
		// $ok[ $i ][ $a ][ $c ]: rooms $i..end can take exactly $a adults and $c children.
		$ok              = array_fill( 0, $n + 1, array() );
		$ok[ $n ][0][0] = true;
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$r = $rooms[ $i ];
			for ( $a = 0; $a <= $adults; $a++ ) {
				for ( $c = 0; $c <= $children; $c++ ) {
					foreach ( self::fillings( $r, $a, $c ) as $fill ) {
						if ( ! empty( $ok[ $i + 1 ][ $a - $fill[0] ][ $c - $fill[1] ] ) ) {
							$ok[ $i ][ $a ][ $c ] = true;
							break;
						}
					}
				}
			}
		}
		if ( empty( $ok[0][ $adults ][ $children ] ) ) {
			return null;
		}
		$out = array();
		$a   = $adults;
		$c   = $children;
		for ( $i = 0; $i < $n; $i++ ) {
			foreach ( self::fillings( $rooms[ $i ], $a, $c ) as $fill ) {
				if ( ! empty( $ok[ $i + 1 ][ $a - $fill[0] ][ $c - $fill[1] ] ) ) {
					$out[] = array(
						'room_type_id' => (int) $rooms[ $i ]['id'],
						'adults'       => $fill[0],
						'children'     => $fill[1],
					);
					$a    -= $fill[0];
					$c    -= $fill[1];
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * How one room can be filled from $a adults and $c children still to place: fullest
	 * first, adults before children.
	 *
	 * @param array{max_adults: int, max_children: int, max_occupancy: int} $r
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function fillings( array $r, int $a, int $c ): array {
		$out = array();
		for ( $x = min( $a, $r['max_adults'], $r['max_occupancy'] ); $x >= 1; $x-- ) {
			for ( $y = min( $c, $r['max_children'], $r['max_occupancy'] - $x ); $y >= 0; $y-- ) {
				$out[] = array( $x, $y );
			}
		}
		usort( $out, static fn( array $p, array $q ) => ( $q[0] + $q[1] ) <=> ( $p[0] + $p[1] ) ?: $q[0] <=> $p[0] );
		return $out;
	}

	/**
	 * Whether the group would still fit with one room fewer: then the combination asks
	 * for a room the guests do not need (three doubles for four people).
	 *
	 * @param array<int, array<string, int>> $rooms
	 */
	private static function has_spare_room( array $rooms, int $adults, int $children ): bool {
		$tried = array();
		foreach ( $rooms as $i => $room ) {
			if ( isset( $tried[ $room['id'] ] ) ) {
				continue;
			}
			$tried[ $room['id'] ] = true;
			$less                 = $rooms;
			unset( $less[ $i ] );
			if ( null !== self::split( array_values( $less ), $adults, $children ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every way to pick $k rooms from the types, within the free rooms of each.
	 *
	 * @param array<int, array{free: int}> $types
	 * @return \Generator<array<int, int>> type index => rooms
	 */
	private static function multisets( array $types, int $k, int $from = 0 ): \Generator {
		if ( 0 === $k ) {
			yield array();
			return;
		}
		$count = count( $types );
		for ( $i = $from; $i < $count; $i++ ) {
			for ( $take = min( $k, $types[ $i ]['free'] ); $take >= 1; $take-- ) {
				foreach ( self::multisets( $types, $k - $take, $i + 1 ) as $rest ) {
					yield array( $i => $take ) + $rest;
				}
			}
		}
	}
}
