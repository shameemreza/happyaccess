<?php
/**
 * Stand-in for WP 2FA's main class and user helper. Load it only inside a
 * test that runs in its own process, because a class can't be unloaded.
 *
 * Matches WP 2FA 4.2.0: the class \WP2FA\WP2FA
 * (includes/classes/class-wp2fa.php) and the static
 * \WP2FA\Admin\Helpers\User_Helper::is_user_using_two_factor( $user = null )
 * (includes/classes/Admin/Helpers/class-user-helper.php), which takes a
 * user id or a WP_User.
 *
 * @package HappyAccess
 */

namespace WP2FA {

	class WP2FA {
	}
}

namespace WP2FA\Admin\Helpers {

	class User_Helper {

		/**
		 * Ids of users that have a method turned on.
		 *
		 * @var array
		 */
		public static $users = array();

		public static function is_user_using_two_factor( $user = null ) {
			$id = $user instanceof \WP_User ? $user->ID : (int) $user;
			return in_array( (int) $id, self::$users, true );
		}
	}
}
