<?php
/**
 * Two-step REST routes for the user's own setup.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\RateLimiter;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\BackupCodes;
use HappyAccess\Features\TwoStep\Challenge;
use HappyAccess\Features\TwoStep\EmailMethod;
use HappyAccess\Features\TwoStep\Feature;
use HappyAccess\Features\TwoStep\RestController;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;
use HappyAccess\Login\Router;

class TwoStepRestControllerTest extends WP_UnitTestCase {

	const PASSWORD = 'correct horse battery';

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
		Clock::freeze( 1790000000 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		foreach ( array( 'new_allowed_options', 'wp_registered_settings' ) as $name ) {
			$this->settings_globals[ $name ] = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;
		}

		Features::set( 'two_step', true );
		$this->set_policy( array( 'editor' => 'optional' ) );
		Feature::register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
		add_filter( 'pre_wp_mail', '__return_true' );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			$GLOBALS['wp_rest_server']                     = null;
			$GLOBALS['wp_rest_application_password_uuid'] = null;
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
			foreach ( $this->settings_globals as $name => $value ) {
				$GLOBALS[ $name ] = $value;
			}
			Clock::freeze( null );
			Router::reset();
		} finally {
			parent::tear_down();
		}
	}

	/**
	 * Saves the role policy, replacing the old one.
	 *
	 * @param array $policy Role slug to choice.
	 * @return void
	 */
	private function set_policy( array $policy ) {
		Settings::update( array( 'two_step' => array( 'role_policy' => array_fill_keys( array_keys( wp_roles()->roles ), 'off' ) ) ) );
		Settings::update( array( 'two_step' => array( 'role_policy' => $policy ) ) );
	}

	/**
	 * Logs in an editor with a known password.
	 *
	 * @return WP_User
	 */
	private function editor() {
		$user = self::factory()->user->create_and_get(
			array(
				'role'      => 'editor',
				'user_pass' => self::PASSWORD,
			)
		);
		wp_set_current_user( $user->ID );
		$this->start_session( $user->ID );
		return $user;
	}

	/**
	 * Starts a login session for the user and sends its cookie, as a browser
	 * would.
	 *
	 * @param int $user_id User id.
	 * @return string The session token.
	 */
	private function start_session( $user_id ) {
		$expires = time() + HOUR_IN_SECONDS;
		$token   = WP_Session_Tokens::get_instance( $user_id )->create( $expires );

		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $token );
		return $token;
	}

	/**
	 * The re-check time stored for this session, 0 for none.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	private function recheck_at( $user_id ) {
		$map = get_user_meta( $user_id, UserState::META_RECHECK, true );
		$key = hash( 'sha256', wp_get_session_token() );
		return is_array( $map ) && isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
	}

	/**
	 * Posts to a two-step route, through the filters the REST server runs on output.
	 *
	 * @param string $path   Route after twostep/.
	 * @param array  $params Body params.
	 * @return WP_REST_Response
	 */
	private function call( $path, array $params = array() ) {
		$request = new WP_REST_Request( 'POST', '/happyaccess/v1/twostep/' . $path );
		$request->set_body_params( $params );
		$response = rest_do_request( $request );
		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );
	}

	/**
	 * Error code of a response.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return string
	 */
	private function code_of( WP_REST_Response $response ) {
		$data = $response->get_data();
		return is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '';
	}

	/**
	 * Passes the re-check with the password.
	 *
	 * @return void
	 */
	private function recheck() {
		$response = $this->call( 'recheck', array( 'password' => self::PASSWORD ) );
		$this->assertSame( 200, $response->get_status(), 'The re-check passes.' );
	}

	/**
	 * The app code for the current time step.
	 *
	 * @param string $secret Secret.
	 * @param int    $offset Steps from now.
	 * @return string
	 */
	private function app_code( $secret, $offset = 0 ) {
		return Totp::code( $secret, Totp::step_for( Clock::now() ) + $offset );
	}

	/**
	 * Routes that change something, so they need a fresh re-check.
	 *
	 * @return array
	 */
	private function change_routes() {
		return array( 'app/begin', 'app/confirm', 'app/disable', 'email/begin', 'email/confirm', 'email/disable', 'backup/regenerate' );
	}

	public function test_every_route_refuses_a_logged_out_request() {
		foreach ( array_merge( array( 'recheck' ), $this->change_routes() ) as $route ) {
			$response = $this->call( $route, array( 'password' => self::PASSWORD ) );
			$this->assertSame( 401, $response->get_status(), $route );
		}
	}

	public function test_every_change_route_refuses_without_a_fresh_recheck() {
		$user = $this->editor();
		UserState::enable_email( $user->ID );

		foreach ( $this->change_routes() as $route ) {
			$response = $this->call( $route, array( 'code' => '123456' ) );
			$this->assertSame( 403, $response->get_status(), $route );
			$this->assertSame( 'happyaccess_recheck_required', $this->code_of( $response ), $route );
		}

		update_user_meta( $user->ID, UserState::META_RECHECK, array( hash( 'sha256', wp_get_session_token() ) => Clock::now() - 16 * MINUTE_IN_SECONDS ) );
		foreach ( $this->change_routes() as $route ) {
			$this->assertSame( 'happyaccess_recheck_required', $this->code_of( $this->call( $route ) ), $route . ' with a stale re-check' );
		}
		$this->assertTrue( UserState::email_enabled( $user->ID ), 'Nothing changed.' );
	}

	public function test_a_support_temp_user_is_refused() {
		$user = $this->editor();
		update_user_meta( $user->ID, 'happyaccess_temp_user', 1 );

		foreach ( array_merge( array( 'recheck' ), $this->change_routes() ) as $route ) {
			$this->assertSame( 403, $this->call( $route, array( 'password' => self::PASSWORD ) )->get_status(), $route );
		}
		$this->assertSame( '', get_user_meta( $user->ID, UserState::META_RECHECK, true ) );
	}

	public function test_the_password_recheck_sets_the_timestamp_and_every_response_is_no_store() {
		$user = $this->editor();

		$response = $this->call( 'recheck', array( 'password' => self::PASSWORD ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Clock::now(), $this->recheck_at( $user->ID ) );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );

		wp_set_current_user( 0 );
		$refused = $this->call( 'app/begin' );
		$this->assertSame( 401, $refused->get_status() );
		$this->assertStringContainsString( 'no-store', $refused->get_headers()['Cache-Control'], 'Refusals are no-store too.' );
	}

	public function test_a_wrong_password_counts_against_the_ip_limit() {
		$user = $this->editor();
		$max  = (int) Settings::get( 'security.max_attempts' );

		for ( $i = 0; $i < $max; $i++ ) {
			$response = $this->call( 'recheck', array( 'password' => 'wrong' ) );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'happyaccess_recheck_failed', $this->code_of( $response ) );
		}
		$locked = $this->call( 'recheck', array( 'password' => self::PASSWORD ) );
		$this->assertSame( 429, $locked->get_status(), 'Past the limit even the right password waits.' );
		$this->assertSame( '', get_user_meta( $user->ID, UserState::META_RECHECK, true ) );
	}

	public function test_an_app_or_backup_code_passes_the_recheck_once() {
		$user   = $this->editor();
		$secret = Totp::new_secret();
		UserState::enable_app( $user->ID, $secret );
		$codes = BackupCodes::generate( $user->ID );

		$this->assertSame( 200, $this->call( 'recheck', array( 'code' => $this->app_code( $secret ) ) )->get_status() );
		$this->assertSame( 403, $this->call( 'recheck', array( 'code' => $this->app_code( $secret ) ) )->get_status(), 'The same app code is used up.' );

		$this->assertSame( 200, $this->call( 'recheck', array( 'code' => $codes[0] ) )->get_status() );
		$this->assertSame( 9, BackupCodes::remaining( $user->ID ), 'The backup code is used up.' );
	}

	public function test_app_begin_confirm_and_disable_round_trip() {
		$user = $this->editor();
		$this->recheck();

		$begin = $this->call( 'app/begin' );
		$this->assertSame( 200, $begin->get_status() );
		$this->assertStringContainsString( 'no-store', $begin->get_headers()['Cache-Control'] );
		$data   = $begin->get_data();
		$secret = $data['secret'];
		$this->assertSame( 32, strlen( $secret ) );
		$this->assertStringStartsWith( 'otpauth://totp/', $data['uri'] );
		$this->assertStringContainsString( 'secret=' . $secret, $data['uri'] );
		$this->assertFalse( UserState::app_enabled( $user->ID ), 'Nothing is on before the first code.' );

		global $wpdb;
		$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . RestController::TRANSIENT . $user->ID ) );
		$this->assertNotSame( '', $held, 'The secret waits in a transient.' );
		$this->assertStringNotContainsString( $secret, $held, 'It waits encrypted.' );
		$timeout = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_timeout_' . RestController::TRANSIENT . $user->ID ) );
		$this->assertLessThanOrEqual( time() + 600, $timeout );

		$wrong = $this->call( 'app/confirm', array( 'code' => '000000' === $this->app_code( $secret ) ? '111111' : '000000' ) );
		$this->assertSame( 400, $wrong->get_status() );
		$this->assertFalse( UserState::app_enabled( $user->ID ) );

		$confirm = $this->call( 'app/confirm', array( 'code' => $this->app_code( $secret ) ) );
		$this->assertSame( 200, $confirm->get_status() );
		$this->assertStringContainsString( 'no-store', $confirm->get_headers()['Cache-Control'] );
		$this->assertTrue( UserState::app_enabled( $user->ID ) );
		$this->assertSame( $secret, UserState::totp_secret( $user->ID ) );
		$this->assertCount( 10, $confirm->get_data()['codes'], 'The first method makes the backup codes.' );
		$this->assertSame( 10, BackupCodes::remaining( $user->ID ) );
		$this->assertSame( '', (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . RestController::TRANSIENT . $user->ID ) ), 'The held secret is gone.' );

		$again = $this->call( 'app/confirm', array( 'code' => $this->app_code( $secret, 1 ) ) );
		$this->assertSame( 400, $again->get_status(), 'Confirm works once per begin.' );

		$off = $this->call( 'app/disable' );
		$this->assertSame( 200, $off->get_status() );
		$this->assertFalse( UserState::app_enabled( $user->ID ) );
	}

	public function test_a_second_method_keeps_the_saved_backup_codes() {
		$user = $this->editor();
		UserState::enable_email( $user->ID );
		$old = BackupCodes::generate( $user->ID );
		$this->recheck();

		$secret  = $this->call( 'app/begin' )->get_data()['secret'];
		$confirm = $this->call( 'app/confirm', array( 'code' => $this->app_code( $secret ) ) );
		$this->assertSame( array(), $confirm->get_data()['codes'] );
		$this->assertNotSame( '', BackupCodes::match_code( $user->ID, $old[0] ), 'The old codes still work.' );
	}

	/**
	 * Asks for an email code and returns it, read from the email.
	 *
	 * @return string
	 */
	private function emailed_code() {
		$mails = array();
		$catch = static function ( $null, $atts ) use ( &$mails ) {
			unset( $null );
			$mails[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $catch, 5, 2 );
		try {
			$this->assertSame( 200, $this->call( 'email/begin' )->get_status() );
			EmailMethod::flush_queue();
		} finally {
			remove_filter( 'pre_wp_mail', $catch, 5 );
		}
		$this->assertCount( 1, $mails );
		$this->assertSame( 1, preg_match( '/(\d{3}) (\d{3})/', $mails[0]['message'], $m ) );
		return $m[1] . $m[2];
	}

	public function test_email_codes_turn_on_only_after_one_emailed_code_comes_back() {
		$user = $this->editor();
		$this->recheck();

		$this->assertSame( 404, $this->call( 'email/enable' )->get_status(), 'No route turns email codes on without a code.' );

		$code = $this->emailed_code();
		$this->assertFalse( UserState::email_enabled( $user->ID ), 'Sending a code turns nothing on.' );

		$wrong = $this->call( 'email/confirm', array( 'code' => '000000' === $code ? '111111' : '000000' ) );
		$this->assertSame( 400, $wrong->get_status() );
		$this->assertFalse( UserState::email_enabled( $user->ID ) );

		$on = $this->call( 'email/confirm', array( 'code' => $code ) );
		$this->assertSame( 200, $on->get_status() );
		$this->assertTrue( UserState::email_enabled( $user->ID ) );
		$this->assertCount( 10, $on->get_data()['codes'] );
		$this->assertSame( array( 'email', 'backup' ), $on->get_data()['methods'] );

		$this->assertSame( 400, $this->call( 'email/confirm', array( 'code' => $code ) )->get_status(), 'A code works once.' );
	}

	public function test_email_begin_shares_the_send_limit_of_the_login_step() {
		$this->editor();
		$this->recheck();
		for ( $i = 0; $i < Challenge::SEND_LIMIT; $i++ ) {
			$this->assertSame( 200, $this->call( 'email/begin' )->get_status() );
		}
		$limited = $this->call( 'email/begin' );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 'happyaccess_locked', $this->code_of( $limited ) );
		EmailMethod::flush_queue();
	}

	public function test_email_enable_and_disable() {
		$user = $this->editor();
		$this->recheck();

		$on = $this->call( 'email/confirm', array( 'code' => $this->emailed_code() ) );
		$this->assertSame( 200, $on->get_status() );
		$this->assertTrue( UserState::email_enabled( $user->ID ) );
		$this->assertCount( 10, $on->get_data()['codes'] );

		$this->assertSame( 200, $this->call( 'email/disable' )->get_status() );
		$this->assertFalse( UserState::email_enabled( $user->ID ) );
		$this->assertSame( 0, BackupCodes::remaining( $user->ID ), 'With no method left the backup codes go too.' );
	}

	public function test_backup_regenerate_replaces_the_old_codes() {
		$user = $this->editor();
		UserState::enable_email( $user->ID );
		$old = BackupCodes::generate( $user->ID );
		$this->recheck();

		$response = $this->call( 'backup/regenerate' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
		$new = $response->get_data()['codes'];
		$this->assertCount( 10, $new );
		$this->assertSame( '', BackupCodes::match_code( $user->ID, $old[0] ), 'An old code stops working.' );
		$this->assertNotSame( '', BackupCodes::match_code( $user->ID, $new[0] ) );
		$this->assertCount( 2, AuditLog::query( array( 'event' => 'twostep_backup_regenerated' ) )['items'], 'The first set and the new one are each logged.' );
	}

	public function test_backup_codes_need_a_method_first() {
		$this->editor();
		$this->recheck();
		$this->assertSame( 409, $this->call( 'backup/regenerate' )->get_status() );
	}

	public function test_a_required_user_cannot_turn_off_the_last_method() {
		$this->set_policy( array( 'editor' => 'required' ) );
		$user = $this->editor();
		UserState::enable_app( $user->ID, Totp::new_secret() );
		$this->recheck();

		$refused = $this->call( 'app/disable' );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'Your role needs two-step login.', $refused->get_data()['message'] );
		$this->assertTrue( UserState::app_enabled( $user->ID ) );

		UserState::enable_email( $user->ID );
		$this->assertSame( 200, $this->call( 'app/disable' )->get_status(), 'Another method is on, so this one can go.' );
		$this->assertSame( 403, $this->call( 'email/disable' )->get_status(), 'Now email is the last one.' );
		$this->assertTrue( UserState::email_enabled( $user->ID ) );
	}

	public function test_a_role_with_two_step_off_cannot_turn_a_method_on() {
		$this->set_policy( array( 'editor' => 'off' ) );
		$user = $this->editor();
		$this->recheck();

		$this->assertSame( 403, $this->call( 'app/begin' )->get_status() );
		$this->assertSame( 403, $this->call( 'email/begin' )->get_status() );
		$this->assertSame( 403, $this->call( 'email/confirm', array( 'code' => '123456' ) )->get_status() );
		$this->assertFalse( UserState::is_enabled( $user->ID ) );

		UserState::enable_email( $user->ID );
		$this->assertSame( 200, $this->call( 'email/disable' )->get_status(), 'A method that is on can still be turned off.' );
	}

	public function test_the_recheck_only_counts_in_the_session_that_passed_it() {
		$user  = $this->editor();
		$first = $_COOKIE[ LOGGED_IN_COOKIE ];
		UserState::enable_email( $user->ID );
		$this->recheck();
		$this->assertSame( 200, $this->call( 'backup/regenerate' )->get_status() );

		$this->start_session( $user->ID );
		$other = $this->call( 'backup/regenerate' );
		$this->assertSame( 403, $other->get_status(), 'Another browser has not passed it.' );
		$this->assertSame( 'happyaccess_recheck_required', $this->code_of( $other ) );

		$_COOKIE[ LOGGED_IN_COOKIE ] = $first;
		$this->assertSame( 200, $this->call( 'backup/regenerate' )->get_status(), 'The first browser still has it.' );
	}

	public function test_the_recheck_map_drops_entries_older_than_15_minutes() {
		$user = $this->editor();
		update_user_meta(
			$user->ID,
			UserState::META_RECHECK,
			array(
				'old'   => Clock::now() - 15 * MINUTE_IN_SECONDS,
				'fresh' => Clock::now() - 60,
			)
		);
		$this->recheck();

		$map = get_user_meta( $user->ID, UserState::META_RECHECK, true );
		$this->assertSame(
			array(
				'fresh'                                  => Clock::now() - 60,
				hash( 'sha256', wp_get_session_token() ) => Clock::now(),
			),
			$map
		);
		$this->assertStringNotContainsString( wp_get_session_token(), maybe_serialize( $map ), 'The token itself is never stored.' );
	}

	public function test_without_a_session_the_recheck_is_refused() {
		$user = $this->editor();
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

		$response = $this->call( 'recheck', array( 'password' => self::PASSWORD ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', get_user_meta( $user->ID, UserState::META_RECHECK, true ) );
	}

	public function test_an_application_password_request_is_refused() {
		$user = $this->editor();
		UserState::enable_email( $user->ID );
		$this->recheck();
		$GLOBALS['wp_rest_application_password_uuid'] = wp_generate_uuid4();

		foreach ( array_merge( array( 'recheck' ), $this->change_routes() ) as $route ) {
			$response = $this->call( $route, array( 'password' => self::PASSWORD ) );
			$this->assertSame( 403, $response->get_status(), $route );
			$this->assertSame( 'happyaccess_app_password', $this->code_of( $response ), $route );
		}
		$this->assertTrue( UserState::email_enabled( $user->ID ), 'Nothing changed.' );
	}

	public function test_a_wrong_recheck_fires_wp_login_failed_and_a_wrong_code_counts_on_the_account() {
		$user = $this->editor();
		UserState::enable_email( $user->ID );
		BackupCodes::generate( $user->ID );
		$failed = array();
		$record = static function ( $login, $error ) use ( &$failed ) {
			$failed[] = array( $login, $error instanceof WP_Error ? $error->get_error_code() : '' );
		};
		add_action( 'wp_login_failed', $record, 10, 2 );
		$count = static function ( $scope, $subject ) {
			return RateLimiter::count( Challenge::CODE_ACTION, $scope, $subject, HOUR_IN_SECONDS );
		};

		try {
			$this->assertSame( 403, $this->call( 'recheck', array( 'password' => 'wrong' ) )->get_status() );
			$this->assertSame( array( array( $user->user_login, 'happyaccess_recheck_failed' ) ), $failed );
			$this->assertSame( 0, $count( 'account', Challenge::account_subject( $user->ID ) ), 'A wrong password is not a code.' );

			$this->assertSame( 403, $this->call( 'recheck', array( 'code' => 'ABCDE-FGHIJ' ) )->get_status() );
			$this->assertCount( 2, $failed );
			$this->assertSame( 1, $count( 'account', Challenge::account_subject( $user->ID ) ), 'A wrong code counts on the account.' );
			$this->assertSame( 0, $count( 'site', 'site' ), 'The re-check leaves the site count alone.' );

			$this->recheck();
			$this->assertCount( 2, $failed, 'A pass fires nothing.' );
			$this->assertSame( 1, $count( 'account', Challenge::account_subject( $user->ID ) ), 'A right password keeps the code tries.' );
		} finally {
			remove_action( 'wp_login_failed', $record, 10 );
		}
	}

	public function test_a_code_recheck_without_two_step_is_refused_before_anything_counts() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user->ID );
		$this->start_session( $user->ID );

		$response = $this->call( 'recheck', array( 'code' => '000000' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'happyaccess_no_method', $this->code_of( $response ) );
		$this->assertSame( 0, RateLimiter::count( Challenge::CODE_ACTION, 'account', Challenge::account_subject( $user->ID ), HOUR_IN_SECONDS ) );
		$this->assertSame( 0, RateLimiter::count( Challenge::CODE_ACTION, 'site', 'site', HOUR_IN_SECONDS ) );
		$this->assertSame( 0, RateLimiter::count( RestController::RECHECK_ACTION, 'ip', RateLimiter::ip_subject(), HOUR_IN_SECONDS ) );
	}

	public function test_recheck_codes_lock_the_account_even_when_a_right_password_comes_between() {
		$user   = $this->editor();
		$secret = Totp::new_secret();
		UserState::enable_app( $user->ID, $secret );

		$wrong = 0;
		for ( $round = 0; $round < 3 && $wrong < 10; $round++ ) {
			for ( $try = 0; $try < 4 && $wrong < 10; $try++ ) {
				$this->assertSame( 403, $this->call( 'recheck', array( 'code' => '000000' ) )->get_status() );
				++$wrong;
			}
			$this->recheck();
		}
		$this->assertSame( 10, $wrong );

		$response = $this->call( 'recheck', array( 'code' => $this->app_code( $secret ) ) );
		$this->assertSame( 429, $response->get_status(), 'The account is paused, so a right code waits.' );
		$this->assertSame( 'happyaccess_locked', $this->code_of( $response ) );
	}

	public function test_a_logged_in_user_cannot_lock_other_accounts_through_the_recheck() {
		$sub = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub->ID );
		$this->start_session( $sub->ID );
		for ( $i = 0; $i < 120; $i++ ) {
			$this->assertSame( 400, $this->call( 'recheck', array( 'code' => '000000' ) )->get_status() );
		}

		$admin  = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$secret = Totp::new_secret();
		UserState::enable_app( $admin->ID, $secret );
		wp_set_current_user( $admin->ID );
		$this->start_session( $admin->ID );
		$response = $this->call( 'recheck', array( 'code' => $this->app_code( $secret ) ) );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_two_disables_at_once_cannot_both_pass_the_last_method_check() {
		$this->set_policy( array( 'editor' => 'required' ) );
		$user = $this->editor();
		UserState::enable_app( $user->ID, Totp::new_secret() );
		UserState::enable_email( $user->ID );
		$this->recheck();

		// The email request lands while the app request is in the middle of turning the app off.
		$inner = null;
		$race  = function ( $meta_ids, $object_id, $meta_key ) use ( &$inner, $user ) {
			unset( $meta_ids );
			if ( null === $inner && UserState::META_TOTP === $meta_key && $user->ID === (int) $object_id ) {
				$inner = $this->call( 'email/disable' );
			}
		};
		add_action( 'delete_user_meta', $race, 10, 3 );
		try {
			$outer = $this->call( 'app/disable' );
		} finally {
			remove_action( 'delete_user_meta', $race, 10 );
		}

		$this->assertSame( 200, $outer->get_status() );
		$this->assertInstanceOf( WP_REST_Response::class, $inner );
		$this->assertSame( 409, $inner->get_status(), 'The second request is refused.' );
		$this->assertTrue( UserState::is_enabled( $user->ID ), 'One method stays on.' );
		$this->assertTrue( UserState::email_enabled( $user->ID ) );

		$this->assertSame( 403, $this->call( 'email/disable' )->get_status(), 'Afterwards email is the last method.' );
	}
}
