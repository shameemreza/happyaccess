<?php
/**
 * Boot switch tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Cron;
use HappyAccess\Plugin;
use HappyAccess\Rest\Routes;

class PluginBootTest extends WP_UnitTestCase {

	public function tear_down() {
		wp_clear_scheduled_hook( Cron::HOOK );
		parent::tear_down();
	}

	/**
	 * Counts how many times a callback is registered on a hook.
	 *
	 * @param string $hook     Hook name.
	 * @param array  $callback Callback.
	 * @return int
	 */
	private function count_registrations( $hook, $callback ) {
		global $wp_filter;
		$count = 0;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				if ( $registered['function'] === $callback ) {
					++$count;
				}
			}
		}
		return $count;
	}

	public function test_boot_wires_the_new_code_on_plugins_loaded() {
		Plugin::boot();
		do_action( 'plugins_loaded' );

		$this->assertNotFalse( has_filter( 'map_meta_cap', array( Capabilities::class, 'map' ) ) );
		$this->assertNotFalse( has_action( 'rest_api_init', array( Routes::class, 'routes' ) ) );
		$this->assertNotFalse( has_action( Cron::HOOK, array( Cron::class, 'run' ) ) );
	}

	public function test_boot_twice_registers_each_hook_once() {
		Plugin::boot();
		Plugin::boot();
		do_action( 'plugins_loaded' );
		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $this->count_registrations( 'plugins_loaded', array( Plugin::class, 'init' ) ) );
		$this->assertSame( 1, $this->count_registrations( 'map_meta_cap', array( Capabilities::class, 'map' ) ) );
		$this->assertSame( 1, $this->count_registrations( 'rest_api_init', array( Routes::class, 'routes' ) ) );
		$this->assertSame( 1, $this->count_registrations( Cron::HOOK, array( Cron::class, 'run' ) ) );
	}

	public function test_deactivate_clears_the_cron_event() {
		Cron::register();
		$this->assertNotFalse( wp_next_scheduled( Cron::HOOK ) );

		Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( Cron::HOOK ) );
	}
}
