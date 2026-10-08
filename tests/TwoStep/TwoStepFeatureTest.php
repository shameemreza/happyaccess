<?php
/**
 * Two-step login feature wiring tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\Feature;
use HappyAccess\Login\Router;
use HappyAccess\Plugin;

class TwoStepFeatureTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
	}

	public function tear_down() {
		Router::reset();
		parent::tear_down();
	}

	/**
	 * A fingerprint of every hook callback, to compare before and after.
	 *
	 * @return array
	 */
	private function hook_snapshot() {
		global $wp_filter;
		$snapshot = array();
		foreach ( $wp_filter as $name => $hook ) {
			$count = 0;
			foreach ( $hook->callbacks as $callbacks ) {
				$count += count( $callbacks );
			}
			$snapshot[ $name ] = $count;
		}
		ksort( $snapshot );
		return $snapshot;
	}

	public function test_the_feature_is_off_by_default() {
		$this->assertFalse( Features::is_enabled( 'two_step' ) );
	}

	public function test_register_with_the_feature_off_adds_no_hook() {
		Settings::all();
		$before = $this->hook_snapshot();
		Feature::register();
		$this->assertSame( $before, $this->hook_snapshot() );
	}

	public function test_register_with_the_feature_on_can_run_twice() {
		Features::set( 'two_step', true );
		Settings::all();
		Feature::register();
		$once = $this->hook_snapshot();
		Feature::register();
		$this->assertSame( $once, $this->hook_snapshot() );
	}

	/**
	 * Booting the plugin with the feature off loads no TwoStep class except
	 * Feature. A separate process keeps classes other tests loaded out of the
	 * answer.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_boot_with_the_feature_off_loads_only_the_feature_class() {
		Features::set( 'two_step', false );
		Plugin::boot();
		do_action( 'plugins_loaded' );

		$this->assertTrue( class_exists( 'HappyAccess\Features\TwoStep\Feature', false ) );
		$files = glob( HAPPYACCESS_PLUGIN_DIR . 'src/Features/TwoStep/*.php' );
		$this->assertGreaterThan( 4, count( $files ) );
		foreach ( $files as $file ) {
			$class = 'HappyAccess\Features\TwoStep\\' . basename( $file, '.php' );
			if ( 'HappyAccess\Features\TwoStep\Feature' !== $class ) {
				$this->assertFalse( class_exists( $class, false ), $class . ' is loaded' );
			}
		}
	}
}
