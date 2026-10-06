<?php
/**
 * Login step tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\LoginSteps;

class LoginStepsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		add_filter( 'send_auth_cookies', '__return_false' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		Grants::flush_cache();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function post_code( $code ) {
		return LoginSteps::handle_code(
			'POST',
			array(
				'pwd'      => $code,
				'_wpnonce' => wp_create_nonce( 'happyaccess_code' ),
			)
		);
	}

	private function wrong_code( $made ) {
		return '00000000' === $made['code'] ? '11111111' : '00000000';
	}

	public function test_get_renders_the_code_form() {
		$res = LoginSteps::handle_code( 'GET', array() );
		$this->assertSame( 'render', $res['type'] );
		$this->assertStringContainsString( 'name="pwd"', $res['body'] );
		$this->assertStringContainsString( 'one-time-code', $res['body'] );
	}

	public function test_valid_code_logs_in_and_redirects() {
		$made = Grants::create(
			array(
				'label'       => 'Acme',
				'redirect_to' => admin_url( 'edit.php' ),
			)
		);
		wp_set_current_user( 0 );
		$res = $this->post_code( $made['code'] );
		$this->assertSame(
			array(
				'type' => 'redirect',
				'url'  => admin_url( 'edit.php' ),
			),
			$res
		);
		$this->assertTrue( (bool) get_user_meta( get_current_user_id(), 'happyaccess_temp_user', true ) );
		$this->assertSame( 1, Grants::get( $made['id'] )['login_count'] );
	}

	public function test_wrong_code_shows_one_generic_error() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		wp_set_current_user( 0 );
		$res = $this->post_code( $this->wrong_code( $made ) );
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( array( 'invalid_code' ), $res['errors']->get_error_codes() );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_bad_nonce_fails() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		$res  = LoginSteps::handle_code(
			'POST',
			array(
				'pwd'      => $made['code'],
				'_wpnonce' => 'nope',
			)
		);
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( 0, Grants::get( $made['id'] )['login_count'] );
	}

	public function test_ip_lockout_after_max_attempts() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->post_code( $this->wrong_code( $made ) );
		}
		$res = $this->post_code( $made['code'] );
		$this->assertSame( array( 'locked' ), $res['errors']->get_error_codes() );
	}

	public function test_suspended_wrong_ip_and_used_one_time_fail() {
		$a = Grants::create( array( 'label' => 'a' ) );
		Grants::suspend( $a['id'] );
		$this->assertSame( 'render', $this->post_code( $a['code'] )['type'] );

		$b = Grants::create(
			array(
				'label' => 'b',
				'ips'   => array( '198.51.100.1' ),
			)
		);
		$this->assertSame( 'render', $this->post_code( $b['code'] )['type'] );

		$c = Grants::create(
			array(
				'label'    => 'c',
				'one_time' => true,
			)
		);
		$this->assertSame( 'redirect', $this->post_code( $c['code'] )['type'] );
		wp_set_current_user( 0 );
		$this->assertSame( 'render', $this->post_code( $c['code'] )['type'] );
	}

	public function test_link_get_confirms_and_post_logs_in() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		wp_set_current_user( 0 );
		$get = LoginSteps::handle_link( 'GET', array( 'k' => $made['link_key'] ), array() );
		$this->assertSame( 'render', $get['type'] );
		$this->assertStringContainsString( 'Acme', $get['body'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 0, Grants::get( $made['id'] )['login_count'] );

		$post = LoginSteps::handle_link(
			'POST',
			array(),
			array(
				'k'        => $made['link_key'],
				'_wpnonce' => wp_create_nonce( 'happyaccess_link' ),
			)
		);
		$this->assertSame( 'redirect', $post['type'] );
		$this->assertSame( 1, Grants::get( $made['id'] )['login_count'] );
	}

	public function test_bad_link_shows_error() {
		$res = LoginSteps::handle_link( 'GET', array( 'k' => 'nope' ), array() );
		$this->assertSame( array( 'invalid_link' ), $res['errors']->get_error_codes() );
	}

	public function test_db_gate_blocks_login_during_migration() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		update_option( 'happyaccess_db_version', '1.0.4' );
		$res = $this->post_code( $made['code'] );
		$this->assertSame( array( 'updating' ), $res['errors']->get_error_codes() );
	}

	public function test_login_form_link_only_with_current_grants() {
		LoginSteps::register();
		ob_start();
		do_action( 'login_form' );
		$this->assertStringNotContainsString( 'happyaccess-code-link', ob_get_clean() );
		Grants::create( array( 'label' => 'Acme' ) );
		ob_start();
		do_action( 'login_form' );
		$this->assertStringContainsString( 'happyaccess-code-link', ob_get_clean() );
	}

	public function test_allowed_ip_logs_in() {
		$made = Grants::create(
			array(
				'label' => 'Acme',
				'ips'   => array( '198.51.100.1', '203.0.113.9' ),
			)
		);
		wp_set_current_user( 0 );
		$this->assertSame( 'redirect', $this->post_code( $made['code'] )['type'] );
	}

	public function test_revoked_grant_gets_the_same_generic_error() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		Grants::revoke( $made['id'] );
		$res = $this->post_code( $made['code'] );
		$this->assertSame( array( 'invalid_code' ), $res['errors']->get_error_codes() );
		$this->assertSame( 0, Grants::get( $made['id'] )['login_count'] );
	}

	public function test_captcha_filter_can_reject_when_enabled() {
		Settings::update( array( 'security' => array( 'recaptcha_enabled' => true ) ) );
		add_filter( 'happyaccess_verify_captcha', '__return_false' );
		$made = Grants::create( array( 'label' => 'Acme' ) );
		$res  = $this->post_code( $made['code'] );
		remove_filter( 'happyaccess_verify_captcha', '__return_false' );
		$this->assertSame( array( 'invalid_code' ), $res['errors']->get_error_codes() );
		$this->assertSame( 0, Grants::get( $made['id'] )['login_count'] );
	}

	public function test_site_cap_locks_all_ips_and_alerts_once() {
		Settings::update( array( 'security' => array( 'site_code_cap' => 5 ) ) );
		reset_phpmailer_instance();
		delete_transient( 'happyaccess_site_lock_alerted' );
		$made = Grants::create( array( 'label' => 'Acme' ) );
		for ( $i = 0; $i < 5; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 20 + $i );
			$this->post_code( $this->wrong_code( $made ) );
		}
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		$res                    = $this->post_code( $made['code'] );
		$this->assertSame( array( 'locked' ), $res['errors']->get_error_codes() );
		$this->assertSame( 0, Grants::get( $made['id'] )['login_count'] );
		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_link_post_with_bad_nonce_or_key_does_not_log_in() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		$bad  = LoginSteps::handle_link(
			'POST',
			array(),
			array(
				'k'        => $made['link_key'],
				'_wpnonce' => 'nope',
			)
		);
		$this->assertSame( array( 'invalid_link' ), $bad['errors']->get_error_codes() );
		$none = LoginSteps::handle_link(
			'POST',
			array(),
			array(
				'k'        => 'nope',
				'_wpnonce' => wp_create_nonce( 'happyaccess_link' ),
			)
		);
		$this->assertSame( array( 'invalid_link' ), $none['errors']->get_error_codes() );
		$this->assertSame( 0, Grants::get( $made['id'] )['login_count'] );
	}

	public function test_link_get_for_suspended_grant_shows_error_and_array_input_is_safe() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		Grants::suspend( $made['id'] );
		$res = LoginSteps::handle_link( 'GET', array( 'k' => $made['link_key'] ), array() );
		$this->assertSame( array( 'invalid_link' ), $res['errors']->get_error_codes() );
		$res = LoginSteps::handle_link( 'GET', array( 'k' => array( 'x' ) ), array() );
		$this->assertSame( array( 'invalid_link' ), $res['errors']->get_error_codes() );
		$res = LoginSteps::handle_code(
			'POST',
			array(
				'pwd'      => array( '1' ),
				'_wpnonce' => wp_create_nonce( 'happyaccess_code' ),
			)
		);
		$this->assertSame( array( 'invalid_code' ), $res['errors']->get_error_codes() );
	}

	public function test_ended_messages_follow_the_reason() {
		$expired = LoginSteps::handle_ended( array( 'reason' => 'expired' ) );
		$this->assertSame( 'render', $expired['type'] );
		$default = LoginSteps::handle_ended( array() );
		$this->assertStringContainsString( 'Your support access has ended.', $default['body'] );
		$this->assertNotSame( $expired['body'], $default['body'] );
		$odd = LoginSteps::handle_ended( array( 'reason' => '<script>' ) );
		$this->assertSame( $default['body'], $odd['body'] );
	}
}
