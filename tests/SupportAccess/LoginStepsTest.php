<?php
/**
 * Login step tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Features;
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

	public function test_login_sets_a_remembered_auth_cookie() {
		$seen = array();
		$spy  = function ( $length, $user_id, $remember ) use ( &$seen ) {
			$seen[] = $remember;
			return $length;
		};
		add_filter( 'auth_cookie_expiration', $spy, 100, 3 );

		$made = Grants::create( array( 'label' => 'Acme' ) );
		wp_set_current_user( 0 );
		$res = $this->post_code( $made['code'] );
		remove_filter( 'auth_cookie_expiration', $spy, 100 );

		$this->assertSame( 'redirect', $res['type'] );
		$this->assertNotEmpty( $seen );
		$this->assertSame( array( true ), array_values( array_unique( $seen ) ) );
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
		$this->assertSame( 'Support access is getting ready. Try again in a minute.', $res['errors']->get_error_message() );
		$this->assertSame( 0, (int) Grants::get( $made['id'] )['login_count'] );
	}

	public function test_db_gate_blocks_the_link_step_while_the_version_lags() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		update_option( 'happyaccess_db_version', '1.0.4' );

		$get  = LoginSteps::handle_link( 'GET', array( 'k' => $made['link_key'] ), array() );
		$post = LoginSteps::handle_link(
			'POST',
			array(),
			array(
				'k'        => $made['link_key'],
				'_wpnonce' => wp_create_nonce( 'happyaccess_link' ),
			)
		);

		foreach ( array( $get, $post ) as $res ) {
			$this->assertSame( array( 'updating' ), $res['errors']->get_error_codes() );
			$this->assertSame( 'Support access is getting ready. Try again in a minute.', $res['errors']->get_error_message() );
		}
		$this->assertSame( 0, (int) Grants::get( $made['id'] )['login_count'] );
	}

	public function test_login_form_link_shows_whenever_the_feature_is_on() {
		LoginSteps::register();
		$this->assertFalse( Grants::has_current() );
		ob_start();
		do_action( 'login_form' );
		$this->assertStringContainsString( 'happyaccess-code-link', ob_get_clean() );
		Grants::create( array( 'label' => 'Acme' ) );
		ob_start();
		do_action( 'login_form' );
		$this->assertStringContainsString( 'happyaccess-code-link', ob_get_clean() );
	}

	public function test_login_form_link_is_not_registered_while_the_feature_is_off() {
		remove_action( 'login_form', array( LoginSteps::class, 'print_code_link' ) );
		Features::set( 'support_access', false );
		LoginSteps::register();
		$this->assertFalse( has_action( 'login_form', array( LoginSteps::class, 'print_code_link' ) ) );
		$this->assertNotNull( \HappyAccess\Login\Router::resolve( 'ended' ) );
	}

	public function test_temp_login_never_goes_through_core_authentication() {
		$calls = 0;
		$spy   = function ( $user ) use ( &$calls ) {
			++$calls;
			return $user;
		};
		add_filter( 'authenticate', $spy, 1 );
		$made = Grants::create( array( 'label' => 'Acme' ) );
		wp_set_current_user( 0 );
		$res = $this->post_code( $made['code'] );
		remove_filter( 'authenticate', $spy, 1 );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 0, $calls );
	}

	public function test_success_clears_only_the_ip_scope() {
		Settings::update( array( 'security' => array( 'site_code_cap' => 5 ) ) );
		$made = Grants::create( array( 'label' => 'Acme' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->post_code( $this->wrong_code( $made ) );
		}
		$this->assertSame( 'redirect', $this->post_code( $made['code'] )['type'] );
		wp_set_current_user( 0 );

		// The IP counter was cleared: five more wrong tries from this IP are all answered with the generic error.
		// The site counter was not: it already holds 4 attempts, so the 2nd one here reaches the cap of 5.
		$this->assertSame( array( 'invalid_code' ), $this->post_code( $this->wrong_code( $made ) )['errors']->get_error_codes() );
		$this->assertSame( array( 'locked' ), $this->post_code( $this->wrong_code( $made ) )['errors']->get_error_codes() );
	}

	public function test_allowlist_matches_other_notations_of_the_same_address() {
		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';
		$made                   = Grants::create(
			array(
				'label' => 'Acme',
				'ips'   => array( '2001:DB8:0:0:0:0:0:1' ),
			)
		);
		wp_set_current_user( 0 );
		$this->assertSame( 'redirect', $this->post_code( $made['code'] )['type'] );

		$_SERVER['REMOTE_ADDR'] = '2001:db8::2';
		wp_set_current_user( 0 );
		$other = Grants::create(
			array(
				'label' => 'Other',
				'ips'   => array( '2001:DB8:0:0:0:0:0:1' ),
			)
		);
		$this->assertSame( 'render', $this->post_code( $other['code'] )['type'] );
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
		$this->assertSame( array( 'expired_page' ), $bad['errors']->get_error_codes() );
		$none = LoginSteps::handle_link(
			'POST',
			array(),
			array(
				'k'        => 'nope',
				'_wpnonce' => wp_create_nonce( 'happyaccess_link' ),
			)
		);
		$this->assertSame( array( 'invalid_link' ), $none['errors']->get_error_codes() );
		$gone = LoginSteps::handle_link(
			'POST',
			array(),
			array(
				'k'        => 'nope',
				'_wpnonce' => 'nope',
			)
		);
		$this->assertSame( array( 'invalid_link' ), $gone['errors']->get_error_codes() );
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

	public function test_expired_nonce_on_a_valid_link_shows_the_confirm_screen_again() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		$res  = LoginSteps::handle_link(
			'POST',
			array(),
			array(
				'k'        => $made['link_key'],
				'_wpnonce' => 'old',
			)
		);
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( array( 'expired_page' ), $res['errors']->get_error_codes() );
		$this->assertStringContainsString( 'This page expired. Select Log in again.', $res['errors']->get_error_message() );
		$this->assertStringContainsString( 'Acme', $res['body'] );
		$this->assertStringContainsString( '_wpnonce', $res['body'] );
		$this->assertSame( 0, Grants::get( $made['id'] )['login_count'] );
	}

	public function test_setup_failure_is_reported_and_logged() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		wp_set_current_user( 0 );
		add_filter( 'pre_user_login', '__return_empty_string' );
		$res = $this->post_code( $made['code'] );
		remove_filter( 'pre_user_login', '__return_empty_string' );

		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( array( 'setup_failed' ), $res['errors']->get_error_codes() );
		$this->assertSame( 0, get_current_user_id() );
		$rows = AuditLog::query( array( 'event' => 'login_failed' ) )['items'];
		$this->assertCount( 1, $rows );
		$this->assertSame( $made['id'], (int) $rows[0]['token_id'] );
		$this->assertSame( "Couldn't set up the support account", $rows[0]['summary'] );
		$this->assertNotEmpty( $rows[0]['meta']['reason'] );
	}

	public function test_audit_and_alert_happen_before_the_wp_login_action() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		wp_set_current_user( 0 );
		$seen = array();
		$spy  = function () use ( &$seen, $made ) {
			$seen['audit'] = ! empty( AuditLog::query( array( 'event' => 'login_success' ) )['items'] );
			$seen['count'] = Grants::get( $made['id'] )['login_count'];
		};
		add_action( 'wp_login', $spy );
		$this->post_code( $made['code'] );
		remove_action( 'wp_login', $spy );
		$this->assertTrue( $seen['audit'] );
		$this->assertSame( 1, $seen['count'] );
	}

	public function test_login_form_link_is_hidden_during_migration() {
		LoginSteps::register();
		Grants::create( array( 'label' => 'Acme' ) );
		update_option( 'happyaccess_db_version', '1.0.4' );
		ob_start();
		do_action( 'login_form' );
		$this->assertStringNotContainsString( 'happyaccess-code-link', ob_get_clean() );
	}
}
