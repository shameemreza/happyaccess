<?php
/**
 * Support Access feature wiring.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\Features;
use HappyAccess\Login\Router;
use HappyAccess\Login\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Support Access hooks. Every hook added here is safe to add
 * twice (same callback and priority), so register() needs no run-once flag.
 */
final class Feature {

	/**
	 * Registers the guards and, when the feature is on, the login entry points.
	 *
	 * The guards keep running while any grant or temp user exists, even after
	 * the feature is switched off, so a live or leftover temp user never loses
	 * its limits.
	 *
	 * @return void
	 */
	public static function register() {
		$enabled = Features::is_enabled( 'support_access' );

		if ( ! $enabled && ! Grants::has_current() && ! TempUsers::any_exist() ) {
			return;
		}

		Session::set_resolver( array( Grants::class, 'resolve_user' ) );
		Session::register();
		CapabilityGuard::register();
		AccountGuard::register();
		MenuGuard::register();
		ActivityTracker::register();
		AdminWatch::register();
		AdminBar::register();
		Notifications::register();

		if ( ! $enabled ) {
			return;
		}

		Router::register();
		LoginSteps::register();
		Cli::register();
	}

	/**
	 * Ends every grant. Runs when the Support Access switch is turned off.
	 *
	 * @return void
	 */
	public static function on_disable() {
		Grants::revoke_all( 'feature_disabled' );
	}
}
