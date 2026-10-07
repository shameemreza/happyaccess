<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Support passes and scheduled events always go. Data goes only when
 * "Delete data on uninstall" is on.
 *
 * @package HappyAccess
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'src/Autoloader.php';
\HappyAccess\Autoloader::register();

\HappyAccess\Core\Uninstaller::run();
