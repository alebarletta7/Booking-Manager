<?php
/**
 * The Cloudflare rules Booking Manager recommends, and how to put them in a zone
 * without touching anyone else's rules (pure, unit-tested). Copied from Night Manager
 * 0.16.3; the expressions are Booking Manager's own.
 *
 * Our rules are recognised by their description, which always starts with PREFIX.
 * Applying them again updates ours in place, removes our duplicates and leaves every
 * other rule of the zone exactly as it is: the plan only ever names our rule ids.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Cloudflare;

defined( 'ABSPATH' ) || exit;

final class Rules {

	public const PREFIX = 'Booking Manager:';

	public const PHASE_WAF   = 'http_request_firewall_custom';
	public const PHASE_CACHE = 'http_request_cache_settings';

	public const WAF_DESCRIPTION   = 'Booking Manager: notifiche di pagamento senza controlli anti-bot';
	public const CACHE_DESCRIPTION = 'Booking Manager: nessuna cache per prenotazioni e API';

	/** Phases skipped for the webhook (custom-rule skip options). */
	public const SKIP_PHASES = array( 'http_ratelimit', 'http_request_firewall_managed', 'http_request_sbfm' );

	/** Security products skipped for the webhook: Zone Lockdown, UA Blocking, Browser Integrity Check, Hotlink, Security Level. */
	public const SKIP_PRODUCTS = array( 'zoneLockdown', 'uaBlock', 'bic', 'hot', 'securityLevel' );

	/** A string literal of Cloudflare's rules language. */
	public static function quote( string $text ): string {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $text ) . '"';
	}

	/**
	 * POST to the exact address of a payment webhook (one per connected Stripe or Nexi
	 * account), with a JSON body.
	 *
	 * WordPress lets a "rest_route" query (or form) argument pick the REST route on any
	 * URL. A rule where the query merely contains the webhook route would let anyone reach
	 * every other route (the booking form included) with Cloudflare's protections skipped,
	 * by adding the webhook route as a second argument. So path and whole query must be
	 * exactly a webhook's, and the body must be JSON, which PHP never reads as form fields
	 * (Stripe and Nexi send application/json). The plugin checks every notification with
	 * the provider anyway (signature or order lookup).
	 *
	 * @param string[] $webhook_urls PaymentAccountService::webhook_url() of each account.
	 */
	public static function webhook_expression( array $webhook_urls ): string {
		$targets = array();
		foreach ( $webhook_urls as $url ) {
			$path  = (string) ( parse_url( $url, PHP_URL_PATH ) ?: '/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure, no WordPress.
			$query = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$targets[ $path . '?' . $query ] = '(http.request.uri.path eq ' . self::quote( $path ) . ' and http.request.uri.query eq ' . self::quote( $query ) . ')';
		}
		if ( ! $targets ) {
			// No payment account yet: a rule that matches nothing, ready to be updated.
			$targets[] = '(http.request.uri.path eq ' . self::quote( '/booking-manager-no-webhook' ) . ')';
		}
		$json = 'starts_with(http.request.headers["content-type"][0], "application/json")';
		return '(http.request.method eq "POST" and ' . $json . ' and (' . implode( ' or ', array_values( $targets ) ) . '))';
	}

	/**
	 * Every REST route of the plugin, through ?rest_route= (the addresses it gives out,
	 * see PublicController::url) or through /wp-json/ (whatever the subdirectory).
	 */
	public static function cache_expression(): string {
		$parts = array(
			'http.request.uri.query contains ' . self::quote( 'rest_route=/booking-manager/' ),
			'http.request.uri.query contains ' . self::quote( 'rest_route=%2Fbooking-manager%2F' ),
			'http.request.uri.path contains ' . self::quote( '/wp-json/booking-manager/' ),
		);
		return '(' . implode( ' or ', $parts ) . ')';
	}

	/**
	 * The custom WAF rule letting the payment providers' notifications through.
	 *
	 * @param string[] $webhook_urls
	 * @return array<string, mixed>
	 */
	public static function waf_rule( array $webhook_urls ): array {
		return array(
			'description'       => self::WAF_DESCRIPTION,
			'expression'        => self::webhook_expression( $webhook_urls ),
			'action'            => 'skip',
			'action_parameters' => array(
				'ruleset'  => 'current',
				'phases'   => self::SKIP_PHASES,
				'products' => self::SKIP_PRODUCTS,
			),
			'logging'           => array( 'enabled' => true ),
			'enabled'           => true,
		);
	}

	/** @return array<string, mixed> The cache rule: never cache the plugin's pages and API. */
	public static function cache_rule(): array {
		return array(
			'description'       => self::CACHE_DESCRIPTION,
			'expression'        => self::cache_expression(),
			'action'            => 'set_cache_settings',
			'action_parameters' => array( 'cache' => false ),
			'enabled'           => true,
		);
	}

	/**
	 * Both recommended rules by phase, with where they belong: the skip rule first (it
	 * skips only what comes after it), the bypass rule last (later cache rules win).
	 *
	 * @param string[] $webhook_urls
	 * @return array<string, array{rule: array<string, mixed>, placement: string}>
	 */
	public static function recommended( array $webhook_urls ): array {
		return array(
			self::PHASE_WAF   => array(
				'rule'      => self::waf_rule( $webhook_urls ),
				'placement' => 'first',
			),
			self::PHASE_CACHE => array(
				'rule'      => self::cache_rule(),
				'placement' => 'last',
			),
		);
	}

	/** @param array<string, mixed> $rule */
	public static function is_ours( array $rule ): bool {
		return str_starts_with( (string) ( $rule['description'] ?? '' ), self::PREFIX );
	}

	/**
	 * What to do in an existing ruleset so it holds exactly one copy of $desired, in
	 * the right place. Other rules are never named.
	 *
	 * @param array<int, array<string, mixed>> $existing  The ruleset's rules, in order.
	 * @param array<string, mixed>             $desired
	 * @param string                           $placement "first" or "last".
	 * @return array<int, array{op: string, id?: string, body?: array<string, mixed>}>
	 *         op: delete | update | create | unchanged; deletions come first.
	 */
	public static function plan( array $existing, array $desired, string $placement ): array {
		$ours = array();
		foreach ( $existing as $rule ) {
			if ( is_array( $rule ) && self::is_ours( $rule ) && '' !== (string) ( $rule['id'] ?? '' ) && ( $rule['description'] ?? '' ) === $desired['description'] ) {
				$ours[] = $rule;
			}
		}
		$ops = array();
		foreach ( array_slice( $ours, 1 ) as $duplicate ) {
			$ops[] = array(
				'op' => 'delete',
				'id' => (string) $duplicate['id'],
			);
		}
		$position = 'first' === $placement ? array( 'before' => '' ) : array( 'after' => '' );
		if ( ! $ours ) {
			$ops[] = array(
				'op'   => 'create',
				'body' => $desired + array( 'position' => $position ),
			);
			return $ops;
		}
		$keep = $ours[0];
		// Where ours sits once the duplicates are gone.
		$remaining = array_values(
			array_filter(
				$existing,
				fn( $r ) => is_array( $r ) && ( ! self::is_ours( $r ) || ( $r['id'] ?? null ) === $keep['id'] || ( $r['description'] ?? '' ) !== $desired['description'] )
			)
		);
		$index    = array_search( $keep['id'], array_map( fn( $r ) => $r['id'] ?? null, $remaining ), true );
		$in_place = 'first' === $placement ? 0 === $index : count( $remaining ) - 1 === $index;
		if ( $in_place && self::same( $keep, $desired ) ) {
			$ops[] = array(
				'op' => 'unchanged',
				'id' => (string) $keep['id'],
			);
			return $ops;
		}
		$ops[] = array(
			'op'   => 'update',
			'id'   => (string) $keep['id'],
			'body' => $desired + ( $in_place ? array() : array( 'position' => $position ) ),
		);
		return $ops;
	}

	/**
	 * True when an existing rule already has every field of the desired one.
	 *
	 * @param array<string, mixed> $existing
	 * @param array<string, mixed> $desired
	 */
	public static function same( array $existing, array $desired ): bool {
		foreach ( $desired as $key => $value ) {
			if ( ! array_key_exists( $key, $existing ) || self::canonical( $existing[ $key ] ) !== self::canonical( $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Applies a plan to a list of rules, as Cloudflare would (used by the tests and to
	 * preview the result; the real changes are made one rule at a time).
	 *
	 * @param array<int, array<string, mixed>> $existing
	 * @param array<int, array<string, mixed>> $ops
	 * @return array<int, array<string, mixed>>
	 */
	public static function simulate( array $existing, array $ops, string $new_id = 'new' ): array {
		$rules = array_values( $existing );
		foreach ( $ops as $op ) {
			$id = $op['id'] ?? $new_id;
			if ( 'delete' === $op['op'] ) {
				$rules = array_values( array_filter( $rules, fn( $r ) => ( $r['id'] ?? null ) !== $id ) );
				continue;
			}
			if ( 'unchanged' === $op['op'] ) {
				continue;
			}
			$body     = $op['body'];
			$position = $body['position'] ?? null;
			unset( $body['position'] );
			$rule = array( 'id' => $id ) + $body;
			$at   = null;
			foreach ( $rules as $i => $r ) {
				if ( ( $r['id'] ?? null ) === $id ) {
					$at = $i;
				}
			}
			if ( null !== $at && null === $position ) {
				$rules[ $at ] = $rule;
				continue;
			}
			if ( null !== $at ) {
				array_splice( $rules, $at, 1 );
			}
			if ( isset( $position['before'] ) ) {
				array_unshift( $rules, $rule );
			} else {
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * Associative arrays with sorted keys, lists as they are.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( self::class, 'canonical' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return $value;
	}
}
