<?php
/**
 * Stand-in for Kadence Security's module list. Load it only inside a test
 * that runs in its own process, because a class can't be unloaded.
 *
 * Matches the static ITSEC_Modules::is_active( $module_id ) in Kadence
 * Security 10.0.5 (core/modules.php). The real plugin always loads this
 * class; its two-factor module only while that module is on.
 *
 * @package HappyAccess
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- The stub must use the plugin's class name.
final class ITSEC_Modules {

	/**
	 * Module ids that are on, as keys.
	 *
	 * @var array
	 */
	public static $active = array();

	public static function is_active( $module_id ) {
		return ! empty( self::$active[ $module_id ] );
	}
}
