<?php
/**
 * Class autoloader for the HappyAccess namespace.
 *
 * @package HappyAccess
 */

namespace HappyAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Maps HappyAccess\Foo\Bar to src/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Registers the loader once.
	 *
	 * @return void
	 */
	public static function register() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		spl_autoload_register( array( __CLASS__, 'load' ) );
		$registered = true;
	}

	/**
	 * Loads one class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
}
