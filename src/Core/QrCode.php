<?php
/**
 * QR code generator (ISO/IEC 18004): byte mode, error correction level M, versions 1–10
 * (up to 213 bytes, plenty for an identifier). Written here to add no dependency; the
 * images need only zlib (PNG) or nothing (SVG), not GD.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

final class QrCode {

	/**
	 * Level M per version: [error correction codewords per block, [blocks, data codewords]...].
	 */
	private const BLOCKS = array(
		1  => array( 10, array( 1, 16 ) ),
		2  => array( 16, array( 1, 28 ) ),
		3  => array( 26, array( 1, 44 ) ),
		4  => array( 18, array( 2, 32 ) ),
		5  => array( 24, array( 2, 43 ) ),
		6  => array( 16, array( 4, 27 ) ),
		7  => array( 18, array( 4, 31 ) ),
		8  => array( 22, array( 2, 38 ), array( 2, 39 ) ),
		9  => array( 22, array( 3, 36 ), array( 2, 37 ) ),
		10 => array( 26, array( 4, 43 ), array( 1, 44 ) ),
	);

	private const ALIGNMENT = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	/** @var array<int, array<int, bool>> dark modules */
	private array $modules = array();

	/** @var array<int, array<int, bool>> function patterns (not data) */
	private array $reserved = array();

	private int $size;

	/**
	 * @param string $data bytes to encode
	 * @throws \InvalidArgumentException when the data does not fit in version 10.
	 */
	private function __construct( string $data ) {
		$version = 0;
		foreach ( self::BLOCKS as $v => $spec ) {
			if ( 4 + ( $v < 10 ? 8 : 16 ) + 8 * strlen( $data ) <= 8 * self::data_codewords( $v ) ) {
				$version = $v;
				break;
			}
		}
		if ( ! $version ) {
			throw new \InvalidArgumentException( 'Too much data for a QR code.' );
		}
		$this->size = 17 + 4 * $version;
		$this->function_patterns( $version );
		$codewords = $this->codewords( $data, $version );
		$this->place( $codewords );
		$best      = null;
		$best_mask = 0;
		$plain     = $this->modules;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->modules = $plain;
			$this->apply_mask( $mask );
			$this->format_bits( $mask );
			$score = $this->penalty();
			if ( null === $best || $score < $best ) {
				$best      = $score;
				$best_mask = $mask;
			}
		}
		$this->modules = $plain;
		$this->apply_mask( $best_mask );
		$this->format_bits( $best_mask );
	}

	/**
	 * The matrix: rows of booleans (true = dark), without the quiet zone.
	 *
	 * @return array<int, array<int, bool>>
	 */
	public static function matrix( string $data ): array {
		$qr = new self( $data );
		$out = array();
		for ( $y = 0; $y < $qr->size; $y++ ) {
			for ( $x = 0; $x < $qr->size; $x++ ) {
				$out[ $y ][ $x ] = ! empty( $qr->modules[ $y ][ $x ] );
			}
		}
		return $out;
	}

	/** SVG image (scales to any size), with a 4-module quiet zone. */
	public static function svg( string $data, string $label = '' ): string {
		$matrix = self::matrix( $data );
		$n      = count( $matrix ) + 8;
		$path   = '';
		foreach ( $matrix as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $dark ) {
					$path .= 'M' . ( $x + 4 ) . ' ' . ( $y + 4 ) . 'h1v1h-1z';
				}
			}
		}
		$title = '' !== $label ? '<title>' . esc_html( $label ) . '</title>' : '';
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" shape-rendering="crispEdges" role="img"' . ( '' !== $label ? ' aria-label="' . esc_attr( $label ) . '"' : '' ) . '>'
			. $title . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
	}

	/** PNG image (black on white, 4-module quiet zone), $scale pixels per module. */
	public static function png( string $data, int $scale = 8 ): string {
		$matrix = self::matrix( $data );
		$scale  = max( 1, min( 20, $scale ) );
		$n      = count( $matrix ) + 8;
		$side   = $n * $scale;
		$raw    = '';
		for ( $y = 0; $y < $n; $y++ ) {
			$line = '';
			for ( $x = 0; $x < $n; $x++ ) {
				$dark  = ! empty( $matrix[ $y - 4 ][ $x - 4 ] );
				$line .= str_repeat( $dark ? "\x00" : "\xFF", $scale );
			}
			// Filter type 0 (none) per row; 8-bit greyscale.
			$raw .= str_repeat( "\x00" . $line, $scale );
		}
		$chunk = static function ( string $type, string $body ): string {
			return pack( 'N', strlen( $body ) ) . $type . $body . pack( 'N', crc32( $type . $body ) );
		};
		return "\x89PNG\r\n\x1A\n"
			. $chunk( 'IHDR', pack( 'NNCCCCC', $side, $side, 8, 0, 0, 0, 0 ) )
			. $chunk( 'IDAT', (string) gzcompress( $raw, 9 ) )
			. $chunk( 'IEND', '' );
	}

	/* ------------------------------------------------------------------ structure */

	private static function data_codewords( int $version ): int {
		$total = 0;
		foreach ( array_slice( self::BLOCKS[ $version ], 1 ) as [ $count, $size ] ) {
			$total += $count * $size;
		}
		return $total;
	}

	private function set( int $x, int $y, bool $dark, bool $reserve = true ): void {
		$this->modules[ $y ][ $x ] = $dark;
		if ( $reserve ) {
			$this->reserved[ $y ][ $x ] = true;
		}
	}

	private function function_patterns( int $version ): void {
		// Finder patterns with their separators.
		foreach ( array( array( 0, 0 ), array( $this->size - 7, 0 ), array( 0, $this->size - 7 ) ) as [ $fx, $fy ] ) {
			for ( $dy = -1; $dy <= 7; $dy++ ) {
				for ( $dx = -1; $dx <= 7; $dx++ ) {
					$x = $fx + $dx;
					$y = $fy + $dy;
					if ( $x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size ) {
						continue;
					}
					$ring = max( abs( $dx - 3 ), abs( $dy - 3 ) );
					$this->set( $x, $y, 2 !== $ring && 4 !== $ring );
				}
			}
		}
		// Timing patterns.
		for ( $i = 8; $i < $this->size - 8; $i++ ) {
			$this->set( $i, 6, 0 === $i % 2 );
			$this->set( 6, $i, 0 === $i % 2 );
		}
		// Alignment patterns (not over the finders).
		$pos  = self::ALIGNMENT[ $version ];
		$last = count( $pos ) - 1;
		foreach ( $pos as $i => $cy ) {
			foreach ( $pos as $j => $cx ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $last === $j ) || ( $last === $i && 0 === $j ) ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$this->set( $cx + $dx, $cy + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}
		// Format areas (filled later) and the dark module.
		for ( $i = 0; $i < 9; $i++ ) {
			$this->reserved[8][ $i ] = true;
			$this->reserved[ $i ][8] = true;
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->reserved[8][ $this->size - 1 - $i ] = true;
			$this->reserved[ $this->size - 1 - $i ][8] = true;
		}
		$this->set( 8, $this->size - 8, true );
		// Version information (7 and up).
		if ( $version >= 7 ) {
			$rem = $version;
			for ( $i = 0; $i < 12; $i++ ) {
				$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
			}
			$bits = ( $version << 12 ) | $rem;
			for ( $i = 0; $i < 18; $i++ ) {
				$dark = 1 === ( ( $bits >> $i ) & 1 );
				$a    = $this->size - 11 + $i % 3;
				$b    = intdiv( $i, 3 );
				$this->set( $a, $b, $dark );
				$this->set( $b, $a, $dark );
			}
		}
	}

	private function format_bits( int $mask ): void {
		$data = ( 0 << 3 ) | $mask; // Level M = 00.
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
		$bit  = static fn( int $i ): bool => 1 === ( ( $bits >> $i ) & 1 );
		// First copy, around the top-left finder.
		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set( 8, $i, $bit( $i ) );
		}
		$this->set( 8, 7, $bit( 6 ) );
		$this->set( 8, 8, $bit( 7 ) );
		$this->set( 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set( 14 - $i, 8, $bit( $i ) );
		}
		// Second copy, split between the other two finders.
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set( $this->size - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set( 8, $this->size - 15 + $i, $bit( $i ) );
		}
		$this->set( 8, $this->size - 8, true );
	}

	/* ------------------------------------------------------------------ data */

	/** @return int[] data and error correction codewords, interleaved */
	private function codewords( string $data, int $version ): array {
		$capacity = self::data_codewords( $version );
		$bits     = array();
		$push     = static function ( int $value, int $length ) use ( &$bits ): void {
			for ( $i = $length - 1; $i >= 0; $i-- ) {
				$bits[] = ( $value >> $i ) & 1;
			}
		};
		$push( 0b0100, 4 ); // Byte mode.
		$push( strlen( $data ), $version < 10 ? 8 : 16 );
		foreach ( str_split( $data ) as $char ) {
			$push( ord( $char ), 8 );
		}
		$push( 0, min( 4, $capacity * 8 - count( $bits ) ) ); // Terminator.
		while ( 0 !== count( $bits ) % 8 ) {
			$bits[] = 0;
		}
		$bytes = array();
		foreach ( array_chunk( $bits, 8 ) as $byte ) {
			$bytes[] = (int) bindec( implode( '', $byte ) );
		}
		for ( $pad = 0; count( $bytes ) < $capacity; $pad++ ) {
			$bytes[] = 0 === $pad % 2 ? 0xEC : 0x11;
		}

		// Split into blocks, add Reed-Solomon codewords, interleave.
		$ec_len = self::BLOCKS[ $version ][0];
		$blocks = array();
		$offset = 0;
		foreach ( array_slice( self::BLOCKS[ $version ], 1 ) as [ $count, $size ] ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$block    = array_slice( $bytes, $offset, $size );
				$offset  += $size;
				$blocks[] = array( $block, self::reed_solomon( $block, $ec_len ) );
			}
		}
		$out     = array();
		$longest = max( array_map( static fn( $b ) => count( $b[0] ), $blocks ) );
		for ( $i = 0; $i < $longest; $i++ ) {
			foreach ( $blocks as [ $block ] ) {
				if ( isset( $block[ $i ] ) ) {
					$out[] = $block[ $i ];
				}
			}
		}
		for ( $i = 0; $i < $ec_len; $i++ ) {
			foreach ( $blocks as [ , $ec ] ) {
				$out[] = $ec[ $i ];
			}
		}
		return $out;
	}

	/**
	 * @param int[] $data
	 * @return int[]
	 */
	private static function reed_solomon( array $data, int $degree ): array {
		// Generator polynomial (x - α^0)(x - α^1)…(x - α^(degree-1)), leading 1 dropped.
		$gen  = array_fill( 0, $degree, 0 );
		$gen[ $degree - 1 ] = 1;
		$root = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$gen[ $j ] = self::gf_mul( $gen[ $j ], $root ) ^ ( $gen[ $j + 1 ] ?? 0 );
			}
			$root = self::gf_mul( $root, 2 );
		}
		$rem = array_fill( 0, $degree, 0 );
		foreach ( $data as $byte ) {
			$factor = $byte ^ array_shift( $rem );
			$rem[]  = 0;
			for ( $j = 0; $j < $degree; $j++ ) {
				$rem[ $j ] ^= self::gf_mul( $gen[ $j ], $factor );
			}
		}
		return $rem;
	}

	private static function gf_mul( int $a, int $b ): int {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $b >> $i ) & 1 ) * $a;
		}
		return $z & 0xFF;
	}

	/** @param int[] $codewords */
	private function place( array $codewords ): void {
		$bit   = 0;
		$total = count( $codewords ) * 8;
		for ( $right = $this->size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $this->size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $this->size - 1 - $vert : $vert;
					if ( ! empty( $this->reserved[ $y ][ $x ] ) ) {
						continue;
					}
					$dark = $bit < $total && 1 === ( ( $codewords[ intdiv( $bit, 8 ) ] >> ( 7 - $bit % 8 ) ) & 1 );
					$this->modules[ $y ][ $x ] = $dark;
					++$bit;
				}
			}
		}
	}

	private function apply_mask( int $mask ): void {
		for ( $y = 0; $y < $this->size; $y++ ) {
			for ( $x = 0; $x < $this->size; $x++ ) {
				if ( ! empty( $this->reserved[ $y ][ $x ] ) ) {
					continue;
				}
				$flip = match ( $mask ) {
					0 => 0 === ( $x + $y ) % 2,
					1 => 0 === $y % 2,
					2 => 0 === $x % 3,
					3 => 0 === ( $x + $y ) % 3,
					4 => 0 === ( intdiv( $y, 2 ) + intdiv( $x, 3 ) ) % 2,
					5 => 0 === ( $x * $y ) % 2 + ( $x * $y ) % 3,
					6 => 0 === ( ( $x * $y ) % 2 + ( $x * $y ) % 3 ) % 2,
					default => 0 === ( ( $x + $y ) % 2 + ( $x * $y ) % 3 ) % 2,
				};
				if ( $flip ) {
					$this->modules[ $y ][ $x ] = empty( $this->modules[ $y ][ $x ] );
				}
			}
		}
	}

	/** The four penalty rules of the standard: the lowest score picks the mask. */
	private function penalty(): int {
		$n     = $this->size;
		$dark  = static fn( array $m, int $x, int $y ): bool => ! empty( $m[ $y ][ $x ] );
		$m     = $this->modules;
		$score = 0;
		// Rule 1: runs of 5+ same-coloured modules in rows and columns.
		for ( $a = 0; $a < $n; $a++ ) {
			foreach ( array( true, false ) as $rows ) {
				$run  = 0;
				$prev = null;
				for ( $b = 0; $b < $n; $b++ ) {
					$c = $rows ? $dark( $m, $b, $a ) : $dark( $m, $a, $b );
					if ( $c === $prev ) {
						++$run;
					} else {
						$score += $run >= 5 ? $run - 2 : 0;
						$run    = 1;
						$prev   = $c;
					}
				}
				$score += $run >= 5 ? $run - 2 : 0;
			}
		}
		// Rule 2: 2×2 blocks of one colour.
		for ( $y = 0; $y < $n - 1; $y++ ) {
			for ( $x = 0; $x < $n - 1; $x++ ) {
				$c = $dark( $m, $x, $y );
				if ( $c === $dark( $m, $x + 1, $y ) && $c === $dark( $m, $x, $y + 1 ) && $c === $dark( $m, $x + 1, $y + 1 ) ) {
					$score += 3;
				}
			}
		}
		// Rule 3: finder-like patterns 1:1:3:1:1 with 4 light modules on one side.
		$patterns = array( '10111010000', '00001011101' );
		for ( $a = 0; $a < $n; $a++ ) {
			$row = '';
			$col = '';
			for ( $b = 0; $b < $n; $b++ ) {
				$row .= $dark( $m, $b, $a ) ? '1' : '0';
				$col .= $dark( $m, $a, $b ) ? '1' : '0';
			}
			foreach ( array( $row, $col ) as $line ) {
				foreach ( $patterns as $p ) {
					for ( $i = strpos( $line, $p ); false !== $i; $i = strpos( $line, $p, $i + 1 ) ) {
						$score += 40;
					}
				}
			}
		}
		// Rule 4: balance of dark and light.
		$count = 0;
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				$count += $dark( $m, $x, $y ) ? 1 : 0;
			}
		}
		$score += intdiv( abs( $count * 20 - $n * $n * 10 ), $n * $n ) * 10;
		return $score;
	}
}
