<?php
/**
 * HTTP calls to the Channex API (wp_remote_request, JSON, header user-api-key).
 *
 * Every call returns the same shape, whatever happened:
 *   status  HTTP status (0: no answer: timeout, DNS, TLS)
 *   body    the decoded JSON (array) or null
 *   error   a short message for logs and the screen, null when 2xx
 *   retry_after  seconds Channex asks to wait (429), or null
 *
 * The API key is sent only in the header, never logged. Validation errors come back
 * with status 200 in body.meta.warnings: the caller reads them (see warnings()).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

defined( 'ABSPATH' ) || exit;

class ChannexClient {

	public const TIMEOUT = 30;

	public function __construct( private ChannexSettings $settings ) {}

	/**
	 * @param array<string, mixed> $query
	 * @return array{status: int, body: array<string, mixed>|null, error: string|null, retry_after: int|null}
	 */
	public function get( string $path, array $query = array() ): array {
		return $this->request( 'GET', $path, $query, null );
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array{status: int, body: array<string, mixed>|null, error: string|null, retry_after: int|null}
	 */
	public function post( string $path, ?array $body = null ): array {
		return $this->request( 'POST', $path, array(), $body );
	}

	/**
	 * @param array<string, mixed>      $query
	 * @param array<string, mixed>|null $body
	 * @return array{status: int, body: array<string, mixed>|null, error: string|null, retry_after: int|null}
	 */
	public function request( string $method, string $path, array $query = array(), ?array $body = null ): array {
		$key = $this->settings->api_key();
		if ( '' === $key ) {
			return self::result( 0, null, __( 'Chiave API di Channex mancante.', 'booking-manager' ), null );
		}
		$url = $this->settings->base_url() . '/' . ltrim( $path, '/' );
		if ( $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'user-api-key' => $key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'User-Agent'   => 'BookingManager/' . ( defined( 'BKNG_VERSION' ) ? BKNG_VERSION : 'dev' ) . '; ' . home_url( '/' ),
			),
		);
		if ( null !== $body ) {
			$args['body'] = (string) wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error of the connection. */
			return self::result( 0, null, sprintf( __( 'Channex non risponde: %s', 'booking-manager' ), wp_strip_all_tags( $response->get_error_message() ) ), null );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : null;
		$retry   = wp_remote_retrieve_header( $response, 'retry-after' );
		$retry   = is_numeric( $retry ) ? max( 1, (int) $retry ) : null;
		if ( $status >= 200 && $status < 300 ) {
			return self::result( $status, $decoded, null, null );
		}
		return self::result( $status, $decoded, self::error_message( $status, $decoded ), $retry );
	}

	/**
	 * A readable message for an answer that is not 2xx (unit-tested).
	 *
	 * @param array<string, mixed>|null $body
	 */
	public static function error_message( int $status, ?array $body ): string {
		$detail = '';
		if ( isset( $body['errors'] ) ) {
			$errors = $body['errors'];
			if ( is_array( $errors ) && isset( $errors['title'] ) ) {
				$detail = (string) $errors['title'];
				if ( isset( $errors['details'] ) && is_array( $errors['details'] ) ) {
					$parts = array();
					foreach ( $errors['details'] as $field => $messages ) {
						$parts[] = $field . ': ' . implode( ', ', array_map( 'strval', (array) $messages ) );
					}
					$detail .= $parts ? ' (' . implode( '; ', $parts ) . ')' : '';
				}
			} elseif ( is_array( $errors ) ) {
				$detail = (string) wp_json_encode( $errors );
			} else {
				$detail = (string) $errors;
			}
		}
		$label = match ( true ) {
			401 === $status => __( 'Chiave API non valida o revocata', 'booking-manager' ),
			403 === $status => __( 'Accesso negato da Channex', 'booking-manager' ),
			404 === $status => __( 'Elemento non trovato su Channex', 'booking-manager' ),
			422 === $status || 400 === $status => __( 'Dati rifiutati da Channex', 'booking-manager' ),
			429 === $status => __( 'Troppe richieste a Channex (limite al minuto)', 'booking-manager' ),
			$status >= 500 => __( 'Channex ha un problema temporaneo', 'booking-manager' ),
			default => __( 'Risposta inattesa da Channex', 'booking-manager' ),
		};
		return mb_substr( $label . ' (HTTP ' . $status . ')' . ( '' !== $detail ? ': ' . wp_strip_all_tags( $detail ) : '' ), 0, 250 );
	}

	/**
	 * Warnings of an ARI answer (status 200, values refused one by one).
	 *
	 * @param array<string, mixed>|null $body
	 * @return array<int, mixed>
	 */
	public static function warnings( ?array $body ): array {
		$warnings = $body['meta']['warnings'] ?? array();
		return is_array( $warnings ) ? array_values( $warnings ) : array();
	}

	/**
	 * The task id of an ARI answer (data[].id with type "task").
	 *
	 * @param array<string, mixed>|null $body
	 */
	public static function task_id( ?array $body ): ?string {
		$data = $body['data'] ?? null;
		if ( is_array( $data ) && isset( $data['id'] ) ) {
			$data = array( $data );
		}
		foreach ( is_array( $data ) ? $data : array() as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) && ( ! isset( $item['type'] ) || 'task' === $item['type'] ) ) {
				return substr( (string) $item['id'], 0, 36 );
			}
		}
		return null;
	}

	/**
	 * Id and title of the items of an "options" list (properties, room types, rate plans).
	 *
	 * @param array<string, mixed>|null $body
	 * @return array<int, array<string, mixed>> {id, title, ...other attributes}
	 */
	public static function options( ?array $body ): array {
		$out = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$attributes = is_array( $item['attributes'] ?? null ) ? $item['attributes'] : array();
			$id         = (string) ( $item['id'] ?? $attributes['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'title' => (string) ( $attributes['title'] ?? $item['title'] ?? $id ),
			) + array_intersect_key( $attributes, array_flip( array( 'room_type_id', 'currency', 'occupancy', 'sell_mode', 'rate_mode' ) ) );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array{status: int, body: array<string, mixed>|null, error: string|null, retry_after: int|null}
	 */
	private static function result( int $status, ?array $body, ?string $error, ?int $retry ): array {
		return array(
			'status'      => $status,
			'body'        => $body,
			'error'       => $error,
			'retry_after' => $retry,
		);
	}
}
