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

	public function tear_down() {
		unset( $GLOBALS['pagenow'], $GLOBALS['menu'], $GLOBALS['submenu'] );
		parent::tear_down();
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
		$this->assertSame( 0, $this->blocked_log_count(), 'The owner is not blocked or logged.' );

		wp_set_current_user( $temp );
		try {
			MenuGuard::block_screen();
			$this->fail( 'Expected the blocked page to end in wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 1, $this->blocked_log_count() );
		}
	}

	public function test_block_screen_normalizes_the_page_arg() {
		$temp = $this->temp_user_with_menus( array( 'wc-settings' ) );
		wp_set_current_user( $temp );
		$GLOBALS['pagenow'] = 'admin.php';
		$_GET['page']       = '/wc-settings';
		$this->expectException( WPDieException::class );
		MenuGuard::block_screen();
	}

	public function test_block_screen_blocks_editing_a_blocked_post_type() {
		$temp = $this->temp_user_with_menus( array( 'edit.php?post_type=product' ) );
		wp_set_current_user( $temp );
		$GLOBALS['pagenow'] = 'post.php';
		$_GET['post']       = '5';
		$_GET['action']     = 'edit';
		$this->expectException( WPDieException::class );
		MenuGuard::block_screen( (object) array( 'post_type' => 'product' ) );
	}

	public function test_block_screen_allows_editing_other_post_types() {
		$temp = $this->temp_user_with_menus( array( 'edit.php?post_type=product' ) );
		wp_set_current_user( $temp );
		$GLOBALS['pagenow'] = 'post.php';
		MenuGuard::block_screen( (object) array( 'post_type' => 'page' ) );
		$this->assertSame( 0, $this->blocked_log_count() );
	}

	public function test_block_screen_uses_the_screen_taxonomy() {
		$temp = $this->temp_user_with_menus( array( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) );
		wp_set_current_user( $temp );
		$GLOBALS['pagenow'] = 'edit-tags.php';
		MenuGuard::block_screen( (object) array( 'post_type' => 'product', 'taxonomy' => 'product_tag' ) );
		$this->assertSame( 0, $this->blocked_log_count() );

		$this->expectException( WPDieException::class );
		MenuGuard::block_screen( (object) array( 'post_type' => 'product', 'taxonomy' => 'product_cat' ) );
	}

	public function test_matcher_page_slug_with_args() {
		$blocked = array( 'woocommerce::wc-admin&path=/customers' );
		$this->assertTrue( MenuGuard::is_blocked( $blocked, 'admin.php', 'wc-admin', '', array( 'path' => '/customers' ) ) );
		$this->assertFalse( MenuGuard::is_blocked( $blocked, 'admin.php', 'wc-admin', '', array( 'path' => '/analytics/overview' ) ) );
		$this->assertFalse( MenuGuard::is_blocked( $blocked, 'admin.php', 'wc-admin', '', array() ) );
	}

	public function test_matcher_bare_page_slug_does_not_match_path_requests() {
		$blocked = array( 'woocommerce::wc-admin' );
		$this->assertTrue( MenuGuard::is_blocked( $blocked, 'admin.php', 'wc-admin', '', array() ) );
		$this->assertFalse( MenuGuard::is_blocked( $blocked, 'admin.php', 'wc-admin', '', array( 'path' => '/customers' ) ) );
	}

	public function test_matcher_taxonomy_slugs() {
		$product = array( 'edit-tags.php?taxonomy=product_cat&post_type=product' );
		$this->assertTrue( MenuGuard::is_blocked( $product, 'edit-tags.php', '', 'product', array( 'taxonomy' => 'product_cat' ) ) );
		$this->assertFalse( MenuGuard::is_blocked( $product, 'edit-tags.php', '', 'product', array( 'taxonomy' => 'product_tag' ) ) );

		$category = array( 'edit-tags.php?taxonomy=category' );
		$this->assertTrue( MenuGuard::is_blocked( $category, 'edit-tags.php', '', '', array( 'taxonomy' => 'category' ) ) );
		$this->assertFalse( MenuGuard::is_blocked( $category, 'edit-tags.php', '', '', array( 'taxonomy' => 'post_tag' ) ) );
	}

	public function test_matcher_edit_family_respects_post_type() {
		$this->assertTrue( MenuGuard::is_blocked( array( 'edit.php' ), 'edit.php', '', '' ) );
		$this->assertTrue( MenuGuard::is_blocked( array( 'edit.php' ), 'edit.php', '', 'post' ) );
		$this->assertFalse( MenuGuard::is_blocked( array( 'edit.php' ), 'edit.php', '', 'product' ) );
		$this->assertFalse( MenuGuard::is_blocked( array( 'post-new.php' ), 'post-new.php', '', 'page' ) );
		$this->assertFalse( MenuGuard::is_blocked( array( 'edit.php?post_type=product' ), 'edit.php', '', '' ) );
	}

	public function test_menu_snapshot_strips_nested_count_bubbles() {
		$GLOBALS['menu'] = array(
			array( 'Plugins <span class="update-plugins count-2"><span class="plugin-count">2</span></span>', 'activate_plugins', 'plugins.php' ),
		);
		$snapshot = MenuGuard::menu_snapshot();
		$this->assertSame( 'Plugins', $snapshot[0]['title'] );
	}

	private function temp_user_with_menus( array $menus ) {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $owner );
		$made = Grants::create( array( 'label' => 'x', 'menus' => $menus ) );
		return TempUsers::get_or_create( Grants::get( $made['id'] ) );
	}

	private function blocked_log_count() {
		global $wpdb;
		$table = Installer::table( 'logs' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'access_blocked'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
