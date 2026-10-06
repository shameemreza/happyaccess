<?php
/**
 * CapabilityGuard tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\CapabilityGuard;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class CapabilityGuardTest extends WP_UnitTestCase {

	private $owner;
	private $other_admin;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::register();
		CapabilityGuard::register();
		$this->owner       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner );
		Grants::flush_cache();
	}

	private function temp( array $args = array() ) {
		$made = Grants::create( array_merge( array( 'label' => 'Acme' ), $args ) );
		return TempUsers::get_or_create( Grants::get( $made['id'] ) );
	}

	public function test_normal_admin_is_untouched() {
		foreach ( array( 'create_users', 'edit_plugins', 'install_plugins', 'manage_options' ) as $cap ) {
			$this->assertTrue( user_can( $this->owner, $cap ), $cap );
		}
	}

	public function test_protected_admin_blocks_the_always_list_but_allows_work() {
		$temp = $this->temp();
		foreach ( array( 'create_users', 'promote_users', 'delete_users', 'edit_plugins', 'edit_themes', 'edit_files', Capabilities::MANAGE ) as $cap ) {
			$this->assertFalse( user_can( $temp, $cap ), $cap );
		}
		foreach ( array( 'manage_options', 'activate_plugins', 'install_plugins', 'edit_posts' ) as $cap ) {
			$this->assertTrue( user_can( $temp, $cap ), $cap );
		}
	}

	public function test_block_installs_removes_install_caps() {
		$temp = $this->temp( array( 'block_installs' => true ) );
		foreach ( array( 'install_plugins', 'update_plugins', 'delete_plugins', 'install_themes', 'update_core' ) as $cap ) {
			$this->assertFalse( user_can( $temp, $cap ), $cap );
		}
		$this->assertTrue( user_can( $temp, 'activate_plugins' ) );
	}

	public function test_user_edits_on_self_owner_and_admins_are_blocked() {
		$temp     = $this->temp();
		$customer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertFalse( user_can( $temp, 'edit_user', $temp ) );
		$this->assertFalse( user_can( $temp, 'edit_user', $this->owner ) );
		$this->assertFalse( user_can( $temp, 'delete_user', $this->other_admin ) );
		$this->assertTrue( user_can( $temp, 'edit_user', $customer ) );
	}

	public function test_cannot_deactivate_happyaccess_but_can_other_plugins() {
		$temp = $this->temp();
		$this->assertFalse( user_can( $temp, 'deactivate_plugin', HAPPYACCESS_PLUGIN_BASENAME ) );
		$this->assertTrue( user_can( $temp, 'deactivate_plugin', 'hello.php' ) );
	}

	public function test_application_passwords_unavailable_for_temp_users() {
		$temp = $this->temp();
		$this->assertFalse( wp_is_application_passwords_available_for_user( $temp ) );
	}

	public function test_protected_options_ignore_temp_user_writes() {
		$temp = $this->temp();
		update_option( 'default_role', 'subscriber' );
		wp_set_current_user( $temp );
		update_option( 'default_role', 'administrator' );
		$this->assertSame( 'subscriber', get_option( 'default_role' ) );

		$allowed = apply_filters( 'allowed_options', array( 'general' => array( 'blogname', 'default_role', 'admin_email' ) ) );
		$this->assertSame( array( 'blogname' ), array_values( $allowed['general'] ) );
	}

	public function test_lists_hide_happyaccess_and_the_owner() {
		$temp = $this->temp();
		wp_set_current_user( $temp );
		$plugins = apply_filters( 'all_plugins', array( HAPPYACCESS_PLUGIN_BASENAME => array(), 'hello.php' => array() ) );
		$this->assertSame( array( 'hello.php' ), array_keys( $plugins ) );
		$args = apply_filters( 'users_list_table_query_args', array() );
		$this->assertContains( $this->owner, $args['exclude'] );
	}

	public function test_happyaccess_path_matching_is_strict_about_the_folder() {
		$method = new ReflectionMethod( CapabilityGuard::class, 'is_happyaccess_path' );
		$method->setAccessible( true );
		foreach ( array( 'happyaccess/readme.txt', 'happyaccess//happyaccess.php', 'HappyAccess/happyaccess.php', ' happyaccess/happyaccess.php ' ) as $file ) {
			$this->assertTrue( $method->invoke( null, $file ), $file );
		}
		$this->assertFalse( $method->invoke( null, 'hello.php' ) );
		$this->assertFalse( $method->invoke( null, 'happyaccess-pro/pro.php' ) );
	}

	public function test_deactivate_and_delete_are_blocked_for_any_path_inside_the_folder() {
		$temp = $this->temp();
		$this->assertFalse( user_can( $temp, 'delete_plugin', 'HappyAccess/readme.txt' ) );
		$this->assertFalse( user_can( $temp, 'deactivate_plugin', 'happyaccess//happyaccess.php' ) );
	}

	public function test_temp_user_cannot_delete_or_uninstall_happyaccess_files() {
		$temp = $this->temp();
		wp_set_current_user( $temp );
		$this->expectException( 'WPDieException' );
		do_action( 'pre_uninstall_plugin', 'happyaccess/readme.txt', array() );
	}

	public function test_temp_user_cannot_trigger_delete_plugin_for_a_folder_file() {
		$temp = $this->temp();
		wp_set_current_user( $temp );
		$this->expectException( 'WPDieException' );
		do_action( 'delete_plugin', 'happyaccess//happyaccess.php' );
	}

	public function test_other_plugins_can_be_uninstalled_by_a_temp_user() {
		$temp = $this->temp();
		wp_set_current_user( $temp );
		do_action( 'pre_uninstall_plugin', 'hello.php', array() );
		do_action( 'delete_plugin', 'hello.php' );
		$this->assertTrue( true );
	}

	public function test_plural_user_caps_with_a_target_follow_the_target_rules() {
		$temp     = $this->temp();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$this->assertFalse( user_can( $temp, 'edit_users', $this->owner ) );
		$this->assertFalse( user_can( $temp, 'edit_users', $this->other_admin ) );
		$this->assertFalse( user_can( $temp, 'edit_users', $temp ) );
		$this->assertTrue( user_can( $temp, 'edit_users', $customer ) );
		$this->assertTrue( user_can( $temp, 'edit_users', get_userdata( $customer ) ) );
		$this->assertFalse( user_can( $temp, 'edit_users', get_userdata( $this->owner ) ) );
		$this->assertTrue( user_can( $temp, 'edit_users' ) );
	}

	public function test_options_page_groups_are_removed_for_temp_users() {
		$temp = $this->temp();
		wp_set_current_user( $temp );
		$allowed = apply_filters(
			'allowed_options',
			array(
				'options'              => array( 'anything' ),
				'happyaccess_settings' => array( 'happyaccess_settings' ),
				'general'              => array( 'blogname', 'siteurl', 'happyaccess_x' ),
			)
		);
		$this->assertArrayNotHasKey( 'options', $allowed );
		$this->assertArrayNotHasKey( 'happyaccess_settings', $allowed );
		$this->assertSame( array( 'blogname' ), $allowed['general'] );
	}

	public function test_prefixed_and_role_options_ignore_temp_user_writes() {
		global $wpdb;
		$temp = $this->temp();
		update_option( 'happyaccess_anything', 'old' );
		$roles = get_option( $wpdb->prefix . 'user_roles' );
		wp_set_current_user( $temp );
		update_option( 'happyaccess_anything', 'new' );
		update_option( $wpdb->prefix . 'user_roles', array() );
		$this->assertSame( 'old', get_option( 'happyaccess_anything' ) );
		$this->assertSame( $roles, get_option( $wpdb->prefix . 'user_roles' ) );
	}

	public function test_temp_user_activating_plugins_cannot_drop_happyaccess() {
		$temp = $this->temp();
		update_option( 'active_plugins', array( HAPPYACCESS_PLUGIN_BASENAME ) );
		wp_set_current_user( $temp );
		update_option( 'active_plugins', array( 'hello.php' ) );
		$this->assertEqualsCanonicalizing( array( 'hello.php', HAPPYACCESS_PLUGIN_BASENAME ), get_option( 'active_plugins' ) );
	}

	public function test_temp_user_cannot_delete_protected_options() {
		$temp = $this->temp();
		wp_set_current_user( $temp );
		$this->expectException( 'WPDieException' );
		delete_option( 'admin_email' );
	}

	public function test_normal_admin_can_delete_and_write_protected_options() {
		update_option( 'happyaccess_anything', 'a' );
		update_option( 'happyaccess_anything', 'b' );
		$this->assertSame( 'b', get_option( 'happyaccess_anything' ) );
		delete_option( 'happyaccess_anything' );
		$this->assertFalse( get_option( 'happyaccess_anything' ) );
	}

	public function test_application_password_caps_are_denied_for_any_target() {
		$temp     = $this->temp();
		$customer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		foreach ( array( 'create_app_password', 'edit_app_password', 'list_app_passwords', 'read_app_password', 'delete_app_password', 'delete_app_passwords' ) as $cap ) {
			$this->assertFalse( user_can( $temp, $cap, $customer ), $cap );
		}
	}

	public function test_unfiltered_caps_are_blocked() {
		$temp = $this->temp();
		$this->assertFalse( user_can( $temp, 'unfiltered_html' ) );
		$this->assertFalse( user_can( $temp, 'unfiltered_upload' ) );
	}

	public function test_unknown_protection_value_blocks_installs() {
		global $wpdb;
		$temp = $this->temp();
		$wpdb->update( \HappyAccess\Core\Installer::table( 'tokens' ), array( 'protection' => 'something_else' ), array( 'id' => Capabilities::grant_id( $temp ) ) );
		Grants::flush_cache();
		$this->assertFalse( user_can( $temp, 'install_plugins' ) );
	}

	public function test_themes_can_still_be_switched_by_a_temp_user() {
		$temp = $this->temp();
		wp_set_current_user( $temp );
		$theme = get_stylesheet();
		switch_theme( $theme );
		$this->assertSame( $theme, get_option( 'stylesheet' ) );
	}
}
