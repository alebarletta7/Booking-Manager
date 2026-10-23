<?php
/**
 * Plugin kernel: wires services and modules.
 *
 * Booking Manager boots on its own. It never checks whether Night, Beach or Restaurant
 * Manager are active and never loads their code; optional integrations register through
 * the bkng_modules filter and the bkng_* hooks, and can be switched off without touching
 * the core.
 *
 * @package BookingManager
 */

declare( strict_types=1 );

namespace BookingManager\Core;

use BookingManager\Access\Policy;
use BookingManager\Access\PropertyScope;
use BookingManager\Access\RoleManager;
use BookingManager\Audit\AuditLogger;
use BookingManager\Audit\AuditLogReader;
use BookingManager\Availability\HoldService;
use BookingManager\Availability\Ledger;
use BookingManager\Availability\RestrictionRepository;
use BookingManager\Bookings\BookingRepository;
use BookingManager\Bookings\BookingService;
use BookingManager\Bookings\StayBuilder;
use BookingManager\Database\Migrator;
use BookingManager\Guests\GuestRepository;
use BookingManager\Guests\GuestService;
use BookingManager\Inventory\InventoryService;
use BookingManager\Inventory\RoomTypeRepository;
use BookingManager\Inventory\UnitRepository;
use BookingManager\Licensing\LicenseClient;
use BookingManager\Licensing\LicenseGate;
use BookingManager\Licensing\LicenseManager;
use BookingManager\Licensing\LicenseScheduler;
use BookingManager\Licensing\LicenseStore;
use BookingManager\Licensing\ServerConfig;
use BookingManager\Licensing\ServerGate;
use BookingManager\Licensing\WriteGate;
use BookingManager\Properties\PropertyRepository;
use BookingManager\Properties\PropertyService;
use BookingManager\Rates\RateRepository;
use BookingManager\Rates\RateService;
use BookingManager\Updates\UpdateChecker;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private Container $container;

	private bool $booted = false;

	/** @var ModuleInterface[] */
	private array $modules = array();

	private function __construct() {
		$this->container = new Container();
		$this->register_services( $this->container );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Hooked on plugins_loaded. */
	public static function boot(): void {
		$plugin = self::instance();
		if ( $plugin->booted ) {
			return;
		}
		$plugin->booted = true;

		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );

		$plugin->load_modules();
		$plugin->hooks();

		/**
		 * Fires when Booking Manager is ready. Integrations should hook here.
		 *
		 * @param Plugin $plugin
		 */
		do_action( Hooks::LOADED, $plugin ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- bkng_loaded.
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'booking-manager', false, dirname( plugin_basename( BKNG_FILE ) ) . '/languages' );
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * Shortcut for integrations: bkng()->get( BookingService::class ).
	 *
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get( string $id ): object {
		return $this->container->get( $id );
	}

	private function register_services( Container $c ): void {
		$c->set( Settings::class, fn() => new Settings() );
		$c->set( Clock::class, fn() => new Clock() );
		$c->set( SecretBox::class, fn() => new SecretBox() );
		$c->set( Migrator::class, fn() => new Migrator() );
		$c->set( RoleManager::class, fn() => new RoleManager() );
		$c->set( PropertyScope::class, fn() => new PropertyScope() );
		$c->set( Policy::class, fn( Container $c ) => new Policy( $c->get( PropertyScope::class ) ) );
		$c->set( AuditLogger::class, fn( Container $c ) => new AuditLogger( $c->get( Settings::class ) ) );
		$c->set( AuditLogReader::class, fn() => new AuditLogReader() );

		// Licence (licence server shared with Night, Beach and Restaurant Manager): state,
		// gates on bookings, rooms and the REST API, scheduled checks, updates.
		$c->set( LicenseStore::class, fn() => new LicenseStore() );
		$c->set(
			LicenseClient::class,
			fn( Container $c ) => new LicenseClient(
				ServerConfig::from_environment(),
				static function ( string $endpoint, ?string $kid ) use ( $c ): void {
					$c->get( AuditLogger::class )->log(
						array(
							'action'      => 'license.bad_signature',
							'entity_type' => 'license',
							'new'         => array(
								'endpoint' => $endpoint,
								'kid'      => $kid,
							),
						)
					);
				}
			)
		);
		$c->set( LicenseManager::class, fn( Container $c ) => new LicenseManager( $c->get( LicenseStore::class ), $c->get( LicenseClient::class ), $c->get( AuditLogger::class ) ) );
		$c->set( LicenseScheduler::class, fn( Container $c ) => new LicenseScheduler( $c->get( LicenseManager::class ) ) );
		$c->set( WriteGate::class, fn( Container $c ) => new WriteGate( $c->get( LicenseManager::class ) ) );
		$c->set( UpdateChecker::class, fn( Container $c ) => new UpdateChecker( $c->get( LicenseManager::class ) ) );
		$c->set( LicenseGate::class, fn( Container $c ) => new ServerGate( $c->get( LicenseManager::class ) ) );

		// Properties and inventory.
		$c->set( PropertyRepository::class, fn() => new PropertyRepository() );
		$c->set( PropertyService::class, fn( Container $c ) => new PropertyService( $c->get( PropertyRepository::class ), $c->get( PropertyScope::class ), $c->get( AuditLogger::class ) ) );
		$c->set( RoomTypeRepository::class, fn() => new RoomTypeRepository() );
		$c->set( UnitRepository::class, fn() => new UnitRepository() );
		$c->set( Ledger::class, fn() => new Ledger() );
		$c->set( InventoryService::class, fn( Container $c ) => new InventoryService( $c->get( RoomTypeRepository::class ), $c->get( UnitRepository::class ), $c->get( PropertyRepository::class ), $c->get( Ledger::class ), $c->get( AuditLogger::class ), $c->get( Clock::class ), $c->get( LicenseGate::class ) ) );

		// Rates and availability.
		$c->set( RateRepository::class, fn() => new RateRepository() );
		$c->set( RestrictionRepository::class, fn() => new RestrictionRepository() );
		$c->set( RateService::class, fn( Container $c ) => new RateService( $c->get( RateRepository::class ), $c->get( RestrictionRepository::class ), $c->get( RoomTypeRepository::class ), $c->get( PropertyRepository::class ), $c->get( AuditLogger::class ) ) );
		$c->set( HoldService::class, fn( Container $c ) => new HoldService( $c->get( Ledger::class ) ) );

		// Guests and bookings.
		$c->set( GuestRepository::class, fn() => new GuestRepository() );
		$c->set( GuestService::class, fn( Container $c ) => new GuestService( $c->get( GuestRepository::class ), $c->get( AuditLogger::class ) ) );
		$c->set( BookingRepository::class, fn() => new BookingRepository() );
		$c->set( StayBuilder::class, fn( Container $c ) => new StayBuilder( $c->get( Ledger::class ), $c->get( RoomTypeRepository::class ), $c->get( UnitRepository::class ), $c->get( RateRepository::class ), $c->get( RestrictionRepository::class ), $c->get( Clock::class ) ) );
		$c->set(
			BookingService::class,
			fn( Container $c ) => new BookingService(
				$c->get( BookingRepository::class ),
				$c->get( PropertyRepository::class ),
				$c->get( StayBuilder::class ),
				$c->get( Ledger::class ),
				$c->get( HoldService::class ),
				$c->get( GuestService::class ),
				$c->get( GuestRepository::class ),
				$c->get( AuditLogger::class ),
				$c->get( Clock::class ),
				$c->get( LicenseGate::class )
			)
		);
	}

	/**
	 * Optional modules (integrations, add-ons). Each one registers its services and hooks;
	 * a failing module is skipped and reported, never taking the core down with it.
	 */
	private function load_modules(): void {
		/**
		 * Optional modules to load. Core features never depend on them.
		 *
		 * @param ModuleInterface[] $modules
		 */
		$modules = (array) apply_filters( 'bkng_modules', array() );
		foreach ( $modules as $module ) {
			if ( ! $module instanceof ModuleInterface ) {
				continue;
			}
			try {
				$module->services( $this->container );
				$module->hooks( $this->container );
				$this->modules[] = $module;
			} catch ( \Throwable $e ) {
				/**
				 * Fires when an optional module fails to load.
				 *
				 * @param ModuleInterface $module
				 * @param \Throwable      $error
				 */
				do_action( 'bkng_module_failed', $module, $e );
			}
		}
	}

	/**
	 * REST controllers of the plugin.
	 *
	 * @return array<int, class-string>
	 */
	public static function controllers(): array {
		return array();
	}

	private function hooks(): void {
		$c = $this->container;

		$c->get( RoleManager::class )->hooks();
		// Licence: the gate on the REST API, scheduled checks, updates from the server.
		$c->get( WriteGate::class )->hooks();
		$c->get( LicenseScheduler::class )->hooks();
		$c->get( UpdateChecker::class )->hooks();

		add_filter( 'cron_schedules', array( Installer::class, 'cron_schedules' ) );
		add_action( 'init', array( Installer::class, 'ensure_schedules' ), 20 );

		add_action( Installer::HOURLY_HOOK, fn() => $c->get( BookingService::class )->expire_options() );
		add_action( Installer::DAILY_HOOK, fn() => $c->get( BookingService::class )->auto_complete() );
		add_action( Installer::DAILY_HOOK, fn() => $c->get( HoldService::class )->expire() );
		add_action( Installer::DAILY_HOOK, fn() => $c->get( AuditLogger::class )->purge( (int) $c->get( Settings::class )->get( 'audit_retention_months' ), time() ) );

		add_action(
			'rest_api_init',
			function () use ( $c ) {
				foreach ( self::controllers() as $controller ) {
					$c->get( $controller )->register_routes();
				}
			}
		);
	}
}
