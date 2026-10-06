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
}
