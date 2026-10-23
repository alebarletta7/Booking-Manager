<?php
/**
 * Outcome of one attempt to send a notification through a channel.
 *
 * - sent: accepted by the channel (for e-mail: handed to the mail system by wp_mail();
 *   whether the inbox received it is not known to WordPress);
 * - retry: a passing error (SMTP down, provider timeout): tried again later;
 * - failed: will not work by retrying (bad address, rejected template);
 * - skipped: not sent on purpose (channel not configured, no consent, booking changed);
 *   never counted as a delivery.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Notifications;

defined( 'ABSPATH' ) || exit;

final class SendResult {

	public const SENT    = 'sent';
	public const RETRY   = 'retry';
	public const FAILED  = 'failed';
	public const SKIPPED = 'skipped';

	private function __construct(
		public readonly string $outcome,
		public readonly string $id = '',
		public readonly string $error = ''
	) {}

	public static function sent( string $id = '' ): self {
		return new self( self::SENT, $id );
	}

	public static function retry( string $error ): self {
		return new self( self::RETRY, '', $error );
	}

	public static function failed( string $error ): self {
		return new self( self::FAILED, '', $error );
	}

	public static function skipped( string $reason ): self {
		return new self( self::SKIPPED, '', $reason );
	}
}
