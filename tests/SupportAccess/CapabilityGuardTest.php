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
}
