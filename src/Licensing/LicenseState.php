<?php
/**
 * The licence as the plugin knows it right now: built from the last signed authorization
 * and the clock, never from anything unsigned.
 *
 * - offline_grace: the last check failed (server unreachable, or answers that do not
 *   check out), the last authorization was a good one and now ≤ its valid_until (never
 *   less than 7 days after it was signed) + 24 hours of margin;
 * - unverified: same, past that limit. It does not mean expired.
 * - a lifetime licence never becomes unverified: with the server down it stays usable for
 *   good (grace_until null), so a property keeps working even if the licence server is gone.
 * - an expired, suspended or revoked authorization stays what it is while the server is
 *   unreachable: an outage never improves a licence.
 * - site_changed: the server said so (signed), or the stored activation is for another
 *   domain (the database was copied to another address).
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Licensing;

defined( 'ABSPATH' ) || exit;

final class LicenseState {

	/** The plugin never applies less than this, whatever the server says. */
	public const MIN_GRACE_DAYS = 7;

	public const DAY = 86400;

	/** For a site that had no visits to run the check in time. */
	public const MARGIN = self::DAY;

	/**
	 * @param array{read: bool, write: bool, update: bool} $access
	 * @param int|null                                     $grace_until Unix time
	 */
	public function __construct(
		public readonly string $status,
		public readonly ?\stdClass $auth,
		public readonly array $access,
		public readonly ?int $grace_until
	) {}

	/** The state of a copy without the licence server's keys: nothing is blocked. */
	public static function not_configured(): self {
		return new self( AccessMatrix::NOT_CONFIGURED, null, AccessMatrix::access( AccessMatrix::NOT_CONFIGURED, null ), null );
	}

	/**
	 * @param \stdClass|null $auth         a verified authorization (ok: true), or null
	 * @param string         $signed_error site_changed | recovery_required | ''
	 * @param bool           $failing      the last check did not end with a signed answer
	 * @param string         $site_domain  this site's domain, as the server normalizes it
	 */
	public static function from( ?\stdClass $auth, string $signed_error, bool $failing, int $now, string $site_domain ): self {
		if ( null === $auth ) {
			$status = in_array( $signed_error, array( AccessMatrix::SITE_CHANGED, AccessMatrix::RECOVERY_REQUIRED ), true ) ? $signed_error : AccessMatrix::INACTIVE;
			return new self( $status, null, AccessMatrix::access( $status, null ), null );
		}

		$server = array(
			'read'   => (bool) ( $auth->access->read ?? false ),
			'write'  => (bool) ( $auth->access->write ?? false ),
			'update' => (bool) ( $auth->access->update ?? false ),
		);
		$sent   = is_string( $auth->license_status ?? null ) ? $auth->license_status : '';
		$status = AccessMatrix::is_server_status( $sent ) ? $sent : AccessMatrix::INVALID;
		$until  = self::grace_end( $auth );
		$domain = is_string( $auth->activation->domain ?? null ) ? $auth->activation->domain : '';

		if ( in_array( $signed_error, array( AccessMatrix::SITE_CHANGED, AccessMatrix::RECOVERY_REQUIRED ), true ) ) {
			$status = $signed_error;
		} elseif ( '' !== $domain && '' !== $site_domain && $domain !== $site_domain ) {
			$status = AccessMatrix::SITE_CHANGED; // The database was copied to another address.
		} elseif ( AccessMatrix::LIFETIME === $status ) {
			// No end offline: with the server down it stays usable for good.
			$status = $failing ? AccessMatrix::OFFLINE_GRACE : $status;
			$until  = null;
		} elseif ( $failing && AccessMatrix::is_good( $status ) ) {
			$status = null !== $until && $now <= $until ? AccessMatrix::OFFLINE_GRACE : AccessMatrix::UNVERIFIED;
		}
		return new self( $status, $auth, AccessMatrix::access( $status, $server ), $until );
	}

	/** valid_until, never earlier than server_time + 7 days, plus the margin. */
	public static function grace_end( \stdClass $auth ): ?int {
		$signed = is_string( $auth->server_time ?? null ) ? strtotime( $auth->server_time ) : false;
		if ( false === $signed ) {
			return null;
		}
		$valid = is_string( $auth->valid_until ?? null ) ? strtotime( $auth->valid_until ) : false;
		$valid = false === $valid ? $signed : $valid;
		return max( $valid, $signed + self::MIN_GRACE_DAYS * self::DAY ) + self::MARGIN;
	}

	/** The domain as the server writes it: lower case, no www., no port. */
	public static function domain_of( string $url ): string {
		$host = rtrim( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), '.' );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/** Hours between two scheduled checks: the server's revalidate_hours, 1 to 24, else 12. */
	public static function revalidate_hours( ?\stdClass $auth ): int {
		$hours = $auth->policy->revalidate_hours ?? null;
		return is_int( $hours ) ? max( 1, min( 24, $hours ) ) : 12;
	}
}
