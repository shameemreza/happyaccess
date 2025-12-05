<?php
/**
 * Plugin Name:       HappyAccess
 * Plugin URI:        https://github.com/shameemreza/happyaccess
 * Description:       Secure temporary admin access for WordPress support engineers. Generate OTP-based access without sharing passwords.
 * Version:           1.0.3
 * Author:            Shameem Reza
 * Author URI:        https://shameem.blog/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       happyaccess
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      6.9
 * Requires PHP:      7.4
 * WC requires at least: 9.0
 * WC tested up to:   10.3
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'HAPPYACCESS_VERSION', '1.0.3' );
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
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-magic-link.php';
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-recaptcha.php';
		require_once HAPPYACCESS_PLUGIN_DIR . 'includes/class-happyaccess-otp-share.php';

		// Initialize GDPR compliance (WordPress privacy tools integration).
		new HappyAccess_GDPR();

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
		
		// reCAPTCHA v3 integration (optional).
		add_action( 'login_enqueue_scripts', array( 'HappyAccess_ReCaptcha', 'enqueue_scripts' ) );
		
		// Magic link and OTP share cleanup with token cleanup.
		add_action( 'happyaccess_cleanup_expired', array( 'HappyAccess_Magic_Link', 'cleanup_expired' ) );
		add_action( 'happyaccess_cleanup_expired', array( 'HappyAccess_OTP_Share', 'cleanup_expired' ) );
		
		// Admin bar modifications.
		if ( is_admin() && $this->admin ) {
			add_action( 'admin_bar_menu', array( $this->admin, 'add_emergency_lock_button' ), 999 );
			add_action( 'wp_ajax_happyaccess_emergency_lock', array( $this->admin, 'ajax_emergency_lock' ) );
		}
		
		// Plugin action links (Settings).
		add_filter( 'plugin_action_links_' . HAPPYACCESS_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
		
		// Plugin row meta (Get Support link in description area).
		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_row_meta' ), 10, 2 );
	}
	
	/**
	 * Add plugin action links on plugins page.
	 *
	 * @since 1.0.1
	 * @since 1.0.2 Removed Support link (moved to row meta).
	 *
	 * @param array $links Existing links.
	 * @return array Modified links.
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'users.php?page=happyaccess&tab=settings' ) ),
			esc_html__( 'Settings', 'happyaccess' )
		);
		
		// Add at the beginning of the array.
		array_unshift( $links, $settings_link );
		
		return $links;
	}
	
	/**
	 * Add plugin row meta links (in description area).
	 *
	 * @since 1.0.2
	 *
	 * @param array  $links Plugin meta links.
	 * @param string $file  Plugin file path.
	 * @return array Modified links.
	 */
	public function add_plugin_row_meta( $links, $file ) {
		if ( HAPPYACCESS_PLUGIN_BASENAME !== $file ) {
			return $links;
		}
		
		$support_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://github.com/shameemreza/happyaccess/issues' ),
			esc_html__( 'Get Support', 'happyaccess' )
		);
		
		$links[] = $support_link;
		
		return $links;
	}

	/**
	 * Initialize plugin.
	 */
	public function init() {
		// WordPress automatically loads translations for plugins on WordPress.org since 4.6.
		// No need to manually call load_plugin_textdomain().

		// Ensure database tables exist (for upgrades without deactivation).
		$this->ensure_tables_exist();

		// Check for plugin upgrades.
		$this->maybe_upgrade();

		// Schedule cleanup cron if not already scheduled.
		if ( ! wp_next_scheduled( 'happyaccess_cleanup_expired' ) ) {
			wp_schedule_event( time(), 'hourly', 'happyaccess_cleanup_expired' );
		}
	}

	/**
	 * Check for plugin upgrades and run migrations.
	 *
	 * @since 1.0.0
	 * @since 1.0.3 Added magic links table creation.
	 */
	private function maybe_upgrade() {
		global $wpdb;
		$current_version = get_option( 'happyaccess_version', '0.0.0' );
		
		// Only run upgrades if version has changed.
		if ( version_compare( $current_version, HAPPYACCESS_VERSION, '<' ) ) {
			// Migrate token_expiry from old 24 hours default to new 7 days default.
			$token_expiry = get_option( 'happyaccess_token_expiry' );
			if ( 86400 === (int) $token_expiry ) {
				update_option( 'happyaccess_token_expiry', 604800 );
			}
			
			// Fix for 1.0.1: Update existing tokens to allow unlimited reuse.
			// Old tokens had max_uses=1 which prevented reuse.
			if ( version_compare( $current_version, '1.0.1', '<' ) ) {
				$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Upgrade migration.
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped.
						"UPDATE `$table` SET max_uses = %d WHERE max_uses = %d AND revoked_at IS NULL",
						0,
						1
					)
				);
			}
			
			// Upgrade for 1.0.3: Create magic links table.
			if ( version_compare( $current_version, '1.0.3', '<' ) ) {
				HappyAccess_Magic_Link::create_table();
			}
			
			
			// Update version.
			update_option( 'happyaccess_version', HAPPYACCESS_VERSION );
		}
	}

	/**
	 * Initialize admin settings registration.
	 *
	 * Note: HappyAccess_Admin instance is created in load_dependencies().
	 * This hook is only for registering settings that require admin_init.
	 */
	public function admin_init() {
		// Settings are registered via HappyAccess_Admin->handle_settings().
		// No additional instantiation needed here.
	}

	/**
	 * Ensure all database tables exist.
	 *
	 * This runs on every init to handle plugin updates without deactivation.
	 *
	 * @since 1.0.3
	 */
	private function ensure_tables_exist() {
		global $wpdb;
		
		// Check magic links table (added in 1.0.3).
		$magic_table = $wpdb->prefix . 'happyaccess_magic_links';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking table existence.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$magic_table
			)
		);
		
		if ( ! $table_exists ) {
			HappyAccess_Magic_Link::create_table();
		}
	}
}

// Initialize the plugin.
HappyAccess::get_instance();
