<?php
/**
 * Operations recorded in the payment ledger (payment_transactions). A payment is the
 * intention to collect an amount; each operation on it is a separate, append-only row.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

defined( 'ABSPATH' ) || exit;

final class TransactionType {

	public const AUTHORIZATION  = 'AUTHORIZATION';
	public const CAPTURE        = 'CAPTURE';
	public const REFUND         = 'REFUND';
	public const PARTIAL_REFUND = 'PARTIAL_REFUND';
	public const CANCELLATION   = 'CANCELLATION';
	public const PAYMENT_FAILED = 'PAYMENT_FAILED';

	public const REFUNDS = array( self::REFUND, self::PARTIAL_REFUND );

	// Status of a single operation.
	public const PENDING   = 'pending';
	public const SUCCEEDED = 'succeeded';
	public const FAILED    = 'failed';
	public const CANCELED  = 'canceled';
}
