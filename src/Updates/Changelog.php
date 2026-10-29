<?php
/**
 * The changelog of a release as the licence server sends it (the «## [x.y.z]» section
 * of CHANGELOG.md, plain text) turned into the HTML of the «View details» window.
 *
 * Only what CHANGELOG.md uses: «### » headings, «- » lists, paragraphs, **bold** and
 * [links](https://…). Everything is escaped first, so the text cannot carry markup.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Updates;

defined( 'ABSPATH' ) || exit;

final class Changelog {

	/**
	 * The section of a version in the CHANGELOG.md shipped with the plugin, as plain text
	 * without its «## » heading; '' when missing.
	 */
	public static function local( string $version, string $file = '' ): string {
		$file = '' !== $file ? $file : ( defined( 'BKNG_FILE' ) ? dirname( BKNG_FILE ) . '/CHANGELOG.md' : '' );
		$text = '' !== $file && is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return self::section( $text, $version );
	}

	/** The «## x.y.z …» or «## [x.y.z] …» section of a changelog text (pure). */
	public static function section( string $text, string $version ): string {
		$head = '/^##\s+\[?' . preg_quote( $version, '/' ) . '\]?(\s|$).*$/m';
		if ( '' === $version || ! preg_match( $head, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$rest = substr( $text, $m[0][1] + strlen( $m[0][0] ) );
		$next = preg_match( '/^##\s/m', $rest, $n, PREG_OFFSET_CAPTURE ) ? $n[0][1] : strlen( $rest );
		return trim( substr( $rest, 0, $next ) );
	}

	public static function to_html( string $text ): string {
		$html  = '';
		$list  = false;
		$para  = array();
		$close = static function () use ( &$html, &$list, &$para ): void {
			if ( $para ) {
				$html .= '<p>' . implode( ' ', $para ) . "</p>\n";
				$para  = array();
			}
			if ( $list ) {
				$html .= "</ul>\n";
				$list  = false;
			}
		};
		foreach ( preg_split( '/\R/', $text ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				$close();
			} elseif ( preg_match( '/^#{2,6}\s+(.+)$/', $line, $m ) ) {
				$close();
				$html .= '<h4>' . self::inline( $m[1] ) . "</h4>\n";
			} elseif ( preg_match( '/^[-*]\s+(.+)$/', $line, $m ) ) {
				if ( $para ) {
					$html .= '<p>' . implode( ' ', $para ) . "</p>\n";
					$para  = array();
				}
				if ( ! $list ) {
					$html .= "<ul>\n";
					$list  = true;
				}
				$html .= '<li>' . self::inline( $m[1] ) . "</li>\n";
			} elseif ( $list ) {
				$close();
				$para[] = self::inline( $line );
			} else {
				$para[] = self::inline( $line );
			}
		}
		$close();
		return wp_kses_post( $html );
	}

	private static function inline( string $text ): string {
		$text = esc_html( $text );
		$text = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		return (string) preg_replace_callback(
			'/\[([^\]]+)\]\((https:\/\/[^\s)]+)\)/',
			static fn( array $m ): string => '<a href="' . esc_url( html_entity_decode( $m[2], ENT_QUOTES ) ) . '">' . $m[1] . '</a>',
			$text
		);
	}
}
