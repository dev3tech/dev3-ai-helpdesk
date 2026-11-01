<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiHelpdesk
 */

namespace Dev3\AiHelpdesk;

use Dev3\AiHelpdesk\Core3\Core\Assets;
use Dev3\AiHelpdesk\Core3\Core\Privacy;
use Dev3\AiHelpdesk\Core3\Core\Schema;
use Dev3\AiHelpdesk\Data\Personal_Data;
use Dev3\AiHelpdesk\Data\Tables;
use Dev3\AiHelpdesk\Data\Tickets;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3hd_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation: tables, roles, cron, pages.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3HD_DB_VERSION );
		Roles::install();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		Helpdesk\Automation::schedule();
		Kb\Kb::register_types();
		Frontend\Portal::create_pages();
		if ( class_exists( 'WooCommerce' ) ) {
			add_rewrite_endpoint( Frontend\Portal::ENDPOINT, EP_ROOT | EP_PAGES );
		}
		flush_rewrite_rules();
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		Helpdesk\Automation::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3hd_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3hd_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3hd_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3hd_schema_sql', array( Tables::class, 'schema' ), 10, 2 );
		}
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( $this, 'textdomain' ), 1 );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compat' ) );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3HD_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', array( Ai\Prompts_Library::class, 'register' ), 5 );
		Privacy::init();
		add_action( 'init', array( $this, 'privacy' ) );
		Roles::register();
		// DEV3 AI tools (core can_generate, usage meter) are for helpdesk agents.
		add_filter( 'dev3hd_capability', static fn() => Roles::CAP );
		// Background job types must be registered on every request (the runner looks them up).
		Ai\Automation_Jobs::register();
		Kb\Indexer::register();
		Mail\Poller::register();
		( new Helpdesk\Automation() )->register();
		( new Helpdesk\Files() )->register();
		( new Kb\Kb() )->register();
		( new Mail\Mailer() )->register();
		( new Frontend\Portal() )->register();
		add_action( 'admin_init', array( $this, 'admin_assets' ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
	}

	/**
	 * Admin bundle (registered once; Assets::register adds the boot script on every call).
	 */
	public function admin_assets() {
		if ( wp_script_is( Assets::handle( 'admin' ), 'registered' ) ) {
			return;
		}
		Assets::register(
			array( 'admin' ),
			array(
				'statuses'   => Tickets::status_labels(),
				'priorities' => Tickets::priority_labels(),
				'channels'   => Tickets::channel_labels(),
				'woo'        => class_exists( 'WooCommerce' ),
				'isManager'  => Roles::is_manager(),
				'kbUrl'      => admin_url( 'edit.php?post_type=' . Kb\Kb::POST_TYPE ),
				'newKbUrl'   => admin_url( 'post-new.php?post_type=' . Kb\Kb::POST_TYPE ),
				'kbCatsUrl'  => admin_url( 'edit-tags.php?taxonomy=' . Kb\Kb::TAXONOMY . '&post_type=' . Kb\Kb::POST_TYPE ),
				'pages'      => Frontend\Portal::page_links(),
			)
		);
	}

	/**
	 * GDPR exporter/eraser (registered on init so the label can be translated).
	 */
	public function privacy() {
		Privacy::add_exporter( __( 'Support tickets', 'dev3-ai-helpdesk' ), array( Personal_Data::class, 'export' ) );
		Privacy::add_eraser( __( 'Support tickets', 'dev3-ai-helpdesk' ), array( Personal_Data::class, 'erase' ) );
	}

	/**
	 * Translations (on init, as WP 6.7 expects).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-helpdesk', false, dirname( plugin_basename( DEV3HD_FILE ) ) . '/languages' );
	}

	/**
	 * HPOS + Cart/Checkout blocks compatibility (the order panel only uses WC CRUD; nothing touches checkout).
	 */
	public function declare_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3HD_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3HD_FILE, true );
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Agent_Controller() )->register_routes();
		( new Rest\Manager_Controller() )->register_routes();
		( new Rest\Public_Controller() )->register_routes();
	}
}
