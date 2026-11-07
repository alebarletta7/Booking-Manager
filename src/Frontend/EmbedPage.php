<?php
/**
 * The booking form as a page of its own, to show in an iframe on the client's site
 * (0.2.3): /booking/{property-slug}/ (or ?bkng_embed=slug with plain permalinks). The
 * small script that adapts the iframe's height is a static file of the plugin
 * (assets/embed/embed.js), so web servers and CDNs serve and cache it as any script.
 *
 * - The page is the same form as the shortcode (same script, same public API), printed
 *   without the theme: nothing of the central site shows inside the client's page.
 * - Who may frame it: the Content-Security-Policy frame-ancestors lists only 'self' and
 *   the domains the platform authorised for the property's customer; any other site gets
 *   an empty frame from the browser. A domain grants nothing on the API: the public
 *   endpoints stay what they were (the slug is public, a booking needs code and token).
 * - Unknown or deactivated property, or archived customer: a 404 page. A suspended or
 *   expired customer keeps the page, where the form says that online bookings are not
 *   available and shows the contacts (as the shortcode does).
 * - Payment: inside the iframe the form does not open the payment page by itself; the
 *   guest presses a link that opens it in the whole window (target _top), and the
 *   payment returns to this page opened on its own (see PublicFrontend::manage_url()).
 * - No rewrite rules: the address is read from the request, so nothing needs flushing.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Frontend;

use BookingManager\Bookings\ManageLink;
use BookingManager\Core\NoStore;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantGate;
use BookingManager\Tenants\TenantRepository;

defined( 'ABSPATH' ) || exit;

class EmbedPage {

	/** First segment of the address; filter bkng_embed_base. */
	public const BASE = 'booking';
	/** Query argument used when the site has no pretty permalinks. */
	public const ARG = 'bkng_embed';
	public function __construct(
		private PropertyRepository $properties,
		private TenantRepository $tenants,
		private TenantGate $gate,
		private PublicFrontend $frontend
	) {}

	public function hooks(): void {
		// Before canonical redirects (10) and the "manage" page (5).
		add_action( 'template_redirect', array( $this, 'serve' ), 1 );
	}

	public static function base(): string {
		$base = sanitize_title( (string) apply_filters( 'bkng_embed_base', self::BASE ) );
		return '' === $base ? self::BASE : $base;
	}

	/** Address of the page of a property. */
	public static function url( string $slug ): string {
		$slug = sanitize_title( $slug );
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::ARG, $slug, home_url( '/' ) );
		}
		return home_url( '/' . self::base() . '/' . $slug . '/' );
	}

	/** Address of the height script. */
	public static function script_url(): string {
		return BKNG_URL . 'assets/embed/embed.js';
	}

	/** The code a client pastes in its page. */
	public static function snippet( string $slug, string $title = '' ): string {
		return '<iframe src="' . esc_url( self::url( $slug ) ) . '" title="' . esc_attr( '' !== $title ? $title : __( 'Prenota online', 'booking-manager' ) ) . '" style="width:100%;min-height:640px;border:0" loading="lazy"></iframe>' . "\n"
			. '<script src="' . esc_url( self::script_url() ) . '" async></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- text shown to the client, not printed in a page.
	}

	/**
	 * The property slug the request asks for, or null.
	 *
	 * @param string $request_uri $_SERVER['REQUEST_URI'].
	 * @param array<string, mixed> $query $_GET.
	 */
	public static function match( string $request_uri, array $query, string $home_path ): ?string {
		if ( isset( $query[ self::ARG ] ) && is_string( $query[ self::ARG ] ) ) {
			$value = strtolower( trim( $query[ self::ARG ] ) );
			return preg_match( '/^[a-z0-9-]{1,200}$/', $value ) ? $value : null;
		}
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$home = '/' . trim( $home_path, '/' );
		$home = '/' === $home ? '' : $home;
		if ( '' !== $home ) {
			if ( ! str_starts_with( $path . '/', $home . '/' ) ) {
				return null;
			}
			$path = substr( $path, strlen( $home ) );
		}
		$path = ltrim( $path, '/' );
		// PATHINFO permalinks (/index.php/booking/…).
		if ( str_starts_with( $path, 'index.php/' ) ) {
			$path = substr( $path, strlen( 'index.php/' ) );
		}
		$base = preg_quote( self::base(), '#' );
		return preg_match( '#^' . $base . '/([a-z0-9-]{1,200})/?$#', $path, $m ) ? $m[1] : null;
	}

	/**
	 * frame-ancestors of a customer's pages: 'self' and its domains, without scheme
	 * (on an https platform the browser then accepts only https pages of those hosts).
	 *
	 * @param string[] $domains
	 */
	public static function csp( array $domains ): string {
		$sources = array( "'self'" );
		foreach ( $domains as $host ) {
			// Only what Domains::normalize() lets in: never a quote, a space or a ';'.
			if ( preg_match( '/^[a-z0-9.-]+$/', (string) $host ) ) {
				$sources[] = (string) $host;
			}
		}
		return 'frame-ancestors ' . implode( ' ', array_unique( $sources ) );
	}

	public function serve(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against strict patterns.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page.
		$target = self::match( $uri, (array) wp_unslash( $_GET ), (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( null === $target ) {
			return;
		}
		if ( ! is_ssl() && 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			// Same address over https (the token of a "manage" link never travels in clear twice).
			wp_safe_redirect( 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ) . $uri, 301 );
			exit;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only page; the token is the credential.
		$manage = ! empty( $_GET[ ManageLink::ARG_CODE ] ) && ! empty( $_GET[ ManageLink::ARG_TOKEN ] );
		// phpcs:enable
		$this->send( $this->render( $target, $manage ) );
		exit;
	}

	/**
	 * The response for a property page.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function render( string $slug, bool $manage = false ): array {
		$property = $this->properties->find_by_slug( sanitize_title( $slug ) );
		$tenant   = $property ? $this->tenant_of( $property ) : 0;
		$found    = $property
			&& ( 'active' === $property['status'] || $manage )
			&& ! $this->gate->archived( $tenant );
		$headers  = array(
			'Content-Type'            => 'text/html; charset=' . get_bloginfo( 'charset' ),
			'Content-Security-Policy' => self::csp( $found && $tenant > 0 ? $this->tenants->domains( $tenant ) : array() ),
			'Referrer-Policy'         => 'strict-origin',
			'X-Content-Type-Options'  => 'nosniff',
			'X-Robots-Tag'            => 'noindex, nofollow',
		) + NoStore::HEADERS;
		/**
		 * Filters the headers of the embeddable page (e.g. to add frame-ancestors sources).
		 *
		 * @param array<string, string>     $headers
		 * @param array<string, mixed>|null $property
		 */
		$headers = (array) apply_filters( 'bkng_embed_headers', $headers, $found ? $property : null );
		return array(
			'status'  => $found ? 200 : 404,
			'headers' => $headers,
			'body'    => $found ? $this->page( (string) $property['slug'], (string) $property['name'], $manage ) : $this->missing(),
		);
	}

	/** @param array<string, mixed> $property */
	private function tenant_of( array $property ): int {
		if ( ! Tenancy::ready() ) {
			return 0;
		}
		$tenant = (int) ( $property['tenant_id'] ?? 0 );
		return $tenant > 0 ? $tenant : Tenancy::of_property( (int) $property['id'] );
	}

	/** @param array{status: int, headers: array<string, string>, body: string} $response */
	private function send( array $response ): void {
		if ( ! headers_sent() ) {
			status_header( $response['status'] );
			header_remove( 'X-Frame-Options' );
			header_remove( 'X-Pingback' );
			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in page()/missing().
	}

	private function page( string $slug, string $name, bool $manage ): string {
		$this->frontend->enqueue();
		$body = $this->frontend->container( $manage ? '' : $slug, $manage, true );
		ob_start();
		wp_print_styles( array( PublicFrontend::HANDLE ) );
		$styles = (string) ob_get_clean();
		ob_start();
		wp_print_scripts( array( PublicFrontend::HANDLE, 'bkng-turnstile' ) );
		$scripts = (string) ob_get_clean();
		$title   = $manage ? __( 'La tua prenotazione', 'booking-manager' ) : sprintf( /* translators: %s: property name */ __( 'Prenota – %s', 'booking-manager' ), $name );
		return '<!doctype html>' . "\n"
			. '<html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '">' . "\n"
			. '<head>' . "\n"
			. '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">' . "\n"
			. '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
			. '<meta name="robots" content="noindex, nofollow">' . "\n"
			// Links (contacts, "manage my booking", payment) open in the whole window, not in the frame.
			. '<base target="_top">' . "\n"
			. '<title>' . esc_html( $title ) . '</title>' . "\n"
			. $styles
			. '</head>' . "\n"
			. '<body class="bkng-embed-body">' . "\n"
			. '<main class="bkng-embed-page">' . $body . '</main>' . "\n"
			. $scripts
			. '</body>' . "\n"
			. '</html>' . "\n";
	}

	private function missing(): string {
		return '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . esc_html__( 'Pagina non disponibile', 'booking-manager' ) . '</title></head>'
			. '<body style="font-family:system-ui,sans-serif;margin:0;padding:24px;color:#1d2327"><p>' . esc_html__( 'Il modulo di prenotazione non è disponibile.', 'booking-manager' ) . '</p></body></html>';
	}
}
