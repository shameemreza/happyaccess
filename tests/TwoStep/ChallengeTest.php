<?php
/**
 * Second step at login tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\RateLimiter;
use HappyAccess\Core\Settings;
use HappyAccess\Features\Passwordless\LoginSteps;
use HappyAccess\Features\TwoStep\BackupCodes;
use HappyAccess\Features\TwoStep\Challenge;
use HappyAccess\Features\TwoStep\EmailMethod;
use HappyAccess\Features\TwoStep\Feature;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;
use HappyAccess\Login\Router;

/**
 * Thrown by the test redirector so a redirect from inside wp_signon() stops
 * the login the way exit does. An Error, so WooCommerce's catch of Exception
 * doesn't swallow it.
 */
class HappyAccess_Test_TwoStep_Redirect extends Error {

	/**
	 * Where the browser was sent.
	 *
	 * @var string
	 */
	public $url = '';
}

class ChallengeTest extends WP_UnitTestCase {

	const PASSWORD = 'correct horse battery';

	/**
	 * Cookies the step tried to send: name, value and options.
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

	/**
	 * How many times wp_login_failed ran.
	 *
	 * @var int
	 */
	private $failed_count = 0;

	/**
	 * Mails caught before they are sent.
	 *
	 * @var array
	 */
	private $mails = array();

	/**
	 * The user a late authenticate filter returns, or whose backup code a parallel request takes.
	 *
	 * @var WP_User|null
	 */
	private $late_user = null;

	/**
	 * Pending login that parallel checks fill up, once.
	 *
	 * @var int
	 */
	private $race_row = 0;

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
		Challenge::reset();
		Features::set( 'two_step', true );
		Clock::freeze( 1790000000 );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_COOKIE                = array();
		$_REQUEST               = array();
		$_POST                  = array();
		$_GET                   = array();

		$this->cookies        = array();
		$this->auth           = array();
		$this->mails          = array();
		$this->wp_login_count = 0;
		$this->failed_count   = 0;

		add_filter( 'send_auth_cookies', '__return_false' );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		add_filter( 'happyaccess_twostep_send_cookie', array( $this, 'catch_cookie' ), 10, 4 );
		add_filter( 'happyaccess_passwordless_send_cookie', array( $this, 'catch_cookie' ), 10, 4 );
		add_action( 'set_auth_cookie', array( $this, 'catch_auth' ), 10, 6 );
		add_action( 'wp_login', array( $this, 'count_login' ) );
		add_action( 'wp_login_failed', array( $this, 'count_failed' ) );

		Feature::register();
		Challenge::set_redirector( array( $this, 'stop_redirect' ) );
		Challenge::set_context( 'browser' );

