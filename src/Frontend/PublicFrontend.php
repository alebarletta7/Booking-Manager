<?php
/**
 * The booking form on the site: shortcode [booking_manager_prenota struttura="slug"] and
 * the guest's "manage my booking" page.
 *
 * The shortcode prints a container with its configuration and loads the small public
 * script (assets/build/public.js), which talks to booking-manager/public/v1. The form
 * takes the site theme's colours and fonts: its stylesheet uses the theme's preset
 * variables (--wp--preset--color--*), falling back to "inherit". The page hosting the
 * form of a property is remembered, so the links in e-mails open there; if none is known,
 * the link opens the site's home page, where a minimal page is shown.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Frontend;

use BookingManager\Bookings\ManageLink;
use BookingManager\Core\NoStore;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Rest\PublicController;

defined( 'ABSPATH' ) || exit;

class PublicFrontend {

	public const SHORTCODE = 'booking_manager_prenota';
	public const HANDLE    = 'bkng-public';
	/** slug => page id of the page that shows the form. */
	public const PAGES_OPTION = 'bkng_booking_pages';

	private bool $enqueued = false;

	public function __construct( private PropertyRepository $properties, private Captcha $captcha ) {}

	public function hooks(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'manage_page' ), 5 );
		add_filter( 'bkng_manage_url', array( $this, 'manage_url' ), 5, 2 );
	}

	/** The shortcode text for a property, as written in a page. */
	public static function shortcode_for( string $slug ): string {
		return '[' . self::SHORTCODE . ' struttura="' . sanitize_title( $slug ) . '"]';
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'struttura' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		$slug = sanitize_title( (string) $atts['struttura'] );
		if ( '' === $slug ) {
			// One property: the form needs no attribute.
			$active = $this->properties->all( null, false );
			$slug   = 1 === count( $active ) ? (string) $active[0]['slug'] : '';
		}
		$property = '' === $slug ? null : $this->properties->find_by_slug( $slug );
		if ( ! $property || 'active' !== $property['status'] ) {
			return current_user_can( 'bkng_manage_properties' )
				? '<div class="bkng-public-notice">' . esc_html__( 'Booking Manager: indica la struttura nello shortcode, ad esempio [booking_manager_prenota struttura="nome-struttura"].', 'booking-manager' ) . '</div>'
				: '';
		}
		$page_id = is_singular() ? (int) get_queried_object_id() : 0;
		// Only the page being viewed, not a widget or a feed printing the shortcode elsewhere.
		if ( $page_id > 0 && (int) get_the_ID() === $page_id && 'publish' === get_post_status( $page_id ) ) {
			self::remember_page( $slug, $page_id );
		}
		$this->enqueue();
		return $this->container( $slug, false );
	}

	/** Guest link opened on a page without the form (e.g. the home page). */
	public function manage_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page; the token is the credential.
		if ( empty( $_GET[ ManageLink::ARG_CODE ] ) || empty( $_GET[ ManageLink::ARG_TOKEN ] ) ) {
			return;
		}
		// phpcs:enable
		$post = get_queried_object();
		if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
			return; // The page with the form shows it.
		}
		NoStore::send();
		header( 'X-Robots-Tag: noindex, nofollow' );
		$this->enqueue();
		$title = __( 'La tua prenotazione', 'booking-manager' );
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' – ' . get_bloginfo( 'name' ) ); ?></title>
		<?php wp_head(); ?>
</head>
<body class="bkng-manage-body">
<main class="bkng-manage-page">
		<?php echo $this->container( '', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in container(). ?>
</main>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Link of e-mails: the page with the form of the booking's property, when known.
	 *
	 * @param array<string, mixed> $booking
	 */
	public function manage_url( string $url, array $booking ): string {
		$property = $this->properties->find( (int) ( $booking['property_id'] ?? 0 ) );
		$page_id  = $property ? self::page_for( (string) $property['slug'] ) : 0;
		if ( $page_id ) {
			$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
			parse_str( $query, $args );
			return add_query_arg( $args, (string) get_permalink( $page_id ) );
		}
		return $url;
	}

	/** Published page that shows the form of a property, or 0. */
	public static function page_for( string $slug ): int {
		$pages   = get_option( self::PAGES_OPTION, array() );
		$page_id = is_array( $pages ) ? (int) ( $pages[ $slug ] ?? 0 ) : 0;
		return $page_id && 'publish' === get_post_status( $page_id ) ? $page_id : 0;
	}

	public static function remember_page( string $slug, int $page_id ): void {
		$pages = get_option( self::PAGES_OPTION, array() );
		$pages = is_array( $pages ) ? $pages : array();
		if ( ( $pages[ $slug ] ?? 0 ) !== $page_id ) {
			$pages[ $slug ] = $page_id;
			update_option( self::PAGES_OPTION, $pages, false );
		}
	}

	private function container( string $slug, bool $manage_only ): string {
		$config = array(
			'restUrl'    => esc_url_raw( PublicController::url() ),
			'property'   => $slug,
			'manageOnly' => $manage_only,
			'captcha'    => $this->captcha->public_config(),
			'locale'     => str_replace( '_', '-', determine_locale() ),
			'args'       => array(
				'code'  => ManageLink::ARG_CODE,
				'token' => ManageLink::ARG_TOKEN,
			),
		);
		// alignwide: block themes give the form their wide width instead of the text column.
		return '<div class="bkng-public alignwide" data-bkng="' . esc_attr( (string) wp_json_encode( $config ) ) . '">'
			. '<noscript>' . esc_html__( 'Per prenotare online serve JavaScript attivo. In alternativa contatta la struttura.', 'booking-manager' ) . '</noscript>'
			. '<p class="bkng-public-loading">' . esc_html__( 'Caricamento…', 'booking-manager' ) . '</p>'
			. '</div>';
	}

	private function enqueue(): void {
		if ( $this->enqueued ) {
			return;
		}
		$this->enqueued = true;
		$asset_file     = BKNG_DIR . 'assets/build/public.asset.php';
		$asset          = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => BKNG_VERSION,
		);
		if ( file_exists( BKNG_DIR . 'assets/build/public.js' ) ) {
			wp_enqueue_script( self::HANDLE, BKNG_URL . 'assets/build/public.js', $asset['dependencies'], $asset['version'], true );
			wp_set_script_translations( self::HANDLE, 'booking-manager', BKNG_DIR . 'languages' );
		}
		if ( file_exists( BKNG_DIR . 'assets/build/style-public.css' ) ) {
			wp_enqueue_style( self::HANDLE, BKNG_URL . 'assets/build/style-public.css', array(), $asset['version'] );
		}
		if ( $this->captcha->enabled() ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare serves the current version.
			wp_enqueue_script( 'bkng-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', array(), null, true );
		}
	}
}
