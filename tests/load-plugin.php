<?php
/**
 * Loads the plugin code under test.
 *
 * Stage 1 loads only the new core, without booting it. Stage 2 replaces
 * this file's body with a require of happyaccess.php.
 *
 * @package HappyAccess
 */

$happyaccess_root = dirname( __DIR__ );

defined( 'HAPPYACCESS_VERSION' ) || define( 'HAPPYACCESS_VERSION', '1.1.0' );
defined( 'HAPPYACCESS_PLUGIN_FILE' ) || define( 'HAPPYACCESS_PLUGIN_FILE', $happyaccess_root . '/happyaccess.php' );
defined( 'HAPPYACCESS_PLUGIN_DIR' ) || define( 'HAPPYACCESS_PLUGIN_DIR', $happyaccess_root . '/' );
defined( 'HAPPYACCESS_PLUGIN_BASENAME' ) || define( 'HAPPYACCESS_PLUGIN_BASENAME', 'happyaccess/happyaccess.php' );

if ( file_exists( $happyaccess_root . '/src/Autoloader.php' ) ) {
	require_once $happyaccess_root . '/src/Autoloader.php';
	\HappyAccess\Autoloader::register();
}
