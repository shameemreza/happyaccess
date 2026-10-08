<?php
/**
 * Stand-in for Wordfence Login Security's user controller. Load it only
 * inside a test that runs in its own process.
 *
 * Matches \WordfenceLS\Controller_Users::shared() and
 * has_2fa_active( $user ) in Wordfence 9.0.2.
 *
 * @package HappyAccess
 */

namespace WordfenceLS;

class Controller_Users {

	/**
	 * Ids of users that have 2FA turned on.
	 *
	 * @var array
	 */
	public static $users = array();

	public static function shared() {
		static $shared = null;
		if ( null === $shared ) {
			$shared = new Controller_Users();
		}
		return $shared;
	}

	public function has_2fa_active( $user ) {
		return in_array( (int) $user->ID, self::$users, true );
	}
}
