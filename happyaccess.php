<?php
/**
 * Plugin Name:       HappyAccess
 * Plugin URI:        https://wordpress.org/plugins/happyaccess
 * Description:       Give support temporary access without sharing a password, let people log in with an email code, and add two-step login.
 * Version:           1.1.0
 * Author:            Shameem Reza
 * Author URI:        https://shameem.dev/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       happyaccess
 * Domain Path:       /languages
 * Requires at least: 6.7
 * Requires PHP:      7.4
 *
 * @package HappyAccess
 */

use HappyAccess\Plugin;

defined( 'ABSPATH' ) || exit;

// The test bootstrap defines these first, so each one is guarded.
defined( 'HAPPYACCESS_VERSION' ) || define( 'HAPPYACCESS_VERSION', '1.1.0' );
defined( 'HAPPYACCESS_PLUGIN_FILE' ) || define( 'HAPPYACCESS_PLUGIN_FILE', __FILE__ );
defined( 'HAPPYACCESS_PLUGIN_DIR' ) || define( 'HAPPYACCESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'HAPPYACCESS_PLUGIN_URL' ) || define( 'HAPPYACCESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
defined( 'HAPPYACCESS_PLUGIN_BASENAME' ) || define( 'HAPPYACCESS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once HAPPYACCESS_PLUGIN_DIR . 'src/Autoloader.php';
\HappyAccess\Autoloader::register();

/**
 * Declares support for WooCommerce order storage (HPOS) and the cart and
 * checkout blocks.
 *
 * @return void
 */
function happyaccess_declare_woocommerce_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HAPPYACCESS_PLUGIN_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', HAPPYACCESS_PLUGIN_FILE, true );
}
add_action( 'before_woocommerce_init', 'happyaccess_declare_woocommerce_compatibility' );

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

Plugin::boot();
