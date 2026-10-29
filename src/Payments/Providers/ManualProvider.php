<?php
/**
 * Payments taken at the desk (cash, card terminal, bank transfer): recorded by staff, no
 * money moves through the plugin. Refunds are recorded the same way.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments\Providers;

use BookingManager\Payments\Capability;
use BookingManager\Payments\PaymentAccount;
use BookingManager\Payments\RefundResult;
use BookingManager\Payments\TransactionType;

defined( 'ABSPATH' ) || exit;

class ManualProvider extends AbstractProvider {

	public const ID = 'manual';

	public const METHODS = array( 'cash', 'pos', 'bank_transfer', 'other' );

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'In struttura', 'booking-manager' );
	}

	public function capabilities( PaymentAccount $account ): array {
		return array( Capability::REFUNDS, Capability::PARTIAL_REFUNDS );
	}

	public function refund_payment( PaymentAccount $account, array $payment, int $amount_cents, string $idempotency_key, string $reason ): RefundResult {
		return new RefundResult( null, TransactionType::SUCCEEDED, $amount_cents );
	}

	/** Every property can record desk payments, with no configuration. */
	public static function account( int $property_id ): PaymentAccount {
		return new PaymentAccount( 0, $property_id, self::ID, 'live', PaymentAccount::STATUS_ACTIVE, array(), array() );
	}
}
