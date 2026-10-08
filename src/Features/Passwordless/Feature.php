<?php
/**
 * Passwordless login feature wiring.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\Passwordless;

use HappyAccess\Core\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the passwordless login hooks. Plugin::init() calls register() on
 * every load, and this is the only class of the feature loaded while it is
 * off: nothing else is autoloaded and no hook is added.
 */
final class Feature {

	/**
	 * Registers the login steps, forms, REST routes and role policy while
	 * the feature is on. Every hook added here must be safe to add twice
	 * (same callback and priority), so register() needs no run-once flag.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! Features::is_enabled( 'passwordless' ) ) {
			return;
		}

		LoginSteps::register();
		RolePolicy::register();
		Forms::register();
		Shortcode::register();
		RestController::register();
	}
}
