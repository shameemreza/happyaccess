<?php
/**
 * Passwordless per-role policy tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;
use HappyAccess\Features\Passwordless\Feature;
use HappyAccess\Features\Passwordless\RolePolicy;
use HappyAccess\Login\Router;
use HappyAccess\Login\Session;

class PasswordlessRolePolicyTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
		$_REQUEST = array();
		Features::set( 'passwordless', true );
		Settings::update( array( 'passwordless' => array( 'role_policy' => array( 'subscriber' => 'email_only' ) ) ) );
		Feature::register();
	}

	public function tear_down() {
		$_REQUEST = array();
		remove_filter( 'authenticate', array( RolePolicy::class, 'filter_authenticate' ), 30 );
		Router::reset();
		parent::tear_down();
	}

	private function make_user( $role, $login ) {
		return self::factory()->user->create_and_get(
			array(
				'role'       => $role,
				'user_login' => $login,
				'user_pass'  => 'correct-horse-battery',
			)
		);
	}

	public function test_an_email_only_role_is_refused_with_a_password() {
		$user   = $this->make_user( 'subscriber', 'plsub' );
		$result = wp_authenticate( 'plsub', 'correct-horse-battery' );

		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_email_only', $result->get_error_code() );
		$this->assertSame(
			"This account logs in with an email code. Use 'Email me a login code' below.",
			html_entity_decode( wp_strip_all_tags( $result->get_error_message() ), ENT_QUOTES )
		);
		$this->assertStringContainsString( 'step=request', $result->get_error_message() );
		$this->assertSame( 'either', RolePolicy::for_user( $this->make_user( 'editor', 'pled' ) ) );
		$this->assertSame( 'email_only', RolePolicy::for_user( $user ) );
	}

	public function test_an_email_only_account_logs_in_with_its_password_while_login_is_updating() {
		$user = $this->make_user( 'subscriber', 'plupdating' );
		update_option( 'happyaccess_db_version', '1.0.4' );

		$result = wp_authenticate( 'plupdating', 'correct-horse-battery' );
		$this->assertInstanceOf( WP_User::class, $result );
		$this->assertSame( $user->ID, $result->ID );

		$rows = AuditLog::query( array( 'event' => 'passwordless_failed' ) )['items'];
		$this->assertCount( 1, $rows );
		$this->assertSame( 'policy_suspended', $rows[0]['meta']['reason'] );
		$this->assertSame( 'passwordless', $rows[0]['feature'] );
		$this->assertSame( $user->ID, (int) $rows[0]['user_id'] );

		$wrong = wp_authenticate( 'plupdating', 'not-the-password' );
		$this->assertSame( 'incorrect_password', $wrong->get_error_code() );
		$this->assertCount( 1, AuditLog::query( array( 'event' => 'passwordless_failed' ) )['items'], 'A wrong password writes no row.' );
	}

	public function test_an_email_only_account_logs_in_with_its_password_when_the_site_key_is_not_saved() {
		$user = $this->make_user( 'subscriber', 'plnokey' );
		Secrets::key();
		add_filter( 'pre_option_' . Secrets::OPTION, array( $this, 'other_site_key' ) );
		$this->assertFalse( Secrets::is_persisted() );

		$result = wp_authenticate( 'plnokey', 'correct-horse-battery' );
		remove_filter( 'pre_option_' . Secrets::OPTION, array( $this, 'other_site_key' ) );

		$this->assertInstanceOf( WP_User::class, $result );
		$this->assertSame( $user->ID, $result->ID );
		$this->assertSame( 'policy_suspended', AuditLog::query( array( 'event' => 'passwordless_failed' ) )['items'][0]['meta']['reason'] );
	}

	/**
	 * A stored key that is not the one in use, as when saving the key failed.
	 *
	 * @return string
	 */
	public function other_site_key() {
		return base64_encode( str_repeat( 'x', 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A stored key value.
	}

	public function test_a_role_left_at_either_still_logs_in() {
		$editor = $this->make_user( 'editor', 'pleditor' );
		$result = wp_authenticate( 'pleditor', 'correct-horse-battery' );

		$this->assertInstanceOf( WP_User::class, $result );
		$this->assertSame( $editor->ID, $result->ID );
	}

	public function test_a_user_with_two_roles_is_email_only_when_one_role_is() {
		$user = $this->make_user( 'editor', 'pltwo' );
		$user->add_role( 'subscriber' );

		$this->assertSame( 'email_only', RolePolicy::for_user( get_userdata( $user->ID ) ) );
		$this->assertWPError( wp_authenticate( 'pltwo', 'correct-horse-battery' ) );
	}

	public function test_the_error_link_keeps_a_valid_redirect_and_drops_an_offsite_one() {
		$this->make_user( 'subscriber', 'plredir' );

		$_REQUEST['redirect_to'] = home_url( '/my-account/' );
		$result                  = wp_authenticate( 'plredir', 'correct-horse-battery' );
		$this->assertStringContainsString( 'redirect_to=', $result->get_error_message() );

		$_REQUEST['redirect_to'] = 'https://evil.example/steal';
		$result                  = wp_authenticate( 'plredir', 'correct-horse-battery' );
		$this->assertStringNotContainsString( 'evil.example', $result->get_error_message() );
	}

	public function test_a_wp_error_or_null_passes_through_unchanged() {
		$error = new WP_Error( 'other', 'Other.' );
		$this->assertSame( $error, RolePolicy::filter_authenticate( $error, 'x', 'y' ) );
		$this->assertNull( RolePolicy::filter_authenticate( null, 'x', 'y' ) );
	}

	public function test_a_temp_support_user_never_gets_the_email_only_error() {
		$user = $this->make_user( 'subscriber', 'pltemp' );
		update_user_meta( $user->ID, 'happyaccess_temp_user', 1 );

		$result = RolePolicy::filter_authenticate( $user, 'pltemp', 'correct-horse-battery' );
		$this->assertSame( $user, $result );

		Session::register();
		$result = wp_authenticate( 'pltemp', 'correct-horse-battery' );
		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_temp_user', $result->get_error_code() );
	}

	public function test_an_application_password_login_is_not_touched() {
		$user = $this->make_user( 'subscriber', 'plapp' );
		do_action( 'application_password_did_authenticate', $user, array() );

		$this->assertSame( $user, RolePolicy::filter_authenticate( $user, 'plapp', 'abcd efgh' ) );
	}

	public function test_a_non_password_result_is_not_refused() {
		$user = $this->make_user( 'subscriber', 'plcookie' );

		$this->assertSame( $user, RolePolicy::filter_authenticate( $user, '', '' ) );
	}

	/**
	 * @group ms-only
	 */
	public function test_a_super_admin_on_multisite_always_logs_in_with_a_password() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$user = $this->make_user( 'subscriber', 'plsuper' );
		grant_super_admin( $user->ID );

		$this->assertSame( 'either', RolePolicy::for_user( get_userdata( $user->ID ) ) );
		$this->assertInstanceOf( WP_User::class, wp_authenticate( 'plsuper', 'correct-horse-battery' ) );
	}

	public function test_turning_the_feature_off_adds_no_filter() {
		remove_filter( 'authenticate', array( RolePolicy::class, 'filter_authenticate' ), 30 );
		Features::set( 'passwordless', false );
		Feature::register();

		$this->assertFalse( has_filter( 'authenticate', array( RolePolicy::class, 'filter_authenticate' ) ) );
		$this->make_user( 'subscriber', 'plfeatureoff' );
		$this->assertInstanceOf( WP_User::class, wp_authenticate( 'plfeatureoff', 'correct-horse-battery' ) );
	}

	public function test_the_filter_is_added_at_priority_30_when_the_feature_is_on() {
		$this->assertSame( 30, has_filter( 'authenticate', array( RolePolicy::class, 'filter_authenticate' ) ) );
	}

	public function test_a_wrong_password_gets_the_same_error_as_the_right_one_for_an_email_only_account() {
		$this->make_user( 'subscriber', 'plsame' );

		$wrong = wp_authenticate( 'plsame', 'not-the-password' );
		$right = wp_authenticate( 'plsame', 'correct-horse-battery' );

		$this->assertWPError( $wrong );
		$this->assertWPError( $right );
		$this->assertSame( 'happyaccess_email_only', $wrong->get_error_code() );
		$this->assertSame( $right->get_error_code(), $wrong->get_error_code() );
		$this->assertSame( $right->get_error_message(), $wrong->get_error_message() );
		$this->assertSame( array( 'happyaccess_email_only' ), $wrong->get_error_codes() );
	}

	public function test_a_wrong_password_by_email_gets_the_same_error_as_the_right_one() {
		$user = $this->make_user( 'subscriber', 'plsameemail' );

		$wrong = wp_authenticate( $user->user_email, 'not-the-password' );
		$right = wp_authenticate( $user->user_email, 'correct-horse-battery' );

		$this->assertWPError( $wrong );
		$this->assertWPError( $right );
		$this->assertSame( 'happyaccess_email_only', $wrong->get_error_code() );
		$this->assertSame( $right->get_error_code(), $wrong->get_error_code() );
		$this->assertSame( $right->get_error_message(), $wrong->get_error_message() );
	}

	public function test_a_wrong_password_for_a_default_policy_user_is_left_alone() {
		$this->make_user( 'editor', 'plwrongeditor' );

		$result = wp_authenticate( 'plwrongeditor', 'not-the-password' );
		$this->assertWPError( $result );
		$this->assertSame( 'incorrect_password', $result->get_error_code() );
	}

	public function test_a_wrong_password_for_a_missing_account_is_left_alone() {
		$result = wp_authenticate( 'nobody-here', 'not-the-password' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_username', $result->get_error_code() );
	}

	public function test_a_wrong_password_for_a_temp_support_user_is_left_alone() {
		$user = $this->make_user( 'subscriber', 'plwrongtemp' );
		update_user_meta( $user->ID, 'happyaccess_temp_user', 1 );

		$result = wp_authenticate( 'plwrongtemp', 'not-the-password' );
		$this->assertWPError( $result );
		$this->assertSame( 'incorrect_password', $result->get_error_code() );
	}

	public function test_an_empty_password_error_is_left_alone() {
		$this->make_user( 'subscriber', 'plwrongempty' );
		$error = new WP_Error( 'incorrect_password', 'Wrong.' );

		$this->assertSame( $error, RolePolicy::filter_authenticate( $error, 'plwrongempty', '' ) );
	}

	/**
	 * The constant is defined here, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_wp_config_constant_leaves_a_wrong_password_error_alone() {
		define( 'HAPPYACCESS_ALLOW_PASSWORD_LOGIN', true );
		$this->make_user( 'subscriber', 'plconstwrong' );

		$result = wp_authenticate( 'plconstwrong', 'not-the-password' );
		$this->assertWPError( $result );
		$this->assertSame( 'incorrect_password', $result->get_error_code() );
	}

	/**
	 * The constant is defined here, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_wp_config_constant_turns_the_policy_off() {
		define( 'HAPPYACCESS_ALLOW_PASSWORD_LOGIN', true );
		$this->make_user( 'subscriber', 'plconst' );

		$result = wp_authenticate( 'plconst', 'correct-horse-battery' );
		$this->assertInstanceOf( WP_User::class, $result );
	}
}
