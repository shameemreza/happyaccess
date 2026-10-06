<?php
/**
 * Smoke test for the test setup.
 *
 * @package HappyAccess
 */

class SmokeTest extends WP_UnitTestCase {

	public function test_wordpress_and_constants_load() {
		$this->assertTrue( function_exists( 'wp_insert_post' ) );
		$this->assertTrue( defined( 'HAPPYACCESS_PLUGIN_DIR' ) );
	}
}
