<?php
/**
 * Messages to guests and staff: the log, sending again, the property's own texts.
 *
 *   GET    /properties/{property_id}/notifications?status&channel&booking_id&page   log (bkng_view_bookings)
 *   POST   /notifications/{id}/resend                                              bkng_edit_booking
 *   POST   /bookings/{id}/notify { template }                                      a guest message now (bkng_edit_booking)
 *   POST   /properties/{property_id}/notifications/test { to }                     test e-mail (bkng_manage_property_settings)
 *   GET    /properties/{property_id}/templates                                     texts (bkng_manage_property_settings)
 *   PUT    /properties/{property_id}/templates/{key} { language, subject, body }  body: plain or limited HTML
 *   DELETE /properties/{property_id}/templates/{key}?language=it                   back to the default text (a custom message: removed)
 *   GET    /properties/{property_id}/templates/custom                              names of the custom messages (bkng_view_bookings)
 *   POST   /properties/{property_id}/templates/custom { language, subject, body }  a new custom message (0.2.5)
 *   POST   /properties/{property_id}/templates/{key}/preview { subject, body, design }
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Core\Validation;
use BookingManager\Notifications\NotificationRepository;
use BookingManager\Notifications\NotificationService;
use BookingManager\Notifications\NotificationTemplates;
use BookingManager\Notifications\TemplateRepository;
use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantTemplates;

defined( 'ABSPATH' ) || exit;

class NotificationsController extends RestController {

	public const LANGUAGES = array( 'it', 'en', 'de', 'fr', 'es' );

	public function __construct(
		Policy $policy,
		private NotificationService $service,
		private NotificationRepository $repo,
		private TemplateRepository $templates,
		private BookingRepository $bookings,
		private ?TenantTemplates $tenant_templates = null
	) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$ns       = self::REST_NAMESPACE;
		$base     = '/properties/(?P<property_id>\d+)';
		$settings = $this->require_cap_for_property( 'bkng_manage_property_settings' );
		$template = $base . '/templates/(?P<key>(?!custom$)[a-z0-9_]+)';

		register_rest_route(
			$ns,
			$base . '/notifications',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'log' ),
				'permission_callback' => $this->require_cap_for_property( 'bkng_view_bookings' ),
			)
		);
		register_rest_route(
			$ns,
			'/notifications/(?P<id>\d+)/resend',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->resend( (int) $r['id'] ), 201 ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_edit_booking', fn( int $id ) => $this->repo->find( $id ) ),
			)
		);
		register_rest_route(
			$ns,
			'/bookings/(?P<id>\d+)/notify',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $this->service->send_now( (int) $r['id'], sanitize_key( (string) $r->get_param( 'template' ) ) ), 201 ),
				'permission_callback' => $this->require_cap_for_record( 'bkng_edit_booking', fn( int $id ) => $this->bookings->find( $id ) ),
			)
		);
		register_rest_route(
			$ns,
			$base . '/notifications/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( \WP_REST_Request $r ) => rest_ensure_response( $this->service->send_test_email( (int) $r['property_id'], sanitize_email( (string) $r->get_param( 'to' ) ) ) ),
				'permission_callback' => $settings,
			)
		);
		register_rest_route(
			$ns,
			$base . '/templates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_templates' ),
				'permission_callback' => $settings,
			)
		);
		register_rest_route(
			$ns,
			$template,
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'save_template' ),
					'permission_callback' => $settings,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => function ( \WP_REST_Request $r ) {
						$key = (string) $r['key'];
						if ( NotificationTemplates::is_custom( $key ) && null === $r->get_param( 'language' ) ) {
							// The whole custom message, in every language.
							foreach ( $this->custom( (int) $r['property_id'] )[ $key ] ?? array() as $lang => $unused ) {
								$this->templates->delete( (int) $r['property_id'], 'email', $key, (string) $lang );
							}
							return $this->list_templates( $r );
						}
						if ( ! NotificationTemplates::editable( $key ) && ! NotificationTemplates::is_custom( $key ) ) {
							return self::unknown_template();
						}
						$this->templates->delete( (int) $r['property_id'], 'email', $key, self::language( $r ) );
						return $this->list_templates( $r );
					},
					'permission_callback' => $settings,
				),
			)
		);
		register_rest_route(
			$ns,
			$base . '/templates/custom',
			array(
				array(
					// Names of the custom messages, for «Invia un'email» on a booking.
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => rest_ensure_response(
						array_map(
							static fn( string $key, array $languages ) => array(
								'key'       => $key,
								'label'     => (string) ( $languages['it']['subject'] ?? reset( $languages )['subject'] ?? $key ),
								'languages' => array_keys( $languages ),
							),
							array_keys( $this->custom( (int) $r['property_id'] ) ),
							$this->custom( (int) $r['property_id'] )
						)
					),
					'permission_callback' => $this->require_cap_for_property( 'bkng_view_bookings' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_custom' ),
					'permission_callback' => $settings,
				),
			)
		);
		register_rest_route(
			$ns,
			$template . '/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( \WP_REST_Request $r ) {
					$subject = $r->get_param( 'subject' );
					$body    = $r->get_param( 'body' );
					// The look being edited in Email → Aspetto, not saved: only the e-mail keys.
					$design = array_filter( (array) $r->get_param( 'design' ), static fn( $k ) => is_string( $k ) && str_starts_with( $k, 'email_' ), ARRAY_FILTER_USE_KEY );
					return self::respond( $this->service->preview( (int) $r['property_id'], (string) $r['key'], null === $subject ? null : sanitize_text_field( (string) $subject ), null === $body ? null : NotificationTemplates::clean_body( (string) $body ), $design ) );
				},
				'permission_callback' => $settings,
			)
		);
	}

	public function log( \WP_REST_Request $r ): \WP_REST_Response {
		$per_page = max( 1, min( 200, (int) ( $r->get_param( 'per_page' ) ?? 50 ) ) );
		$result   = $this->repo->search(
			array( (int) $r['property_id'] ),
			array(
				'booking_id' => (int) $r->get_param( 'booking_id' ),
				'status'     => in_array( $r->get_param( 'status' ), NotificationRepository::STATUSES, true ) ? (string) $r->get_param( 'status' ) : '',
				'channel'    => sanitize_key( (string) $r->get_param( 'channel' ) ),
				'page'       => (int) ( $r->get_param( 'page' ) ?? 1 ),
				'per_page'   => $per_page,
			)
		);
		return self::paged(
			array(
				'items' => array_map( array( $this->service, 'payload' ), $result['items'] ),
				'total' => $result['total'],
			),
			$per_page
		);
	}

	public function list_templates( \WP_REST_Request $r ): \WP_REST_Response {
		$property_id = (int) $r['property_id'];
		$own         = array();
		foreach ( $this->templates->for_property( $property_id ) as $row ) {
			$own[ $row['template_key'] ][ $row['language'] ] = array(
				'subject' => (string) $row['subject'],
				'body'    => (string) $row['body'],
			);
		}
		$tenant = $this->tenant_templates && Tenancy::ready() ? $this->tenant_templates->all( Tenancy::of_property( $property_id ) ) : array();
		$out    = array();
		foreach ( NotificationTemplates::labels() as $key => $label ) {
			if ( ! NotificationTemplates::editable( $key ) ) {
				continue;
			}
			$defaults = NotificationTemplates::defaults( $key );
			$out[]    = array(
				'key'      => $key,
				'label'    => $label,
				'defaults' => array(
					'subject' => $defaults['subject'],
					'body'    => $defaults['intro'],
				),
				'own'      => (object) ( $own[ $key ] ?? array() ),
				// The customer's texts (0.2.5), used where the property has none.
				'tenant'   => (object) ( $tenant[ $key ] ?? array() ),
			);
		}
		$custom = array();
		foreach ( $this->custom( $property_id ) as $key => $languages ) {
			$custom[] = array(
				'key'   => $key,
				'label' => (string) ( $languages['it']['subject'] ?? reset( $languages )['subject'] ?? $key ),
				'own'   => (object) $languages,
			);
		}
		return rest_ensure_response(
			array(
				'templates'    => $out,
				'custom'       => $custom,
				'placeholders' => NotificationTemplates::PLACEHOLDERS,
				'languages'    => self::LANGUAGES,
			)
		);
	}

	/** A new custom message of the property: custom_N, N one more than the highest. */
	public function create_custom( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$subject = mb_substr( sanitize_text_field( (string) $r->get_param( 'subject' ) ), 0, 255 );
		$body    = mb_substr( NotificationTemplates::clean_body( (string) $r->get_param( 'body' ) ), 0, 20000 );
		if ( '' === trim( $subject ) || '' === trim( wp_strip_all_tags( $body ) ) ) {
			return Validation::error(
				array_filter(
					array(
						'subject' => '' === trim( $subject ) ? __( 'Scrivi l\'oggetto: è anche il nome del messaggio.', 'booking-manager' ) : null,
						'body'    => '' === trim( wp_strip_all_tags( $body ) ) ? __( 'Scrivi il testo del messaggio.', 'booking-manager' ) : null,
					)
				)
			);
		}
		$next = 1;
		foreach ( array_keys( $this->custom( (int) $r['property_id'] ) ) as $key ) {
			$next = max( $next, (int) substr( (string) $key, strlen( NotificationTemplates::CUSTOM_PREFIX ) ) + 1 );
		}
		$key = NotificationTemplates::CUSTOM_PREFIX . $next;
		$this->templates->save( (int) $r['property_id'], 'email', $key, self::language( $r ), $subject, $body, get_current_user_id() );
		$response = $this->list_templates( $r );
		$data     = (array) $response->get_data();
		$data['created'] = $key;
		$response->set_data( $data );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * The custom messages of a property.
	 *
	 * @return array<string, array<string, array{subject: string, body: string}>> key => language => texts
	 */
	private function custom( int $property_id ): array {
		$out = array();
		foreach ( $this->templates->for_property( $property_id ) as $row ) {
			if ( 'email' === $row['channel'] && NotificationTemplates::is_custom( (string) $row['template_key'] ) ) {
				$out[ (string) $row['template_key'] ][ (string) $row['language'] ] = array(
					'subject' => (string) $row['subject'],
					'body'    => (string) $row['body'],
				);
			}
		}
		uksort( $out, static fn( string $a, string $b ) => (int) substr( $a, 7 ) <=> (int) substr( $b, 7 ) );
		return $out;
	}

	public function save_template( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$key = (string) $r['key'];
		// A custom message is changed here, created with POST …/templates/custom.
		if ( ! NotificationTemplates::editable( $key ) && ! ( NotificationTemplates::is_custom( $key ) && isset( $this->custom( (int) $r['property_id'] )[ $key ] ) ) ) {
			return self::unknown_template();
		}
		$subject = mb_substr( sanitize_text_field( (string) $r->get_param( 'subject' ) ), 0, 255 );
		$body    = mb_substr( NotificationTemplates::clean_body( (string) $r->get_param( 'body' ) ), 0, 20000 );
		$this->templates->save( (int) $r['property_id'], 'email', $key, self::language( $r ), $subject, $body, get_current_user_id() );
		return $this->list_templates( $r );
	}

	private static function language( \WP_REST_Request $r ): string {
		$lang = sanitize_key( (string) ( $r->get_param( 'language' ) ?? 'it' ) );
		return in_array( $lang, self::LANGUAGES, true ) ? $lang : 'it';
	}

	private static function unknown_template(): \WP_Error {
		return new \WP_Error( 'bkng_unknown_template', __( 'Messaggio sconosciuto.', 'booking-manager' ), array( 'status' => 404 ) );
	}
}
