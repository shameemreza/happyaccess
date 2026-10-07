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
	 * Characters a class name may hold. Anything else, such as dots, slashes
	 * or a null byte, could point the loader outside src/.
	 */
	const NAME_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_\\';

	/**
	 * Loads one class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		$file = self::file_for( $class_name );
		if ( '' !== $file ) {
			require $file;
		}
	}

	/**
	 * The file for a class in this namespace.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return string Path, or an empty string when the name isn't ours, holds a character outside letters, digits, underscore and backslash, or has no readable file.
	 */
	public static function file_for( $class_name ) {
		$class_name = (string) $class_name;
		$prefix     = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) || strlen( $class_name ) !== strspn( $class_name, self::NAME_CHARS ) ) {
			return '';
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		return is_readable( $file ) ? $file : '';
	}
}
