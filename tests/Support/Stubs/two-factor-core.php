<?php
/**
 * Stand-in for the Two Factor plugin's core class. Load it only inside a
 * test that runs in its own process, because a class can't be unloaded.
 *
 * Matches Two_Factor_Core::is_user_using_two_factor( $user = null ) in
 * Two Factor 0.14.2, which takes a user id or a WP_User, and add_hooks(),
 * which Kadence Security's class of the same name lacks.
 *
 * @package HappyAccess
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- The stub must use the plugin's class name.
class Two_Factor_Core {

	/**
	 * Ids of users that have a provider turned on.
	 *
	 * @var array
	 */
	public static $users = array();

	public static function add_hooks( $compat ) {
		unset( $compat );
	}

	public static function is_user_using_two_factor( $user = null ) {
		$id = $user instanceof WP_User ? $user->ID : (int) $user;
		return in_array( (int) $id, self::$users, true );
	}
}