		EmailMethod::flush_queue();
		$this->mails = array();
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			$_COOKIE  = array();
			$_REQUEST = array();
			$_POST    = array();
			$_GET     = array();
			Clock::freeze( null );
			Router::reset();
			EmailMethod::flush_queue();
			Challenge::reset();
		} finally {
			parent::tear_down();
		}
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
		unset( $cookie, $expire, $expiration, $scheme, $token );
		$this->auth[] = $user_id;
	}

	public function count_login() {
		++$this->wp_login_count;
	}

	public function count_failed() {
		++$this->failed_count;
	}

	public function stop_redirect( $url ) {
		$stop      = new HappyAccess_Test_TwoStep_Redirect( 'redirect' );
		$stop->url = $url;
		throw $stop;
	}

	/**
	 * A user with a known password and the authenticator app on.
	 *
	 * @param string $role Role.
	 * @return array user and secret.
	 */
	private function app_user( $role = 'editor' ) {
		$user   = self::factory()->user->create_and_get(
			array(
				'role'      => $role,
				'user_pass' => self::PASSWORD,
			)
		);
		$secret = Totp::new_secret();
		$this->assertTrue( UserState::enable_app( $user->ID, $secret ) );
		return array(
			'user'   => $user,
			'secret' => $secret,
		);
	}

	/**
	 * A user with a known password and only email codes on.
	 *
	 * @return WP_User
	 */
	private function email_user() {
		$user = self::factory()->user->create_and_get(
			array(
				'role'       => 'editor',
				'user_pass'  => self::PASSWORD,
				'user_email' => 'sam@example.org',
			)
		);
		UserState::enable_email( $user->ID );
		return $user;
	}

	/**
	 * The app code for the current time step, plus an offset in steps.
	 *
	 * @param string $secret Secret.
	 * @param int    $offset Steps from now.
	 * @return string
	 */
	private function app_code( $secret, $offset = 0 ) {
		return Totp::code( $secret, Totp::step_for( Clock::now() ) + $offset );
	}

	/**
	 * A password login through wp_signon(), as wp-login.php makes it.
	 *
	 * @param WP_User $user  User.
	 * @param array   $extra Extra form fields.
	 * @param string  $pass  Password.
	 * @return array url when the browser was sent somewhere, result otherwise.
	 */
	private function password_login( WP_User $user, array $extra = array(), $pass = self::PASSWORD ) {
		$this->cookies = array();
		$_POST         = array_merge(
			array(
				'log' => $user->user_login,
				'pwd' => $pass,
			),
			$extra
		);
		$_REQUEST      = $_POST;
		try {
			$result = wp_signon(
				array(
					'user_login'    => $user->user_login,
					'user_password' => $pass,
					'remember'      => ! empty( $extra['rememberme'] ),
				)
			);
		} catch ( HappyAccess_Test_TwoStep_Redirect $stop ) {
			return array(
				'url'    => $stop->url,
				'result' => null,
			);
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		return array(
			'url'    => null,
			'result' => $result,
		);
	}

	/**
	 * The value of the last pending-login cookie sent.
	 *
	 * @return string
	 */
	private function pending_cookie() {
		foreach ( array_reverse( $this->cookies ) as $cookie ) {
			if ( Challenge::COOKIE === $cookie['name'] ) {
				return $cookie['value'];
			}
		}
		return '';
	}

	/**
	 * Query args of a URL.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	private function query( $url ) {
		$args = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return $args;
	}

	/**
	 * Posts a code on the two-step screen.
	 *
	 * @param string $cookie Pending-login cookie.
	 * @param string $code   Code.
	 * @param string $method app, email or backup.
	 * @param array  $extra  Extra fields.
	 * @return array Response.
	 */
	private function post_code( $cookie, $code, $method = 'app', array $extra = array() ) {
		return Challenge::handle(
			'POST',
			array(),
			array_merge(
				array(
					'pwd'      => $code,
					'method'   => $method,
					'_wpnonce' => wp_create_nonce( 'happyaccess_twostep' ),
				),
				$extra
			),
			'' === $cookie ? array() : array( Challenge::COOKIE => $cookie )
		);
	}

	/**
	 * Challenge rows of the pending logins.
	 *
	 * @return array
	 */
	private function pending_rows() {
		global $wpdb;
		$table = Installer::table( 'challenges' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE purpose LIKE 'twostep_login%' ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function log_rows( $event ) {
		return AuditLog::query( array( 'event' => $event ) )['items'];
	}

	public function test_with_the_feature_off_the_filter_is_not_added() {
		$this->assertSame( 50, has_filter( 'authenticate', array( Challenge::class, 'filter_authenticate' ) ) );
		$this->assertNotNull( Router::resolve( 'twostep' ) );

		remove_filter( 'authenticate', array( Challenge::class, 'filter_authenticate' ), 50 );
		remove_filter( 'authenticate', array( Challenge::class, 'finish_authenticate' ), PHP_INT_MAX );
		Router::reset();
		Features::set( 'two_step', false );
		Feature::register();

		$this->assertFalse( has_filter( 'authenticate', array( Challenge::class, 'filter_authenticate' ) ) );
		$this->assertFalse( has_filter( 'authenticate', array( Challenge::class, 'finish_authenticate' ) ) );
		$this->assertNull( Router::resolve( 'twostep' ) );
	}

	public function test_a_correct_password_waits_for_the_second_step() {
		$made = $this->app_user();
		$res  = $this->password_login( $made['user'] );

		$this->assertNotNull( $res['url'], 'The browser goes to the two-step screen.' );
		$args = $this->query( $res['url'] );
		$this->assertSame( 'happyaccess', $args['action'] );
		$this->assertSame( 'twostep', $args['step'] );
		$this->assertSame( array(), $this->auth, 'No auth cookie before the second step.' );
		$this->assertSame( 0, $this->wp_login_count );
		$this->assertSame( 0, $this->failed_count, 'Lockout plugins see no failed login.' );
		$this->assertSame( 0, get_current_user_id() );

		$cookie = $this->pending_cookie();
		$this->assertSame( 43, strlen( $cookie ) );
		$options = $this->cookies[0]['options'];
		$this->assertTrue( $options['httponly'] );
		$this->assertSame( 'Lax', $options['samesite'] );
		$this->assertSame( COOKIEPATH, $options['path'] );
		$this->assertFalse( $options['secure'] );
		$this->assertSame( Clock::now() + 600, $options['expires'] );

		$rows = $this->pending_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'twostep_login', $rows[0]['purpose'] );
		$this->assertSame( (int) $made['user']->ID, (int) $rows[0]['user_id'] );
		$this->assertSame( Codes::hash_key( $cookie ), $rows[0]['link_hash'] );
		$this->assertSame( Clock::mysql( Clock::now() + 600 ), $rows[0]['expires_at'] );
		$this->assertNull( $rows[0]['used_at'] );
	}

	public function test_the_cookie_is_secure_on_https() {
		$_SERVER['HTTPS'] = 'on';
		$made             = $this->app_user();
		$this->password_login( $made['user'] );
		unset( $_SERVER['HTTPS'] );
		$this->assertTrue( $this->cookies[0]['options']['secure'] );
	}

	public function test_a_wrong_password_is_left_to_core() {
		$made = $this->app_user();
		$res  = $this->password_login( $made['user'], array(), 'wrong' );
		$this->assertNull( $res['url'] );
		$this->assertWPError( $res['result'] );
		$this->assertSame( 'incorrect_password', $res['result']->get_error_code() );
		$this->assertSame( array(), $this->pending_rows() );
	}

	public function test_the_app_code_logs_in_once() {
		$made   = $this->app_user();
		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();

		$res = $this->post_code( $cookie, $this->app_code( $made['secret'] ) );

		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( admin_url(), $res['url'] );
		$this->assertSame( array( $made['user']->ID ), $this->auth );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertSame( $made['user']->ID, get_current_user_id() );

		$last = end( $this->cookies );
		$this->assertSame( Challenge::COOKIE, $last['name'] );
		$this->assertSame( '', $last['value'] );
		$this->assertLessThan( Clock::now(), $last['options']['expires'] );

		$passed = $this->log_rows( 'twostep_passed' );
		$this->assertCount( 1, $passed );
		$this->assertSame( 'app', $passed[0]['meta']['method'] );

		$rows = $this->pending_rows();
		$this->assertNotNull( $rows[0]['used_at'] );

		// The pending login is used up, so the same cookie can't log in again.
		$again = $this->post_code( $cookie, $this->app_code( $made['secret'], 1 ) );
		$this->assertSame( 'redirect', $again['type'] );
		$this->assertSame( 'expired', $this->query( $again['url'] )['happyaccess_ts'] );
		$this->assertSame( 1, $this->wp_login_count );
	}

	public function test_a_replayed_app_code_is_refused() {
		$made = $this->app_user();
		$code = $this->app_code( $made['secret'] );

		$this->password_login( $made['user'] );
		$this->assertSame( 'redirect', $this->post_code( $this->pending_cookie(), $code )['type'] );
		wp_set_current_user( 0 );

		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $code );
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( esc_html( "That code didn't work. Try again." ), $res['errors']->get_error_message() );
		$this->assertSame( 1, $this->wp_login_count );
	}

	public function test_the_email_code_works_and_the_address_is_never_shown() {
		$user = $this->email_user();
		$res  = $this->password_login( $user );

		$args = $this->query( $res['url'] );
		$this->assertSame( 'email', $args['method'] );
		EmailMethod::flush_queue();
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'sam@example.org', $this->mails[0]['to'] );
		$this->assertSame( 1, preg_match( '/(\d{3}) (\d{3})/', $this->mails[0]['message'], $m ) );

		$cookie = $this->pending_cookie();
		$screen = Challenge::handle( 'GET', $args, array(), array( Challenge::COOKIE => $cookie ) );
		$this->assertSame( 'render', $screen['type'] );
		$this->assertStringContainsString( 'We sent a code to your email address.', $screen['message'] );
		$this->assertStringNotContainsString( 'sam@example.org', $screen['body'] . $screen['message'] );
		$this->assertStringNotContainsString( 'Use a backup code', $screen['body'] );

		$res = $this->post_code( $cookie, $m[1] . $m[2], 'email' );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertSame( 'email', $this->log_rows( 'twostep_passed' )[0]['meta']['method'] );
	}

	public function test_choosing_email_sends_a_code() {
		$made = $this->app_user();
		UserState::enable_email( $made['user']->ID );
		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();
		EmailMethod::flush_queue();
		$this->assertSame( array(), $this->mails, 'An app user gets no email unless asking for one.' );

		$screen = Challenge::handle( 'GET', array(), array(), array( Challenge::COOKIE => $cookie ) );
		$this->assertStringContainsString( 'Email me a code instead', $screen['body'] );

		$res = Challenge::handle(
			'POST',
			array(),
			array(
				'method'   => 'email',
				'send'     => '1',
				'_wpnonce' => wp_create_nonce( 'happyaccess_twostep_send' ),
			),
			array( Challenge::COOKIE => $cookie )
		);
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 'email', $this->query( $res['url'] )['method'] );
		EmailMethod::flush_queue();
		$this->assertCount( 1, $this->mails );
	}

	public function test_the_email_link_is_a_post_form_and_a_get_sends_nothing() {
		$made = $this->app_user();
		UserState::enable_email( $made['user']->ID );
		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();
		EmailMethod::flush_queue();
		$this->mails = array();

		$screen = Challenge::handle( 'GET', array(), array(), array( Challenge::COOKIE => $cookie ) );
		$this->assertStringContainsString( '<button type="submit" form="happyaccess-ts-send" class="button-link">Email me a code instead</button>', $screen['body'] );
		$this->assertSame( 1, preg_match( '#<form id="happyaccess-ts-send" method="post"[^>]*>(?:(?!</form>).)*</form>#s', $screen['body'], $form ) );
		$this->assertStringContainsString( 'name="send" value="1"', $form[0] );
		$this->assertStringNotContainsString( 'send=1', $screen['body'], 'No link sends a code.' );

		$res = Challenge::handle(
			'GET',
			array(
				'method'   => 'email',
				'send'     => '1',
				'_wpnonce' => wp_create_nonce( 'happyaccess_twostep_send' ),
			),
			array(),
			array( Challenge::COOKIE => $cookie )
		);
		$this->assertSame( 'render', $res['type'] );
		EmailMethod::flush_queue();
		$this->assertSame( array(), $this->mails, 'A GET never sends a code.' );

		$res = Challenge::handle(
			'POST',
			array(),
			array(
				'method'   => 'email',
				'send'     => '1',
				'_wpnonce' => wp_create_nonce( 'happyaccess_twostep_send' ),
			),
			array()
		);
		$this->assertSame( 'expired', $this->query( $res['url'] )['happyaccess_ts'] );
		EmailMethod::flush_queue();
		$this->assertSame( array(), $this->mails, 'The send needs the pending-login cookie.' );
	}

	public function test_an_email_first_login_past_the_send_limit_says_codes_were_sent() {
		$user = $this->email_user();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->password_login( $user );
		}
		$res    = $this->password_login( $user );
		$args   = $this->query( $res['url'] );
		$screen = Challenge::handle( 'GET', $args, array(), array( Challenge::COOKIE => $this->pending_cookie() ) );

		$this->assertStringContainsString( esc_html( 'We already sent several codes. Check your email, or wait a few minutes and try again.' ), $screen['message'] );
		$this->assertStringNotContainsString( 'We sent a code to your email address.', $screen['message'] );
	}

	public function test_a_later_authenticator_cannot_skip_the_step_outside_the_browser() {
		$made            = $this->app_user();
		$this->late_user = $made['user'];
		add_filter( 'authenticate', array( $this, 'return_late_user' ), 60, 1 );

		Challenge::set_context( 'xmlrpc' );
		$result = wp_authenticate( $made['user']->user_login, 'not the password' );
		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_twostep_xmlrpc', $result->get_error_code() );

		Challenge::set_context( 'api' );
		$result = wp_authenticate( $made['user']->user_login, 'not the password' );
		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_twostep_required', $result->get_error_code() );

		remove_filter( 'authenticate', array( $this, 'return_late_user' ), 60 );
		$this->assertSame( 0, $this->wp_login_count );
		$this->assertSame( array(), $this->pending_rows() );
	}

	public function test_a_login_through_the_step_clears_a_pending_password_reset() {
		$made = $this->app_user();
		$this->assertIsString( get_password_reset_key( $made['user'] ) );
		$this->assertNotSame( '', $this->activation_key( $made['user']->ID ) );

		$this->password_login( $made['user'] );
		$this->assertNotSame( '', $this->activation_key( $made['user']->ID ), 'The password alone is not a login.' );

		$this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertSame( '', $this->activation_key( $made['user']->ID ) );
	}

	public function test_a_check_past_the_limit_is_refused_without_checking_the_code() {
		$made = $this->app_user();
		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();
		$before = UserState::last_step( $made['user']->ID );

		// Five checks from parallel requests are counted after this request found the pending login.
		$this->race_row = (int) $this->pending_rows()[0]['id'];
		add_filter( 'query', array( $this, 'count_five_parallel_checks' ) );
		$res = $this->post_code( $cookie, $this->app_code( $made['secret'] ) );
		remove_filter( 'query', array( $this, 'count_five_parallel_checks' ) );

		$this->assertSame( 0, $this->race_row, 'The parallel checks ran.' );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 0, $this->wp_login_count );
		$this->assertSame( $before, UserState::last_step( $made['user']->ID ), 'The app code was never checked, so its time step is still unused.' );
	}

	public function test_every_check_counts_on_the_pending_login() {
		$made = $this->app_user();
		$this->password_login( $made['user'] );
		$this->post_code( $this->pending_cookie(), '000000' );
		$this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( 2, (int) $this->pending_rows()[0]['attempts'] );
		$this->assertNotNull( $this->pending_rows()[0]['used_at'] );
		$this->assertSame( 1, $this->wp_login_count );
	}

	public function test_a_right_code_on_the_fifth_check_still_logs_in() {
		$made = $this->app_user();
		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();
		for ( $i = 0; $i < 4; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( 30 + $i );
			$this->post_code( $cookie, '000000' );
		}
		$_SERVER['REMOTE_ADDR'] = '198.51.100.40';
		$res                    = $this->post_code( $cookie, $this->app_code( $made['secret'] ) );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( admin_url(), $res['url'] );
		$this->assertSame( 1, $this->wp_login_count );
	}

	public function test_a_backup_code_is_kept_when_the_pending_login_runs_out_during_the_check() {
		$made  = $this->app_user();
		$codes = BackupCodes::generate( $made['user']->ID );
		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();

		// The pending login expires while the backup code hashes are checked.
		add_filter( 'check_password', array( $this, 'expire_during_check' ), 10, 1 );
		$res = $this->post_code( $cookie, $codes[0], 'backup' );
		remove_filter( 'check_password', array( $this, 'expire_during_check' ), 10 );
		Clock::freeze( 1790000000 );

		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 'expired', $this->query( $res['url'] )['happyaccess_ts'] );
		$this->assertSame( 0, $this->wp_login_count );
		$this->assertSame( 10, BackupCodes::remaining( $made['user']->ID ), 'The backup code was not used up.' );

		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $codes[0], 'backup' );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertSame( 9, BackupCodes::remaining( $made['user']->ID ) );
	}

	public function test_a_backup_code_taken_by_a_parallel_request_does_not_log_in() {
		$made            = $this->app_user();
		$codes           = BackupCodes::generate( $made['user']->ID );
		$this->late_user = $made['user'];
		$this->password_login( $made['user'] );

		add_filter( 'check_password', array( $this, 'take_code_during_check' ), 10, 3 );
		$res = $this->post_code( $this->pending_cookie(), $codes[0], 'backup' );
		remove_filter( 'check_password', array( $this, 'take_code_during_check' ), 10 );

		$this->assertSame( 0, $this->wp_login_count );
		$this->assertSame( array(), $this->auth );
		$this->assertSame( 9, BackupCodes::remaining( $made['user']->ID ) );
		$this->assertSame( 'redirect', $res['type'] );
	}

	public function test_a_cancelled_login_does_not_lock_the_ip_out_of_the_next_one() {
		$made = $this->app_user();
		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();
		for ( $i = 0; $i < 5; $i++ ) {
			$res = $this->post_code( $cookie, '000000' );
		}
		$this->assertSame( 'locked', $this->query( $res['url'] )['happyaccess_ts'] );

		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( admin_url(), $res['url'] );
		$this->assertSame( 1, $this->wp_login_count );
	}

	public function test_a_cancel_clears_only_its_own_tries_from_the_ip_limit() {
		Settings::update( array( 'security' => array( 'max_attempts' => 10 ) ) );
		$other = $this->app_user();
		$this->password_login( $other['user'] );
		$cookie = $this->pending_cookie();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->post_code( $cookie, '000000' );
		}

		$made = $this->app_user();
		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();
		for ( $i = 0; $i < 5; $i++ ) {
			$res = $this->post_code( $cookie, '000000' );
		}
		$this->assertSame( 'locked', $this->query( $res['url'] )['happyaccess_ts'] );
		$this->assertSame( 3, RateLimiter::count( Challenge::CODE_ACTION, 'ip', RateLimiter::ip_subject(), 900 ), 'The other login keeps its tries.' );
	}

	public function test_the_ip_limit_still_holds_across_logins_that_are_not_cancelled() {
		$made = $this->app_user();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->password_login( $made['user'] );
			$this->post_code( $this->pending_cookie(), '000000' );
		}
		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( 'locked', $res['errors']->get_error_code() );
		$this->assertSame( 0, $this->wp_login_count );
	}

	public function return_late_user( $result ) {
		unset( $result );
		return $this->late_user;
	}

	public function count_five_parallel_checks( $sql ) {
		global $wpdb;
		if ( $this->race_row > 0 && false !== strpos( $sql, Installer::table( 'attempts' ) ) ) {
			$id             = $this->race_row;
			$this->race_row = 0;
			$table          = Installer::table( 'challenges' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = %d WHERE id = %d", Challenge::MAX_ATTEMPTS, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $sql;
	}

	public function expire_during_check( $check ) {
		Clock::freeze( 1790000000 + Challenge::LIFETIME + 1 );
		return $check;
	}

	public function take_code_during_check( $check, $password, $hash ) {
		unset( $password );
		if ( $check ) {
			remove_filter( 'check_password', array( $this, 'take_code_during_check' ), 10 );
			$stored = get_user_meta( $this->late_user->ID, UserState::META_BACKUP, true );
			update_user_meta( $this->late_user->ID, UserState::META_BACKUP, array_values( array_diff( $stored, array( $hash ) ) ) );
		}
		return $check;
	}

	private function activation_key( $user_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d", $user_id ) );
	}

	public function test_the_screen_offers_the_methods_the_user_has() {
		$made   = $this->app_user();
		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();

		$screen = Challenge::handle( 'GET', array(), array(), array( Challenge::COOKIE => $cookie ) );
		$body   = $screen['body'];
		$this->assertStringContainsString( 'Authenticator app code', $body );
		$this->assertStringContainsString( 'name="pwd"', $body );
		$this->assertStringContainsString( 'inputmode="numeric"', $body );
		$this->assertStringContainsString( 'autocomplete="one-time-code"', $body );
		$this->assertStringContainsString( 'name="_wpnonce"', $body );
		$this->assertStringNotContainsString( 'Email me a code instead', $body, 'Email is off and the user has the app.' );
		$this->assertStringNotContainsString( 'Use a backup code', $body, 'No backup codes yet.' );

		BackupCodes::generate( $made['user']->ID );
		$screen = Challenge::handle( 'GET', array(), array(), array( Challenge::COOKIE => $cookie ) );
		$this->assertStringContainsString( 'Use a backup code', $screen['body'] );
	}

	public function test_a_backup_code_works_once_and_warns_when_few_are_left() {
		$made  = $this->app_user();
		$codes = BackupCodes::generate( $made['user']->ID );
		// Keep three codes, so one use leaves two.
		update_user_meta( $made['user']->ID, UserState::META_BACKUP, array_slice( get_user_meta( $made['user']->ID, UserState::META_BACKUP, true ), 0, 3 ) );

		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $codes[0], 'backup' );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertCount( 1, $this->log_rows( 'twostep_backup_used' ), 'Logged once, by BackupCodes.' );
		$this->assertSame( 'backup', $this->log_rows( 'twostep_passed' )[0]['meta']['method'] );

		ob_start();
		Challenge::print_backup_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'You have 2 backup codes left. Make new ones on your profile.', $notice );
		ob_start();
		Challenge::print_backup_notice();
		$this->assertSame( '', ob_get_clean(), 'The notice shows once.' );

		wp_set_current_user( 0 );
		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $codes[0], 'backup' );
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( esc_html( "That code didn't work. Try again." ), $res['errors']->get_error_message() );
		$this->assertSame( 1, $this->wp_login_count );
	}

	public function test_five_wrong_codes_cancel_the_pending_login() {
		$made   = $this->app_user();
		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();

		for ( $i = 0; $i < 4; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( 10 + $i );
			$res                    = $this->post_code( $cookie, '000000' );
			$this->assertSame( 'render', $res['type'] );
			$this->assertSame( esc_html( "That code didn't work. Try again." ), $res['errors']->get_error_message() );
		}
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$res                    = $this->post_code( $cookie, '000000' );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 'locked', $this->query( $res['url'] )['happyaccess_ts'] );
		$this->assertNotNull( $this->pending_rows()[0]['used_at'] );
		$this->assertCount( 1, $this->log_rows( 'twostep_locked' ) );
		$this->assertCount( 4, $this->log_rows( 'twostep_failed' ) );

		$res = $this->post_code( $cookie, $this->app_code( $made['secret'] ) );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 'expired', $this->query( $res['url'] )['happyaccess_ts'] );
		$this->assertSame( 0, $this->wp_login_count );

		$errors = Challenge::login_errors( new WP_Error(), array( 'happyaccess_ts' => 'locked' ) );
		$this->assertSame( 'Too many wrong codes. Log in again.', $errors->get_error_message() );
	}

	/**
	 * Logs in with the password and types five wrong codes, which cancels the pending login.
	 *
	 * @param WP_User $user User.
	 * @return array The last response.
	 */
	private function five_wrong_codes( WP_User $user ) {
		$this->password_login( $user );
		$cookie = $this->pending_cookie();
		$res    = array();
		for ( $try = 0; $try < 5; $try++ ) {
			$res = $this->post_code( $cookie, '000000' );
		}
		return $res;
	}

	/**
	 * Mails sent to the site owner.
	 *
	 * @return array
	 */
	private function owner_mails() {
		return array_values(
			array_filter(
				$this->mails,
				static function ( $mail ) {
					return get_option( 'admin_email' ) === $mail['to'];
				}
			)
		);
	}

	public function test_ten_wrong_codes_lock_the_account_across_ips_and_logins() {
		$made                   = $this->app_user();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$this->five_wrong_codes( $made['user'] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		$this->five_wrong_codes( $made['user'] );
		$this->mails = array();

		$_SERVER['REMOTE_ADDR'] = '192.0.2.200';
		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( 'locked', $res['errors']->get_error_code() );
		$this->assertStringContainsString( '60 minutes', $res['errors']->get_error_message() );
		$this->assertSame( 0, $this->wp_login_count, 'A right code waits until the pause ends.' );

		$locks = array_values(
			array_filter(
				$this->log_rows( 'twostep_locked' ),
				static function ( $row ) {
					return isset( $row['meta']['reason'] ) && 'account_cap' === $row['meta']['reason'];
				}
			)
		);
		$this->assertCount( 1, $locks );
		$this->assertSame( $made['user']->ID, (int) $locks[0]['user_id'] );

		Clock::freeze( Clock::now() + HOUR_IN_SECONDS + 1 );
		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 1, $this->wp_login_count, 'The pause ends after an hour.' );
	}

	public function test_the_user_gets_one_email_when_their_account_locks() {
		$made        = $this->app_user();
		$this->mails = array();
		$this->five_wrong_codes( $made['user'] );
		$this->assertCount( 0, $this->mails );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		$this->five_wrong_codes( $made['user'] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.3';
		$this->password_login( $made['user'] );
		$this->post_code( $this->pending_cookie(), '000000' );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( $made['user']->user_email, $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Two-step login is paused for your account', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( '000000', $this->mails[0]['message'] );
	}

	public function test_the_account_lock_doubles_when_it_trips_again() {
		$made = $this->app_user();
		$this->five_wrong_codes( $made['user'] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		$this->five_wrong_codes( $made['user'] );

		Clock::freeze( Clock::now() + HOUR_IN_SECONDS + 1 );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.3';
		$this->five_wrong_codes( $made['user'] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.4';
		$this->five_wrong_codes( $made['user'] );

		$_SERVER['REMOTE_ADDR'] = '192.0.2.200';
		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( 'locked', $res['errors']->get_error_code() );
		$this->assertStringContainsString( '120 minutes', $res['errors']->get_error_message() );

		Clock::freeze( Clock::now() + HOUR_IN_SECONDS + 1 );
		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( 'locked', $res['errors']->get_error_code(), 'Still paused after one hour.' );
		$this->assertSame( 0, $this->wp_login_count );
	}

	public function test_a_password_reset_ends_the_account_lock() {
		$made = $this->app_user();
		$this->five_wrong_codes( $made['user'] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		$this->five_wrong_codes( $made['user'] );

		$pass = wp_generate_password( 24, false );
		reset_password( $made['user'], $pass );
		$user = get_userdata( $made['user']->ID );

		$_SERVER['REMOTE_ADDR'] = '192.0.2.200';
		$this->password_login( $user, array(), $pass );
		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertSame( 0, RateLimiter::count( Challenge::CODE_ACTION, 'account', Challenge::account_subject( $user->ID ), DAY_IN_SECONDS ) );
	}

	public function test_one_ip_cycling_its_own_account_cannot_lock_other_accounts() {
		$own  = $this->email_user();
		$made = $this->app_user( 'administrator' );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		for ( $round = 0; $round < 20; $round++ ) {
			$this->password_login( $own );
			$cookie = $this->pending_cookie();
			for ( $try = 0; $try < 5; $try++ ) {
				$this->post_code( $cookie, '000000', 'email' );
			}
		}

		$_SERVER['REMOTE_ADDR'] = '192.0.2.200';
		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 1, $this->wp_login_count, 'The admin with a right app code logs in.' );
	}

	public function test_one_hundred_wrong_codes_on_the_site_alert_the_owner_once_and_lock_nobody() {
		for ( $n = 0; $n < 10; $n++ ) {
			$other                  = $this->app_user();
			$_SERVER['REMOTE_ADDR'] = '198.51.' . $n . '.7';
			$this->five_wrong_codes( $other['user'] );
			$_SERVER['REMOTE_ADDR'] = '198.51.' . $n . '.8';
			$this->five_wrong_codes( $other['user'] );
		}
		$owner = $this->owner_mails();
		$this->assertCount( 1, $owner );
		$this->assertStringContainsString( 'Many wrong two-step login codes', $owner[0]['message'] );
		$this->assertCount( 1, $this->log_rows( 'twostep_site_alert' ) );

		$this->mails            = array();
		$made                   = $this->app_user( 'administrator' );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.200';
		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), '000000' );
		$this->assertSame( 'happyaccess_invalid_code', $res['errors']->get_error_code(), 'Codes are still checked.' );
		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertCount( 0, $this->owner_mails(), 'One alert an hour.' );
		$this->assertCount( 1, $this->log_rows( 'twostep_site_alert' ) );
	}

	public function test_a_missing_or_expired_cookie_goes_back_to_the_login() {
		$made = $this->app_user();

		$res = Challenge::handle( 'GET', array(), array(), array() );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 'expired', $this->query( $res['url'] )['happyaccess_ts'] );
		$this->assertStringStartsWith( wp_login_url(), $res['url'] );

		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();
		Clock::freeze( Clock::now() + 601 );
		$res = $this->post_code( $cookie, $this->app_code( $made['secret'] ) );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 'expired', $this->query( $res['url'] )['happyaccess_ts'] );
		$this->assertSame( 0, $this->wp_login_count );

		$errors = Challenge::login_errors( new WP_Error(), array( 'happyaccess_ts' => 'expired' ) );
		$this->assertSame( 'Your login took too long. Log in again.', $errors->get_error_message() );
		$this->assertSame( array(), Challenge::login_errors( new WP_Error(), array() )->get_error_codes() );
	}

	public function test_a_bad_nonce_shows_the_form_again() {
		$made = $this->app_user();
		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ), 'app', array( '_wpnonce' => 'nope' ) );
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( 'expired_page', $res['errors']->get_error_code() );
		$this->assertSame( 0, $this->wp_login_count );
	}

	public function test_a_support_temp_user_is_exempt() {
		$made = $this->app_user();
		update_user_meta( $made['user']->ID, 'happyaccess_temp_user', 1 );
		$this->assertFalse( Challenge::applies( $made['user'] ) );
	}

	public function test_a_user_with_another_plugins_two_step_logs_in_without_the_step() {
		$made = $this->app_user();
		add_filter( 'happyaccess_user_has_other_2fa', '__return_true' );
		$res = $this->password_login( $made['user'] );
		remove_filter( 'happyaccess_user_has_other_2fa', '__return_true' );

		$this->assertNull( $res['url'] );
		$this->assertInstanceOf( WP_User::class, $res['result'] );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertSame( array(), $this->pending_rows() );
	}

	public function test_a_user_without_two_step_logs_in_as_before() {
		$user = self::factory()->user->create_and_get( array( 'user_pass' => self::PASSWORD ) );
		$res  = $this->password_login( $user );
		$this->assertInstanceOf( WP_User::class, $res['result'] );
		$this->assertSame( 1, $this->wp_login_count );
	}

	public function test_an_application_password_login_is_exempt() {
		$made = $this->app_user();
		Challenge::set_context( 'xmlrpc' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
		list( $app_password ) = WP_Application_Passwords::create_new_application_password( $made['user']->ID, array( 'name' => 'Phone' ) );

		$result = wp_authenticate( $made['user']->user_login, $app_password );
		$this->assertInstanceOf( WP_User::class, $result );
		$this->assertSame( array(), $this->pending_rows() );
	}

	public function test_xml_rpc_is_refused_for_a_two_step_user_while_the_setting_is_on() {
		$made = $this->app_user();
		Challenge::set_context( 'xmlrpc' );

		$result = wp_authenticate( $made['user']->user_login, self::PASSWORD );
		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_twostep_xmlrpc', $result->get_error_code() );

		Settings::update( array( 'two_step' => array( 'block_xmlrpc' => false ) ) );
		$result = wp_authenticate( $made['user']->user_login, self::PASSWORD );
		$this->assertInstanceOf( WP_User::class, $result );
		$this->assertSame( array(), $this->pending_rows() );
	}

	public function test_an_ajax_login_is_refused_instead_of_skipping_the_step() {
		$made = $this->app_user();
		Challenge::set_context( 'api' );
		$res = $this->password_login( $made['user'] );
		$this->assertNull( $res['url'] );
		$this->assertWPError( $res['result'] );
		$this->assertSame( 'happyaccess_twostep_required', $res['result']->get_error_code() );
		$this->assertSame( 0, $this->wp_login_count );
	}

	public function test_redirect_to_is_carried_and_an_off_site_one_is_dropped() {
		$made   = $this->app_user();
		$target = admin_url( 'tools.php' );

		$res  = $this->password_login(
			$made['user'],
			array(
				'redirect_to' => $target,
				'rememberme'  => 'forever',
			)
		);
		$args = $this->query( $res['url'] );
		$this->assertSame( $target, $args['redirect_to'] );
		$this->assertSame( '1', $args['rememberme'] );

		$cookie = $this->pending_cookie();
		$screen = Challenge::handle( 'GET', $args, array(), array( Challenge::COOKIE => $cookie ) );
		$this->assertStringContainsString( 'name="redirect_to" value="' . esc_attr( $target ) . '"', $screen['body'] );
		$this->assertStringContainsString( 'name="rememberme" value="1"', $screen['body'] );

		$res = $this->post_code(
			$cookie,
			$this->app_code( $made['secret'] ),
			'app',
			array(
				'redirect_to' => $target,
				'rememberme'  => '1',
			)
		);
		$this->assertSame( $target, $res['url'] );

		wp_set_current_user( 0 );
		$res  = $this->password_login( $made['user'], array( 'redirect_to' => 'https://evil.example/steal' ) );
		$args = $this->query( $res['url'] );
		$this->assertArrayNotHasKey( 'redirect_to', $args );

		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'], 1 ), 'app', array( 'redirect_to' => 'https://evil.example/steal' ) );
		$this->assertSame( admin_url(), $res['url'] );
	}

	public function test_a_subscriber_goes_to_the_profile_like_core() {
		$made = $this->app_user( 'subscriber' );
		$this->password_login( $made['user'] );
		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ) );
		$this->assertSame( admin_url( 'profile.php' ), $res['url'] );
	}

	public function test_an_interim_login_ends_on_the_interim_success_screen() {
		$made = $this->app_user();
		$res  = $this->password_login( $made['user'], array( 'interim-login' => '1' ) );
		$args = $this->query( $res['url'] );
		$this->assertSame( '1', $args['interim-login'] );

		$res = $this->post_code( $this->pending_cookie(), $this->app_code( $made['secret'] ), 'app', array( 'interim-login' => '1' ) );
		$this->assertSame( 'interim', $res['type'] );
		$this->assertStringContainsString( 'You have logged in successfully.', $res['message'] );
		$this->assertSame( 1, $this->wp_login_count );
	}

	public function test_passwordless_email_only_users_log_in_directly() {
		Features::set( 'passwordless', true );
		$user = $this->email_user();
		$code = $this->passwordless_code( $user );

		$res = LoginSteps::verify_flow( $code['cookie'], $code['code'], false, '' );
		$this->assertSame( admin_url(), $res['redirect'] );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertSame( array(), $this->pending_rows() );
	}

	public function test_passwordless_email_users_with_backup_codes_log_in_directly() {
		Features::set( 'passwordless', true );
		$user = $this->email_user();
		BackupCodes::generate( $user->ID );
		$this->assertSame( 10, BackupCodes::remaining( $user->ID ) );
		$this->assertContains( 'backup', UserState::methods( $user->ID ) );
		$code = $this->passwordless_code( $user );

		$res = LoginSteps::verify_flow( $code['cookie'], $code['code'], false, '' );
		$this->assertSame( admin_url(), $res['redirect'] );
		$this->assertSame( array( $user->ID ), $this->auth );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertSame( array(), $this->pending_rows() );
	}

	public function test_passwordless_app_users_with_backup_codes_still_get_the_app_step() {
		Features::set( 'passwordless', true );
		$made = $this->app_user();
		UserState::enable_email( $made['user']->ID );
		BackupCodes::generate( $made['user']->ID );
		$code = $this->passwordless_code( $made['user'] );

		$res = LoginSteps::verify_flow( $code['cookie'], $code['code'], false, '' );
		$this->assertSame( 0, $this->wp_login_count );
		$this->assertSame( array(), $this->auth );
		$args = $this->query( $res['redirect'] );
		$this->assertSame( 'twostep', $args['step'] );
		$this->assertSame( 'app', $args['method'] );
		$this->assertCount( 1, $this->pending_rows() );
	}

	public function test_passwordless_app_users_get_the_app_step() {
		Features::set( 'passwordless', true );
		$made = $this->app_user();
		UserState::enable_email( $made['user']->ID );
		$code = $this->passwordless_code( $made['user'] );

		$res = LoginSteps::verify_flow( $code['cookie'], $code['code'], true, admin_url( 'tools.php' ) );
		$this->assertSame( 0, $this->wp_login_count );
		$this->assertSame( array(), $this->auth );
		$args = $this->query( $res['redirect'] );
		$this->assertSame( 'twostep', $args['step'] );
		$this->assertSame( 'app', $args['method'] );
		$rows = $this->pending_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'twostep_login_pl', $rows[0]['purpose'] );

		$cookie = $this->pending_cookie();
		$screen = Challenge::handle( 'GET', $args, array(), array( Challenge::COOKIE => $cookie ) );
		$this->assertStringContainsString( 'Authenticator app code', $screen['body'] );
		$this->assertStringNotContainsString( 'Email me a code instead', $screen['body'], 'The email code was the first step.' );

		$refused = $this->post_code( $cookie, '123456', 'email' );
		$this->assertSame( 'render', $refused['type'] );

		$res = $this->post_code(
			$cookie,
			$this->app_code( $made['secret'] ),
			'app',
			array(
				'redirect_to' => $args['redirect_to'],
				'rememberme'  => $args['rememberme'],
			)
		);
		$this->assertSame( admin_url( 'tools.php' ), $res['url'] );
		$this->assertSame( 1, $this->wp_login_count );
	}

	/**
	 * Asks for a passwordless code and reads it from the email.
	 *
	 * @param WP_User $user User.
	 * @return array code and cookie.
	 */
	private function passwordless_code( WP_User $user ) {
		$this->mails   = array();
		$this->cookies = array();
		$this->assertIsArray( LoginSteps::request_flow( $user->user_email ) );
		$cookie = $this->cookies[0]['value'];
		LoginSteps::flush_queue();
		$this->assertSame( 1, preg_match( '/(\d{3}) (\d{3})/', $this->mails[0]['message'], $m ) );
		$this->cookies = array();
		return array(
			'code'   => $m[1] . $m[2],
			'cookie' => $cookie,
		);
	}

	/**
	 * The constant is defined here, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_wp_config_constant_turns_the_step_off_for_everyone() {
		define( 'HAPPYACCESS_DISABLE_TWOSTEP', true );
		$made = $this->app_user();
		$this->assertFalse( Challenge::applies( $made['user'] ) );
		$res = $this->password_login( $made['user'] );
		$this->assertInstanceOf( WP_User::class, $res['result'] );
		$this->assertSame( 1, $this->wp_login_count );
	}

	/**
	 * Loads WooCommerce, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_woocommerce_my_account_login_goes_to_the_step_and_back() {
		$woo = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( ! file_exists( $woo ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed next to HappyAccess.' );
		}
		require_once $woo;
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'my-account',
			)
		);
		update_option( 'woocommerce_myaccount_page_id', $page );
		$account = wc_get_page_permalink( 'myaccount' );
		$this->assertSame( get_permalink( $page ), $account );

		if ( null === get_role( 'customer' ) ) {
			add_role( 'customer', 'Customer', array( 'read' => true ) );
		}
		$made  = $this->app_user( 'customer' );
		$_POST = array(
			'username'                => $made['user']->user_login,
			'password'                => self::PASSWORD,
			'login'                   => 'Log in',
			'redirect'                => $account,
			'woocommerce-login-nonce' => wp_create_nonce( 'woocommerce-login' ),
		);
		$_REQUEST = $_POST;
		$url      = '';
		try {
			WC_Form_Handler::process_login();
		} catch ( HappyAccess_Test_TwoStep_Redirect $stop ) {
			$url = $stop->url;
		}
		$_POST    = array();
		$_REQUEST = array();

		$args = $this->query( $url );
		$this->assertSame( 'twostep', $args['step'] );
		$this->assertSame( $account, $args['redirect_to'] );
		$this->assertSame( 'woo', $args['from'] );
		$this->assertSame( 0, $this->wp_login_count );
		$this->assertSame( array(), $this->auth );

		$res = $this->post_code(
			$this->pending_cookie(),
			$this->app_code( $made['secret'] ),
			'app',
			array(
				'redirect_to' => $args['redirect_to'],
				'from'        => 'woo',
			)
		);
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( $account, $res['url'] );
		$this->assertSame( 1, $this->wp_login_count );
	}

	/**
	 * Loads WooCommerce with a My Account page, and a session in memory so
	 * its notices work. Only in a separate process.
	 *
	 * @return string My Account URL.
	 */
	private function load_woocommerce() {
		$woo = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( ! file_exists( $woo ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed next to HappyAccess.' );
		}
		require_once $woo;
		// WooCommerce's init needs its tables, so only its notice functions are told it ran.
		$GLOBALS['wp_actions']['woocommerce_init'] = 1;
		WC()->session                              = new class() extends WC_Session {};
		$page                                      = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'my-account',
			)
		);
		update_option( 'woocommerce_myaccount_page_id', $page );
		if ( null === get_role( 'customer' ) ) {
			add_role( 'customer', 'Customer', array( 'read' => true ) );
		}
		return wc_get_page_permalink( 'myaccount' );
	}

	/**
	 * Posts WooCommerce's reset form with a real reset key, through its form handler.
	 *
	 * @param WP_User $user User.
	 * @param string  $pass New password.
	 * @return string Where the browser was sent, or an empty string.
	 */
	private function woo_reset( WP_User $user, $pass ) {
		$_POST    = array(
			'wc_reset_password'                => 'true',
			'password_1'                       => $pass,
			'password_2'                       => $pass,
			'reset_key'                        => get_password_reset_key( $user ),
			'reset_login'                      => $user->user_login,
			'woocommerce-reset-password-nonce' => wp_create_nonce( 'reset_password' ),
		);
		$_REQUEST = $_POST;
		$url      = '';
		// WooCommerce's own redirect after the reset ends in exit, so it stops here instead.
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		try {
			WC_Form_Handler::process_reset_password();
		} catch ( HappyAccess_Test_TwoStep_Redirect $stop ) {
			$url = $stop->url;
		} finally {
			remove_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
			$_POST    = array();
			$_REQUEST = array();
		}
		return $url;
	}

	/**
	 * Loads WooCommerce, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_woocommerce_password_reset_sends_an_app_user_to_the_step() {
		$account = $this->load_woocommerce();
		$made    = $this->app_user( 'customer' );
		$pass    = wp_generate_password( 24, false );

		$url = $this->woo_reset( $made['user'], $pass );

		$this->assertSame( array(), $this->auth, 'No auth cookie before the second step.' );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 0, $this->wp_login_count );
		$args = $this->query( $url );
		$this->assertSame( 'twostep', $args['step'] );
		$this->assertSame( 'woo', $args['from'] );
		$this->assertSame( $account, $args['redirect_to'] );
		$this->assertTrue( wp_check_password( $pass, get_userdata( $made['user']->ID )->user_pass, $made['user']->ID ), 'The new password is saved.' );

		$res = $this->post_code(
			$this->pending_cookie(),
			$this->app_code( $made['secret'] ),
			'app',
			array(
				'redirect_to' => $args['redirect_to'],
				'from'        => 'woo',
			)
		);
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( $account, $res['url'] );
		$this->assertSame( array( $made['user']->ID ), $this->auth );
		$this->assertSame( 1, $this->wp_login_count );
	}

	/**
	 * Loads WooCommerce, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_woocommerce_password_reset_sends_a_required_user_to_setup() {
		$this->load_woocommerce();
		Settings::update( array( 'two_step' => array( 'role_policy' => array( 'customer' => 'required' ) ) ) );
		$user = self::factory()->user->create_and_get( array( 'role' => 'customer' ) );

		$url = $this->woo_reset( $user, wp_generate_password( 24, false ) );

		$this->assertSame( array(), $this->auth );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 'twostep_setup', $this->query( $url )['step'] );
	}

	/**
	 * Loads WooCommerce, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_woocommerce_password_reset_still_logs_in_a_user_without_two_step() {
		$this->load_woocommerce();
		$user = self::factory()->user->create_and_get( array( 'role' => 'customer' ) );

		$url = @$this->woo_reset( $user, wp_generate_password( 24, false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- WooCommerce's own redirect sends headers under the CLI.

		$this->assertStringContainsString( 'password-reset=true', $url, 'HappyAccess leaves this reset to WooCommerce.' );
		$this->assertSame( array( $user->ID ), $this->auth );
	}

	public function test_a_core_password_reset_is_left_alone() {
		$made = $this->app_user();
		reset_password( $made['user'], wp_generate_password( 24, false ) );
		$this->assertSame( array(), $this->auth, 'wp-login.php never logs in after a reset.' );
		$this->assertSame( array(), $this->cookies );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_an_api_login_of_a_user_who_must_set_up_says_how_and_stays_refused() {
		Settings::update( array( 'two_step' => array( 'role_policy' => array( 'editor' => 'required' ) ) ) );
		$user = self::factory()->user->create_and_get(
			array(
				'role'      => 'editor',
				'user_pass' => self::PASSWORD,
			)
		);
		Challenge::set_context( 'api' );

		$res = $this->password_login( $user );
		$this->assertWPError( $res['result'] );
		$this->assertSame( 'happyaccess_twostep_required', $res['result']->get_error_code() );
		$this->assertSame( esc_html( 'Your role needs two-step login. Log in on the login page to set it up.' ), $res['result']->get_error_message() );

		UserState::start_grace( $user->ID );
		$this->assertWPError( $this->password_login( $user )['result'], 'Refused during the grace period too.' );
		$this->assertSame( 0, $this->wp_login_count );
	}

	public function test_after_a_wrong_code_the_field_points_at_the_error_and_takes_focus() {
		$made = $this->app_user();
		$this->password_login( $made['user'] );
		$cookie = $this->pending_cookie();

		$first = Challenge::handle( 'GET', array(), array(), array( Challenge::COOKIE => $cookie ) )['body'];
		$this->assertStringNotContainsString( 'aria-describedby="login_error"', $first );

		$wrong = $this->post_code( $cookie, '000000' === $this->app_code( $made['secret'] ) ? '111111' : '000000' );
		$this->assertSame( 1, preg_match( '/<input[^>]+id="happyaccess-ts-code"[^>]*>/', $wrong['body'], $field ) );
		$this->assertStringContainsString( 'aria-describedby="login_error"', $field[0] );
		$this->assertStringContainsString( 'autofocus', $field[0] );
	}

	public function test_a_login_while_the_role_is_not_required_clears_the_grace_state() {
		Settings::update( array( 'two_step' => array( 'role_policy' => array( 'editor' => 'required' ) ) ) );
		$user = self::factory()->user->create_and_get(
			array(
				'role'      => 'editor',
				'user_pass' => self::PASSWORD,
			)
		);
		$this->password_login( $user );
		$this->password_login( $user );
		$this->assertSame( 2, UserState::grace_logins_used( $user->ID ) );

		Settings::update( array( 'two_step' => array( 'role_policy' => array( 'editor' => 'optional' ) ) ) );
		$res = $this->password_login( $user );
		$this->assertInstanceOf( WP_User::class, $res['result'] );
		$this->assertSame( 0, UserState::grace_started_at( $user->ID ) );
		$this->assertSame( 0, UserState::grace_logins_used( $user->ID ) );

		Settings::update( array( 'two_step' => array( 'role_policy' => array( 'editor' => 'required' ) ) ) );
		$this->password_login( $user );
		$this->assertSame( 1, UserState::grace_logins_used( $user->ID ), 'Required again, the grace period starts fresh.' );
	}
}
