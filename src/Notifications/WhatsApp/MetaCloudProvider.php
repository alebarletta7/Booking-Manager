<?php
/**
 * WhatsApp Cloud API by Meta (graph.facebook.com), the official API of WhatsApp Business.
 *
 * Only documented endpoints are used:
 *   GET  /{phone-number-id}?fields=display_phone_number,verified_name   check the connection
 *   GET  /{waba-id}?fields=name                                         check the business account
 *   POST /{phone-number-id}/messages      type "template"                send
 *   POST /{waba-id}/message_templates     category UTILITY               ask for approval
 *   GET  /{waba-id}/message_templates?name=…                             approval status
 *
 * The Cloud API has no idempotency key for messages: a request that got no answer may have
 * been delivered, so it is reported as failed (never repeated automatically) with a
 * message saying so.
 * The access token travels only in the Authorization header and never appears in errors.
 * What can be decided without the network (request bodies, the meaning of Meta's
 * answers) is static and unit-tested.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications\WhatsApp;

use BookingManager\Notifications\SendResult;

defined( 'ABSPATH' ) || exit;

class MetaCloudProvider implements WhatsAppProvider {

	public const ID = 'meta';

	/** Graph API version (change with the bkng_whatsapp_graph_version filter when Meta retires it). */
	public const GRAPH_VERSION = 'v23.0';

	private const BASE = 'https://graph.facebook.com/';

	/** Passing errors: rate limits and temporary problems at Meta. */
	public const RETRY_CODES = array( 1, 2, 4, 17, 341, 80007, 130429, 131000, 131016, 131048, 131056, 133004 );

	/** The token is wrong, expired or lacks permissions. */
	public const AUTH_CODES = array( 3, 10, 190, 200, 299 );

	/** «A template with this name and language already exists». */
	public const DUPLICATE_SUBCODES = array( 2388023, 2388024 );

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'WhatsApp Cloud API (Meta)', 'booking-manager' );
	}

	public function fields(): array {
		return array(
			array(
				'name'     => 'phone_number_id',
				'label'    => __( 'ID del numero di telefono', 'booking-manager' ),
				'secret'   => false,
				'required' => true,
				'help'     => __( 'Meta for Developers → la tua app → WhatsApp → Configurazione API: «ID numero di telefono».', 'booking-manager' ),
			),
			array(
				'name'     => 'business_account_id',
				'label'    => __( 'ID dell’account WhatsApp Business', 'booking-manager' ),
				'secret'   => false,
				'required' => true,
				'help'     => __( 'Nella stessa pagina: «ID account WhatsApp Business». Serve per far approvare i messaggi.', 'booking-manager' ),
			),
			array(
				'name'     => 'access_token',
				'label'    => __( 'Token di accesso permanente', 'booking-manager' ),
				'secret'   => true,
				'required' => true,
				'help'     => __( 'Token di un utente di sistema (Impostazioni dell’azienda → Utenti di sistema) con i permessi whatsapp_business_messaging e whatsapp_business_management. Il token temporaneo di 24 ore smette di funzionare il giorno dopo.', 'booking-manager' ),
			),
			array(
				'name'     => 'app_secret',
				'label'    => __( 'Chiave segreta dell’app (facoltativa)', 'booking-manager' ),
				'secret'   => true,
				'required' => false,
				'help'     => __( 'Impostazioni dell’app → Di base → «Chiave segreta». Serve solo per ricevere da Meta gli stati «consegnato» e «letto»: il sito deve essere raggiungibile da internet.', 'booking-manager' ),
			),
		);
	}

	public function check( array $credentials ): array|\WP_Error {
		$phone = $this->call( 'GET', rawurlencode( (string) $credentials['phone_number_id'] ) . '?fields=display_phone_number,verified_name', $credentials );
		if ( is_wp_error( $phone ) ) {
			return $phone;
		}
		$account = $this->call( 'GET', rawurlencode( (string) $credentials['business_account_id'] ) . '?fields=name', $credentials );
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		return array( 'account' => self::account_label( $phone ) );
	}

	public function send( array $credentials, string $to, string $template, string $language, array $params, ?string $button = null ): SendResult {
		$response = $this->raw( 'POST', rawurlencode( (string) $credentials['phone_number_id'] ) . '/messages', $credentials, self::message( $to, $template, $language, $params, $button ) );
		if ( is_wp_error( $response ) ) {
			return self::transport_result( $response );
		}
		return self::send_result( $response[0], $response[1], $credentials );
	}

	public function submit_template( array $credentials, string $name, string $language, string $body, array $examples, ?array $button = null ): array|\WP_Error {
		$response = $this->raw( 'POST', rawurlencode( (string) $credentials['business_account_id'] ) . '/message_templates', $credentials, self::template( $name, $language, $body, $examples, $button ) );
		if ( is_wp_error( $response ) ) {
			return self::unreachable();
		}
		[ $code, $data ] = $response;
		if ( $code >= 400 ) {
			$error = self::error_of( $code, $data, $credentials );
			return new \WP_Error(
				'bkng_whatsapp_error',
				$error['message'],
				array(
					'status'    => 422,
					'duplicate' => in_array( (int) ( $data['error']['error_subcode'] ?? 0 ), self::DUPLICATE_SUBCODES, true ),
				)
			);
		}
		return array(
			'status' => self::status( (string) ( $data['status'] ?? 'PENDING' ) ),
			'reason' => '',
		);
	}

	public function template_status( array $credentials, string $name, string $language ): array|\WP_Error {
		$result = $this->call(
			'GET',
			rawurlencode( (string) $credentials['business_account_id'] ) . '/message_templates?' . http_build_query(
				array(
					'name'   => $name,
					'fields' => 'name,status,language,rejected_reason',
					'limit'  => 50,
				),
				'',
				'&'
			),
			$credentials
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::find_status( $result, $name, $language )
			?? new \WP_Error( 'bkng_whatsapp_template_missing', __( 'Meta non trova più questo messaggio: salvalo di nuovo per chiederne l’approvazione.', 'booking-manager' ) );
	}

	/* ------------------------------------------------------------ pure helpers */

	/**
	 * The body of a template message (unit-tested).
	 *
	 * @param string[] $params
	 * @param string|null $button end of the button's link ({{1}} of its URL)
	 * @return array<string, mixed>
	 */
	public static function message( string $to, string $template, string $language, array $params, ?string $button = null ): array {
		$message = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'template',
			'template'          => array(
				'name'     => $template,
				'language' => array( 'code' => $language ),
			),
		);
		if ( $params ) {
			$message['template']['components'] = array(
				array(
					'type'       => 'body',
					'parameters' => array_map(
						static fn( $value ) => array(
							'type' => 'text',
							'text' => (string) $value,
						),
						array_values( $params )
					),
				),
			);
		}
		if ( null !== $button ) {
			$message['template']['components'][] = array(
				'type'       => 'button',
				'sub_type'   => 'url',
				'index'      => '0',
				'parameters' => array(
					array(
						'type' => 'text',
						'text' => $button,
					),
				),
			);
		}
		return $message;
	}

	/**
	 * The request asking for a template's approval (unit-tested).
	 *
	 * @param string[] $examples
	 * @param array{text: string, url: string, example: string}|null $button
	 * @return array<string, mixed>
	 */
	public static function template( string $name, string $language, string $body, array $examples, ?array $button = null ): array {
		$component = array(
			'type' => 'BODY',
			'text' => $body,
		);
		if ( $examples ) {
			$component['example'] = array( 'body_text' => array( array_values( array_map( 'strval', $examples ) ) ) );
		}
		$components = array( $component );
		if ( $button ) {
			// Meta allows the changing part of a link button only at the end of its URL.
			$components[] = array(
				'type'    => 'BUTTONS',
				'buttons' => array(
					array(
						'type'    => 'URL',
						'text'    => $button['text'],
						'url'     => $button['url'],
						'example' => array( $button['example'] ),
					),
				),
			);
		}
		return array(
			'name'       => $name,
			'language'   => $language,
			'category'   => 'UTILITY',
			'components' => $components,
		);
	}

	/**
	 * "Night Club (+39 333 123 4567)" from GET /{phone-number-id} (unit-tested).
	 *
	 * @param array<string, mixed> $phone
	 */
	public static function account_label( array $phone ): string {
		$name = trim( (string) ( $phone['verified_name'] ?? '' ) );
		$num  = trim( (string) ( $phone['display_phone_number'] ?? '' ) );
		return trim( $name . ( '' !== $num ? ' (' . $num . ')' : '' ) );
	}

	/**
	 * The outcome of POST /messages (unit-tested).
	 *
	 * @param array<string, mixed>  $body
	 * @param array<string, string> $credentials
	 */
	public static function send_result( int $code, array $body, array $credentials = array() ): SendResult {
		if ( $code < 400 && ! empty( $body['messages'][0]['id'] ) ) {
			return SendResult::sent( (string) $body['messages'][0]['id'] );
		}
		$error = self::error_of( $code, $body, $credentials );
		if ( 429 === $code || $code >= 500 || in_array( $error['code'], self::RETRY_CODES, true ) ) {
			return SendResult::retry( $error['message'] );
		}
		return SendResult::failed( $error['message'] );
	}

	/**
	 * Status of one name and language in GET /message_templates, or null (unit-tested).
	 *
	 * @param array<string, mixed> $result
	 * @return array{status: string, reason: string}|null
	 */
	public static function find_status( array $result, string $name, string $language ): ?array {
		foreach ( (array) ( $result['data'] ?? array() ) as $row ) {
			if ( $name === ( $row['name'] ?? '' ) && $language === ( $row['language'] ?? '' ) ) {
				$reason = (string) ( $row['rejected_reason'] ?? '' );
				return array(
					'status' => self::status( (string) ( $row['status'] ?? '' ) ),
					'reason' => 'NONE' === $reason ? '' : $reason,
				);
			}
		}
		return null;
	}

	/**
	 * Before the request left (name lookup, connection): safe to retry. Otherwise (time
	 * out, connection dropped) Meta may have received it (unit-tested).
	 */
	public static function transport_result( \WP_Error $error ): SendResult {
		$text = strtolower( $error->get_error_message() );
		if ( preg_match( '/curl error (6|7|35|60):|could not resolve|failed to connect|connection refused/', $text ) ) {
			return SendResult::retry( __( 'Meta non raggiungibile (connessione assente): nuovo tentativo più tardi.', 'booking-manager' ) );
		}
		return SendResult::failed( __( 'Nessuna risposta da Meta in tempo: il messaggio potrebbe essere partito. Controlla con l’ospite prima di reinviarlo.', 'booking-manager' ) );
	}

	/**
	 * A readable Italian message for a failed answer, with Meta's own words; credentials
	 * are masked whatever Meta echoes back (unit-tested).
	 *
	 * @param array<string, mixed>  $body
	 * @param array<string, string> $credentials
	 * @return array{code: int, message: string}
	 */
	public static function error_of( int $code, array $body, array $credentials = array() ): array {
		$error  = is_array( $body['error'] ?? null ) ? $body['error'] : array();
		$number = (int) ( $error['code'] ?? 0 );
		// error_user_msg says what is wrong («variables can't be at the end…»); message is often just «Invalid parameter».
		$detail = (string) ( $error['error_user_msg'] ?? $error['error_data']['details'] ?? $error['message'] ?? '' );
		foreach ( array( 'access_token', 'app_secret' ) as $secret ) {
			if ( ! empty( $credentials[ $secret ] ) ) {
				$detail = str_replace( (string) $credentials[ $secret ], '•••', $detail );
			}
		}
		$detail = mb_substr( trim( $detail ), 0, 300 );
		if ( 401 === $code || in_array( $number, self::AUTH_CODES, true ) ) {
			/* translators: %s: error detail from Meta */
			$message = sprintf( __( 'Collegamento WhatsApp non valido o scaduto (%s): aggiorna il token in WhatsApp → Collegamento.', 'booking-manager' ), $detail );
		} elseif ( 131030 === $number ) {
			$message = __( 'Con il numero di prova di Meta si può scrivere solo ai numeri aggiunti all’elenco dei destinatari.', 'booking-manager' );
		} elseif ( in_array( $number, array( 131026, 131021, 131051 ), true ) ) {
			$message = __( 'Il numero non può ricevere messaggi WhatsApp.', 'booking-manager' );
		} elseif ( 133010 === $number ) {
			$message = __( 'Il numero della struttura non è ancora registrato su WhatsApp Business.', 'booking-manager' );
		} elseif ( 131042 === $number ) {
			$message = __( 'Problema con il metodo di pagamento dell’account WhatsApp Business.', 'booking-manager' );
		} else {
			/* translators: 1: Meta error code, 2: error detail from Meta */
			$message = sprintf( __( 'Meta, errore %1$d: %2$s', 'booking-manager' ), $number ?: $code, '' !== $detail ? $detail : __( 'errore sconosciuto', 'booking-manager' ) );
		}
		return array(
			'code'    => $number,
			'message' => $message,
		);
	}

	/** Meta's template status in the plugin's words (unit-tested). */
	public static function status( string $meta ): string {
		return match ( strtoupper( $meta ) ) {
			'APPROVED' => 'approved',
			'PENDING', 'IN_APPEAL' => 'pending',
			'REJECTED' => 'rejected',
			'PAUSED' => 'paused',
			default => 'disabled',
		};
	}

	/* -------------------------------------------------------------------- http */

	private static function unreachable(): \WP_Error {
		return new \WP_Error( 'bkng_whatsapp_unreachable', __( 'Meta non risponde: riprova tra poco.', 'booking-manager' ), array( 'status' => 502 ) );
	}

	/**
	 * @param array<string, string>     $credentials
	 * @param array<string, mixed>|null $json
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call( string $method, string $path, array $credentials, ?array $json = null ): array|\WP_Error {
		$response = $this->raw( $method, $path, $credentials, $json );
		if ( is_wp_error( $response ) ) {
			return self::unreachable();
		}
		[ $code, $body ] = $response;
		if ( $code >= 400 ) {
			return new \WP_Error( 'bkng_whatsapp_error', self::error_of( $code, $body, $credentials )['message'], array( 'status' => 422 ) );
		}
		return $body;
	}

	/**
	 * @param array<string, string>     $credentials
	 * @param array<string, mixed>|null $json
	 * @return array{0: int, 1: array<string, mixed>}|\WP_Error
	 */
	private function raw( string $method, string $path, array $credentials, ?array $json = null ): array|\WP_Error {
		$version = (string) apply_filters( 'bkng_whatsapp_graph_version', self::GRAPH_VERSION );
		$args    = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . (string) ( $credentials['access_token'] ?? '' ),
				'Accept'        => 'application/json',
				'User-Agent'    => 'BookingManager/' . ( defined( 'BKNG_VERSION' ) ? BKNG_VERSION : 'dev' ) . ' (WordPress)',
			),
		);
		if ( null !== $json ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $json );
		}
		$response = wp_remote_request( self::BASE . rawurlencode( $version ) . '/' . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array( (int) wp_remote_retrieve_response_code( $response ), is_array( $body ) ? $body : array() );
	}
}
