<?php
/**
 * Passwordless feature wiring tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\Passwordless\Feature;
use HappyAccess\Login\Router;
use HappyAccess\Plugin;

class PasswordlessFeatureTest extends WP_UnitTestCase {

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
		$this->assertFalse( Features::is_enabled( 'passwordless' ) );
	}

	public function test_register_with_the_feature_off_adds_no_hook() {
		// The first settings read adds the settings cache hooks, so take it before the snapshot.
		Settings::all();
		$before = $this->hook_snapshot();
		Feature::register();
		$this->assertSame( $before, $this->hook_snapshot() );
	}

	public function test_register_with_the_feature_on_can_run_twice() {
		Features::set( 'passwordless', true );
		Settings::all();
		Feature::register();
		$once = $this->hook_snapshot();
		Feature::register();
		$this->assertSame( $once, $this->hook_snapshot() );
	}

	/**
	 * Booting the plugin with the feature off loads no Passwordless class
	 * except Feature, and adds no hook of its own. A separate process keeps
	 * classes other tests loaded out of the answer.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_boot_with_the_feature_off_loads_only_the_feature_class() {
		Features::set( 'passwordless', false );
		Plugin::boot();
		do_action( 'plugins_loaded' );

		$this->assertTrue( class_exists( 'HappyAccess\Features\Passwordless\Feature', false ) );
		$this->assertFalse( class_exists( 'HappyAccess\Features\Passwordless\Requests', false ) );
		foreach ( glob( HAPPYACCESS_PLUGIN_DIR . 'src/Features/Passwordless/*.php' ) as $file ) {
			$class = 'HappyAccess\Features\Passwordless\\' . basename( $file, '.php' );
			if ( 'HappyAccess\Features\Passwordless\Feature' !== $class ) {
				$this->assertFalse( class_exists( $class, false ), $class . ' is loaded' );
			}
		}
	}
}
