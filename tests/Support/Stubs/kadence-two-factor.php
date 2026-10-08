<?php
/**
 * Stand-in for Kadence Security's two-factor module. Load it only inside a
 * test that runs in its own process, because a class can't be unloaded.
 *
 * Matches Kadence Security 10.0.5 (core/modules/two-factor):
 * ITSEC_Two_Factor::get_instance() and is_user_using_two_factor( $user_id )
 * in class-itsec-two-factor.php, which takes a user id only and checks the
 * current user for anything else. The module also loads its own
 * Two_Factor_Core (class-itsec-two-factor-core-compat.php), which hands the
 * same call on and has none of the Two Factor plugin's other methods.
 *
 * @package HappyAccess
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- The stub must use the plugin's class name.
class ITSEC_Two_Factor {

	/**
	 * Ids of users that have a provider turned on.
	 *
	 * @var array
	 */
	public static $users = array();

	/**
	 * The one instance.
	 *
	 * @var ITSEC_Two_Factor|null
	 */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function is_user_using_two_factor( $user_id = null ) {
		if ( ! $user_id || ! is_numeric( $user_id ) ) {
			$user_id = get_current_user_id();
		}
		return in_array( (int) $user_id, self::$users, true );
	}
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- The stub must use the plugin's class name.
class Two_Factor_Core {

	public static function is_user_using_two_factor( $user_id = null ) {
		return ITSEC_Two_Factor::get_instance()->is_user_using_two_factor( $user_id );
	}
}
