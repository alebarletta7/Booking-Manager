<?php
/**
 * Rolls back the transaction of a Channex revision and carries its error out.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Channels\Channex;

defined( 'ABSPATH' ) || exit;

final class ChannexAbort extends \RuntimeException {

	public function __construct( public readonly \WP_Error $error ) {
		parent::__construct( $error->get_error_message() );
	}
}
