<?php
/**
 * Checks, from the platform's panel, whether a customer's site can show the booking
 * form in its iframe (0.2.10). The plugin sets the right Content-Security-Policy on
 * /booking/{slug}/, but the web server, a CDN or a security plugin can add headers of
 * their own (X-Frame-Options, another frame-ancestors) that the browser also obeys and
 * that PHP cannot remove: the page is then «refused» on the customer's site.
 *
 * The check asks the platform's own address over HTTP, like a browser would, and reads
 * the headers that arrive. Read-only; only the platform administrator calls it.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

use BookingManager\Database\Schema;
use BookingManager\Frontend\EmbedPage;

defined( 'ABSPATH' ) || exit;

final class EmbedCheck {

	public function __construct( private TenantRepository $tenants ) {}

	/**
	 * @return array{domains: string[], pages: array<int, array<string, mixed>>}
	 */
	public function run( int $tenant_id ): array {
		$domains = $this->tenants->domains( $tenant_id );
		$pages   = array();
		foreach ( $this->properties( $tenant_id ) as $property ) {
			$pages[] = $this->page( $property, $domains );
		}
		return array(
			'domains' => $domains,
			'pages'   => $pages,
		);
	}

	/**
	 * @param array<string, mixed> $property
	 * @param string[]             $domains
	 * @return array<string, mixed>
	 */
	private function page( array $property, array $domains ): array {
		$url      = EmbedPage::url( (string) $property['slug'] );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		$result = array(
			'property' => (string) $property['name'],
			'url'      => $url,
			'ok'       => false,
			'problems' => array(),
		);
		if ( is_wp_error( $response ) ) {
			$result['problems'][] = sprintf(
				/* translators: %s: error message. */
				__( 'Il server non riesce a raggiungere la pagina per verificarla (%s). Prova ad aprire l\'indirizzo dal browser.', 'booking-manager' ),
				$response->get_error_message()
			);
			return $result;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$xfo    = self::values( wp_remote_retrieve_header( $response, 'x-frame-options' ) );
		$csp    = self::values( wp_remote_retrieve_header( $response, 'content-security-policy' ) );
		$result['problems'] = self::diagnose( $status, (string) $property['status'], $xfo, $csp, $domains );
		$result['ok']       = ! $result['problems'];
		return $result;
	}

	/**
	 * Plain-language problems for the headers the page arrived with (unit-tested).
	 *
	 * @param string[] $xfo     X-Frame-Options values.
	 * @param string[] $csp     Content-Security-Policy values.
	 * @param string[] $domains The customer's authorised hosts.
	 * @return string[]
	 */
	public static function diagnose( int $status, string $property_status, array $xfo, array $csp, array $domains ): array {
		$problems = array();
		if ( ! $domains ) {
			$problems[] = __( 'Il cliente non ha domini autorizzati: aggiungi il dominio del suo sito.', 'booking-manager' );
		}
		if ( 'active' !== $property_status ) {
			$problems[] = __( 'La struttura non è attiva: il modulo mostra «non disponibile».', 'booking-manager' );
		} elseif ( 200 !== $status ) {
			$problems[] = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'La pagina risponde con il codice %d invece di 200.', 'booking-manager' ),
				$status
			);
		}
		if ( $xfo ) {
			$problems[] = sprintf(
				/* translators: %s: header value. */
				__( 'Il server aggiunge «X-Frame-Options: %s», che vieta a qualsiasi altro sito di mostrare la pagina. Va tolta dalla configurazione del server (.htaccess, Nginx, pannello dell\'hosting, Cloudflare) o dal plugin di sicurezza che la imposta, almeno per gli indirizzi /booking/.', 'booking-manager' ),
				implode( ', ', $xfo )
			);
		}
		$ancestors = array();
		foreach ( $csp as $policy ) {
			foreach ( explode( ';', $policy ) as $directive ) {
				$parts = preg_split( '/\s+/', trim( strtolower( $directive ) ) );
				if ( $parts && 'frame-ancestors' === $parts[0] ) {
					$ancestors[] = array_slice( $parts, 1 );
				}
			}
		}
		if ( count( $ancestors ) > 1 ) {
			$problems[] = __( 'La pagina arriva con più di una regola «frame-ancestors»: oltre a quella del plugin, il server o un altro plugin ne aggiunge una. Il browser le applica tutte, quindi va tolta quella in più.', 'booking-manager' );
		}
		foreach ( $domains as $host ) {
			foreach ( $ancestors as $sources ) {
				if ( ! self::allows( $sources, $host ) ) {
					$problems[] = sprintf(
						/* translators: %s: domain. */
						__( 'Le intestazioni della pagina non autorizzano %s.', 'booking-manager' ),
						$host
					);
					break;
				}
			}
		}
		return array_values( array_unique( $problems ) );
	}

	/** @param string[] $sources frame-ancestors sources. */
	private static function allows( array $sources, string $host ): bool {
		foreach ( $sources as $source ) {
			$source = preg_replace( '#^https?://#', '', rtrim( $source, '/' ) );
			if ( '*' === $source || $source === $host || 'https:' === $source ) {
				return true;
			}
			if ( str_starts_with( (string) $source, '*.' ) && str_ends_with( $host, substr( (string) $source, 1 ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] */
	private static function values( mixed $header ): array {
		$values = is_array( $header ) ? $header : ( '' === (string) $header ? array() : array( (string) $header ) );
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $values ) ) ) );
	}

	/** @return array<int, array<string, mixed>> */
	private function properties( int $tenant_id ): array {
		global $wpdb;
		$table = Schema::table( 'properties' );
		$null  = Tenancy::default_id() === $tenant_id ? ' OR tenant_id IS NULL OR tenant_id = 0' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name and a fixed clause.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, slug, name, status FROM {$table} WHERE ( tenant_id = %d{$null} ) AND status <> 'archived' ORDER BY name", $tenant_id ), ARRAY_A );
	}
}
