<?php
/**
 * Two-step login feature wiring.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the two-step login hooks. Plugin::init() calls register() on
 * every load, and this is the only class of the feature loaded while it is
 * off: nothing else is autoloaded and no hook is added.
 */
final class Feature {

	/**
	 * Registers the second step, the setup screen at login, the profile
	 * section with its routes, and the CLI reset while the feature is on.
	 * Every hook added here must be safe to add twice (same callback and
	 * priority), so register() needs no run-once flag.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! Features::is_enabled( 'two_step' ) ) {
			return;
		}

		Challenge::register();
		SetupSteps::register();
		Profile::register();
		RestController::register();
		Cli::register();
	}
}
