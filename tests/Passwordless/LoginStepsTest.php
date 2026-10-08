<?php
/**
 * Passwordless login screen tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\Passwordless\LoginSteps;
use HappyAccess\Features\Passwordless\Requests;
use HappyAccess\Login\Router;

class PasswordlessLoginStepsTest extends WP_UnitTestCase {

	/**
	 * Mails caught before they are sent.
	 *
	 * @var array
	 */
	private $mails = array();

	/**
	 * Cookies the steps tried to send: name, value and options.
	 *
	 * @var array
	 */
	private $cookies = array();

	/**
	 * Users passed to wp_set_auth_cookie().
	 *
	 * @var array
	 */
	private $auth = array();

	/**
	 * How many times wp_login ran.
	 *
	 * @var int
	 */
	private $wp_login_count = 0;

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
		Features::set( 'passwordless', true );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_COOKIE                = array();
		$_REQUEST               = array();

		$this->mails          = array();
		$this->cookies        = array();
		$this->auth           = array();
		$this->wp_login_count = 0;

		add_filter( 'send_auth_cookies', '__return_false' );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		add_filter( 'happyaccess_passwordless_send_cookie', array( $this, 'catch_cookie' ), 10, 4 );
		add_action( 'set_auth_cookie', array( $this, 'catch_auth' ), 10, 6 );
		add_action( 'wp_login', array( $this, 'count_login' ) );

		// Empties a queue left by an earlier test.
		LoginSteps::flush_queue();
		$this->mails = array();
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		LoginSteps::flush_queue();
		$_COOKIE  = array();
		$_REQUEST = array();
		unset( $_SERVER['HTTPS'] );
		parent::tear_down();
	}

	public function catch_mail( $null, $atts ) {
		unset( $null );
		$this->mails[] = $atts;
		return true;
	}

	public function catch_cookie( $handled, $name, $value, $options ) {
		unset( $handled );
		$this->cookies[] = array(
			'name'    => $name,
			'value'   => $value,
			'options' => $options,
		);
		return true;
	}

	public function catch_auth( $cookie, $expire, $expiration, $user_id, $scheme, $token ) {
		unset( $cookie, $expiration, $scheme, $token );
		$this->auth[] = array(
			'user_id' => $user_id,
			'expire'  => $expire,
		);
	}

	public function count_login() {
		++$this->wp_login_count;
	}

	private function post_request( $typed, array $extra = array() ) {
		return LoginSteps::handle_request(
			'POST',
			array(),
			array_merge(
				array(
					'log'      => $typed,
					'_wpnonce' => wp_create_nonce( 'happyaccess_pl_request' ),
				),
				$extra
			)
		);
	}

	private function post_code( $code, $cookie, array $extra = array() ) {
		return LoginSteps::handle_verify(
			'POST',
			array(),
			array_merge(
				array(
					'pwd'      => $code,
					'_wpnonce' => wp_create_nonce( 'happyaccess_pl_verify' ),
				),
				$extra
			),
			null === $cookie ? array() : array( 'happyaccess_pl_request' => $cookie )
		);
	}

	private function post_link( $key ) {
		return LoginSteps::handle_verify(
			'POST',
			array(),
			array(
				'k'        => $key,
				'_wpnonce' => wp_create_nonce( 'happyaccess_pl_link' ),
			),
			array()
		);
	}

	/**
	 * Asks for a code as a user, runs the queued send and returns what the
	 * browser and the inbox got.
	 *
	 * @param WP_User $user User asking.
	 * @param array   $extra Extra POST fields.
	 * @return array response, cookie, code and key.
	 */
	private function request_as( WP_User $user, array $extra = array() ) {
		$this->mails   = array();
		$this->cookies = array();
		$response      = $this->post_request( $user->user_email, $extra );
		$cookie        = $this->cookies ? $this->cookies[0]['value'] : '';
		LoginSteps::flush_queue();

		$this->assertCount( 1, $this->mails );
		$message = $this->mails[0]['message'];
		$this->assertSame( 1, preg_match( '/(\d{3}) (\d{3})/', $message, $code ) );
		$this->assertSame( 1, preg_match( '/k=([A-Za-z0-9_-]{43})/', $message, $key ) );

		return array(
			'response' => $response,
			'cookie'   => $cookie,
			'code'     => $code[1] . $code[2],
			'key'      => $key[1],
		);
	}

	private function log_rows( $event ) {
		return AuditLog::query( array( 'event' => $event ) )['items'];
	}

	public function test_requests_for_real_missing_support_and_limited_accounts_look_the_same() {
		$real    = self::factory()->user->create_and_get( array( 'user_email' => 'real@example.org' ) );
		$limited = self::factory()->user->create_and_get( array( 'user_email' => 'limited@example.org' ) );
		$support = self::factory()->user->create_and_get( array( 'user_email' => 'support@example.org' ) );
		update_user_meta( $support->ID, 'happyaccess_temp_user', 1 );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->post_request( 'limited@example.org' );
		}
		LoginSteps::flush_queue();
		$this->mails = array();

		$seen = array();
		foreach ( array( 'real@example.org', 'nobody@example.org', 'support@example.org', 'LIMITED@example.org' ) as $typed ) {
			$this->cookies = array();
			$response      = $this->post_request( $typed );
			$seen[ $typed ] = array(
				'response' => $response,
				'names'    => wp_list_pluck( $this->cookies, 'name' ),
			);
		}

		$first = reset( $seen );
		$this->assertSame( 'redirect', $first['response']['type'] );
		$this->assertStringContainsString( 'step=verify', $first['response']['url'] );
		$this->assertSame( array( 'happyaccess_pl_request' ), $first['names'] );
		foreach ( $seen as $typed => $one ) {
			$this->assertSame( $first['response'], $one['response'], $typed );
			$this->assertSame( $first['names'], $one['names'], $typed );
		}

		$this->assertCount( 0, $this->mails, 'Nothing is sent before shutdown.' );
		LoginSteps::flush_queue();
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'real@example.org', $this->mails[0]['to'] );
		$this->assertSame( $real->user_email, $this->mails[0]['to'] );
		$this->assertNotSame( $limited->user_email, $this->mails[0]['to'] );
	}

	public function test_every_request_gets_its_own_cookie_value() {
		$this->post_request( 'nobody@example.org' );
		$this->post_request( 'nobody@example.org' );
		$this->assertCount( 2, $this->cookies );
		$this->assertNotSame( $this->cookies[0]['value'], $this->cookies[1]['value'] );
	}

	public function test_the_verify_screen_shows_the_same_message_for_everyone() {
		$res = LoginSteps::handle_verify( 'GET', array(), array(), array() );
		$this->assertSame( 'render', $res['type'] );
		$this->assertStringContainsString( 'If that account exists, we sent a login code to its email address. It expires in 10 minutes.', $res['message'] );
		$this->assertStringContainsString( 'name="pwd"', $res['body'] );
		$this->assertStringContainsString( 'inputmode="numeric"', $res['body'] );
		$this->assertStringContainsString( 'autocomplete="one-time-code"', $res['body'] );
		$this->assertStringContainsString( 'name="rememberme"', $res['body'] );
		$this->assertStringContainsString( 'name="_wpnonce"', $res['body'] );
	}

	public function test_the_request_form_has_one_field_a_nonce_and_a_button() {
		$res = LoginSteps::handle_request( 'GET', array(), array() );
		$this->assertSame( 'render', $res['type'] );
		$this->assertStringContainsString( 'name="log"', $res['body'] );
		$this->assertStringContainsString( 'Email or username', $res['body'] );
		$this->assertStringContainsString( 'name="_wpnonce"', $res['body'] );
		$this->assertStringContainsString( 'Send login code', $res['body'] );
	}

	public function test_a_bad_nonce_makes_no_request() {
		self::factory()->user->create( array( 'user_email' => 'real@example.org' ) );
		$res = LoginSteps::handle_request(
			'POST',
			array(),
			array(
				'log'      => 'real@example.org',
				'_wpnonce' => 'nope',
			)
		);
		LoginSteps::flush_queue();
		$this->assertSame( 'render', $res['type'] );
		$this->assertCount( 0, $this->mails );
		$this->assertCount( 0, $this->cookies );
	}

	public function test_the_steps_wait_while_the_database_updates() {
		update_option( 'happyaccess_db_version', '0.0.0' );
		self::factory()->user->create( array( 'user_email' => 'real@example.org' ) );

		$res = $this->post_request( 'real@example.org' );
		LoginSteps::flush_queue();
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( array( 'updating' ), $res['errors']->get_error_codes() );
		$this->assertCount( 0, $this->mails );

		$res = LoginSteps::handle_verify( 'GET', array(), array(), array() );
		$this->assertSame( array( 'updating' ), $res['errors']->get_error_codes() );
	}

	public function test_the_email_has_the_code_the_link_the_expiry_the_ip_and_a_text_part() {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		reset_phpmailer_instance();
		$user = self::factory()->user->create_and_get( array( 'user_email' => 'real@example.org' ) );
		$this->post_request( 'real@example.org' );
		LoginSteps::flush_queue();

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();
		$this->assertNotFalse( $sent );
		$this->assertSame( array( 'real@example.org' ), wp_list_pluck( $sent->to, 0 ) );
		$this->assertStringContainsString( 'Your login code', $sent->subject );
		$this->assertMatchesRegularExpression( '/\d{3} \d{3}/', $sent->body );
		$this->assertStringContainsString( 'step=verify', $sent->body );
		$this->assertStringContainsString( '10 minutes', $sent->body );
		$this->assertStringContainsString( '203.0.113.9', $sent->body );
		$this->assertStringContainsString( "If you didn't ask for this, you can ignore this email. Your password still works.", $sent->body );

		$this->assertStringContainsString( 'Your password still works.', $mailer->AltBody ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertMatchesRegularExpression( '/Your login code is \d{3} \d{3}/', $mailer->AltBody ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertMatchesRegularExpression( '/^http\S+step=verify&k=[A-Za-z0-9_-]{43}$/m', $mailer->AltBody ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertNotNull( $user );
	}

	public function test_queueing_a_mail_hooks_the_send_to_shutdown() {
		self::factory()->user->create( array( 'user_email' => 'real@example.org' ) );
		$this->post_request( 'real@example.org' );
		$this->assertNotFalse( has_action( 'shutdown', array( LoginSteps::class, 'flush_queue' ) ) );
		$this->assertCount( 0, $this->mails );
	}

	public function test_a_code_logs_the_user_in_once() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$made = $this->request_as( $user );

		$res = $this->post_code( $made['code'], $made['cookie'] );

		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( $user->ID, get_current_user_id() );
		$this->assertSame( array( $user->ID ), wp_list_pluck( $this->auth, 'user_id' ) );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertSame( admin_url(), $res['url'] );
		$this->assertEmpty( $this->auth[0]['expire'], 'Not remembered by default.' );

		$deleted = array_filter(
			$this->cookies,
			static function ( $cookie ) {
				return '' === $cookie['value'] && $cookie['options']['expires'] < time();
			}
		);
		$this->assertCount( 1, $deleted, 'The request cookie is removed.' );

		wp_set_current_user( 0 );
		$again = $this->post_code( $made['code'], $made['cookie'] );
		$this->assertSame( 'render', $again['type'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 1, $this->wp_login_count );
	}

	public function test_remember_me_keeps_the_session() {
		$user = self::factory()->user->create_and_get();
		$made = $this->request_as( $user );
		$this->post_code( $made['code'], $made['cookie'], array( 'rememberme' => 'forever' ) );
		$this->assertNotEmpty( $this->auth[0]['expire'] );
	}

	public function test_a_code_typed_with_a_space_works() {
		$user = self::factory()->user->create_and_get();
		$made = $this->request_as( $user );
		$res  = $this->post_code( substr( $made['code'], 0, 3 ) . ' ' . substr( $made['code'], 3 ), $made['cookie'] );
		$this->assertSame( 'redirect', $res['type'] );
	}

	public function test_a_code_fails_without_the_cookie_and_with_another_requests_cookie() {
		$one   = self::factory()->user->create_and_get();
		$two   = self::factory()->user->create_and_get();
		$first = $this->request_as( $one );
		$other = $this->request_as( $two );

		$none = $this->post_code( $first['code'], null );
		$this->assertSame( 'render', $none['type'] );
		$this->assertSame( array( 'happyaccess_invalid_code' ), $none['errors']->get_error_codes() );

		$wrong = $this->post_code( $first['code'], $other['cookie'] );
		$this->assertSame( 'render', $wrong['type'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 0, $this->wp_login_count );

		$right = $this->post_code( $first['code'], $first['cookie'] );
		$this->assertSame( 'redirect', $right['type'] );
		$this->assertSame( $one->ID, get_current_user_id() );
	}

	public function test_a_wrong_code_shows_one_error_and_logs_without_the_code() {
		$user  = self::factory()->user->create_and_get();
		$made  = $this->request_as( $user );
		$wrong = '000000' === $made['code'] ? '111111' : '000000';

		$res = $this->post_code( $wrong, $made['cookie'] );
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( array( 'happyaccess_invalid_code' ), $res['errors']->get_error_codes() );

		$rows = $this->log_rows( 'passwordless_failed' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 0, (int) $rows[0]['user_id'] );
		$this->assertSame( 'passwordless', $rows[0]['feature'] );
		$this->assertStringNotContainsString( $wrong, wp_json_encode( $rows ) );
		$this->assertStringNotContainsString( $made['code'], wp_json_encode( $rows ) );
	}

	public function test_the_fifth_wrong_code_cancels_the_request_and_logs_it() {
		$user  = self::factory()->user->create_and_get();
		$made  = $this->request_as( $user );
		$wrong = '000000' === $made['code'] ? '111111' : '000000';

		for ( $i = 0; $i < 5; $i++ ) {
			$res = $this->post_code( $wrong, $made['cookie'] );
		}
		$this->assertSame( array( 'happyaccess_code_locked' ), $res['errors']->get_error_codes() );
		$this->assertCount( 1, $this->log_rows( 'passwordless_locked' ) );

		$late = $this->post_code( $made['code'], $made['cookie'] );
		$this->assertSame( 'render', $late['type'] );
	}

	public function test_the_code_screen_locks_an_ip_after_too_many_tries() {
		$user  = self::factory()->user->create_and_get();
		$made  = $this->request_as( $user );
		$wrong = '000000' === $made['code'] ? '111111' : '000000';

		for ( $i = 0; $i < 5; $i++ ) {
			$this->post_code( $wrong, $made['cookie'] );
		}
		$res = $this->post_code( $made['code'], $made['cookie'] );
		$this->assertSame( array( 'locked' ), $res['errors']->get_error_codes() );
	}

	public function test_a_link_shows_a_confirm_page_and_logs_in_only_on_post() {
		$user = self::factory()->user->create_and_get( array( 'display_name' => 'Sam Rivers' ) );
		$made = $this->request_as( $user );

		$get = LoginSteps::handle_verify( 'GET', array( 'k' => $made['key'] ), array(), array() );
		$this->assertSame( 'render', $get['type'] );
		$this->assertStringContainsString( 'as Sam Rivers', $get['body'] );
		$this->assertStringContainsString( 'name="_wpnonce"', $get['body'] );
		$this->assertStringContainsString( 'type="submit"', $get['body'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 0, $this->wp_login_count );
		$this->assertNotNull( Requests::find_by_link( $made['key'] ), 'A GET leaves the link unused.' );

		$post = $this->post_link( $made['key'] );
		$this->assertSame( 'redirect', $post['type'] );
		$this->assertSame( $user->ID, get_current_user_id() );
		$this->assertSame( 1, $this->wp_login_count );
		$rows = $this->log_rows( 'passwordless_login' );
		$this->assertSame( 'link', $rows[0]['meta']['method'] );

		wp_set_current_user( 0 );
		$second = $this->post_link( $made['key'] );
		$this->assertSame( 'render', $second['type'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertSame( array( 'invalid_link' ), $second['errors']->get_error_codes() );
		$this->assertStringContainsString( 'This login link has expired or was already used.', $second['errors']->get_error_message() );
		$this->assertStringContainsString( 'step=request', $second['body'] );
	}

	public function test_an_unknown_link_shows_the_expired_message() {
		$res = LoginSteps::handle_verify( 'GET', array( 'k' => 'nope' ), array(), array() );
		$this->assertSame( array( 'invalid_link' ), $res['errors']->get_error_codes() );
		$this->assertStringContainsString( 'step=request', $res['body'] );
	}

	public function test_a_link_with_a_bad_nonce_does_not_log_in() {
		$user = self::factory()->user->create_and_get();
		$made = $this->request_as( $user );

		$res = LoginSteps::handle_verify(
			'POST',
			array(),
			array(
				'k'        => $made['key'],
				'_wpnonce' => 'nope',
			),
			array()
		);
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertNotNull( Requests::find_by_link( $made['key'] ) );
	}

	public function test_a_request_cancels_the_users_earlier_code_and_link() {
		$user  = self::factory()->user->create_and_get();
		$first = $this->request_as( $user );
		$this->request_as( $user );

		$this->assertNull( Requests::find_by_link( $first['key'] ) );
		$this->assertSame( 'render', $this->post_code( $first['code'], $first['cookie'] )['type'] );
	}

	public function test_an_off_site_redirect_is_dropped_and_a_local_one_is_kept() {
		$res = $this->post_request( 'nobody@example.org', array( 'redirect_to' => 'https://evil.example/steal' ) );
		$this->assertStringNotContainsString( 'redirect_to', $res['url'] );
		$this->assertStringNotContainsString( 'evil.example', $res['url'] );

		$local = admin_url( 'profile.php' );
		$res   = $this->post_request( 'nobody@example.org', array( 'redirect_to' => $local ) );
		$this->assertStringContainsString( 'redirect_to=' . rawurlencode( $local ), $res['url'] );

		$user = self::factory()->user->create_and_get();
		$made = $this->request_as( $user, array( 'redirect_to' => $local ) );
		$this->assertSame( $local, $this->post_code( $made['code'], $made['cookie'], array( 'redirect_to' => $local ) )['url'] );
	}

	public function test_a_tampered_redirect_on_the_code_form_is_ignored() {
		$user = self::factory()->user->create_and_get();
		$made = $this->request_as( $user );
		$res  = $this->post_code( $made['code'], $made['cookie'], array( 'redirect_to' => 'https://evil.example/' ) );
		$this->assertSame( admin_url(), $res['url'] );
	}

	public function test_the_login_redirect_filter_decides_when_no_target_was_carried() {
		add_filter(
			'login_redirect',
			static function () {
				return home_url( '/welcome/' );
			}
		);
		$user = self::factory()->user->create_and_get();
		$made = $this->request_as( $user );
		$this->assertSame( home_url( '/welcome/' ), $this->post_code( $made['code'], $made['cookie'] )['url'] );
	}

	/**
	 * Runs in its own process because it defines a WooCommerce function that
	 * must not stay defined for other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_customer_lands_on_my_account_when_woocommerce_is_active() {
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			// phpcs:ignore Universal.Files.SeparateFunctionsFromOO, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			eval( 'function wc_get_page_permalink( $page ) { return home_url( "/my-account/" ); }' );
		}
		Features::set( 'passwordless', true );
		add_role( 'customer', 'Customer', array( 'read' => true ) );

		$customer = self::factory()->user->create_and_get( array( 'role' => 'customer' ) );
		$made     = $this->request_as( $customer );
		$res      = $this->post_code( $made['code'], $made['cookie'] );
		$this->assertSame( wc_get_page_permalink( 'myaccount' ), $res['url'] );

		wp_set_current_user( 0 );
		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$made   = $this->request_as( $editor );
		$this->assertSame( admin_url(), $this->post_code( $made['code'], $made['cookie'] )['url'] );
	}

	public function test_the_login_form_link_follows_the_setting() {
		ob_start();
		do_action( 'login_form' );
		$this->assertSame( '', ob_get_clean() );

		LoginSteps::register();

		ob_start();
		do_action( 'login_form' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Email me a login code', $html );
		$this->assertStringContainsString( 'step=request', $html );

		Settings::update( array( 'passwordless' => array( 'show_on' => array( 'wp_login' => false ) ) ) );
		ob_start();
		do_action( 'login_form' );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_login_form_link_keeps_a_valid_redirect() {
		LoginSteps::register();
		$_REQUEST['redirect_to'] = admin_url( 'tools.php' );
		ob_start();
		do_action( 'login_form' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'redirect_to=' . rawurlencode( admin_url( 'tools.php' ) ), str_replace( '&#038;', '&', $html ) );

		$_REQUEST['redirect_to'] = 'https://evil.example/';
		ob_start();
		do_action( 'login_form' );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'evil.example', $html );
		$this->assertStringContainsString( 'Email me a login code', $html );
	}

	public function test_register_adds_the_steps() {
		Router::reset();
		LoginSteps::register();
		$this->assertNotNull( Router::resolve( 'request' ) );
		$this->assertNotNull( Router::resolve( 'verify' ) );
	}

	public function test_an_ip_is_locked_after_ten_requests_in_an_hour() {
		for ( $i = 0; $i < 10; $i++ ) {
			$res = $this->post_request( 'person' . $i . '@example.org' );
			$this->assertSame( 'redirect', $res['type'] );
		}
		$this->cookies = array();
		$res           = $this->post_request( 'person11@example.org' );

		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( array( 'locked' ), $res['errors']->get_error_codes() );
		$this->assertSame( 'Too many attempts. Try again in 60 minutes.', $res['errors']->get_error_message() );
		$this->assertCount( 0, $this->cookies );
	}

	public function test_the_request_cookie_options() {
		$_SERVER['HTTPS'] = 'on';
		$this->post_request( 'nobody@example.org' );

		$cookie  = $this->cookies[0];
		$options = $cookie['options'];
		$this->assertSame( 'happyaccess_pl_request', $cookie['name'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $cookie['value'] );
		$this->assertTrue( $options['httponly'] );
		$this->assertTrue( $options['secure'] );
		$this->assertSame( 'Lax', $options['samesite'] );
		$this->assertSame( COOKIEPATH, $options['path'] );
		$this->assertEqualsWithDelta( time() + 600, $options['expires'], 5 );

		unset( $_SERVER['HTTPS'] );
		$this->post_request( 'nobody@example.org' );
		$this->assertFalse( $this->cookies[1]['options']['secure'] );
	}

	public function test_the_log_holds_no_typed_value_for_a_missing_account() {
		$this->post_request( 'ghost@example.org' );
		$this->post_request( 'ghostuser' );

		$this->assertSame( array(), $this->log_rows( 'passwordless_requested' ) );
		$all = wp_json_encode( AuditLog::query( array( 'feature' => 'passwordless' ) )['items'] );
		$this->assertStringNotContainsString( 'ghost', $all );
	}

	public function test_a_request_logs_for_the_real_user_without_the_code_or_key() {
		$user = self::factory()->user->create_and_get( array( 'user_email' => 'real@example.org' ) );
		$made = $this->request_as( $user );

		$rows = $this->log_rows( 'passwordless_requested' );
		$this->assertCount( 1, $rows );
		$this->assertSame( $user->ID, (int) $rows[0]['user_id'] );
		$json = wp_json_encode( $rows );
		$this->assertStringNotContainsString( $made['code'], $json );
		$this->assertStringNotContainsString( $made['key'], $json );
		$this->assertStringNotContainsString( 'real@example.org', $json );
	}

	public function test_a_limited_account_logs_a_failure_with_its_user() {
		$user = self::factory()->user->create_and_get( array( 'user_email' => 'limited@example.org' ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->post_request( 'limited@example.org' );
		}
		$rows = $this->log_rows( 'passwordless_failed' );
		$this->assertCount( 1, $rows );
		$this->assertSame( $user->ID, (int) $rows[0]['user_id'] );
		$this->assertSame( 'account_limit', $rows[0]['meta']['reason'] );
		LoginSteps::flush_queue();
		$this->assertCount( 3, $this->mails );
	}

	public function test_a_support_user_cannot_get_a_login_code() {
		$support = self::factory()->user->create_and_get( array( 'user_email' => 'support@example.org' ) );
		update_user_meta( $support->ID, 'happyaccess_temp_user', 1 );
		$this->post_request( 'support@example.org' );
		LoginSteps::flush_queue();
		$this->assertCount( 0, $this->mails );
		$this->assertSame( array(), $this->log_rows( 'passwordless_requested' ) );
	}

	public function test_a_success_clears_the_ip_count() {
		$user = self::factory()->user->create_and_get();
		$made = $this->request_as( $user );
		$wrong = '000000' === $made['code'] ? '111111' : '000000';
		for ( $i = 0; $i < 3; $i++ ) {
			$this->post_code( $wrong, $made['cookie'] );
		}
		$this->assertSame( 'redirect', $this->post_code( $made['code'], $made['cookie'] )['type'] );

		wp_set_current_user( 0 );
		$next = $this->request_as( $user );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->post_code( $wrong, $next['cookie'] );
		}
		$this->assertSame( 'redirect', $this->post_code( $next['code'], $next['cookie'] )['type'] );
	}

	public function test_the_empty_form_asks_for_an_email_or_username() {
		$res = $this->post_request( '   ' );
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( array( 'empty' ), $res['errors']->get_error_codes() );
		$this->assertCount( 0, $this->cookies );
	}
}
