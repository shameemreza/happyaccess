<?php
/**
 * REST base for the HappyAccess admin.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Rest;

use HappyAccess\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every happyaccess/v1 route. Each route lets in only users who
 * can manage HappyAccess, so temp users and visitors are always refused.
 */
final class Routes {

	const NS = 'happyaccess/v1';

	/**
	 * Hooks route registration. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers each controller's routes.
	 *
	 * @return void
	 */
	public static function routes() {
		GrantsController::routes();
		ActivityController::routes();
		SettingsController::routes();
		AuthorCardController::routes();
	}

	/**
	 * Permission check shared by every route.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( Capabilities::MANAGE );
	}
}
