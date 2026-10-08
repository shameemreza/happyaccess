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
 * the plugin may be missing, older or changed. The names were checked
 * against Two Factor 0.14.2, Wordfence 9.0.2, WP 2FA 4.2.0 and Kadence
 * Security 10.0.5.
 *
 * Kadence Security's two-factor module loads a Two_Factor_Core class of its
 * own that only hands calls on to ITSEC_Two_Factor. It has no add_hooks(),
 * so that method tells the Two Factor plugin apart from it.
 */
final class OtherTwoFactor {

	const TWO_FACTOR  = 'Two_Factor_Core';
	const WORDFENCE   = 'WordfenceLS\Controller_Users';
	const WP_2FA      = 'WP2FA\WP2FA';
	const WP_2FA_USER = 'WP2FA\Admin\Helpers\User_Helper';
	const KADENCE     = 'ITSEC_Two_Factor';

	/**
	 * Whether the user has two-step login turned on in another plugin.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function user_has_2fa( \WP_User $user ) {
		$has = self::two_factor( $user ) || self::wordfence( $user ) || self::wp_2fa( $user ) || self::kadence( $user );

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
		return array() !== self::active_plugins();
	}

	/**
	 * The names of the active two-step plugins that HappyAccess checks, for
	 * the admin notices. Product names, so they are not translated.
	 *
	 * @return string[]
	 */
	public static function active_plugins() {
		$names = array();
		if ( class_exists( self::TWO_FACTOR ) && method_exists( self::TWO_FACTOR, 'add_hooks' ) ) {
			$names[] = 'Two Factor';
		}
		if ( class_exists( self::WORDFENCE ) ) {
			$names[] = 'Wordfence';
		}
		if ( class_exists( self::WP_2FA ) ) {
			$names[] = 'WP 2FA';
		}
		if ( class_exists( self::KADENCE ) ) {
			$names[] = 'Kadence Security';
		}
		return $names;
	}

	/**
	 * The Two Factor plugin. It takes a user id or a WP_User; the id also
	 * works with Kadence Security's class of the same name, which checks the
	 * current user when it gets anything else.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	private static function two_factor( \WP_User $user ) {
		if ( ! class_exists( self::TWO_FACTOR ) || ! method_exists( self::TWO_FACTOR, 'is_user_using_two_factor' ) ) {
			return false;
		}
		return (bool) call_user_func( array( self::TWO_FACTOR, 'is_user_using_two_factor' ), (int) $user->ID );
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

	/**
	 * WP 2FA: a user with an enabled method.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	private static function wp_2fa( \WP_User $user ) {
		if ( ! class_exists( self::WP_2FA ) || ! class_exists( self::WP_2FA_USER ) || ! method_exists( self::WP_2FA_USER, 'is_user_using_two_factor' ) ) {
			return false;
		}
		return (bool) call_user_func( array( self::WP_2FA_USER, 'is_user_using_two_factor' ), $user );
	}

	/**
	 * Kadence Security's two-factor module, loaded only while the module is
	 * on. It takes a user id, and answers null when two-factor is turned off
	 * for the whole site.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	private static function kadence( \WP_User $user ) {
		if ( ! class_exists( self::KADENCE ) || ! method_exists( self::KADENCE, 'get_instance' ) || ! method_exists( self::KADENCE, 'is_user_using_two_factor' ) ) {
			return false;
		}
		$module = call_user_func( array( self::KADENCE, 'get_instance' ) );
		return is_object( $module ) && (bool) $module->is_user_using_two_factor( (int) $user->ID );
	}
}
