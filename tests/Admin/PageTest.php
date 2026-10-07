<?php
/**
 * Admin page tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Admin\Page;
use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;
use HappyAccess\Rest\Routes;

class PageTest extends WP_UnitTestCase {

	/**
	 * Admin menu globals as they were before the test.
	 *
	 * @var array
	 */
	private $menu_globals = array();

	/**
	 * Settings globals as they were before the REST server started.
	 *
	 * @var array
	 */
	private $settings_globals = array();

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		Installer::install();
		Clock::freeze( 1790000000 );
		Capabilities::register();

		foreach ( array( 'menu', 'submenu', '_registered_pages', '_parent_pages', 'admin_page_hooks' ) as $name ) {
			$this->menu_globals[ $name ] = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;
		}
		$GLOBALS['menu']    = array();
		$GLOBALS['submenu'] = array();

		foreach ( array( 'new_allowed_options', 'wp_registered_settings' ) as $name ) {
			$this->settings_globals[ $name ] = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;
		}
	}

	public function tear_down() {
		wp_dequeue_script( Page::HANDLE );
		wp_deregister_script( Page::HANDLE );
		wp_dequeue_style( Page::HANDLE );
		wp_deregister_style( Page::HANDLE );
		foreach ( $this->menu_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
		foreach ( $this->settings_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_scripts()->add_data( 'wp-api-fetch', 'after', array() );
		Clock::freeze( null );
		parent::tear_down();
	}

	public function test_register_hooks_the_menu_and_the_assets() {
		Page::register();

		$this->assertNotFalse( has_action( 'admin_menu', array( Page::class, 'add_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( Page::class, 'enqueue' ) ) );
	}

	public function test_menu_is_registered_under_users_for_the_manage_cap() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Page::add_menu();

		$this->assertArrayHasKey( 'users.php', $GLOBALS['submenu'] );
		$found = array_values(
			array_filter(
				$GLOBALS['submenu']['users.php'],
				static function ( $item ) {
					return Page::SLUG === $item[2];
				}
			)
		);
		$this->assertCount( 1, $found );
		$this->assertSame( Capabilities::MANAGE, $found[0][1] );
		$this->assertSame( 'HappyAccess', $found[0][0] );
	}

	public function test_a_temp_user_does_not_get_the_menu() {
		$made = Grants::create( array( 'label' => 'Agent' ) );
		wp_set_current_user( TempUsers::get_or_create( Grants::get( $made['id'] ) ) );
		Grants::flush_cache();

		Page::add_menu();

		$slugs = isset( $GLOBALS['submenu']['users.php'] ) ? wp_list_pluck( $GLOBALS['submenu']['users.php'], 2 ) : array();
		$this->assertNotContains( Page::SLUG, $slugs );
	}

	public function test_assets_enqueue_only_on_our_screen() {
		if ( ! is_readable( HAPPYACCESS_PLUGIN_DIR . 'build/index.asset.php' ) ) {
			$this->markTestSkipped( 'Run npm run build first.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Page::add_menu();
		$this->assertNotSame( '', Page::hook_suffix() );

		Page::enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( Page::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Page::HANDLE, 'enqueued' ) );

		Page::enqueue( Page::hook_suffix() );
		$this->assertTrue( wp_script_is( Page::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Page::HANDLE, 'enqueued' ) );

		$deps = wp_scripts()->registered[ Page::HANDLE ]->deps;
		$this->assertContains( 'wp-date', $deps );
		$before = wp_scripts()->get_data( Page::HANDLE, 'before' );
		$this->assertStringContainsString( 'window.happyaccessBoot = ', implode( '', (array) $before ) );
		$this->assertContains( 'wp-components', wp_styles()->registered[ Page::HANDLE ]->deps );
	}

	public function test_nothing_enqueues_when_no_menu_was_registered() {
		wp_set_current_user( 0 );
		Page::add_menu();
		$this->assertSame( '', Page::hook_suffix() );

		Page::enqueue( 'users_page_happyaccess' );

		$this->assertFalse( wp_script_is( Page::HANDLE, 'enqueued' ) );
	}

	public function test_boot_data_has_the_listed_keys_and_no_secrets() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$data = Page::boot_data();

		foreach ( array( 'siteName', 'homeUrl', 'loginUrl', 'codeUrl', 'adminUrl', 'currentUser', 'timezone', 'features', 'needsSetup', 'menus', 'maxDays', 'isMultisite', 'roles' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		$this->assertSame( 30, $data['maxDays'] );
		$this->assertTrue( $data['needsSetup'] );
		$this->assertSame( array( 'id', 'name' ), array_keys( $data['currentUser'] ) );
		$this->assertStringContainsString( 'step=code', $data['codeUrl'] );

		$json = wp_json_encode( $data );
		foreach ( array( 'link_key', '_hash', 'recaptcha', 'otp_code', '"code"' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	public function test_boot_data_lists_roles_as_slug_and_name() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$roles = Page::boot_data()['roles'];

		$this->assertSame( array( 'slug', 'name' ), array_keys( $roles[0] ) );
		$this->assertContains( 'editor', wp_list_pluck( $roles, 'slug' ) );
		$this->assertSame( array_values( $roles ), $roles );
	}

	public function test_boot_data_needs_setup_follows_consent() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\HappyAccess\Core\Settings::update( array( 'support' => array( 'consent_given_at' => '2026-01-01 00:00:00' ) ) );

		$this->assertFalse( Page::boot_data()['needsSetup'] );
	}

	public function test_render_prints_the_mount_point() {
		ob_start();
		Page::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap"><hr class="wp-header-end"><div id="happyaccess-root" class="happyaccess-app"></div>', $html );
	}

	public function test_nothing_is_preloaded_before_setup() {
		$this->assertSame( array(), Page::preload_paths() );
	}

	public function test_preload_paths_match_what_the_app_requests() {
		\HappyAccess\Core\Settings::update( array( 'support' => array( 'consent_given_at' => '2026-01-01 00:00:00' ) ) );

		// 1790000000 is 2026-09-21 in UTC, the test site's timezone.
		$this->assertSame(
			array(
				'/happyaccess/v1/settings',
				'/happyaccess/v1/grants',
				'/happyaccess/v1/catalog',
				'/happyaccess/v1/activity?since=2026-09-15&until=2026-09-21&page=1&per_page=25',
			),
			Page::preload_paths()
		);
	}

	public function test_preload_days_follow_the_site_timezone() {
		\HappyAccess\Core\Settings::update( array( 'support' => array( 'consent_given_at' => '2026-01-01 00:00:00' ) ) );
		update_option( 'timezone_string', 'Pacific/Auckland' );
		// 2026-09-21 13:46 UTC is already the 22nd in Auckland.
		$this->assertStringContainsString( 'since=2026-09-16&until=2026-09-22', Page::preload_paths()[3] );
	}

	/**
	 * The script printed after wp-api-fetch, decoded.
	 *
	 * @return array{0: string, 1: array} The script and the preload data.
	 */
	private function printed_preload() {
		$after  = implode( '', (array) wp_scripts()->get_data( 'wp-api-fetch', 'after' ) );
		$prefix = 'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( ';
		$this->assertStringContainsString( $prefix, $after );

		$json = substr( $after, strpos( $after, $prefix ) + strlen( $prefix ) );
		$json = substr( $json, 0, strrpos( $json, ' ) );' ) );
		$data = json_decode( $json, true );
		$this->assertIsArray( $data );
		return array( $after, $data );
	}

	public function test_enqueue_preloads_the_first_view_without_secrets() {
		if ( ! is_readable( HAPPYACCESS_PLUGIN_DIR . 'build/index.asset.php' ) ) {
			$this->markTestSkipped( 'Run npm run build first.' );
		}
		\HappyAccess\Core\Settings::update( array( 'support' => array( 'consent_given_at' => '2026-01-01 00:00:00' ) ) );
		update_option( \HappyAccess\Rest\SettingsController::SECRET_OPTION, 'recaptcha-private-value' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Grants::create(
			array(
				'label' => 'Vendor pass',
				'email' => 'agent@example.com',
			)
		);
		AuditLog::add( 'grant_created', array( 'feature' => 'support' ) );
		Routes::register();
		Page::add_menu();

		Page::enqueue( Page::hook_suffix() );

		list( $script, $data ) = $this->printed_preload();
		$this->assertSame( Page::preload_paths(), array_keys( $data ) );
		$this->assertArrayHasKey( 'features', $data['/happyaccess/v1/settings']['body'] );
		$this->assertCount( 1, $data['/happyaccess/v1/grants']['body']['items'] );
		$this->assertArrayHasKey( 'groups', $data['/happyaccess/v1/catalog']['body'] );
		$this->assertGreaterThanOrEqual( 1, $data[ Page::preload_paths()[3] ]['body']['total'] );

		foreach ( array( '_hash', 'code"', 'link_key', 'recaptcha-private-value' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $script );
		}
	}

	public function test_enqueue_preloads_nothing_before_setup() {
		if ( ! is_readable( HAPPYACCESS_PLUGIN_DIR . 'build/index.asset.php' ) ) {
			$this->markTestSkipped( 'Run npm run build first.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Page::add_menu();

		Page::enqueue( Page::hook_suffix() );

		$after = implode( '', (array) wp_scripts()->get_data( 'wp-api-fetch', 'after' ) );
		$this->assertStringNotContainsString( 'createPreloadingMiddleware( {', $after );
	}
}
