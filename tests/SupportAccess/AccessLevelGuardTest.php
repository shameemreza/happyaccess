<?php
/**
 * Guard rules per access level.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\AccountGuard;
use HappyAccess\Features\SupportAccess\CapabilityGuard;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class AccessLevelGuardTest extends WP_UnitTestCase {

	private $owner;
	private $other_admin;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::register();
		CapabilityGuard::register();
		AccountGuard::register();
		$this->owner       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner );
		Grants::flush_cache();
	}

	/**
	 * Creates a grant and its temp user, then logs in as that user.
	 *
	 * @param array $args Grant args.
	 * @return int Temp user id.
	 */
	private function become( array $args = array() ) {
		$made = Grants::create( array_merge( array( 'label' => 'Acme' ), $args ) );
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		wp_set_current_user( $temp );
		return $temp;
	}

	private function full() {
		return $this->become(
			array(
				'level'        => 'full',
				'confirm_full' => true,
			)
		);
	}

	private function custom( array $caps ) {
		return $this->become(
			array(
				'level' => 'custom',
				'caps'  => $caps,
			)
		);
	}

	public function test_full_drops_protected_rules_but_keeps_self_protection() {
		$temp = $this->full();
		$this->assertSame( 'full', CapabilityGuard::level_for( $temp ) );
		$this->assertTrue( current_user_can( 'install_plugins' ) );
		$this->assertTrue( current_user_can( 'create_users' ) );
		$this->assertTrue( current_user_can( 'edit_user', $this->other_admin ) );
		$this->assertFalse( current_user_can( 'edit_user', $this->owner ) );
		$this->assertFalse( current_user_can( 'delete_user', $this->owner ) );
		$this->assertFalse( current_user_can( 'promote_user', $this->owner ) );
		$this->assertFalse( current_user_can( 'deactivate_plugin', 'happyaccess/happyaccess.php' ) );
		$this->assertSame( array( 'do_not_allow' ), CapabilityGuard::map( array( 'delete_plugins' ), 'delete_plugin', $temp, array( HAPPYACCESS_PLUGIN_BASENAME ) ) );
		$this->assertTrue( current_user_can( 'deactivate_plugin', 'hello.php' ) );
		$this->assertFalse( current_user_can( 'create_app_password', $temp ) );
		$this->assertFalse( current_user_can( Capabilities::MANAGE ) );
		foreach ( array( 'erase_others_personal_data', 'export_others_personal_data', 'manage_network', 'setup_network' ) as $cap ) {
			$this->assertFalse( current_user_can( $cap ), $cap );
		}
	}

	public function test_full_writes_site_options_but_not_happyaccess_options() {
		update_option( 'happyaccess_settings', array( 'marker' => 'kept' ) );
		$before = get_option( 'happyaccess_settings' );
		$this->assertNotSame( array(), $before );

		$this->full();
		update_option( 'admin_email', 'x@example.com' );
		update_option( 'happyaccess_settings', array() );

		$this->assertSame( 'x@example.com', get_option( 'admin_email' ) );
		$this->assertSame( $before, get_option( 'happyaccess_settings' ) );
	}

	public function test_full_cannot_delete_a_happyaccess_option() {
		update_option( 'happyaccess_settings', array( 'marker' => 'kept' ) );
		$this->full();
		$this->expectException( WPDieException::class );
		delete_option( 'happyaccess_settings' );
	}

	public function test_full_options_page_keeps_core_options_and_drops_happyaccess_ones() {
		$this->full();
		$allowed = CapabilityGuard::filter_allowed_options(
			array(
				'options'           => array( 'blogname' ),
				'general'           => array( 'blogname', 'admin_email', 'happyaccess_secret' ),
				'happyaccess_group' => array( 'blogname' ),
			)
		);
		$this->assertSame(
			array(
				'options' => array( 'blogname' ),
				'general' => array( 'blogname', 'admin_email' ),
			),
			$allowed
		);
	}

	public function test_full_edits_other_emails_but_not_the_creator() {
		$this->full();
		$creator_email = get_userdata( $this->owner )->user_email;

		wp_update_user(
			array(
				'ID'         => $this->other_admin,
				'user_email' => 'new@example.com',
			)
		);
		wp_update_user(
			array(
				'ID'         => $this->owner,
				'user_email' => 'stolen@example.com',
			)
		);

		$this->assertSame( 'new@example.com', get_userdata( $this->other_admin )->user_email );
		$this->assertSame( $creator_email, get_userdata( $this->owner )->user_email );
	}

	public function test_full_resets_only_block_the_creator() {
		$this->full();
		$this->assertTrue( AccountGuard::block_reset( true, $this->other_admin ) );
		$this->assertFalse( AccountGuard::block_reset( true, $this->owner ) );
	}

	public function test_full_leaves_woocommerce_checks_to_woocommerce() {
		$this->full();
		$errors = AccountGuard::block_wc_account( new WP_Error() );
		$this->assertFalse( $errors->has_errors() );
	}

	public function test_full_hides_the_plugin_and_the_owner() {
		$this->full();
		$plugins = CapabilityGuard::hide_plugin( array( HAPPYACCESS_PLUGIN_BASENAME => array() ) );
		$this->assertArrayNotHasKey( HAPPYACCESS_PLUGIN_BASENAME, $plugins );
		$args = CapabilityGuard::hide_owner( array() );
		$this->assertSame( array( $this->owner ), $args['exclude'] );
	}

	public function test_custom_with_list_users() {
		$this->custom( array( 'edit_posts', 'list_users' ) );
		$this->assertTrue( current_user_can( 'list_users' ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertFalse( current_user_can( 'edit_user', $this->owner ) );
	}

	public function test_custom_with_edit_users_edits_a_subscriber_email_but_not_the_creator() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->custom( array( 'edit_users' ) );
		$creator_email = get_userdata( $this->owner )->user_email;

		$this->assertTrue( current_user_can( 'edit_user', $subscriber ) );
		$this->assertFalse( current_user_can( 'edit_user', $this->owner ) );

		wp_update_user(
			array(
				'ID'         => $subscriber,
				'user_email' => 'customer@example.com',
			)
		);
		wp_update_user(
			array(
				'ID'         => $this->owner,
				'user_email' => 'stolen@example.com',
			)
		);

		$this->assertSame( 'customer@example.com', get_userdata( $subscriber )->user_email );
		$this->assertSame( $creator_email, get_userdata( $this->owner )->user_email );
	}

	public function test_no_creator_means_no_creator_lock() {
		$this->become(
			array(
				'level'        => 'full',
				'confirm_full' => true,
				'created_by'   => 0,
			)
		);
		$this->assertTrue( current_user_can( 'edit_user', $this->owner ) );
		$this->assertTrue( current_user_can( 'edit_user', $this->other_admin ) );
	}

	public function test_deleted_creator_means_no_creator_lock() {
		$creator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $creator );
		$this->full();
		self::delete_user( $creator );
		Grants::flush_cache();
		$this->assertTrue( current_user_can( 'edit_user', $this->other_admin ) );
		$this->assertTrue( AccountGuard::block_reset( true, $creator ) );
	}

	public function test_protected_keeps_the_old_rules() {
		$temp = $this->become();
		$this->assertSame( 'protected', CapabilityGuard::level_for( $temp ) );
		$this->assertFalse( current_user_can( 'install_plugins' ) );
		$this->assertFalse( current_user_can( 'edit_user', $this->other_admin ) );

		$email = get_option( 'admin_email' );
		update_option( 'admin_email', 'x@example.com' );
		$this->assertSame( $email, get_option( 'admin_email' ) );
	}

	public function test_legacy_allow_installs_keeps_the_protected_rules() {
		$temp = $this->become( array( 'allow_installs' => true ) );
		$this->assertSame( 'protected', CapabilityGuard::level_for( $temp ) );
		$this->assertTrue( current_user_can( 'install_plugins' ) );
		$this->assertFalse( current_user_can( 'create_users' ) );
	}

	public function test_unreadable_grant_fails_closed_to_protected() {
		$temp = $this->full();
		update_user_meta( $temp, 'happyaccess_token_id', 999999 );
		Grants::flush_cache();
		$this->assertSame( 'protected', CapabilityGuard::level_for( $temp ) );
		$this->assertFalse( current_user_can( 'create_users' ) );
	}
}
