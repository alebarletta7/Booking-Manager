<?php
/**
 * WP-CLI: customers (tenants) of the platform.
 *
 *   wp booking-manager tenant status
 *   wp booking-manager tenant migrate --dry-run
 *   wp booking-manager tenant migrate --backup-done [--name=<nome>]
 *   wp booking-manager tenant verify
 *   wp booking-manager tenant rollback --yes
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Cli;

use BookingManager\Tenants\Tenancy;
use BookingManager\Tenants\TenantMigration;
use BookingManager\Tenants\TenantRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Gestisce i clienti (tenant) della piattaforma e la migrazione dei dati esistenti.
 */
class TenantCommand {

	public function __construct( private TenantMigration $migration, private TenantRepository $tenants ) {}

	/**
	 * Mostra i clienti e lo stato della migrazione.
	 */
	public function status(): void {
		$plan = $this->migration->plan();
		\WP_CLI::log( 'Tabelle dei clienti: ' . ( $plan['schema_ready'] ? 'presenti' : 'mancanti (aggiorna il plugin)' ) );
		\WP_CLI::log( 'Dati migrati: ' . ( $plan['migrated'] ? 'sì (' . get_option( Tenancy::MIGRATED_OPTION ) . ' UTC)' : 'no' ) );
		if ( ! $plan['schema_ready'] ) {
			return;
		}
		$rows = array();
		foreach ( $this->tenants->all() as $t ) {
			$rows[] = array(
				'id'       => $t['id'],
				'nome'     => $t['name'],
				'stato'    => $t['status'],
				'piano'    => $t['plan'],
				'default'  => $t['is_default'] ? 'sì' : '',
				'utenti'   => count( $this->tenants->user_ids( (int) $t['id'] ) ),
				'domini'   => implode( ', ', $this->tenants->domains( (int) $t['id'] ) ),
			);
		}
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		}
	}

	/**
	 * Collega i dati esistenti al cliente predefinito. Non cancella né duplica nulla.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Mostra cosa verrebbe fatto senza scrivere.
	 *
	 * [--backup-done]
	 * : Conferma di aver fatto il backup del database (obbligatorio per eseguire).
	 *
	 * [--name=<nome>]
	 * : Nome del cliente predefinito (altrimenti ragione sociale o nome della prima struttura).
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function migrate( array $args, array $assoc ): void {
		if ( isset( $assoc['dry-run'] ) ) {
			$plan = $this->migration->plan();
			if ( ! $plan['schema_ready'] ) {
				\WP_CLI::error( 'Le tabelle dei clienti non esistono: aggiorna il database del plugin.' );
			}
			\WP_CLI::log( 'Cliente predefinito: ' . ( $plan['default_tenant'] ? '#' . $plan['default_tenant']['id'] . ' ' . $plan['default_tenant']['name'] . ' (già esistente)' : 'da creare' ) );
			foreach ( $plan['rows'] as $table => $n ) {
				\WP_CLI::log( sprintf( '  %-16s %d righe da collegare', $table, $n ) );
			}
			\WP_CLI::log( sprintf( '  utenti staff      %d da collegare', count( $plan['users'] ) ) );
			\WP_CLI::log( 'Nessuna modifica eseguita (--dry-run).' );
			return;
		}
		if ( ! isset( $assoc['backup-done'] ) ) {
			\WP_CLI::error( "Fai prima un backup, per esempio:\n  wp db export backup-prima-dei-clienti.sql\npoi ripeti con --backup-done." );
		}
		$result = $this->migration->migrate(
			true,
			array(
				'name'    => (string) ( $assoc['name'] ?? '' ),
				'user_id' => get_current_user_id(),
			)
		);
		if ( $result instanceof \WP_Error ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::log( 'Cliente predefinito: #' . $result['tenant']['id'] . ' ' . $result['tenant']['name'] );
		foreach ( $result['rows'] as $table => $n ) {
			\WP_CLI::log( sprintf( '  %-16s %d righe collegate', $table, $n ) );
		}
		\WP_CLI::log( sprintf( '  utenti staff      %d collegati', count( $result['users'] ) ) );
		$this->report( $result['verify'] );
	}

	/**
	 * Controlla il risultato della migrazione.
	 */
	public function verify(): void {
		$this->report( $this->migration->verify() );
	}

	/**
	 * Rimette tenant_id a NULL (il plugin torna a funzionare come prima). Non cancella nulla.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Conferma.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function rollback( array $args, array $assoc ): void {
		\WP_CLI::confirm( 'Scollegare tutti i dati dai clienti? Il ritorno completo si fa ripristinando il backup.', $assoc );
		foreach ( $this->migration->rollback( get_current_user_id() ) as $table => $n ) {
			\WP_CLI::log( sprintf( '  %-16s %d righe scollegate', $table, $n ) );
		}
		\WP_CLI::success( 'Fatto.' );
	}

	/** @param array{ok: bool, problems: string[], checks: array<string, mixed>} $check */
	private function report( array $check ): void {
		foreach ( $check['problems'] as $problem ) {
			\WP_CLI::warning( $problem );
		}
		if ( ! $check['ok'] ) {
			\WP_CLI::error( 'Verifica non superata: controlla i messaggi sopra (il backup permette di tornare indietro).' );
		}
		\WP_CLI::success( 'Verifica superata: righe, importi e stati invariati, ogni dato ha il suo cliente.' );
	}
}
