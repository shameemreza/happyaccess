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
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;
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
		$this->assertNotFalse( has_action( 'happyaccess_grant_ended', array( \HappyAccess\Admin\AuthorCard::class, 'note_expiry' ) ) );
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
		Cron::schedule();
		$this->assertNotFalse( wp_next_scheduled( Cron::HOOK ) );

		Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( Cron::HOOK ) );
	}

	public function test_deactivate_clears_the_network_upgrade_event() {
		wp_schedule_single_event( time() + 60, Installer::NETWORK_HOOK );
		$this->assertNotFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );

		Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );
	}

	/**
	 * Makes a pass and its temp user.
	 *
	 * @param string $label Pass label.
	 * @return array Grant id and temp user id.
	 */
	private function pass_with_user( $label ) {
		$made = Grants::create(
			array(
				'label'    => $label,
				'duration' => DAY_IN_SECONDS,
			)
		);
		return array( $made['id'], TempUsers::get_or_create( Grants::get( $made['id'] ) ) );
	}

	public function test_deactivate_as_an_admin_revokes_every_pass_and_keeps_the_data() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		Settings::update( array( 'privacy' => array( 'retention_days' => 90 ) ) );
		$settings = get_option( Settings::OPTION );
		Cron::schedule();
		list( $first, $first_user )   = $this->pass_with_user( 'One' );
		list( $second, $second_user ) = $this->pass_with_user( 'Two' );

		Plugin::deactivate();

		$this->assertGreaterThan( 0, Grants::get( $first )['revoked_at'] );
		$this->assertGreaterThan( 0, Grants::get( $second )['revoked_at'] );
		$this->assertSame( 'plugin_deactivated', Grants::get( $first )['end_reason'] );
		$this->assertFalse( get_userdata( $first_user ) );
		$this->assertFalse( get_userdata( $second_user ) );
		$this->assertFalse( TempUsers::any_exist() );
		$this->assertSame( $settings, get_option( Settings::OPTION ) );
		$this->assertSame( array(), Grants::list_current() );
		$this->assertTrue( Installer::table_exists( 'tokens' ) );
		$this->assertTrue( Installer::table_exists( 'logs' ) );
		$this->assertFalse( wp_next_scheduled( Cron::HOOK ) );
		$this->assertNotFalse( get_userdata( $admin ) );
	}

	public function test_deactivate_as_a_temp_user_changes_nothing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Cron::schedule();
		list( $id, $user_id ) = $this->pass_with_user( 'Acme' );
		wp_set_current_user( $user_id );

		Plugin::deactivate();

		$this->assertSame( 0, Grants::get( $id )['revoked_at'] );
		$this->assertNotFalse( get_userdata( $user_id ) );
		$this->assertNotFalse( wp_next_scheduled( Cron::HOOK ) );
	}

	public function test_the_plugins_row_gets_a_settings_link_for_managers_only() {
		Plugin::boot();
		do_action( 'plugins_loaded' );
		$filter = 'plugin_action_links_' . HAPPYACCESS_PLUGIN_BASENAME;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$links = apply_filters( $filter, array( 'deactivate' => '<a href="#">Deactivate</a>' ) );
		$this->assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'users.php?page=happyaccess' ) ) . '"', $links['settings'] );
		$this->assertStringContainsString( '>Settings</a>', $links['settings'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( array( 'deactivate' ), array_keys( apply_filters( $filter, array( 'deactivate' => 'x' ) ) ) );

		wp_set_current_user( 0 );
		$this->assertSame( array( 'deactivate' ), array_keys( apply_filters( $filter, array( 'deactivate' => 'x' ) ) ) );
	}

	public function test_the_settings_link_is_registered_once() {
		Plugin::boot();
		do_action( 'plugins_loaded' );
		do_action( 'plugins_loaded' );

		$this->assertSame( 1, $this->count_registrations( 'plugin_action_links_' . HAPPYACCESS_PLUGIN_BASENAME, array( Plugin::class, 'action_links' ) ) );
	}
}
