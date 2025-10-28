<?php
/**
 * Plugin Name:       HappyAccess
 * Plugin URI:        https://github.com/shameemreza/happyaccess
 * Description:       Secure temporary admin access for WordPress support engineers. Generate OTP-based access without sharing passwords.
 * Version:           1.0.0
 * Author:            Shameem Reza
 * Author URI:        https://shameem.blog/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       happyaccess
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      6.8
 * Requires PHP:      7.4
 * WC requires at least: 9.0
 * WC tested up to:   10.3
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'HAPPYACCESS_VERSION', '1.0.0' );
define( 'HAPPYACCESS_PLUGIN_FILE', __FILE__ );
define( 'HAPPYACCESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HAPPYACCESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HAPPYACCESS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Declare HPOS compatibility (if WooCommerce is active).
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
		
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
	}
} );

/**
 * Main plugin class.
 *
 * @since 1.0.0
 */
class HappyAccess {

	/**
	 * Plugin instance.
	 *
	 * @var HappyAccess|null
	 */
	private static $instance = null;
	
	/**
	 * Admin instance.
	 *
	 * @var HappyAccess_Admin|null
	 */
	private $admin = null;

	/**
	 * Get plugin instance.
	 *
	 * @return HappyAccess
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Load plugin dependencies.
	 */
	private function load_dependencies() {
		// Core classes.
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-activator.php';
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-deactivator.php';
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-token-manager.php';
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-otp-handler.php';
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-temp-user.php';
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-cleanup.php';
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-logger.php';
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-gdpr.php';
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-rate-limiter.php';
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-login-handler.php';

		// Admin classes.
		if ( is_admin() ) {
			require_once HAPPYACCESS_PLUGIN_DIR . 'admin/class-happyaccess-admin.php';
			$this->admin = new HappyAccess_Admin();
		}
	}

	/**
	 * Initialize plugin hooks.
	 */
	private function init_hooks() {
		// Activation/deactivation hooks.
		register_activation_hook( __FILE__, array( 'HappyAccess_Activator', 'activate' ) );
		register_deactivation_hook( __FILE__, array( 'HappyAccess_Deactivator', 'deactivate' ) );

		// Initialize components.
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// Cron events.
		add_action( 'happyaccess_cleanup_expired', array( 'HappyAccess_Cleanup', 'cleanup_expired_tokens' ) );

		// Login form modifications.
		add_action( 'login_form', array( 'HappyAccess_Login_Handler', 'add_otp_field' ) );
		add_filter( 'authenticate', array( 'HappyAccess_Login_Handler', 'authenticate_otp' ), 10, 3 );
		add_action( 'login_enqueue_scripts', array( 'HappyAccess_Login_Handler', 'enqueue_login_scripts' ) );
		
		// Admin bar modifications.
		if ( is_admin() && $this->admin ) {
			add_action( 'admin_bar_menu', array( $this->admin, 'add_emergency_lock_button' ), 999 );
			add_action( 'wp_ajax_happyaccess_emergency_lock', array( $this->admin, 'ajax_emergency_lock' ) );
		}
	}

	/**
	 * Initialize plugin.
	 */
	public function init() {
		// WordPress automatically loads translations for plugins on WordPress.org since 4.6.
		// No need to manually call load_plugin_textdomain().

		// Schedule cleanup cron if not already scheduled.
		if ( ! wp_next_scheduled( 'happyaccess_cleanup_expired' ) ) {
			wp_schedule_event( time(), 'hourly', 'happyaccess_cleanup_expired' );
		}
	}

	/**
	 * Initialize admin.
	 */
	public function admin_init() {
		if ( class_exists( 'HappyAccess_Admin' ) ) {
			new HappyAccess_Admin();
		}
	}
}

// Initialize the plugin.
HappyAccess::get_instance();
