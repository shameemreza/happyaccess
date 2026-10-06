<?php
/**
 * MenuGuard tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\MenuGuard;
use HappyAccess\Features\SupportAccess\TempUsers;

class MenuGuardTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		Grants::flush_cache();
		$GLOBALS['submenu'] = array(
			'woocommerce' => array(
				array( 'Orders', 'edit_shop_orders', 'wc-orders' ),
				array( 'Settings', 'manage_woocommerce', 'wc-settings' ),
			),
		);
	}

	public function test_matcher_top_level_and_query_slugs() {
		$this->assertTrue( MenuGuard::is_blocked( array( 'tools.php' ), 'tools.php', '', '' ) );
		$this->assertTrue( MenuGuard::is_blocked( array( 'edit.php?post_type=product' ), 'edit.php', '', 'product' ) );
		$this->assertFalse( MenuGuard::is_blocked( array( 'edit.php?post_type=product' ), 'edit.php', '', 'page' ) );
		$this->assertFalse( MenuGuard::is_blocked( array( 'tools.php' ), 'edit.php', '', '' ) );
	}

	public function test_matcher_child_and_parent_blocks_children() {
		$this->assertTrue( MenuGuard::is_blocked( array( 'woocommerce::wc-settings' ), 'admin.php', 'wc-settings', '' ) );
		$this->assertFalse( MenuGuard::is_blocked( array( 'woocommerce::wc-settings' ), 'admin.php', 'wc-orders', '' ) );
		$this->assertTrue( MenuGuard::is_blocked( array( 'woocommerce' ), 'admin.php', 'wc-orders', '' ) );
	}

	public function test_matcher_keeps_uppercase_slugs() {
		$this->assertTrue( MenuGuard::is_blocked( array( 'MyPlugin-Settings' ), 'admin.php', 'MyPlugin-Settings', '' ) );
	}

	public function test_admin_bar_hidden_only_for_restricted_temp_user() {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $owner );
		$made = Grants::create( array( 'label' => 'x', 'hide_admin_bar' => true ) );
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );

		MenuGuard::register();
		$this->assertTrue( apply_filters( 'show_admin_bar', true ) );
		wp_set_current_user( $temp );
		$this->assertFalse( apply_filters( 'show_admin_bar', true ) );
	}

	public function test_menu_snapshot_skips_noise() {
		$GLOBALS['menu'] = array(
			array( 'Dashboard', 'read', 'index.php' ),
			array( '', 'read', 'separator1', '', 'wp-menu-separator' ),
			array( 'WooCommerce <span class="update-plugins">3</span>', 'manage_woocommerce', 'woocommerce' ),
			array( 'HappyAccess', 'manage_options', 'happyaccess' ),
		);
		$snapshot = MenuGuard::menu_snapshot();
		$this->assertCount( 1, $snapshot );
		$this->assertSame( 'woocommerce', $snapshot[0]['slug'] );
		$this->assertSame( 'WooCommerce', $snapshot[0]['title'] );
		$this->assertSame( 'woocommerce::wc-settings', $snapshot[0]['children'][1]['slug'] );
	}

	public function test_block_screen_refuses_blocked_page_for_temp_user_only() {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $owner );
		$made = Grants::create( array( 'label' => 'x', 'menus' => array( 'woocommerce::wc-settings' ) ) );
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );

		$GLOBALS['pagenow'] = 'admin.php';
		$_GET['page']       = 'wc-settings';

		MenuGuard::block_screen();
		$this->assertTrue( true, 'The owner is not blocked.' );

		wp_set_current_user( $temp );
		$this->expectException( WPDieException::class );
		MenuGuard::block_screen();
	}

	public function test_menu_snapshot_strips_nested_count_bubbles() {
		$GLOBALS['menu'] = array(
			array( 'Plugins <span class="update-plugins count-2"><span class="plugin-count">2</span></span>', 'activate_plugins', 'plugins.php' ),
		);
		$snapshot = MenuGuard::menu_snapshot();
		$this->assertSame( 'Plugins', $snapshot[0]['title'] );
	}
}
