<?php
/**
 * Base class for REST controllers.
 *
 * Controllers contain no business logic: they validate input, authorize through the
 * Policy and call services. Every route MUST declare a permission_callback.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;

defined( 'ABSPATH' ) || exit;

abstract class RestController {

	public const REST_NAMESPACE = 'booking-manager/v1';

	public function __construct( protected Policy $policy ) {}

	abstract public function register_routes(): void;

	/**
	 * permission_callback factory: capability only.
	 */
	protected function require_cap( string $cap ): callable {
		return function () use ( $cap ) {
			return $this->policy->authorize( $cap );
		};
	}

	/**
	 * permission_callback factory: capability + access to the property given in a request param.
	 */
	protected function require_cap_for_property( string $cap, string $param = 'property_id' ): callable {
		return function ( \WP_REST_Request $request ) use ( $cap, $param ) {
			// Authenticate and check the capability first: anonymous callers learn nothing,
			// not even which parameters a route expects.
			$basic = $this->policy->authorize( $cap );
			if ( true !== $basic ) {
				return $basic;
			}
			$property_id = (int) $request->get_param( $param );
			if ( $property_id <= 0 ) {
				return new \WP_Error( 'rest_missing_property', __( 'Struttura non specificata.', 'booking-manager' ), array( 'status' => 400 ) );
			}
			return $this->policy->authorize( $cap, $property_id );
		};
	}

	/**
	 * permission_callback factory for routes on an existing record ("/events/{id}"):
	 * capability + access to the property OF THE RECORD, never to a property the caller passes.
	 * A record of a property the caller cannot see answers 404, like a missing one.
	 *
	 * @param callable(int): (array<string, mixed>|null) $load Returns the row (with property_id) or null.
	 */
	protected function require_cap_for_record( string $cap, callable $load, string $param = 'id' ): callable {
		return function ( \WP_REST_Request $request ) use ( $cap, $load, $param ) {
			$basic = $this->policy->authorize( $cap );
			if ( true !== $basic ) {
				return $basic;
			}
			$row = $load( (int) $request->get_param( $param ) );
			if ( ! $row || true !== $this->policy->authorize( $cap, (int) $row['property_id'] ) ) {
				return new \WP_Error( 'bkng_not_found', __( 'Elemento non trovato.', 'booking-manager' ), array( 'status' => 404 ) );
			}
			return true;
		};
	}

	/** Either of two permission checks (the first error is returned when both fail). */
	protected function require_any( callable $first, callable $second ): callable {
		return function ( \WP_REST_Request $request ) use ( $first, $second ) {
			$ok = $first( $request );
			return true === $ok || true === $second( $request ) ? true : $ok;
		};
	}

	/**
	 * Turns a service result into a REST response (WP_Error passes through).
	 *
	 * @param mixed $result
	 */
	protected static function respond( $result, int $status = 200 ): \WP_REST_Response|\WP_Error {
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$response = rest_ensure_response( true === $result ? array( 'deleted' => true ) : $result );
		$response->set_status( $status );
		return $response;
	}

	/** @return array<string, mixed> */
	protected static function property_arg(): array {
		// Not "required": WordPress would check it before permissions. Presence is
		// enforced in require_cap_for_property(), after authentication.
		return array(
			'type'              => 'integer',
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * A page of a list with WordPress' usual pagination headers.
	 *
	 * @param array{items: array<int, mixed>, total: int} $result
	 */
	protected static function paged( array $result, int $per_page ): \WP_REST_Response {
		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / max( 1, $per_page ) ) ) );
		return $response;
	}
}
