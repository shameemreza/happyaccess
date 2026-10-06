<?php
/**
 * PHPUnit bootstrap.
 *
 * @package HappyAccess
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$happyaccess_tests_dir = getenv( 'WP_PHPUNIT__DIR' );

require_once $happyaccess_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require __DIR__ . '/load-plugin.php';
	}
);

require $happyaccess_tests_dir . '/includes/bootstrap.php';
