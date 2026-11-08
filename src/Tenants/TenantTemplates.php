<?php
/**
 * The customer's own texts of the guest e-mails (0.2.5), used by all its properties when
 * a property has none: property → customer → default. Stored in
 * bkng_tenants.settings['templates'][key][language] = {subject, body}.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Tenants;

use BookingManager\Audit\AuditLogger;

defined( 'ABSPATH' ) || exit;

class TenantTemplates {

	public const KEY = 'templates';

	public function __construct( private TenantRepository $tenants, private AuditLogger $audit ) {}

	/**
	 * @return array<string, array<string, array{subject: string, body: string}>> key => language => texts
	 */
	public function all( int $tenant_id ): array {
		$tenant = $tenant_id > 0 ? $this->tenants->find( $tenant_id ) : null;
		$stored = is_array( $tenant['settings'][ self::KEY ] ?? null ) ? $tenant['settings'][ self::KEY ] : array();
		$out    = array();
		foreach ( $stored as $key => $languages ) {
			foreach ( (array) $languages as $lang => $texts ) {
				$out[ (string) $key ][ (string) $lang ] = array(
					'subject' => (string) ( $texts['subject'] ?? '' ),
					'body'    => (string) ( $texts['body'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/** @return array{subject: string, body: string}|null in the language, else in Italian */
	public function find( int $tenant_id, string $key, string $language = 'it' ): ?array {
		if ( $tenant_id <= 0 ) {
			return null;
		}
		$all = $this->all( $tenant_id );
		foreach ( array_unique( array( $language, 'it' ) ) as $lang ) {
			if ( isset( $all[ $key ][ $lang ] ) ) {
				return $all[ $key ][ $lang ];
			}
		}
		return null;
	}

	public function save( int $tenant_id, string $key, string $language, string $subject, string $body ): void {
		$this->write( $tenant_id, $key, $language, array( 'subject' => $subject, 'body' => $body ) );
	}

	public function delete( int $tenant_id, string $key, string $language ): void {
		$this->write( $tenant_id, $key, $language, null );
	}

	/** @param array{subject: string, body: string}|null $texts */
	private function write( int $tenant_id, string $key, string $language, ?array $texts ): void {
		$tenant = $tenant_id > 0 ? $this->tenants->find( $tenant_id ) : null;
		if ( ! $tenant ) {
			return;
		}
		$settings = is_array( $tenant['settings'] ) ? $tenant['settings'] : array();
		$all      = is_array( $settings[ self::KEY ] ?? null ) ? $settings[ self::KEY ] : array();
		if ( null === $texts ) {
			unset( $all[ $key ][ $language ] );
			if ( empty( $all[ $key ] ) ) {
				unset( $all[ $key ] );
			}
		} else {
			$all[ $key ][ $language ] = $texts;
		}
		$settings[ self::KEY ] = $all;
		$this->tenants->update( $tenant_id, array( 'settings' => $settings ) );
		$this->audit->log(
			array(
				'action'      => null === $texts ? 'tenant.template_reset' : 'tenant.template_saved',
				'entity_type' => 'tenant',
				'entity_id'   => $tenant_id,
				'tenant_id'   => $tenant_id,
				'new'         => array(
					'template' => $key,
					'language' => $language,
				),
			)
		);
	}
}
