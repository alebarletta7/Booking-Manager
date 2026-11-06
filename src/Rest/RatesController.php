<?php
/**
 * Rates and stay rules (bkng_manage_rates; reading with bkng_access):
 *
 *   /properties/{property_id}/rate-plans      GET, POST      /rate-plans/{id}     PUT   /rate-plans/{id}/archive POST
 *   /properties/{property_id}/prices          GET, POST      /prices/{id}         PUT, DELETE
 *   /properties/{property_id}/adjustments     GET, POST      /adjustments/{id}    PUT   /adjustments/{id}/archive POST
 *   /properties/{property_id}/restrictions    GET, POST      /restrictions/{id}   PUT, DELETE
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Rest;

use BookingManager\Access\Policy;
use BookingManager\Availability\RestrictionRepository;
use BookingManager\Core\Validation;
use BookingManager\Rates\RateRepository;
use BookingManager\Rates\RateService;

defined( 'ABSPATH' ) || exit;

class RatesController extends RestController {

	public function __construct( Policy $policy, private RateService $service, private RateRepository $repo, private RestrictionRepository $restrictions ) {
		parent::__construct( $policy );
	}

	public function register_routes(): void {
		$read   = $this->require_cap_for_property( 'bkng_access' );
		$write  = $this->require_cap_for_property( 'bkng_manage_rates' );
		$plan   = $this->require_cap_for_record( 'bkng_manage_rates', fn( int $id ) => $this->repo->find_plan( $id ) );
		$price  = $this->require_cap_for_record( 'bkng_manage_rates', fn( int $id ) => $this->repo->find_price( $id ) );
		$adjust = $this->require_cap_for_record( 'bkng_manage_rates', fn( int $id ) => $this->repo->find_adjustment( $id ) );
		$rule   = $this->require_cap_for_record( 'bkng_manage_rates', fn( int $id ) => $this->restrictions->find( $id ) );

		$this->collection(
			'rate-plans',
			$read,
			$write,
			fn( \WP_REST_Request $r ) => $this->repo->plans( (int) $r['property_id'], rest_sanitize_boolean( $r['include_archived'] ) ),
			fn( \WP_REST_Request $r ) => $this->service->create_plan( (int) $r['property_id'], (array) $r->get_json_params() )
		);
		$this->item( 'rate-plans', \WP_REST_Server::EDITABLE, $plan, fn( \WP_REST_Request $r ) => $this->service->update_plan( (int) $r['id'], (array) $r->get_json_params() ) );
		$this->item( 'rate-plans', \WP_REST_Server::CREATABLE, $plan, fn( \WP_REST_Request $r ) => $this->service->archive_plan( (int) $r['id'] ), '/archive' );

		$this->collection(
			'prices',
			$read,
			$write,
			fn( \WP_REST_Request $r ) => $this->repo->prices( (int) $r['property_id'] ),
			fn( \WP_REST_Request $r ) => $this->service->save_price( (int) $r['property_id'], (array) $r->get_json_params() )
		);
		$this->item(
			'prices',
			\WP_REST_Server::EDITABLE,
			$price,
			function ( \WP_REST_Request $r ) {
				$row = (array) $this->repo->find_price( (int) $r['id'] );
				return $this->service->save_price( (int) $row['property_id'], (array) $r->get_json_params(), (int) $r['id'] );
			}
		);
		$this->item( 'prices', \WP_REST_Server::DELETABLE, $price, fn( \WP_REST_Request $r ) => $this->service->delete_price( (int) $r['id'] ) );

		$this->collection(
			'adjustments',
			$read,
			$write,
			fn( \WP_REST_Request $r ) => $this->repo->adjustments( (int) $r['property_id'], rest_sanitize_boolean( $r['include_archived'] ) ),
			fn( \WP_REST_Request $r ) => $this->service->save_adjustment( (int) $r['property_id'], (array) $r->get_json_params() )
		);
		$this->item(
			'adjustments',
			\WP_REST_Server::EDITABLE,
			$adjust,
			function ( \WP_REST_Request $r ) {
				$row = (array) $this->repo->find_adjustment( (int) $r['id'] );
				return $this->service->save_adjustment( (int) $row['property_id'], (array) $r->get_json_params(), (int) $r['id'] );
			}
		);
		$this->item( 'adjustments', \WP_REST_Server::CREATABLE, $adjust, fn( \WP_REST_Request $r ) => $this->service->archive_adjustment( (int) $r['id'] ), '/archive' );

		$this->collection(
			'restrictions',
			$read,
			$write,
			function ( \WP_REST_Request $r ) {
				$from = Validation::date( $r['from'] ) ?? '1970-01-01';
				$to   = Validation::date( $r['to'] ) ?? '2999-12-31';
				return $this->restrictions->between( (int) $r['property_id'], $from, $to );
			},
			fn( \WP_REST_Request $r ) => $this->service->save_restriction( (int) $r['property_id'], (array) $r->get_json_params(), get_current_user_id() )
		);
		$this->item(
			'restrictions',
			\WP_REST_Server::EDITABLE,
			$rule,
			function ( \WP_REST_Request $r ) {
				$row = (array) $this->restrictions->find( (int) $r['id'] );
				return $this->service->save_restriction( (int) $row['property_id'], (array) $r->get_json_params(), get_current_user_id(), (int) $r['id'] );
			}
		);
		$this->item( 'restrictions', \WP_REST_Server::DELETABLE, $rule, fn( \WP_REST_Request $r ) => $this->service->delete_restriction( (int) $r['id'] ) );
	}

	private function collection( string $name, callable $read, callable $write, callable $list, callable $create ): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/properties/(?P<property_id>\d+)/' . $name,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( \WP_REST_Request $r ) => rest_ensure_response( $list( $r ) ),
					'permission_callback' => $read,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( \WP_REST_Request $r ) => self::respond( $create( $r ), 201 ),
					'permission_callback' => $write,
				),
			)
		);
	}

	private function item( string $name, string $methods, callable $permission, callable $callback, string $suffix = '' ): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . $name . '/(?P<id>\d+)' . $suffix,
			array(
				'methods'             => $methods,
				'callback'            => fn( \WP_REST_Request $r ) => self::respond( $callback( $r ) ),
				'permission_callback' => $permission,
			)
		);
	}
}
