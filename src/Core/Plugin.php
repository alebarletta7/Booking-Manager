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

use BookingManager\Admin\AdminApp;
use BookingManager\Admin\StaffEntry;
use BookingManager\Availability\AvailabilityService;
use BookingManager\Calendar\PlanningService;
use BookingManager\Cloudflare\CloudflareClient;
use BookingManager\Cloudflare\CloudflareService;
use BookingManager\Cloudflare\CloudflareSettings;
use BookingManager\Frontend\Captcha;
use BookingManager\Frontend\PublicBookingService;
use BookingManager\Frontend\PublicFrontend;
use BookingManager\Reports\DashboardService;
use BookingManager\Rest\AuditController;
use BookingManager\Rest\AvailabilityController;
use BookingManager\Rest\BookingsController;
use BookingManager\Rest\CloudflareController;
use BookingManager\Rest\GuestsController;
use BookingManager\Rest\InventoryController;
use BookingManager\Rest\LicenseController;
use BookingManager\Rest\MeController;
use BookingManager\Rest\PropertiesController;
use BookingManager\Rest\PublicController;
use BookingManager\Rest\RatesController;
use BookingManager\Rest\SettingsController;
use BookingManager\Rest\SetupController;
use BookingManager\Rest\StaffController;
use BookingManager\Staff\StaffService;
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
use BookingManager\Bookings\ManageLink;
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
use BookingManager\Payments\PaymentAccountRepository;
use BookingManager\Payments\PaymentAccountService;
use BookingManager\Payments\PaymentRepository;
use BookingManager\Payments\PaymentService;
use BookingManager\Payments\ProviderRegistry;
use BookingManager\Payments\Providers\ManualProvider;
use BookingManager\Payments\Providers\NexiClient;
use BookingManager\Payments\Providers\NexiProvider;
use BookingManager\Payments\Providers\StripeClient;
use BookingManager\Payments\Providers\StripeProvider;
use BookingManager\Rest\PaymentsController;
use BookingManager\Rest\CheckinController;
use BookingManager\Rest\HousekeepingController;
use BookingManager\Cli\LicenseCommand;
use BookingManager\Checkin\CheckinRepository;
use BookingManager\Checkin\CheckinService;
use BookingManager\Checkin\QrToken;
use BookingManager\Housekeeping\HousekeepingService;
use BookingManager\Notifications\Channels\EmailChannel;
use BookingManager\Notifications\Channels\WhatsAppChannel;
use BookingManager\Notifications\NotificationRepository;
use BookingManager\Notifications\NotificationService;
use BookingManager\Notifications\NotificationTemplates;
use BookingManager\Notifications\TemplateRepository;
use BookingManager\Rest\NotificationsController;
use BookingManager\Rest\ChannelsController;
use BookingManager\Channels\ChannelRefRepository;
use BookingManager\Channels\ChannelService;
use BookingManager\Channels\SyncLogRepository;
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
		$c->set( ManageLink::class, fn() => new ManageLink() );
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
				$c->get( LicenseGate::class ),
				$c->get( ManageLink::class ),
				$c->get( UnitRepository::class )
			)
		);
		// Read models, public form, staff, integrations.
		$c->set( AvailabilityService::class, fn( Container $c ) => new AvailabilityService( $c->get( Ledger::class ), $c->get( RoomTypeRepository::class ), $c->get( RateRepository::class ), $c->get( RestrictionRepository::class ), $c->get( Clock::class ) ) );
		$c->set( PlanningService::class, fn( Container $c ) => new PlanningService( $c->get( RoomTypeRepository::class ), $c->get( UnitRepository::class ), $c->get( BookingRepository::class ), $c->get( AvailabilityService::class ) ) );
		$c->set( DashboardService::class, fn( Container $c ) => new DashboardService( $c->get( PropertyRepository::class ), $c->get( Clock::class ) ) );
		$c->set( Captcha::class, fn( Container $c ) => new Captcha( $c->get( SecretBox::class ) ) );
		$c->set(
			PublicBookingService::class,
			fn( Container $c ) => new PublicBookingService(
				$c->get( PropertyRepository::class ),
				$c->get( RoomTypeRepository::class ),
				$c->get( AvailabilityService::class ),
				$c->get( HoldService::class ),
				$c->get( BookingService::class ),
				$c->get( BookingRepository::class ),
				$c->get( ManageLink::class ),
				$c->get( Captcha::class ),
				$c->get( Clock::class ),
				$c->get( PaymentService::class ),
				$c->get( PaymentAccountService::class ),
				$c->get( RateRepository::class )
			)
		);
		$c->set( PublicFrontend::class, fn( Container $c ) => new PublicFrontend( $c->get( PropertyRepository::class ), $c->get( Captcha::class ) ) );
		$c->set( StaffService::class, fn( Container $c ) => new StaffService( $c->get( RoleManager::class ), $c->get( PropertyScope::class ), $c->get( AuditLogger::class ) ) );
		$c->set( CloudflareSettings::class, fn( Container $c ) => new CloudflareSettings( $c->get( SecretBox::class ) ) );
		$c->set( CloudflareClient::class, fn( Container $c ) => new CloudflareClient( $c->get( CloudflareSettings::class ) ) );
		$c->set( CloudflareService::class, fn( Container $c ) => new CloudflareService( $c->get( CloudflareSettings::class ), $c->get( CloudflareClient::class ), $c->get( PropertyRepository::class ), $c->get( PaymentAccountRepository::class ), $c->get( AuditLogger::class ), $c->get( Clock::class ) ) );

		// Payments: desk payments, Stripe and Nexi hosted pages, refunds, notifications.
		$c->set( PaymentAccountRepository::class, fn( Container $c ) => new PaymentAccountRepository( $c->get( SecretBox::class ) ) );
		$c->set(
			ProviderRegistry::class,
			fn( Container $c ) => new ProviderRegistry(
				array(
					new ManualProvider(),
					new StripeProvider( new StripeClient(), $c->get( Clock::class ) ),
					new NexiProvider( new NexiClient(), $c->get( Clock::class ) ),
				)
			)
		);
		$c->set( PaymentAccountService::class, fn( Container $c ) => new PaymentAccountService( $c->get( PaymentAccountRepository::class ), $c->get( ProviderRegistry::class ), $c->get( PropertyRepository::class ), $c->get( AuditLogger::class ) ) );
		$c->set( PaymentRepository::class, fn() => new PaymentRepository() );
		$c->set(
			PaymentService::class,
			fn( Container $c ) => new PaymentService(
				$c->get( PaymentRepository::class ),
				$c->get( PaymentAccountService::class ),
				$c->get( ProviderRegistry::class ),
				$c->get( BookingRepository::class ),
				$c->get( BookingService::class ),
				$c->get( PropertyRepository::class ),
				$c->get( AuditLogger::class ),
				$c->get( Clock::class )
			)
		);

		// Front desk and housekeeping.
		$c->set( QrToken::class, fn() => new QrToken() );
		$c->set( CheckinRepository::class, fn() => new CheckinRepository() );
		$c->set( CheckinService::class, fn( Container $c ) => new CheckinService( $c->get( BookingRepository::class ), $c->get( BookingService::class ), $c->get( CheckinRepository::class ), $c->get( PropertyRepository::class ), $c->get( RoomTypeRepository::class ), $c->get( UnitRepository::class ), $c->get( QrToken::class ), $c->get( AuditLogger::class ), $c->get( Clock::class ) ) );
		$c->set( HousekeepingService::class, fn( Container $c ) => new HousekeepingService( $c->get( UnitRepository::class ), $c->get( RoomTypeRepository::class ), $c->get( BookingRepository::class ), $c->get( PropertyRepository::class ), $c->get( Clock::class ) ) );

		// Messages: e-mail, WhatsApp prepared for an add-on provider.
		$c->set( NotificationRepository::class, fn() => new NotificationRepository() );
		$c->set( TemplateRepository::class, fn() => new TemplateRepository() );
		$c->set( NotificationTemplates::class, fn( Container $c ) => new NotificationTemplates( $c->get( TemplateRepository::class ), $c->get( BookingRepository::class ), $c->get( RoomTypeRepository::class ) ) );
		$c->set( EmailChannel::class, fn() => new EmailChannel() );
		$c->set( WhatsAppChannel::class, fn() => new WhatsAppChannel() );
		$c->set(
			NotificationService::class,
			fn( Container $c ) => new NotificationService(
				$c->get( NotificationRepository::class ),
				$c->get( NotificationTemplates::class ),
				$c->get( BookingRepository::class ),
				$c->get( PropertyRepository::class ),
				$c->get( EmailChannel::class ),
				$c->get( WhatsAppChannel::class ),
				$c->get( QrToken::class ),
				$c->get( ManageLink::class ),
				$c->get( LicenseGate::class ),
				$c->get( Clock::class )
			)
		);

		// Sales channels: prepared for connectors supplied by add-ons.
		$c->set( ChannelRefRepository::class, fn() => new ChannelRefRepository() );
		$c->set( SyncLogRepository::class, fn() => new SyncLogRepository() );
		$c->set( ChannelService::class, fn( Container $c ) => new ChannelService( $c->get( ChannelRefRepository::class ), $c->get( SyncLogRepository::class ), $c->get( BookingService::class ), $c->get( BookingRepository::class ), $c->get( PropertyRepository::class ) ) );

		// REST controllers.
		$p = fn( Container $c ) => $c->get( Policy::class );
		$c->set( MeController::class, fn( Container $c ) => new MeController( $p( $c ), $c->get( PropertyScope::class ), $c->get( PropertyRepository::class ), $c->get( Clock::class ) ) );
		$c->set( SetupController::class, fn( Container $c ) => new SetupController( $p( $c ), $c->get( PropertyRepository::class ), $c->get( RoomTypeRepository::class ), $c->get( UnitRepository::class ), $c->get( RateRepository::class ), $c->get( AuditLogger::class ) ) );
		$c->set( LicenseController::class, fn( Container $c ) => new LicenseController( $p( $c ), $c->get( LicenseManager::class ), $c->get( UpdateChecker::class ) ) );
		$c->set( PropertiesController::class, fn( Container $c ) => new PropertiesController( $p( $c ), $c->get( PropertyService::class ), $c->get( PropertyRepository::class ) ) );
		$c->set( InventoryController::class, fn( Container $c ) => new InventoryController( $p( $c ), $c->get( InventoryService::class ), $c->get( RoomTypeRepository::class ), $c->get( UnitRepository::class ) ) );
		$c->set( RatesController::class, fn( Container $c ) => new RatesController( $p( $c ), $c->get( RateService::class ), $c->get( RateRepository::class ), $c->get( RestrictionRepository::class ) ) );
		$c->set( AvailabilityController::class, fn( Container $c ) => new AvailabilityController( $p( $c ), $c->get( AvailabilityService::class ), $c->get( PlanningService::class ), $c->get( DashboardService::class ), $c->get( PropertyRepository::class ), $c->get( Clock::class ) ) );
		$c->set( BookingsController::class, fn( Container $c ) => new BookingsController( $p( $c ), $c->get( BookingService::class ), $c->get( BookingRepository::class ), $c->get( PropertyRepository::class ), $c->get( AuditLogReader::class ) ) );
		$c->set( GuestsController::class, fn( Container $c ) => new GuestsController( $p( $c ), $c->get( GuestService::class ), $c->get( GuestRepository::class ), $c->get( BookingRepository::class ) ) );
		$c->set( SettingsController::class, fn( Container $c ) => new SettingsController( $p( $c ), $c->get( Settings::class ), $c->get( AuditLogger::class ), $c->get( Captcha::class ) ) );
		$c->set( AuditController::class, fn( Container $c ) => new AuditController( $p( $c ), $c->get( AuditLogReader::class ), $c->get( PropertyRepository::class ) ) );
		$c->set( StaffController::class, fn( Container $c ) => new StaffController( $p( $c ), $c->get( StaffService::class ) ) );
		$c->set( CloudflareController::class, fn( Container $c ) => new CloudflareController( $p( $c ), $c->get( CloudflareSettings::class ), $c->get( CloudflareService::class ), $c->get( AuditLogger::class ) ) );
		$c->set( PaymentsController::class, fn( Container $c ) => new PaymentsController( $p( $c ), $c->get( PaymentService::class ), $c->get( PaymentRepository::class ), $c->get( PaymentAccountService::class ), $c->get( BookingRepository::class ), $c->get( ManageLink::class ) ) );
		$c->set( CheckinController::class, fn( Container $c ) => new CheckinController( $p( $c ), $c->get( CheckinService::class ), $c->get( BookingRepository::class ) ) );
		$c->set( HousekeepingController::class, fn( Container $c ) => new HousekeepingController( $p( $c ), $c->get( HousekeepingService::class ), $c->get( UnitRepository::class ) ) );
		$c->set( NotificationsController::class, fn( Container $c ) => new NotificationsController( $p( $c ), $c->get( NotificationService::class ), $c->get( NotificationRepository::class ), $c->get( TemplateRepository::class ), $c->get( BookingRepository::class ) ) );
		$c->set( ChannelsController::class, fn( Container $c ) => new ChannelsController( $p( $c ), $c->get( ChannelService::class ), $c->get( RoomTypeRepository::class ) ) );
		$c->set( PublicController::class, fn( Container $c ) => new PublicController( $c->get( PublicBookingService::class ) ) );
		$c->set( AdminApp::class, fn( Container $c ) => new AdminApp( $c->get( MeController::class ), $c->get( LicenseManager::class ), $c->get( SetupController::class ) ) );
		$c->set( StaffEntry::class, fn() => new StaffEntry() );
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
		return array(
			MeController::class,
			SetupController::class,
			LicenseController::class,
			PropertiesController::class,
			InventoryController::class,
			RatesController::class,
			AvailabilityController::class,
			BookingsController::class,
			GuestsController::class,
			SettingsController::class,
			AuditController::class,
			StaffController::class,
			CloudflareController::class,
			PaymentsController::class,
			CheckinController::class,
			HousekeepingController::class,
			NotificationsController::class,
			ChannelsController::class,
			PublicController::class,
		);
	}

	private function hooks(): void {
		$c = $this->container;

		$c->get( RoleManager::class )->hooks();
		$c->get( PublicFrontend::class )->hooks();
		$c->get( StaffEntry::class )->hooks();
		if ( is_admin() ) {
			$c->get( AdminApp::class )->hooks();
		}
		// Licence: the gate on the REST API, scheduled checks, updates from the server.
		$c->get( WriteGate::class )->hooks();
		$c->get( LicenseScheduler::class )->hooks();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'booking-manager license', new LicenseCommand( $c->get( LicenseManager::class ) ) );
		}
		$c->get( UpdateChecker::class )->hooks();
		$c->get( NotificationService::class )->hooks();
		$c->get( ChannelService::class )->hooks();
		add_action( Installer::NOTIFICATIONS_HOOK, fn() => $c->get( NotificationService::class )->run() );
		add_action( Installer::REMINDERS_HOOK, fn() => $c->get( NotificationService::class )->queue_reminders() );

		add_filter( 'cron_schedules', array( Installer::class, 'cron_schedules' ) );
		add_action( 'init', array( Installer::class, 'ensure_schedules' ), 20 );

		add_action( Installer::HOURLY_HOOK, fn() => $c->get( BookingService::class )->expire_options() );
		// Every 15 minutes: first ask the providers about open payments (a paid option is
		// confirmed), then let the expired options go.
		add_action(
			Installer::PAYMENTS_HOOK,
			function () use ( $c ) {
				$c->get( PaymentService::class )->reconcile_open();
				$c->get( BookingService::class )->expire_options();
			}
		);
		// A cancelled booking closes its open payment pages (a payment already made is refunded).
		add_action( Hooks::BOOKING_CANCELLED, fn( int $booking_id ) => $c->get( PaymentService::class )->cancel_open_payments( $booking_id ) );
		// A room checked out becomes "da pulire" (when the property asks so).
		add_action( Hooks::ROOM_CHECKED_OUT, fn( int $room_id, array $context = array() ) => $c->get( HousekeepingService::class )->on_checkout( $room_id, $context ), 10, 2 );
		add_action( Installer::DAILY_HOOK, fn() => $c->get( BookingService::class )->auto_complete() );
		add_action( Installer::DAILY_HOOK, fn() => $c->get( HoldService::class )->expire() );
		add_action( Installer::DAILY_HOOK, fn() => $c->get( AuditLogger::class )->purge( (int) $c->get( Settings::class )->get( 'audit_retention_months' ), time() ) );
		add_action( Installer::DAILY_HOOK, fn() => $c->get( GuestService::class )->anonymize_inactive( (int) $c->get( Settings::class )->get( 'guest_retention_months' ), time() ) );
		add_action( Installer::DAILY_HOOK, fn() => $c->get( NotificationService::class )->purge( (int) $c->get( Settings::class )->get( 'notification_retention_months' ) ) );
		add_action( Installer::DAILY_HOOK, fn() => $c->get( SyncLogRepository::class )->purge( (int) $c->get( Settings::class )->get( 'audit_retention_months' ), time() ) );
		add_action( Installer::DAILY_HOOK, fn() => $c->get( CheckinRepository::class )->purge( (int) $c->get( Settings::class )->get( 'checkin_retention_months' ), time() ) );

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
