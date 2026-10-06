<?php
/**
 * CapabilityGuard tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
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
		foreach ( array( 'manage_options', 'activate_plugins', 'edit_posts' ) as $cap ) {
			$this->assertTrue( user_can( $temp, $cap ), $cap );
		}
	}

	public function test_default_grant_blocks_installs_but_allows_activation() {
		$temp = $this->temp();
		foreach ( array( 'install_plugins', 'upload_plugins', 'update_plugins', 'delete_plugins', 'install_themes', 'update_core' ) as $cap ) {
			$this->assertFalse( user_can( $temp, $cap ), $cap );
		}
		$this->assertTrue( user_can( $temp, 'activate_plugins' ) );
	}

	public function test_allow_installs_restores_install_caps() {
		$temp = $this->temp( array( 'allow_installs' => true ) );
		foreach ( array( 'install_plugins', 'update_core' ) as $cap ) {
			$this->assertTrue( user_can( $temp, $cap ), $cap );
		}
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
		if ( ! get_role( 'customer' ) ) {
			add_role( 'customer', 'Customer', array( 'read' => true ) );
		}
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

	public function test_role_writes_are_allowed_while_a_plugin_activates_and_logged() {
		global $wpdb;
		$temp    = $this->temp();
		$key     = $wpdb->prefix . 'user_roles';
		$changed = get_option( $key );
		$changed['administrator']['name'] = 'Admin renamed';
		wp_set_current_user( $temp );

		do_action( 'activate_plugin', 'x/x.php', false );
		update_option( $key, $changed );
		$this->assertSame( 'Admin renamed', get_option( $key )['administrator']['name'] );

		$rows = AuditLog::query( array( 'event' => 'roles_changed', 'token_id' => Capabilities::grant_id( $temp ) ) )['items'];
		$this->assertCount( 1, $rows );
		$this->assertSame( 'support', $rows[0]['feature'] );
		$this->assertSame( 'Roles changed while activating or updating a plugin', $rows[0]['summary'] );

		do_action( 'activated_plugin', 'x/x.php', false );
		$again = $changed;
		$again['administrator']['name'] = 'Admin again';
		update_option( $key, $again );
		$this->assertSame( 'Admin renamed', get_option( $key )['administrator']['name'] );
	}

	public function test_other_protected_options_stay_protected_during_activation() {
		$temp = $this->temp();
		update_option( 'default_role', 'subscriber' );
		wp_set_current_user( $temp );
		do_action( 'activate_plugin', 'x/x.php', false );
		update_option( 'default_role', 'administrator' );
		$this->assertSame( 'subscriber', get_option( 'default_role' ) );
		do_action( 'activated_plugin', 'x/x.php', false );
	}

	public function test_the_plugin_write_counter_never_goes_below_zero() {
		global $wpdb;
		$temp    = $this->temp();
		$key     = $wpdb->prefix . 'user_roles';
		$changed = get_option( $key );
		$changed['administrator']['name'] = 'Changed';
		wp_set_current_user( $temp );

		do_action( 'activated_plugin', 'x/x.php', false );
		do_action( 'activated_plugin', 'x/x.php', false );
		do_action( 'activate_plugin', 'x/x.php', false );
		update_option( $key, $changed );
		$this->assertSame( 'Changed', get_option( $key )['administrator']['name'] );
		do_action( 'activated_plugin', 'x/x.php', false );
	}

	public function test_a_plugin_update_opens_the_role_window_until_it_completes() {
		global $wpdb;
		$temp    = $this->temp();
		$key     = $wpdb->prefix . 'user_roles';
		$changed = get_option( $key );
		$changed['administrator']['name'] = 'Updated';
		wp_set_current_user( $temp );
		$this->only_guard_closes_updates();

		$this->assertSame( 'kept', apply_filters( 'upgrader_pre_install', 'kept', array() ) );
		update_option( $key, $changed );
		$this->assertSame( 'Updated', get_option( $key )['administrator']['name'] );
		do_action( 'upgrader_process_complete', null, array() );
	}

	public function test_a_bulk_update_closes_the_role_window_with_one_complete() {
		global $wpdb;
		$temp    = $this->temp();
		$key     = $wpdb->prefix . 'user_roles';
		$changed = get_option( $key );
		$changed['administrator']['name'] = 'After bulk';
		wp_set_current_user( $temp );
		$this->only_guard_closes_updates();

		apply_filters( 'upgrader_pre_install', true, array() );
		apply_filters( 'upgrader_pre_install', true, array() );
		do_action( 'upgrader_process_complete', null, array() );
		update_option( $key, $changed );
		$this->assertNotSame( 'After bulk', get_option( $key )['administrator']['name'] );
	}

	/**
	 * Core listens to the complete hook and calls the network. Drop those
	 * listeners for the test and keep only this guard's.
	 *
	 * @return void
	 */
	private function only_guard_closes_updates() {
		remove_all_actions( 'upgrader_process_complete' );
		CapabilityGuard::register();
	}

	public function test_an_unchanged_role_table_write_during_activation_is_not_logged() {
		global $wpdb;
		$temp = $this->temp();
		wp_set_current_user( $temp );
		do_action( 'activate_plugin', 'x/x.php', false );
		update_option( $wpdb->prefix . 'user_roles', get_option( $wpdb->prefix . 'user_roles' ) );
		do_action( 'activated_plugin', 'x/x.php', false );
		$this->assertSame( 0, AuditLog::query( array( 'event' => 'roles_changed' ) )['total'] );
	}

	public function test_temp_user_cannot_delete_protected_options() {
		$temp = $this->temp();
		wp_set_current_user( $temp );
		$this->expectException( 'WPDieException' );
		delete_option( 'admin_email' );
	}

	public function test_temp_user_site_option_writes_keep_the_old_value() {
		$temp = $this->temp();
		wp_set_current_user( $temp );
		foreach ( array( 'site_admins', 'admin_email', 'registration', 'happyaccess_network_flag' ) as $option ) {
			$this->assertSame( 'old', CapabilityGuard::keep_old_site_option( 'new', 'old', $option, 1 ), $option );
		}
		$this->assertSame( 'new', CapabilityGuard::keep_old_site_option( 'new', 'old', 'blog_upload_space_check_disabled', 1 ) );
	}

	public function test_admin_site_option_writes_go_through() {
		wp_set_current_user( $this->owner );
		$this->assertSame( 'new', CapabilityGuard::keep_old_site_option( 'new', 'old', 'site_admins', 1 ) );
	}

	public function test_internal_writes_to_site_options_go_through_for_a_temp_user() {
		$temp = $this->temp();
		wp_set_current_user( $temp );
		$this->assertSame( 'new', \HappyAccess\Core\Internal::run(
				static function () {
					return CapabilityGuard::keep_old_site_option( 'new', 'old', 'site_admins', 1 );
				}
			) );
	}

	public function test_site_option_hooks_are_registered_once_per_named_option() {
		CapabilityGuard::register();
		foreach ( array( 'site_admins', 'admin_email', 'registration' ) as $option ) {
			$this->assertSame( 10, has_filter( 'pre_update_site_option_' . $option, array( CapabilityGuard::class, 'keep_old_site_option' ) ), $option );
			$this->assertSame( 10, has_action( 'pre_delete_site_option_' . $option, array( CapabilityGuard::class, 'block_site_option_delete' ) ), $option );
		}
	}

	public function test_temp_user_cannot_delete_network_options() {
		$temp = $this->temp();
		wp_set_current_user( $temp );
		$this->expectException( 'WPDieException' );
		CapabilityGuard::block_site_option_delete( 'site_admins', 1 );
	}

	public function test_admin_can_delete_network_options_and_internal_deletes_pass() {
		wp_set_current_user( $this->owner );
		CapabilityGuard::block_site_option_delete( 'site_admins', 1 );
		$temp = $this->temp();
		wp_set_current_user( $temp );
		\HappyAccess\Core\Internal::run(
			static function () {
				CapabilityGuard::block_site_option_delete( 'site_admins', 1 );
			}
		);
		$this->assertTrue( true );
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

	public function test_edit_users_with_a_target_is_allowed_for_low_privilege_targets_only() {
		if ( ! get_role( 'shop_manager_test' ) ) {
			add_role( 'shop_manager_test', 'Shop manager test', array( 'read' => true, 'manage_woocommerce' => true, 'edit_users' => true ) );
		}
		$temp       = $this->temp();
		$manager    = self::factory()->user->create( array( 'role' => 'shop_manager_test' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertFalse( user_can( $temp, 'edit_users', $manager ) );
		$this->assertTrue( user_can( $temp, 'edit_users', $subscriber ) );
		$this->assertFalse( user_can( $temp, 'edit_users', 999999 ) );
	}
}
