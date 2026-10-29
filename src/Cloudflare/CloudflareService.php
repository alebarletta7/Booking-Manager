<?php
/**
 * Cloudflare in Impostazioni: how this request reached WordPress, the two recommended
 * rules (to copy, or applied to the zone with an API token) and the connection check.
 * Adapted from Night Manager 0.16.3; the visitor's IP is decided by ClientIp alone.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Cloudflare;

use BookingManager\Audit\AuditLogger;
use BookingManager\Core\ClientIp;
use BookingManager\Core\Clock;
use BookingManager\Payments\PaymentAccountRepository;
use BookingManager\Payments\PaymentAccountService;
use BookingManager\Properties\PropertyRepository;

defined( 'ABSPATH' ) || exit;

class CloudflareService {

	/** Minimum API token permissions (shown in the panel and in docs/CLOUDFLARE.md). */
	public const TOKEN_PERMISSIONS = array(
		'Zona → Zona → Lettura (Zone → Zone → Read)',
		'Zona → WAF della zona → Modifica (Zone → Zone WAF → Edit)',
		'Zona → Regole cache → Modifica (Zone → Cache Rules → Edit)',
	);

	public function __construct(
		private CloudflareSettings $settings,
		private CloudflareClient $client,
		private PropertyRepository $properties,
		private PaymentAccountRepository $accounts,
		private AuditLogger $audit,
		private Clock $clock
	) {}

	/**
	 * Everything the panel shows (never the token).
	 *
	 * @return array<string, mixed>
	 */
	public function view(): array {
		return self::diagnostics() + $this->settings->public_view() + array(
			'rules'             => $this->rules_view(),
			'token_permissions' => self::TOKEN_PERMISSIONS,
		);
	}

	/**
	 * How this request reached WordPress:
	 * - "direct": from a Cloudflare address; the visitor's IP comes from CF-Connecting-IP;
	 * - "restored": through Cloudflare, but the hosting already puts the visitor's IP in
	 *   REMOTE_ADDR (mod_remoteip and the like): nothing to do;
	 * - "proxy": Cloudflare headers, but REMOTE_ADDR is neither Cloudflare nor the visitor:
	 *   another proxy sits in between (or the headers are forged);
	 * - "none": no sign of Cloudflare.
	 * Headers alone only feed this report: the IP is trusted only in the "direct" case.
	 *
	 * @return array{through: bool, mode: string, remote: string, visitor: string, header_ip: string}
	 */
	public static function diagnostics(): array {
		$remote = ClientIp::remote_addr();
		$header = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ) : '';
		$ray    = ! empty( $_SERVER['HTTP_CF_RAY'] ) || '' !== $header;
		if ( '' !== $remote && ClientIp::is_cloudflare( $remote ) ) {
			$mode = 'direct';
		} elseif ( $ray && '' !== $header && $header === $remote ) {
			$mode = 'restored';
		} elseif ( $ray ) {
			$mode = 'proxy';
		} else {
			$mode = 'none';
		}
		return array(
			'through'   => 'none' !== $mode,
			'mode'      => $mode,
			'remote'    => $remote,
			'visitor'   => ClientIp::current(),
			'header_ip' => false === filter_var( $header, FILTER_VALIDATE_IP ) ? '' : $header,
		);
	}

	/**
	 * The recommended rules, ready to copy for those who prefer to create them by hand.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function rules_view(): array {
		$how = array(
			Rules::PHASE_WAF   => array(
				'kind'   => 'waf',
				'action' => __( 'Sicurezza → WAF → Regole personalizzate: azione «Salta», in prima posizione. Spunta tutti i componenti da ignorare tranne «All remaining custom rules in this phase» (Cloudflare non lo accetta insieme a «Tutte le restanti regole personalizzate»).', 'booking-manager' ),
			),
			Rules::PHASE_CACHE => array(
				'kind'   => 'cache',
				'action' => __( 'Caching → Regole cache: «Ignora la cache», in ultima posizione.', 'booking-manager' ),
			),
		);
		$out = array();
		foreach ( Rules::recommended( $this->webhook_urls() ) as $phase => $item ) {
			$out[] = array(
				'kind'       => $how[ $phase ]['kind'],
				'title'      => $item['rule']['description'],
				'action'     => $how[ $phase ]['action'],
				'expression' => $item['rule']['expression'],
				'json'       => (string) wp_json_encode( $item['rule'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			);
		}
		return $out;
	}

	/**
	 * The webhook address of every payment account (archived properties too: the
	 * provider may still call it).
	 *
	 * @return string[]
	 */
	public function webhook_urls(): array {
		$urls = array();
		foreach ( $this->properties->all() as $property ) {
			foreach ( $this->accounts->rows_for( (int) $property['id'] ) as $row ) {
				$account = $this->accounts->find( (int) $row['id'] );
				if ( $account ) {
					$urls[] = PaymentAccountService::webhook_url( $account );
				}
			}
		}
		return $urls;
	}

	/**
	 * "Verifica collegamento": the token is active and can read the zone.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function verify(): array|\WP_Error {
		$zone = $this->client->zone();
		if ( $zone instanceof \WP_Error ) {
			return $zone;
		}
		// Only user-owned tokens answer on /user/tokens/verify: a failure here is not fatal.
		$token = $this->client->verify_token();
		$name  = (string) ( $zone['name'] ?? '' );
		$host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return array(
			'ok'           => true,
			'token_status' => is_array( $token ) ? (string) ( $token['status'] ?? '' ) : null,
			'zone'         => array(
				'name'   => $name,
				'status' => (string) ( $zone['status'] ?? '' ),
				'plan'   => (string) ( $zone['plan']['name'] ?? '' ),
				'paused' => ! empty( $zone['paused'] ),
			),
			'host'         => $host,
			'host_in_zone' => self::host_in_zone( $host, $name ),
		);
	}

	/** True when the site's host belongs to the zone (unit-tested). */
	public static function host_in_zone( string $host, string $zone ): bool {
		$host = strtolower( rtrim( $host, '.' ) );
		$zone = strtolower( rtrim( $zone, '.' ) );
		return '' !== $zone && ( $host === $zone || str_ends_with( $host, '.' . $zone ) );
	}

	/**
	 * "Applica regole consigliate": creates or updates our two rules in the zone, never
	 * touching the others.
	 *
	 * @return array<string, mixed>|\WP_Error {phases: {phase: {created|updated|unchanged|deleted: int}}, applied_at}
	 */
	public function apply_rules(): array|\WP_Error {
		$summary = array();
		$failure = null;
		foreach ( Rules::recommended( $this->webhook_urls() ) as $phase => $item ) {
			$result = $this->apply_phase( $phase, $item['rule'], $item['placement'] );
			if ( $result instanceof \WP_Error ) {
				$failure = $result;
				break;
			}
			$summary[ $phase ] = $result;
		}
		$this->audit->log(
			array(
				'action'      => 'cloudflare.rules_applied',
				'entity_type' => 'settings',
				'new'         => array(
					'zone_id' => $this->settings->zone_id(),
					'phases'  => $summary,
					'error'   => $failure ? $failure->get_error_message() : null,
				),
			)
		);
		if ( $failure ) {
			return $failure;
		}
		$when = $this->clock->now_utc()->format( 'c' );
		$this->settings->mark_applied( $when );
		return array(
			'phases'     => $summary,
			'applied_at' => $when,
		);
	}

	/**
	 * @param array<string, mixed> $rule
	 * @return array<string, int>|\WP_Error
	 */
	private function apply_phase( string $phase, array $rule, string $placement ): array|\WP_Error {
		$counts = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'deleted'   => 0,
		);
		$entry = $this->client->entrypoint( $phase );
		if ( $entry instanceof \WP_Error ) {
			return $entry;
		}
		if ( null === $entry ) {
			$created = $this->client->create_entrypoint( $phase, array( $rule ) );
			if ( $created instanceof \WP_Error ) {
				return $created;
			}
			++$counts['created'];
			return $counts;
		}
		$ruleset = (string) ( $entry['id'] ?? '' );
		$rules   = is_array( $entry['rules'] ?? null ) ? $entry['rules'] : array();
		foreach ( Rules::plan( $rules, $rule, $placement ) as $op ) {
			switch ( $op['op'] ) {
				case 'delete':
					$result = $this->client->delete_rule( $ruleset, $op['id'] );
					$key    = 'deleted';
					break;
				case 'update':
					$result = $this->client->update_rule( $ruleset, $op['id'], $op['body'] );
					$key    = 'updated';
					break;
				case 'create':
					$result = $this->client->add_rule( $ruleset, $op['body'] );
					$key    = 'created';
					break;
				default:
					$result = array();
					$key    = 'unchanged';
			}
			if ( $result instanceof \WP_Error ) {
				return $result;
			}
			++$counts[ $key ];
		}
		return $counts;
	}
}
