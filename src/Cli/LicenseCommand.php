<?php
/**
 * wp booking-manager license status | activate [<key>] | validate | deactivate
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Cli;

use BookingManager\Licensing\LicenseManager;

defined( 'ABSPATH' ) || exit;

/**
 * The licence of this site.
 */
class LicenseCommand {

	public function __construct( private LicenseManager $license ) {}

	/**
	 * Shows the state of the licence.
	 *
	 * ## EXAMPLES
	 *
	 *     wp booking-manager license status
	 */
	public function status(): void {
		$this->show( $this->license->summary() );
	}

	/**
	 * Activates this site.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : The licence key. Without it, the saved one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp booking-manager license activate K7QM-4XHT-9RWA-PZ2C
	 *
	 * @param string[] $args
	 */
	public function activate( array $args ): void {
		$result = $this->license->activate( $args[0] ?? null );
		if ( $result instanceof \WP_Error ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		$this->show( $result );
		if ( empty( $result['can_write'] ) ) {
			// Linked, but expired, suspended…: not a success.
			\WP_CLI::warning( 'Licenza collegata a questo sito, ma non permette nuove prenotazioni: ' . ( $result['status_label'] ?? '' ) . '.' );
			return;
		}
		\WP_CLI::success( 'Licenza attivata.' );
	}

	/**
	 * Asks the licence server now (validate).
	 *
	 * ## EXAMPLES
	 *
	 *     wp booking-manager license validate
	 */
	public function validate(): void {
		$summary = $this->license->refresh();
		$this->show( $summary );
		if ( ! $summary['configured'] ) {
			\WP_CLI::warning( 'Server licenze non configurato: niente da verificare.' );
			return;
		}
		if ( ! $summary['activated'] ) {
			\WP_CLI::warning( 'Questo sito non ha un\'attivazione: usa «wp booking-manager license activate».' );
			return;
		}
		if ( '' !== $summary['last_error'] ) {
			\WP_CLI::warning( 'Verifica non riuscita: ' . $summary['last_error_text'] );
			return;
		}
		\WP_CLI::success( 'Verifica fatta.' );
	}

	/**
	 * Frees this site's place on the licence. The key stays saved.
	 *
	 * ## EXAMPLES
	 *
	 *     wp booking-manager license deactivate
	 */
	public function deactivate(): void {
		$summary = $this->license->deactivate();
		if ( ! $summary['freed'] ) {
			\WP_CLI::warning( 'Il server non ha confermato: il posto si libera da solo dopo 7 giorni di silenzio.' );
		}
		\WP_CLI::success( 'Licenza disattivata su questo sito: Booking Manager resta bloccato finché non la riattivi.' );
	}

	/** @param array<string, mixed> $summary */
	private function show( array $summary ): void {
		$rows = array();
		foreach ( array( 'configured', 'server', 'status', 'status_label', 'type', 'plan', 'expires_at', 'updates_until', 'domain', 'site_domain', 'environment', 'key_hint', 'locked', 'can_write', 'can_update', 'last_ok_at', 'next_check_at', 'last_error', 'grace_until' ) as $field ) {
			$value  = $summary[ $field ] ?? null;
			$rows[] = array(
				'field' => $field,
				'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : ( null === $value || '' === $value ? '-' : (string) $value ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}
}
