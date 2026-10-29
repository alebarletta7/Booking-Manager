<?php
/**
 * A feature module: registers its services and WordPress hooks.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

defined( 'ABSPATH' ) || exit;

interface ModuleInterface {

	/** Register services in the container (no side effects). */
	public function services( Container $c ): void;

	/** Attach WordPress hooks. */
	public function hooks( Container $c ): void;
}
