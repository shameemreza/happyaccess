<?php
/**
 * Stand-in for the parts of WP-CLI the HappyAccess commands call. Load it
 * only inside a test that runs in its own process. The real error() stops
 * the command; this one only records the message.
 *
 * @package HappyAccess
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- The stub must use WP-CLI's class name.
class WP_CLI {

	/**
	 * Commands added, name to callable.
	 *
	 * @var array
	 */
	public static $commands = array();

	/**
	 * Output calls, as array( type, message ).
	 *
	 * @var array
	 */
	public static $calls = array();

	public static function add_command( $name, $callable ) {
		self::$commands[ $name ] = $callable;
		return true;
	}

	public static function success( $message ) {
		self::$calls[] = array( 'success', $message );
	}

	public static function error( $message ) {
		self::$calls[] = array( 'error', $message );
	}
}
