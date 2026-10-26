<?php
/**
 * The look of a property's e-mails, chosen in Messaggi → Aspetto: logo, header photo,
 * colours, background and the short sentences of header and footer.
 *
 * Images travel inside the e-mail (cid), never as links to the site: logo and photo from
 * the media library, the line icons of assets/email tinted in the chosen colour (GD;
 * without GD the e-mail simply has no icons). image() records what the HTML uses and
 * inline() hands it to the message.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications;

use BookingManager\Properties\PropertySettings;

defined( 'ABSPATH' ) || exit;

final class EmailBrand {

	public const GREEN = '#1f5c43';

	/** Largest photo or logo embedded (bytes): bigger files are left out. */
	private const MAX_IMAGE = 1500000;

	/** @var array<string, array{cid: string, data: string, name: string, mime: string}> */
	private array $used = array();

	/** @var array<string, string> tinted icons of this request, by colour and name */
	private static array $tinted = array();

	/**
	 * @param array{primary: string, header: string, page: string, soft: string, line: string, ink: string, muted: string} $colors
	 * @param array{name: string, subtitle: string, tagline: string, signoff: string} $texts
	 */
	private function __construct(
		public readonly array $colors,
		public readonly array $texts,
		private int $logo_id,
		private int $banner_id
	) {}

	/**
	 * @param array<string, mixed> $property
	 * @param array<string, mixed> $design  unsaved settings being edited (preview)
	 * @param int                  $photo   first photo of the rooms, used when the header photo is "automatic"
	 */
	public static function for_property( array $property, array $design = array(), int $photo = 0 ): self {
		$s       = PropertySettings::merge( (array) ( $property['settings'] ?? array() ), $design );
		$primary = '' !== $s['email_color'] ? $s['email_color'] : ( '' !== $s['form_color'] ? $s['form_color'] : self::GREEN );
		$banner  = (int) $s['email_banner_id'];
		return new self(
			array(
				'primary' => $primary,
				'header'  => '' !== $s['email_header_bg'] ? $s['email_header_bg'] : '#f6f1e9',
				'page'    => '' !== $s['email_page_bg'] ? $s['email_page_bg'] : '#f4f6f4',
				'soft'    => self::mix( $primary, '#ffffff', 0.05 ),
				'line'    => self::mix( $primary, '#ffffff', 0.16 ),
				'ink'     => '#1b2420',
				'muted'   => '#5b6862',
			),
			array(
				'name'     => (string) ( $property['name'] ?? '' ),
				'subtitle' => (string) $s['email_subtitle'],
				'tagline'  => (string) $s['email_tagline'],
				'signoff'  => (string) $s['email_signoff'],
			),
			max( 0, (int) $s['email_logo_id'] ),
			-1 === $banner ? 0 : ( $banner > 0 ? $banner : $photo )
		);
	}

	/** Text colour readable on a background: white or dark. */
	public static function on( string $hex ): string {
		[ $r, $g, $b ] = self::rgb( $hex );
		return ( $r * 299 + $g * 587 + $b * 114 ) / 1000 > 150 ? '#1b2420' : '#ffffff';
	}

	/** The logo as an embedded image (src), or null. */
	public function logo(): ?string {
		return $this->logo_id ? $this->photo( 'logo', $this->logo_id, 'medium' ) : null;
	}

	/** The header photo as an embedded image (src), or null. */
	public function banner(): ?string {
		return $this->banner_id ? $this->photo( 'banner', $this->banner_id, 'medium_large' ) : null;
	}

	/** A line icon of assets/email in the main colour (or white), as src; null without GD. */
	public function icon( string $name, bool $white = false ): ?string {
		$color = $white ? '#ffffff' : $this->colors['primary'];
		$key   = 'icon-' . $name . ( $white ? '-w' : '' );
		if ( isset( $this->used[ $key ] ) ) {
			return 'cid:' . $this->used[ $key ]['cid'];
		}
		$data = self::tint( $name, $color );
		if ( null === $data ) {
			return null;
		}
		return $this->use( $key, $data, $name . '.png', 'image/png' );
	}

	/** @return array<int, array{cid: string, data: string, name: string, mime: string}> */
	public function inline(): array {
		return array_values( $this->used );
	}

	/**
	 * The HTML with the embedded images as data: addresses, for a preview in the browser.
	 */
	public function for_browser( string $html ): string {
		foreach ( $this->used as $image ) {
			$html = str_replace( 'cid:' . $image['cid'], 'data:' . $image['mime'] . ';base64,' . base64_encode( $image['data'] ), $html );
		}
		return $html;
	}

	private function use( string $key, string $data, string $name, string $mime ): string {
		$cid                = 'bkng-' . $key . '-' . substr( md5( $data ), 0, 8 );
		$this->used[ $key ] = array(
			'cid'  => $cid,
			'data' => $data,
			'name' => $name,
			'mime' => $mime,
		);
		return 'cid:' . $cid;
	}

	private function photo( string $key, int $id, string $size ): ?string {
		if ( isset( $this->used[ $key ] ) ) {
			return 'cid:' . $this->used[ $key ]['cid'];
		}
		$file = self::file( $id, $size );
		if ( null === $file ) {
			return null;
		}
		$data = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$mime = (string) ( wp_check_filetype( $file )['type'] ?? '' );
		if ( '' === $data || ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			return null;
		}
		return $this->use( $key, $data, basename( $file ), $mime );
	}

	/** Path of an image of the media library at a size (the original when the size is missing). */
	private static function file( int $id, string $size ): ?string {
		if ( ! wp_attachment_is_image( $id ) ) {
			return null;
		}
		$original = get_attached_file( $id );
		if ( ! $original ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $id );
		$file = $original;
		foreach ( array( $size, 'large' ) as $try ) {
			if ( ! empty( $meta['sizes'][ $try ]['file'] ) ) {
				$file = dirname( $original ) . '/' . $meta['sizes'][ $try ]['file'];
				break;
			}
		}
		if ( ! is_readable( $file ) ) {
			$file = $original;
		}
		return is_readable( $file ) && filesize( $file ) <= self::MAX_IMAGE ? $file : null;
	}

	private static function tint( string $name, string $color ): ?string {
		$key = $color . $name;
		if ( isset( self::$tinted[ $key ] ) ) {
			return self::$tinted[ $key ];
		}
		$file = BKNG_DIR . 'assets/email/' . sanitize_key( $name ) . '.png';
		if ( ! function_exists( 'imagecreatefrompng' ) || ! is_readable( $file ) ) {
			return null;
		}
		$image = imagecreatefrompng( $file );
		if ( ! $image ) {
			return null;
		}
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		[ $r, $g, $b ] = self::rgb( $color );
		// Black lines plus the colour give the colour; white stays white.
		imagefilter( $image, IMG_FILTER_COLORIZE, $r, $g, $b );
		ob_start();
		imagepng( $image, null, 9 );
		$data = (string) ob_get_clean();
		imagedestroy( $image );
		self::$tinted[ $key ] = $data;
		return $data;
	}

	/** @return array{0: int, 1: int, 2: int} */
	private static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return array( 0x1f, 0x5c, 0x43 );
		}
		return array( (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) );
	}

	/** $a mixed into $b at $amount (0..1), as #rrggbb. */
	private static function mix( string $a, string $b, float $amount ): string {
		$x = self::rgb( $a );
		$y = self::rgb( $b );
		return sprintf( '#%02x%02x%02x', ...array_map( static fn( int $i ) => (int) round( $x[ $i ] * $amount + $y[ $i ] * ( 1 - $amount ) ), array( 0, 1, 2 ) ) );
	}
}
