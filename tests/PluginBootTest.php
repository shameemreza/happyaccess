<?php
/**
 * Boot switch tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Admin\Page;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Cron;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Login\Router;
use HappyAccess\Plugin;
use HappyAccess\Rest\Routes;

class PluginBootTest extends WP_UnitTestCase {

	/**
	 * Every class the 1.0.6 code defined.
	 */
	const LEGACY_CLASSES = array(
		'HappyAccess',
		'HappyAccess_Access_Guard',
		'HappyAccess_Activator',
		'HappyAccess_Admin',
		'HappyAccess_Cleanup',
		'HappyAccess_Deactivator',
		'HappyAccess_GDPR',
		'HappyAccess_Logger',
		'HappyAccess_Login_Handler',
		'HappyAccess_Magic_Link',
		'HappyAccess_OTP_Handler',
		'HappyAccess_OTP_Share',
		'HappyAccess_Rate_Limiter',
		'HappyAccess_ReCaptcha',
		'HappyAccess_Temp_User',
		'HappyAccess_Token_Manager',
	);

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
	}

	public function tear_down() {
		wp_clear_scheduled_hook( Cron::HOOK );
		$GLOBALS['current_screen'] = null;
		Router::reset();
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

	public function test_boot_registers_the_rest_routes_the_admin_page_and_the_capability_map() {
		Plugin::boot();
		do_action( 'plugins_loaded' );

		$this->assertNotFalse( has_action( 'rest_api_init', array( Routes::class, 'routes' ) ) );
		$this->assertNotFalse( has_action( 'admin_menu', array( Page::class, 'add_menu' ) ) );
		$this->assertNotFalse( has_filter( 'map_meta_cap', array( Capabilities::class, 'map' ) ) );
	}

	public function test_the_plugin_file_boots_the_new_code_and_loads_no_legacy_class() {
		set_current_screen( 'dashboard' );

		require_once HAPPYACCESS_PLUGIN_DIR . 'happyaccess.php';
		do_action( 'plugins_loaded' );

		$this->assertSame( 5, has_action( 'plugins_loaded', array( Plugin::class, 'init' ) ) );
		$this->assertNotFalse( has_action( 'admin_menu', array( Page::class, 'add_menu' ) ) );
		foreach ( self::LEGACY_CLASSES as $class ) {
			$this->assertFalse( class_exists( $class, false ), $class . ' is still loaded' );
		}
		$this->assertFalse( has_action( 'admin_bar_menu', array( 'HappyAccess_Admin', 'add_emergency_lock_button' ) ) );
	}

	public function test_router_handles_the_code_step_when_support_access_is_on() {
		$this->assertTrue( Features::is_enabled( 'support_access' ) );

		Plugin::boot();
		do_action( 'plugins_loaded' );

		$this->assertSame( 10, has_action( 'login_form_' . Router::ACTION, array( Router::class, 'dispatch' ) ) );
		$this->assertNotNull( Router::resolve( 'code' ) );
	}

	public function test_router_is_hooked_even_when_support_access_is_off() {
		Features::set( 'support_access', false );

		Plugin::boot();
		do_action( 'plugins_loaded' );

		$this->assertNotFalse( has_action( 'login_form_' . Router::ACTION, array( Router::class, 'dispatch' ) ) );
		$this->assertNull( Router::resolve( 'code' ) );
	}

	public function test_new_sites_are_set_up_at_priority_11() {
		Plugin::boot();
		do_action( 'plugins_loaded' );

		$this->assertSame( 11, has_action( 'wp_initialize_site', array( Installer::class, 'on_new_site' ) ) );
		$this->assertNotFalse( has_action( Installer::NETWORK_HOOK, array( Installer::class, 'network_upgrade' ) ) );
	}

	public function test_migration_clears_the_legacy_cron_hooks() {
		wp_schedule_event( time(), 'hourly', 'happyaccess_cleanup_expired' );
		wp_schedule_event( time(), 'daily', 'happyaccess_cleanup_attempts' );
		delete_option( 'happyaccess_db_version' );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		foreach ( Installer::LEGACY_CRON_HOOKS as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), $hook . ' is still scheduled' );
		}
		$this->assertSame( array( 'happyaccess_cleanup_expired', 'happyaccess_cleanup_attempts' ), Installer::LEGACY_CRON_HOOKS );
	}

	public function test_deactivate_clears_the_cron_event() {
		Cron::register();
		$this->assertNotFalse( wp_next_scheduled( Cron::HOOK ) );

		Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( Cron::HOOK ) );
	}
}
