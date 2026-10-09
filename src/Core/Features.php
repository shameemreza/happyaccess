<?php
/**
 * Feature switches.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sets which features are on.
 */
final class Features {

	const ALL = array( 'support_access', 'passwordless', 'two_step' );

	/**
	 * Non-autoloaded flag: the rewrite rules need a flush on the next init,
	 * once the My Account endpoint of two-step login is registered.
	 */
	const REWRITE_FLUSH_OPTION = 'happyaccess_rewrite_flush';

	/**
	 * Whether a feature is on.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public static function is_enabled( $feature ) {
		return in_array( $feature, self::ALL, true ) && true === Settings::get( 'features.' . $feature );
	}

	/**
	 * Turns a feature on or off.
	 *
	 * @param string $feature Feature key.
	 * @param bool   $enabled New state.
	 * @return bool False for an unknown feature.
	 */
	public static function set( $feature, $enabled ) {
		if ( ! in_array( $feature, self::ALL, true ) ) {
			return false;
		}
		Settings::update( array( 'features' => array( $feature => (bool) $enabled ) ) );
		return true;
	}

	/**
	 * Asks for one rewrite flush on the next request. Two-step login adds a
	 * My Account endpoint, and its rules only exist once the rules are
	 * built again with it registered.
	 *
	 * @return void
	 */
	public static function request_rewrite_flush() {
		Internal::run(
			static function () {
				update_option( self::REWRITE_FLUSH_OPTION, '1', false );
			}
		);
	}
}
