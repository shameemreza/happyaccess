<?php
/**
 * Passwordless REST route tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\Passwordless\Feature;
use HappyAccess\Features\Passwordless\LoginSteps;
use HappyAccess\Login\Router;

class PasswordlessRestControllerTest extends WP_UnitTestCase {

	/**
	 * Mails caught before they are sent.
	 *
	 * @var array
	 */
	private $mails = array();

	/**
	 * Cookies the routes tried to send: name, value and options.
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
	 * Settings globals as they were before rest_api_init ran.
	 *
	 * @var array
	 */
	private $settings_globals = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_COOKIE                = array();

		$this->mails   = array();
		$this->cookies = array();
		$this->auth    = array();

		add_filter( 'send_auth_cookies', '__return_false' );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		add_filter( 'happyaccess_passwordless_send_cookie', array( $this, 'catch_cookie' ), 10, 4 );
		add_action( 'set_auth_cookie', array( $this, 'catch_auth' ), 10, 6 );

		foreach ( array( 'new_allowed_options', 'wp_registered_settings' ) as $name ) {
			$this->settings_globals[ $name ] = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;
		}

		LoginSteps::flush_queue();
		$this->mails = array();
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		LoginSteps::flush_queue();
		$_COOKIE                   = array();
		$GLOBALS['wp_rest_server'] = null;
		foreach ( $this->settings_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
		Router::reset();
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

	/**
	 * Turns the feature on and builds a fresh REST server.
	 *
	 * @return void
	 */
	private function boot( $enabled = true ) {
		Features::set( 'passwordless', $enabled );
		Feature::register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * Posts to a passwordless route the way the browser script does, and
	 * runs the response through the filters the REST server runs on output.
	 *
	 * @param string $path   request or verify.
	 * @param array  $params Body params.
	 * @param string $header Value of the custom header, or null for none.
	 * @return WP_REST_Response
	 */
	private function call( $path, array $params, $header = '1' ) {
		$request = new WP_REST_Request( 'POST', '/happyaccess/v1/passwordless/' . $path );
		if ( null !== $header ) {
			$request->set_header( 'X-HappyAccess-Login', $header );
		}
		$request->set_body_params( $params );
		$response = rest_do_request( $request );
		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );
	}

	/**
	 * Asks for a code over REST and returns the cookie and the code from the email.
	 *
	 * @param string $typed Email or username.
	 * @return array response, cookie and code.
	 */
	private function request_code( $typed ) {
		$this->mails   = array();
		$this->cookies = array();
		$response      = $this->call( 'request', array( 'login' => $typed ) );
		LoginSteps::flush_queue();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 1, preg_match( '/(\d{3}) (\d{3})/', $this->mails[0]['message'], $code ) );

		return array(
			'response' => $response,
			'cookie'   => $this->cookies[0]['value'],
			'code'     => $code[1] . $code[2],
		);
	}

	public function test_both_routes_refuse_a_request_without_the_header() {
		$this->boot();
		self::factory()->user->create( array( 'user_email' => 'real@example.org' ) );

		$response = $this->call( 'request', array( 'login' => 'real@example.org' ), null );
		LoginSteps::flush_queue();
		$this->assertSame( 403, $response->get_status() );
		$this->assertCount( 0, $this->mails );
		$this->assertCount( 0, $this->cookies );

		$response = $this->call( 'verify', array( 'code' => '123456' ), null );
		$this->assertSame( 403, $response->get_status() );
		$this->assertCount( 0, $this->auth );
	}

	public function test_a_header_with_another_value_is_refused() {
		$this->boot();
		$this->assertSame( 403, $this->call( 'request', array( 'login' => 'real@example.org' ), '0' )->get_status() );
		$this->assertSame( 403, $this->call( 'verify', array( 'code' => '123456' ), 'yes' )->get_status() );
	}

	public function test_the_request_route_answers_a_real_a_missing_a_support_and_a_limited_account_the_same() {
		$this->boot();
		self::factory()->user->create( array( 'user_email' => 'real@example.org' ) );
		self::factory()->user->create( array( 'user_email' => 'limited@example.org' ) );
		$support = self::factory()->user->create( array( 'user_email' => 'support@example.org' ) );
		update_user_meta( $support, 'happyaccess_temp_user', 1 );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->call( 'request', array( 'login' => 'limited@example.org' ) );
		}
		LoginSteps::flush_queue();
		$this->mails = array();

		$seen = array();
		foreach ( array( 'real@example.org', 'nobody@example.org', 'support@example.org', 'limited@example.org' ) as $typed ) {
			$this->cookies  = array();
			$response       = $this->call( 'request', array( 'login' => $typed ) );
			$seen[ $typed ] = array(
				'status'  => $response->get_status(),
				'data'    => $response->get_data(),
				'headers' => $response->get_headers(),
				'cookies' => array_map(
					static function ( $cookie ) {
						return array( $cookie['name'], $cookie['options']['path'], $cookie['options']['httponly'], $cookie['options']['samesite'] );
					},
					$this->cookies
				),
			);
		}
		LoginSteps::flush_queue();

		$this->assertSame( 200, $seen['real@example.org']['status'] );
		$this->assertCount( 1, $seen['real@example.org']['cookies'] );
		foreach ( $seen as $typed => $one ) {
			$this->assertSame( $seen['real@example.org'], $one, $typed );
		}
		$this->assertCount( 1, $this->mails, 'Only the real account gets an email.' );
		$this->assertSame( 'real@example.org', $this->mails[0]['to'] );
	}

	public function test_the_request_body_has_the_neutral_message_and_the_lifetime() {
		$this->boot();
		$data = $this->call( 'request', array( 'login' => 'nobody@example.org' ) )->get_data();

		$this->assertSame( array( 'message', 'expires_in' ), array_keys( $data ) );
		$this->assertSame( 600, $data['expires_in'] );
		$this->assertSame( 'If that account exists, we sent a login code to its email address. It expires in 10 minutes.', $data['message'] );
	}

	public function test_an_empty_login_gets_a_400_that_asks_for_it() {
		$this->boot();
		$response = $this->call( 'request', array( 'login' => '   ' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'happyaccess_empty', $response->get_data()['code'] );
		$this->assertSame( 'Enter your email or username.', $response->get_data()['message'] );
		$this->assertCount( 0, $this->cookies );
	}

	public function test_the_request_route_locks_an_ip_after_ten_requests() {
		$this->boot();
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 200, $this->call( 'request', array( 'login' => 'nobody' . $i . '@example.org' ) )->get_status() );
		}
		$response = $this->call( 'request', array( 'login' => 'another@example.org' ) );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'happyaccess_locked', $response->get_data()['code'] );
	}

	public function test_the_verify_route_logs_in_with_the_right_code_and_cookie() {
		$this->boot();
		$user = self::factory()->user->create_and_get(
			array(
				'user_email' => 'real@example.org',
				'role'       => 'editor',
			)
		);
		$made = $this->request_code( 'real@example.org' );

		$_COOKIE['happyaccess_pl_request'] = $made['cookie'];
		$response                          = $this->call( 'verify', array( 'code' => $made['code'] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'redirect' => admin_url() ), $response->get_data() );
		$this->assertSame( $user->ID, get_current_user_id() );
		$this->assertCount( 1, $this->auth );
		$this->assertSame( $user->ID, $this->auth[0]['user_id'] );
		$this->assertSame( 0, $this->auth[0]['expire'], 'Without remember the session ends with the browser.' );
	}

	public function test_remember_keeps_the_session() {
		$this->boot();
		self::factory()->user->create( array( 'user_email' => 'real@example.org' ) );
		$made = $this->request_code( 'real@example.org' );

		$_COOKIE['happyaccess_pl_request'] = $made['cookie'];
		$this->call(
			'verify',
			array(
				'code'     => $made['code'],
				'remember' => true,
			)
		);

		$this->assertCount( 1, $this->auth );
		$this->assertGreaterThan( 0, $this->auth[0]['expire'] );
	}

	public function test_the_verify_route_keeps_a_local_redirect_and_drops_an_offsite_one() {
		$this->boot();
		self::factory()->user->create(
			array(
				'user_email' => 'real@example.org',
				'role'       => 'editor',
			)
		);

		$made                              = $this->request_code( 'real@example.org' );
		$_COOKIE['happyaccess_pl_request'] = $made['cookie'];
		$data                              = $this->call(
			'verify',
			array(
				'code'        => $made['code'],
				'redirect_to' => home_url( '/checkout/' ),
			)
		)->get_data();
		$this->assertSame( home_url( '/checkout/' ), $data['redirect'] );

		wp_set_current_user( 0 );
		$made                              = $this->request_code( 'real@example.org' );
		$_COOKIE['happyaccess_pl_request'] = $made['cookie'];
		$data                              = $this->call(
			'verify',
			array(
				'code'        => $made['code'],
				'redirect_to' => 'https://evil.example.com/',
			)
		)->get_data();
		$this->assertSame( admin_url(), $data['redirect'] );
	}

	public function test_a_code_without_the_cookie_or_a_wrong_code_gets_one_400() {
		$this->boot();
		self::factory()->user->create( array( 'user_email' => 'real@example.org' ) );
		$made = $this->request_code( 'real@example.org' );

		$response = $this->call( 'verify', array( 'code' => $made['code'] ) );
		$this->assertSame( 400, $response->get_status() );
		$no_cookie = $response->get_data();

		$_COOKIE['happyaccess_pl_request'] = $made['cookie'];
		$wrong                             = '000000' === $made['code'] ? '111111' : '000000';
		$response                          = $this->call( 'verify', array( 'code' => $wrong ) );
		$this->assertSame( 400, $response->get_status() );

		$this->assertSame( $no_cookie, $response->get_data() );
		$this->assertSame( 'happyaccess_invalid_code', $no_cookie['code'] );
		$this->assertSame( 'That code is not right or has expired. Ask for a new code.', $no_cookie['message'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertCount( 0, $this->auth );
	}

	public function test_both_routes_send_no_store_on_success_and_on_failure() {
		$this->boot();
		$responses = array(
			$this->call( 'request', array( 'login' => 'nobody@example.org' ) ),
			$this->call( 'request', array( 'login' => 'nobody@example.org' ), null ),
			$this->call( 'verify', array( 'code' => '123456' ) ),
			$this->call( 'verify', array( 'code' => '123456' ), null ),
		);
		foreach ( $responses as $i => $response ) {
			$headers = $response->get_headers();
			$this->assertArrayHasKey( 'Cache-Control', $headers, (string) $i );
			$this->assertStringContainsString( 'no-store', $headers['Cache-Control'], (string) $i );
		}
	}

	public function test_the_routes_are_public_and_take_typed_args() {
		$this->boot();
		$routes = rest_get_server()->get_routes( 'happyaccess/v1' );

		foreach ( array( '/happyaccess/v1/passwordless/request', '/happyaccess/v1/passwordless/verify' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
			$this->assertCount( 1, $routes[ $route ] );
			$this->assertSame( array( 'POST' => true ), $routes[ $route ][0]['methods'] );
			foreach ( $routes[ $route ][0]['args'] as $name => $arg ) {
				$this->assertArrayHasKey( 'type', $arg, $name );
				$this->assertArrayHasKey( 'sanitize_callback', $arg, $name );
			}
		}
	}

	public function test_the_custom_header_is_not_allowed_across_origins() {
		$this->boot();
		$allowed = array_map( 'strtolower', (array) apply_filters( 'rest_allowed_cors_headers', array( 'X-WP-Nonce', 'Content-Type' ), new WP_REST_Request() ) );
		$this->assertNotContains( 'x-happyaccess-login', $allowed );
	}

	public function test_the_request_cookie_reaches_the_rest_url() {
		$this->boot();
		$this->call( 'request', array( 'login' => 'nobody@example.org' ) );

		$path = (string) wp_parse_url( rest_url(), PHP_URL_PATH );
		$this->assertSame( 0, strpos( $path, $this->cookies[0]['options']['path'] ), $path );
	}

	public function test_with_the_feature_off_the_routes_do_not_exist() {
		$this->boot( false );

		$this->assertSame( 404, $this->call( 'request', array( 'login' => 'nobody@example.org' ) )->get_status() );
		$this->assertSame( 404, $this->call( 'verify', array( 'code' => '123456' ) )->get_status() );
		$this->assertFalse( has_filter( 'rest_post_dispatch', array( 'HappyAccess\Features\Passwordless\RestController', 'no_store' ) ) );
	}
}
