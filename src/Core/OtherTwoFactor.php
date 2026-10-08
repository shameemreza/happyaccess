<?php
/**
 * Two-step login from other plugins.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Says whether a user has two-step login turned on in another plugin. A login
 * that skips the password form also skips those plugins' checks, so a login
 * path of our own hands these users back to the password form.
 *
 * Every plugin call sits behind class_exists() and method_exists(), because
 * the plugin may be missing, older or changed. The method names were checked
 * against Two Factor 0.14.2 and Wordfence 9.0.2.
 */
final class OtherTwoFactor {

	const TWO_FACTOR = 'Two_Factor_Core';
	const WORDFENCE  = 'WordfenceLS\Controller_Users';

	/**
	 * Whether the user has two-step login turned on in another plugin.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function user_has_2fa( \WP_User $user ) {
		$has = self::two_factor( $user ) || self::wordfence( $user );

		/**
		 * Filters whether a user has two-step login from another plugin.
		 * Return true for a plugin HappyAccess doesn't check.
		 *
		 * @param bool     $has  Whether the user has it.
		 * @param \WP_User $user The user.
		 */
		return (bool) apply_filters( 'happyaccess_user_has_other_2fa', $has, $user );
	}

	/**
	 * Whether a two-step plugin that HappyAccess checks is active.
	 *
	 * @return bool
	 */
	public static function plugin_active() {
		return class_exists( self::TWO_FACTOR ) || class_exists( self::WORDFENCE );
	}

	/**
	 * The Two Factor plugin.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	private static function two_factor( \WP_User $user ) {
		if ( ! class_exists( self::TWO_FACTOR ) || ! method_exists( self::TWO_FACTOR, 'is_user_using_two_factor' ) ) {
			return false;
		}
		return (bool) call_user_func( array( self::TWO_FACTOR, 'is_user_using_two_factor' ), $user );
	}

	/**
	 * Wordfence Login Security, inside Wordfence or on its own.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	private static function wordfence( \WP_User $user ) {
		if ( ! class_exists( self::WORDFENCE ) || ! method_exists( self::WORDFENCE, 'shared' ) || ! method_exists( self::WORDFENCE, 'has_2fa_active' ) ) {
			return false;
		}
		$users = call_user_func( array( self::WORDFENCE, 'shared' ) );
		return is_object( $users ) && (bool) $users->has_2fa_active( $user );
	}
}
