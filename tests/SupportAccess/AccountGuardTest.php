<?php
/**
 * AccountGuard tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\AccountGuard;
use HappyAccess\Features\SupportAccess\CapabilityGuard;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class AccountGuardTest extends WP_UnitTestCase {

	private $temp;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::register();
		CapabilityGuard::register();
		AccountGuard::register();
		if ( ! get_role( 'shop_manager_test' ) ) {
			add_role(
				'shop_manager_test',
				'Shop manager test',
				array(
					'read'               => true,
					'manage_woocommerce' => true,
					'edit_users'         => true,
				)
			);
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$made       = Grants::create( array( 'label' => 'Acme' ) );
		$this->temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );
	}

	public function test_low_privilege_detection() {
		$subscriber = new WP_User( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$editor     = new WP_User( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$manager    = new WP_User( self::factory()->user->create( array( 'role' => 'shop_manager_test' ) ) );
		$this->assertTrue( CapabilityGuard::is_low_privilege( $subscriber ) );
		$this->assertFalse( CapabilityGuard::is_low_privilege( $editor ) );
		$this->assertFalse( CapabilityGuard::is_low_privilege( $manager ) );
	}

	public function test_temp_user_edits_customers_only() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$manager    = self::factory()->user->create( array( 'role' => 'shop_manager_test' ) );
		$this->assertTrue( user_can( $this->temp, 'edit_user', $subscriber ) );
		$this->assertFalse( user_can( $this->temp, 'edit_user', $editor ) );
		$this->assertFalse( user_can( $this->temp, 'edit_users', $manager ) );
		// Deleting users stays blocked for everyone (delete_users is in ALWAYS_BLOCKED).
		$this->assertFalse( user_can( $this->temp, 'delete_user', $subscriber ) );
		$this->assertFalse( user_can( $this->temp, 'delete_user', $editor ) );
	}

	public function test_password_and_email_never_change_under_a_temp_user() {
		$subscriber = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'before@example.org',
			)
		);
		$hash       = get_userdata( $subscriber )->user_pass;
		wp_set_current_user( $this->temp );
		wp_update_user(
			array(
				'ID'           => $subscriber,
				'user_email'   => 'after@example.org',
				'user_pass'    => 'new-pass-123',
				'display_name' => 'Changed',
			)
		);
		clean_user_cache( $subscriber );
		$user = get_userdata( $subscriber );
		$this->assertSame( 'before@example.org', $user->user_email );
		$this->assertSame( $hash, $user->user_pass );
		$this->assertSame( 'Changed', $user->display_name );

		wp_update_user(
			array(
				'ID'         => $this->temp,
				'user_email' => 'mine@example.org',
			)
		);
		clean_user_cache( $this->temp );
		$this->assertStringEndsWith( '@happyaccess.invalid', get_userdata( $this->temp )->user_email );
	}

	public function test_password_reset_blocked_for_temp_users() {
		$this->assertFalse( apply_filters( 'allow_password_reset', true, $this->temp ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertTrue( apply_filters( 'allow_password_reset', true, $subscriber ) );
	}

	public function test_wc_account_details_error_for_temp_user() {
		wp_set_current_user( $this->temp );
		$errors = apply_filters( 'woocommerce_save_account_details_errors', new WP_Error(), get_userdata( $this->temp ) );
		$this->assertContains( 'happyaccess_temp', $errors->get_error_codes() );
	}

	public function test_wc_api_key_and_auth_requests_are_blocked_for_temp_users() {
		$this->assertSame( 0, has_action( 'wp_ajax_woocommerce_update_api_key', array( AccountGuard::class, 'block_wc_api_key' ) ) );
		$this->assertFalse( AccountGuard::is_blocked_request() );
		wp_set_current_user( $this->temp );
		$this->assertTrue( AccountGuard::is_blocked_request() );
		$wp                                = new WP();
		$wp->query_vars['wc-auth-version'] = 1;
		$this->expectException( WPDieException::class );
		AccountGuard::block_wc_auth( $wp );
	}

	public function test_credential_change_emails_are_skipped_for_temp_users() {
		$this->assertTrue( apply_filters( 'send_password_change_email', true, array(), array() ) );
		$this->assertTrue( apply_filters( 'send_email_change_email', true, array(), array() ) );
		wp_set_current_user( $this->temp );
		$this->assertFalse( apply_filters( 'send_password_change_email', true, array(), array() ) );
		$this->assertFalse( apply_filters( 'send_email_change_email', true, array(), array() ) );
	}
}
