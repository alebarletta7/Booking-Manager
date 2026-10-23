<?php
/**
 * The payment providers available in this installation.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Payments;

use BookingManager\Payments\Providers\PaymentProvider;

defined( 'ABSPATH' ) || exit;

class ProviderRegistry {

	/** @var array<string, PaymentProvider>|null */
	private ?array $providers = null;

	/** @param PaymentProvider[] $builtin */
	public function __construct( private array $builtin ) {}

	/** @return array<string, PaymentProvider> */
	public function all(): array {
		if ( null === $this->providers ) {
			/**
			 * Payment providers. Add-ons may add their own PaymentProvider implementation.
			 *
			 * @param PaymentProvider[] $providers
			 */
			$list            = apply_filters( 'bkng_payment_providers', $this->builtin );
			$this->providers = array();
			foreach ( $list as $provider ) {
				if ( $provider instanceof PaymentProvider ) {
					$this->providers[ $provider->id() ] = $provider;
				}
			}
		}
		return $this->providers;
	}

	public function get( string $id ): ?PaymentProvider {
		return $this->all()[ $id ] ?? null;
	}

	/** @return array<string, PaymentProvider> providers a property can choose for online payments */
	public function online(): array {
		return array_filter( $this->all(), fn( PaymentProvider $p ) => Providers\ManualProvider::ID !== $p->id() );
	}

	/** Forget the cached list (tests, add-ons registered late). */
	public function reset(): void {
		$this->providers = null;
	}
}
