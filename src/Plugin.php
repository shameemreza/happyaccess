<?php
/**
 * Boots the 1.1.0 code.
 *
 * @package HappyAccess
 */

namespace HappyAccess;

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Cron;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Privacy;
use HappyAccess\Features\SupportAccess\Feature;
use HappyAccess\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every 1.1.0 class. It runs only when HAPPYACCESS_NEXT is true.
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
		Installer::maybe_upgrade();
		Capabilities::register();
		Cron::register();
		Privacy::register();
		Feature::register();
		Routes::register();

		// The admin page arrives in a later task, so skip it until the class exists.
		if ( class_exists( '\HappyAccess\Admin\Page' ) ) {
			\HappyAccess\Admin\Page::register();
		}
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
