<?php
/**
 * WordPress side of the separation between customers (tenants):
 *
 * - staff linked to a customer: a user who receives a Booking Manager role outside the
 *   Staff screen (WordPress Users screen, WP-CLI) joins the only customer when the
 *   platform has just one (a single site works as before); with several customers the
 *   platform administrator links them explicitly;
 * - media library: files uploaded by a customer's staff are marked with the customer,
 *   the media picker of a customer's staff lists only that customer's files (the files
 *   from before 0.2.0, without a mark, belong to the default customer), and
 *   allows_attachment() refuses IDs of other customers when settings and rooms are saved.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

use BookingManager\Access\Capabilities;
use BookingManager\Access\PropertyScope;

defined( 'ABSPATH' ) || exit;

class TenantAccess {

	/** Post meta of an attachment uploaded by a customer's staff. */
	public const ATTACHMENT_META = '_bkng_tenant_id';

	public function __construct( private TenantRepository $tenants, private PropertyScope $scope ) {}

	public function hooks(): void {
		add_action( 'set_user_role', array( $this, 'on_role' ), 10, 2 );
		add_action( 'add_user_role', array( $this, 'on_role' ), 10, 2 );
		add_action( 'add_attachment', array( $this, 'mark_attachment' ) );
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_media' ) );
	}

	/**
	 * @param int|string $user_id
	 * @param mixed      $role
	 */
	public function on_role( $user_id, $role ): void {
		$user_id = (int) $user_id;
		if ( ! is_string( $role ) || ! array_key_exists( $role, Capabilities::role_names() ) || ! Tenancy::migrated() ) {
			return;
		}
		if ( user_can( $user_id, 'manage_options' ) || $this->tenants->tenant_of_user( $user_id ) > 0 ) {
			return;
		}
		$all = $this->tenants->all();
		if ( 1 === count( $all ) ) {
			$this->tenants->add_user( (int) $all[0]['id'], $user_id, get_current_user_id() ?: null );
			$this->scope->flush();
		}
	}

	/** @param int|string $attachment_id */
	public function mark_attachment( $attachment_id ): void {
		$tenant = $this->scope->tenant_of( get_current_user_id() );
		if ( $tenant > 0 && ! $this->scope->is_platform( get_current_user_id() ) ) {
			update_post_meta( (int) $attachment_id, self::ATTACHMENT_META, $tenant );
		}
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	public function filter_media( $query ): array {
		$query   = is_array( $query ) ? $query : array();
		$user_id = get_current_user_id();
		if ( ! Tenancy::migrated() || $this->scope->is_platform( $user_id ) ) {
			return $query;
		}
		$tenant = $this->scope->tenant_of( $user_id );
		if ( $tenant <= 0 ) {
			$query['post__in'] = array( 0 );
			return $query;
		}
		$mine = array(
			'key'   => self::ATTACHMENT_META,
			'value' => (string) $tenant,
		);
		$meta = Tenancy::default_id() === $tenant
			? array(
				'relation' => 'OR',
				$mine,
				array(
					'key'     => self::ATTACHMENT_META,
					'compare' => 'NOT EXISTS',
				),
			)
			: array( $mine );
		$query['meta_query'] = isset( $query['meta_query'] ) && is_array( $query['meta_query'] )
			? array(
				'relation' => 'AND',
				$query['meta_query'],
				$meta,
			)
			: $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- media picker only.
		return $query;
	}

	/**
	 * May an attachment be used by a customer (cover, logo, banner, room photos)?
	 * Its mark must be the customer's; files without a mark belong to the default customer.
	 * Before the data migration everything is allowed, as before.
	 */
	public static function allows_attachment( int $attachment_id, int $tenant_id ): bool {
		if ( $attachment_id <= 0 || ! Tenancy::migrated() ) {
			return true;
		}
		$mark = (int) get_post_meta( $attachment_id, self::ATTACHMENT_META, true );
		return $mark > 0 ? $mark === $tenant_id : $tenant_id === Tenancy::default_id();
	}
}
