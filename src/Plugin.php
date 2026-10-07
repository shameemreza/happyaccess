<?php
/**
 * Boots the 1.1.0 code.
 *
 * @package HappyAccess
 */

namespace HappyAccess;

use HappyAccess\Admin\Page;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Cron;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Privacy;
use HappyAccess\Features\SupportAccess\Feature;
use HappyAccess\Login\Router;
use HappyAccess\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every class. happyaccess.php calls boot() on every load.
 */
final class Plugin {

	/**
	 * Hooks the setup to plugins_loaded. Safe to call twice.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'plugins_loaded', array( self::class, 'init' ), 5 );
	}

	/**
	 * Registers each class, in order.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_initialize_site', array( Installer::class, 'on_new_site' ), 11 );
		add_action( Installer::NETWORK_HOOK, array( Installer::class, 'network_upgrade' ) );
		Installer::maybe_upgrade();
		Capabilities::register();
		Cron::register();
		Privacy::register();
		// Hooked whether or not Support Access is on: with no steps added, the dispatcher sends visitors to the normal login.
		Router::register();
		Feature::register();
		Routes::register();
		Page::register();
	}

	/**
	 * Activation entry point.
	 *
	 * @param bool $network_wide Whether the plugin is network activated.
	 * @return void
	 */
	public static function activate( $network_wide ) {
		Installer::activate( $network_wide );
	}

	/**
	 * Deactivation entry point. Only stops the cleanup event for now.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Cron::unschedule();
	}
}
