<?php
/**
 * Minimal Cloudflare API v4 client (no SDK, copied from Night Manager 0.16.3): token
 * check, zone details, Rulesets API. Kept thin: what can be decided without the network
 * lives in Rules and the static helpers here (unit-tested).
 *
 * The token is sent only to api.cloudflare.com, in the Authorization header, and never
 * appears in an error message.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Cloudflare;

defined( 'ABSPATH' ) || exit;

class CloudflareClient {

	public const API = 'https://api.cloudflare.com/client/v4';

	public function __construct( private CloudflareSettings $settings ) {}

	/** @return array<string, mixed>|\WP_Error GET /user/tokens/verify → {id, status, …}. */
	public function verify_token(): array|\WP_Error {
		return $this->request( 'GET', '/user/tokens/verify' );
	}

	/** @return array<string, mixed>|\WP_Error GET /zones/{zone_id} → {id, name, status, plan, …}. */
	public function zone(): array|\WP_Error {
		return $this->request( 'GET', '/zones/' . $this->zone_id() );
	}

	/**
	 * GET /zones/{zone_id}/rulesets/phases/{phase}/entrypoint.
	 *
	 * @return array<string, mixed>|null|\WP_Error The ruleset, or null when the zone has none for the phase yet.
	 */
	public function entrypoint( string $phase ): array|null|\WP_Error {
		$result = $this->request( 'GET', '/zones/' . $this->zone_id() . '/rulesets/phases/' . rawurlencode( $phase ) . '/entrypoint' );
		if ( $result instanceof \WP_Error && 404 === ( $result->get_error_data()['http'] ?? 0 ) ) {
			return null;
		}
		return $result;
	}

	/**
	 * PUT /zones/{zone_id}/rulesets/phases/{phase}/entrypoint — only used when the
	 * phase has no ruleset yet (it creates it), since a PUT replaces every rule.
	 *
	 * @param array<int, array<string, mixed>> $rules
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_entrypoint( string $phase, array $rules ): array|\WP_Error {
		return $this->request( 'PUT', '/zones/' . $this->zone_id() . '/rulesets/phases/' . rawurlencode( $phase ) . '/entrypoint', array( 'rules' => $rules ) );
	}

	/**
	 * POST /zones/{zone_id}/rulesets/{ruleset_id}/rules
	 *
	 * @param array<string, mixed> $rule
	 * @return array<string, mixed>|\WP_Error
	 */
	public function add_rule( string $ruleset_id, array $rule ): array|\WP_Error {
		return $this->request( 'POST', '/zones/' . $this->zone_id() . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules', $rule );
	}

	/**
	 * PATCH /zones/{zone_id}/rulesets/{ruleset_id}/rules/{rule_id}
	 *
	 * @param array<string, mixed> $rule The whole definition (and an optional position).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_rule( string $ruleset_id, string $rule_id, array $rule ): array|\WP_Error {
		return $this->request( 'PATCH', '/zones/' . $this->zone_id() . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules/' . rawurlencode( $rule_id ), $rule );
	}

	/** @return array<string, mixed>|\WP_Error DELETE /zones/{zone_id}/rulesets/{ruleset_id}/rules/{rule_id} */
	public function delete_rule( string $ruleset_id, string $rule_id ): array|\WP_Error {
		return $this->request( 'DELETE', '/zones/' . $this->zone_id() . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules/' . rawurlencode( $rule_id ) );
	}

	private function zone_id(): string {
		return rawurlencode( $this->settings->zone_id() );
	}

	/**
	 * @param array<string, mixed>|null $body JSON body.
	 * @return array<string, mixed>|\WP_Error The "result" of Cloudflare's envelope.
	 */
	private function request( string $method, string $path, ?array $body = null ): array|\WP_Error {
		$token = $this->settings->api_token();
		if ( '' === $token || ! CloudflareSettings::valid_zone_id( $this->settings->zone_id() ) ) {
			return new \WP_Error( 'bkng_cloudflare_not_configured', __( 'Inserisci e salva Zone ID e token API di Cloudflare.', 'booking-manager' ), array( 'status' => 400 ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}
		$response = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'bkng_cloudflare_unreachable', __( 'Cloudflare non risponde. Riprova tra poco.', 'booking-manager' ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) && true === ( $data['success'] ?? false ) ) {
			return is_array( $data['result'] ?? null ) ? $data['result'] : array();
		}
		return new \WP_Error(
			'bkng_cloudflare_error',
			self::error_message( $code, is_array( $data ) ? $data : array(), $token ),
			array(
				'status' => 404 === $code ? 404 : 502,
				'http'   => $code,
				'codes'  => self::error_codes( is_array( $data ) ? $data : array() ),
			)
		);
	}

	/**
	 * Cloudflare's error codes of a failed answer.
	 *
	 * @param array<string, mixed> $data
	 * @return int[]
	 */
	public static function error_codes( array $data ): array {
		$codes = array();
		foreach ( (array) ( $data['errors'] ?? array() ) as $error ) {
			if ( is_array( $error ) && isset( $error['code'] ) ) {
				$codes[] = (int) $error['code'];
			}
		}
		return $codes;
	}

	/**
	 * A readable Italian message for a failed answer, with Cloudflare's own text (which
	 * never contains the token; it is masked anyway, in case).
	 *
	 * @param array<string, mixed> $data Decoded body: {success, errors: [{code, message}], …}.
	 */
	public static function error_message( int $http, array $data, string $token = '' ): string {
		$details = array();
		foreach ( (array) ( $data['errors'] ?? array() ) as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}
			$text = trim( (string) ( $error['message'] ?? '' ) );
			if ( '' !== $text ) {
				$details[] = ( isset( $error['code'] ) ? '[' . (int) $error['code'] . '] ' : '' ) . $text;
			}
		}
		$detail = mb_substr( implode( '; ', array_slice( $details, 0, 3 ) ), 0, 300 );
		if ( '' !== $token ) {
			$detail = str_replace( $token, '***', $detail );
		}
		if ( 401 === $http || 403 === $http || in_array( 1000, self::error_codes( $data ), true ) || in_array( 10000, self::error_codes( $data ), true ) ) {
			$base = __( 'Cloudflare ha rifiutato il token: è sbagliato, scaduto o non ha i permessi necessari per questa zona.', 'booking-manager' );
		} elseif ( 404 === $http ) {
			$base = __( 'Cloudflare non trova la zona o la risorsa richiesta: controlla lo Zone ID.', 'booking-manager' );
		} elseif ( 429 === $http ) {
			$base = __( 'Troppe richieste a Cloudflare: riprova tra qualche minuto.', 'booking-manager' );
		} elseif ( $http >= 500 || 0 === $http ) {
			$base = __( 'Cloudflare ha avuto un problema temporaneo. Riprova tra poco.', 'booking-manager' );
		} else {
			$base = __( 'Cloudflare ha rifiutato la richiesta.', 'booking-manager' );
		}
		return '' === $detail ? $base : $base . ' (' . $detail . ')';
	}
}
