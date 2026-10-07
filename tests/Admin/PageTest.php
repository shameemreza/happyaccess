<?php
/**
 * Admin page tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Admin\Page;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class PageTest extends WP_UnitTestCase {

	/**
	 * Admin menu globals as they were before the test.
	 *
	 * @var array
	 */
	private $menu_globals = array();

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
	}

	public function tear_down() {
		wp_dequeue_script( Page::HANDLE );
		wp_deregister_script( Page::HANDLE );
		wp_dequeue_style( Page::HANDLE );
		wp_deregister_style( Page::HANDLE );
		foreach ( $this->menu_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
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

		foreach ( array( 'siteName', 'homeUrl', 'loginUrl', 'codeUrl', 'adminUrl', 'currentUser', 'timezone', 'features', 'needsSetup', 'menus', 'maxDays', 'isMultisite' ) as $key ) {
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

	public function test_boot_data_needs_setup_follows_consent() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\HappyAccess\Core\Settings::update( array( 'support' => array( 'consent_given_at' => '2026-01-01 00:00:00' ) ) );

		$this->assertFalse( Page::boot_data()['needsSetup'] );
	}

	public function test_render_prints_the_mount_point() {
		ob_start();
		Page::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="happyaccess-root" class="happyaccess-app"', $html );
	}
}
