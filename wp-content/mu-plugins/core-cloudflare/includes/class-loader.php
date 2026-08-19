<?php
/**
 * Core Cloudflare - Loader
 *
 * @package Core_Cloudflare
 */

declare( strict_types=1 );

namespace Core_Cloudflare;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Loader
 *
 * Single Responsibility: require class files in dependency order and
 * instantiate/wire the services that need to hook into WordPress.
 * Contains no business logic of its own.
 */
final class Loader {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Resolved service instances, keyed by short name, so other classes
	 * can retrieve shared instances instead of constructing their own.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * Whether boot() has already run, to guard against double-boot.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Get the singleton loader instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin: require files, construct services, register hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->require_files();
		$this->register_services();
		$this->register_hooks();

		$this->booted = true;
	}

	/**
	 * Require all class files in dependency order.
	 *
	 * WordPress does not autoload MU plugin subdirectories, so explicit
	 * requires are necessary. Order matters: dependencies first.
	 *
	 * @return void
	 */
	private function require_files(): void {
		$includes = CORE_CLOUDFLARE_INCLUDES_DIR;

		require_once $includes . 'helpers.php';
		require_once $includes . 'class-encryptor.php';
		require_once $includes . 'class-config.php';
		require_once $includes . 'class-logger.php';
		require_once $includes . 'class-cloudflare-api.php';
		require_once $includes . 'class-zone-manager.php';
		require_once $includes . 'class-cache-purge.php';
		require_once $includes . 'class-settings.php';
		require_once $includes . 'class-admin-ui.php';

	}

	/**
	 * Construct shared service instances in dependency order and store
	 * them for retrieval via get_service().
	 *
	 * @return void
	 */
	private function register_services(): void {
		$config = Config::instance();
		$logger = new Logger( $config );
		$api    = new Cloudflare_API( $config, $logger );
		$zones  = new Zone_Manager( $config, $api, $logger );
		$purge  = new Cache_Purge( $config, $api, $zones, $logger );

		$this->services = [
			'config' => $config,
			'logger' => $logger,
			'api'    => $api,
			'zones'  => $zones,
			'purge'  => $purge,
		];
	}

	/**
	 * Register WordPress hooks for the admin UI and scheduled log rotation.
 * Each collaborator
	 * is responsible for its own internal hook registration; this only
	 * wires the top-level entry points.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		if ( is_admin() ) {
			$admin_ui = new Admin_UI(
				$this->get_service( 'config' ),
				$this->get_service( 'zones' ),
				$this->get_service( 'purge' ),
				$this->get_service( 'logger' ),
				$this->get_service( 'api' )
			);
			$admin_ui->register();
		}


		$this->register_cron();
	}

	/**
	 * Schedule (if not already scheduled) the daily log rotation event,
	 * and bind it to Logger::rotate(). Runs network-wide, once per day.
	 *
	 * @return void
	 */
	private function register_cron(): void {
		add_action(
			'core_cloudflare_daily_log_rotation',
			function (): void {
				$this->get_service( 'logger' )->rotate();
			}
		);

		if ( ! wp_next_scheduled( 'core_cloudflare_daily_log_rotation' ) ) {
			wp_schedule_event( time(), 'daily', 'core_cloudflare_daily_log_rotation' );
		}
	}

	/**
	 * Retrieve a previously registered shared service.
	 *
	 * @param string $name Service key (config|logger|api|zones|purge).
	 * @return object
	 *
	 * @throws \RuntimeException If the service has not been registered.
	 */
	public function get_service( string $name ): object {
		if ( ! isset( $this->services[ $name ] ) ) {
			throw new \RuntimeException( esc_html( "Core Cloudflare: service '{$name}' has not been registered." ) );
		}

		return $this->services[ $name ];
	}
}
