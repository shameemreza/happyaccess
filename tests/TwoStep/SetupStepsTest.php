<?php
/**
 * Two-step setup at login for required roles.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;
use HappyAccess\Features\Passwordless\LoginSteps;
use HappyAccess\Features\TwoStep\BackupCodes;
use HappyAccess\Features\TwoStep\Challenge;
use HappyAccess\Features\TwoStep\EmailMethod;
use HappyAccess\Features\TwoStep\Feature;
use HappyAccess\Features\TwoStep\SetupSteps;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;
use HappyAccess\Login\Router;

/**
 * Thrown by the test redirector so a redirect from inside wp_signon() stops
 * the login the way exit does.
 */
class HappyAccess_Test_TwoStep_Setup_Redirect extends Error {

	/**
	 * Where the browser was sent.
	 *
	 * @var string
	 */
	public $url = '';
}

class SetupStepsTest extends WP_UnitTestCase {

	const PASSWORD = 'correct horse battery';

	/**
	 * Cookies the steps tried to send.
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
	 * Mails caught before they are sent.
	 *
	 * @var array
	 */
	private $mails = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
		Challenge::reset();
		Features::set( 'two_step', true );
		Settings::update(
			array(
				'two_step' => array(
					'role_policy'  => array( 'administrator' => 'required' ),
					'grace_type'   => 'logins',
					'grace_logins' => 3,
				),
			)
		);
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

		add_filter( 'send_auth_cookies', '__return_false' );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		add_filter( 'happyaccess_twostep_send_cookie', array( $this, 'catch_cookie' ), 10, 4 );
		add_filter( 'happyaccess_passwordless_send_cookie', array( $this, 'catch_cookie' ), 10, 4 );
		add_action( 'set_auth_cookie', array( $this, 'catch_auth' ), 10, 6 );
		add_action( 'wp_login', array( $this, 'count_login' ) );

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
		unset( $handled, $options );
		$this->cookies[] = array(
			'name'  => $name,
			'value' => $value,
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

	public function stop_redirect( $url ) {
		$stop      = new HappyAccess_Test_TwoStep_Setup_Redirect( 'redirect' );
		$stop->url = $url;
		throw $stop;
	}

	/**
	 * An administrator with a known password and no two-step method.
	 *
	 * @return WP_User
	 */
	private function admin() {
		return self::factory()->user->create_and_get(
			array(
				'role'       => 'administrator',
				'user_pass'  => self::PASSWORD,
				'user_email' => 'ada@example.org',
			)
		);
	}

	/**
	 * A password login through wp_signon(), as wp-login.php makes it.
	 *
	 * @param WP_User $user User.
	 * @return array url and result.
	 */
	private function password_login( WP_User $user ) {
		$this->cookies = array();
		$_POST         = array(
			'log' => $user->user_login,
			'pwd' => self::PASSWORD,
		);
		$_REQUEST      = $_POST;
		try {
			$result = wp_signon(
				array(
					'user_login'    => $user->user_login,
					'user_password' => self::PASSWORD,
				)
			);
		} catch ( HappyAccess_Test_TwoStep_Setup_Redirect $stop ) {
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
	 * Logs in with the password and returns the pending cookie.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private function start_setup( WP_User $user ) {
		$res = $this->password_login( $user );
		$this->assertNotNull( $res['url'], 'The browser goes to the setup screen.' );
		$this->assertSame( 'twostep_setup', $this->query( $res['url'] )['step'] );
		return $this->pending_cookie();
	}

	/**
	 * Opens the setup screen.
	 *
	 * @param string $cookie Pending cookie.
	 * @param array  $get    Query args.
	 * @return array Response.
	 */
	private function screen( $cookie, array $get = array() ) {
		return SetupSteps::handle( 'GET', $get, array(), array( Challenge::COOKIE => $cookie ) );
	}

	/**
	 * Posts an action on the setup screen.
	 *
	 * @param string $cookie Pending cookie.
	 * @param string $action app, email, send, later or continue.
	 * @param array  $extra  Extra fields.
	 * @return array Response.
	 */
	private function post( $cookie, $action, array $extra = array() ) {
		return SetupSteps::handle(
			'POST',
			array(),
			array_merge(
				array(
					SetupSteps::FIELD => $action,
					'_wpnonce'        => wp_create_nonce( 'happyaccess_twostep_setup' ),
				),
				$extra
			),
			array( Challenge::COOKIE => $cookie )
		);
	}

	/**
	 * The secret the app screen shows, from the QR address.
	 *
	 * @param string $body Screen body.
	 * @return string
	 */
	private function shown_secret( $body ) {
		$this->assertSame( 1, preg_match( '/data-happyaccess-uri="([^"]+)"/', $body, $m ) );
		$args = array();
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $args );
		return $args['secret'];
	}

	/**
	 * The backup codes a screen shows.
	 *
	 * @param string $body Screen body.
	 * @return string[]
	 */
	private function shown_codes( $body ) {
		preg_match_all( '/<code class="happyaccess-ts-backup">([^<]+)<\/code>/', $body, $m );
		return $m[1];
	}

	/**
	 * The setup transients in the options table.
	 *
	 * @return array name => value
	 */
	private function setup_transients() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . SetupSteps::TRANSIENT ) . '%' ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['option_name'] ] = $row['option_value'];
		}
		return $out;
	}

	private function log_rows( $event ) {
		return AuditLog::query( array( 'event' => $event ) )['items'];
	}

	public function test_a_required_user_goes_to_setup_without_an_auth_cookie() {
		$user   = $this->admin();
		$cookie = $this->start_setup( $user );

		$this->assertNotSame( '', $cookie );
		$this->assertSame( array(), $this->auth );
		$this->assertSame( 0, $this->wp_login_count );
		$this->assertSame( 1, UserState::grace_logins_used( $user->ID ), 'The password login counts once.' );

		$screen = $this->screen( $cookie );
		$this->screen( $cookie );
		$this->screen( $cookie, array( 'method' => 'email' ) );
		$this->assertSame( 1, UserState::grace_logins_used( $user->ID ), 'Opening the screen again counts nothing.' );

		$this->assertSame( 'render', $screen['type'] );
		$this->assertSame( 'Set up two-step login', $screen['title'] );
		$this->assertStringContainsString( 'Authenticator app', $screen['body'] );
		$this->assertStringContainsString( 'Use email codes instead', $screen['body'] );
		$this->assertStringContainsString( 'You can skip this 2 more times.', $screen['message'] );
	}

	public function test_logins_one_to_three_offer_later_and_login_four_does_not() {
		$user = $this->admin();

		for ( $login = 1; $login <= 3; $login++ ) {
			$cookie = $this->start_setup( $user );
			$screen = $this->screen( $cookie );
			$this->assertStringContainsString( '>Later<', $screen['body'], "Login $login offers Later." );

			$res = $this->post( $cookie, 'later' );
			$this->assertSame( 'redirect', $res['type'], "Later finishes login $login." );
			$this->assertSame( admin_url(), $res['url'] );
			$this->assertSame( $login, $this->wp_login_count );
			wp_set_current_user( 0 );
		}
		$this->assertCount( 3, $this->log_rows( 'twostep_skipped' ) );
		$this->assertCount( 0, $this->log_rows( 'twostep_passed' ), 'Putting setup off is not a passed step.' );

		$cookie = $this->start_setup( $user );
		$screen = $this->screen( $cookie );
		$this->assertStringNotContainsString( '>Later<', $screen['body'], 'Login 4 has no Later.' );
		$this->assertStringContainsString( 'Set it up to finish logging in.', $screen['message'] );

		$res = $this->post( $cookie, 'later' );
		$this->assertSame( 'render', $res['type'], 'A posted Later after the grace period is refused.' );
		$this->assertSame( 'happyaccess_no_grace', $res['errors']->get_error_code() );
		$this->assertSame( 3, $this->wp_login_count );
		$this->assertSame( array( $user->ID, $user->ID, $user->ID ), $this->auth );
	}

	public function test_grace_by_days_shows_the_end_date() {
		Settings::update(
			array(
				'two_step' => array(
					'grace_type' => 'days',
					'grace_days' => 7,
				),
			)
		);
		$user   = $this->admin();
		$cookie = $this->start_setup( $user );
		$screen = $this->screen( $cookie );
		$date   = wp_date( get_option( 'date_format' ), 1790000000 + 7 * DAY_IN_SECONDS );
		$this->assertStringContainsString( 'You can skip this until ' . $date . '.', $screen['message'] );
		$this->assertStringContainsString( '>Later<', $screen['body'] );

		Clock::freeze( 1790000000 + 7 * DAY_IN_SECONDS );
		$cookie = $this->start_setup( $user );
		$this->assertStringNotContainsString( '>Later<', $this->screen( $cookie )['body'] );
		$this->assertSame( 'render', $this->post( $cookie, 'later' )['type'] );
		$this->assertSame( 0, $this->wp_login_count );
	}

	public function test_the_app_is_turned_on_only_after_a_right_code() {
		$user   = $this->admin();
		$cookie = $this->start_setup( $user );
		$body   = $this->screen( $cookie )['body'];
		$secret = $this->shown_secret( $body );

		$this->assertSame( 32, strlen( $secret ) );
		$this->assertStringContainsString( implode( ' ', str_split( $secret, 4 ) ), $body, 'The key shows in groups of 4.' );
		$this->assertStringContainsString( 'role="img"', $body );
		$this->assertStringContainsString( 'aria-label="', $body );
		$this->assertSame( $secret, $this->shown_secret( $this->screen( $cookie )['body'] ), 'The same secret until it is used.' );

		$this->assertFalse( UserState::app_enabled( $user->ID ), 'Not stored as enabled before the code.' );
		$this->assertFalse( metadata_exists( 'user', $user->ID, UserState::META_TOTP ) );
		$held = $this->setup_transients();
		$this->assertCount( 1, $held );
		foreach ( $held as $value ) {
			$this->assertStringNotContainsString( $secret, $value, 'The held secret is encrypted.' );
		}

		$wrong = $this->post( $cookie, 'app', array( 'pwd' => '000000' === Totp::code( $secret, Totp::step_for( Clock::now() ) ) ? '111111' : '000000' ) );
		$this->assertSame( 'render', $wrong['type'] );
		$this->assertSame( 'happyaccess_invalid_code', $wrong['errors']->get_error_code() );
		$this->assertFalse( UserState::app_enabled( $user->ID ) );
		$this->assertCount( 1, $this->log_rows( 'twostep_failed' ) );

		$right = $this->post( $cookie, 'app', array( 'pwd' => Totp::code( $secret, Totp::step_for( Clock::now() ) ) ) );
		$this->assertSame( 'render', $right['type'] );
		$this->assertTrue( UserState::app_enabled( $user->ID ) );
		$this->assertSame( $secret, UserState::totp_secret( $user->ID ) );
		$this->assertSame( Totp::step_for( Clock::now() ), UserState::last_step( $user->ID ), 'The setup code is used up.' );
		foreach ( $this->setup_transients() as $value ) {
			$this->assertSame( '', maybe_unserialize( $value )['secret'], 'The held secret is gone once used.' );
		}
		$this->assertSame( array(), $this->auth, 'Still no auth cookie before Continue.' );
		$this->assertCount( 10, $this->shown_codes( $right['body'] ) );
	}

	public function test_backup_codes_are_shown_once_and_stored_hashed() {
		$user   = $this->admin();
		$cookie = $this->start_setup( $user );
		$secret = $this->shown_secret( $this->screen( $cookie )['body'] );
		$res    = $this->post( $cookie, 'app', array( 'pwd' => Totp::code( $secret, Totp::step_for( Clock::now() ) ) ) );

		$codes = $this->shown_codes( $res['body'] );
		$this->assertCount( 10, $codes );
		$this->assertStringContainsString( 'I saved these codes', $res['body'] );
		$this->assertStringContainsString( 'Download as text', $res['body'] );
		$this->assertStringContainsString( '>Copy<', $res['body'] );

		$stored = get_user_meta( $user->ID, UserState::META_BACKUP, true );
		$this->assertCount( 10, $stored );
		foreach ( $codes as $i => $code ) {
			$plain = str_replace( '-', '', $code );
			$this->assertNotContains( $plain, $stored );
			$this->assertTrue( wp_check_password( $plain, $stored[ $i ] ), 'Stored as a password hash.' );
		}

		$again = $this->screen( $cookie );
		$this->assertSame( array(), $this->shown_codes( $again['body'] ), 'The codes are not shown again.' );
		$this->assertStringContainsString( 'Continue', $again['body'] );
		$posted = $this->post( $cookie, 'app', array( 'pwd' => Totp::code( $secret, Totp::step_for( Clock::now() ) ) ) );
		$this->assertSame( array(), $this->shown_codes( $posted['body'] ), 'A repeated post does not make new codes.' );
		$this->assertSame( $stored, get_user_meta( $user->ID, UserState::META_BACKUP, true ) );

		$done = $this->post( $cookie, 'continue', array( 'saved' => '1' ) );
		$this->assertSame( 'redirect', $done['type'] );
		$this->assertSame( admin_url(), $done['url'] );
		$this->assertSame( array( $user->ID ), $this->auth );
		$this->assertSame( 1, $this->wp_login_count );
		$this->assertSame( $user->ID, get_current_user_id() );
		$this->assertCount( 1, $this->log_rows( 'twostep_passed' ) );

		$gone = $this->screen( $cookie );
		$this->assertSame( 'redirect', $gone['type'], 'The pending login is used up.' );
	}

	public function test_email_setup_needs_one_confirmed_code() {
		$user   = $this->admin();
		$cookie = $this->start_setup( $user );

		$screen = $this->screen( $cookie, array( 'method' => 'email' ) );
		$this->assertStringContainsString( 'Email me a code', $screen['body'] );
		$this->assertStringNotContainsString( 'ada@example.org', $screen['body'] . $screen['message'] );

		$early = $this->post( $cookie, 'email', array( 'pwd' => '123456' ) );
		$this->assertSame( 'render', $early['type'] );
		$this->assertFalse( UserState::email_enabled( $user->ID ) );

		$sent = $this->post( $cookie, 'send' );
		$this->assertSame( 'redirect', $sent['type'] );
		$args = $this->query( $sent['url'] );
		$this->assertSame( 'twostep_setup', $args['step'] );
		$this->assertSame( 'email', $args['method'] );
		$this->assertSame( '1', $args['sent'] );
		EmailMethod::flush_queue();
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 1, preg_match( '/(\d{3}) (\d{3})/', $this->mails[0]['message'], $m ) );
		$this->assertFalse( UserState::email_enabled( $user->ID ), 'Sending a code turns nothing on.' );

		$res = $this->post( $cookie, 'email', array( 'pwd' => $m[1] . $m[2] ) );
		$this->assertSame( 'render', $res['type'] );
		$this->assertTrue( UserState::email_enabled( $user->ID ) );
		$this->assertFalse( UserState::app_enabled( $user->ID ) );
		$this->assertCount( 10, $this->shown_codes( $res['body'] ) );

		$done = $this->post( $cookie, 'continue', array( 'saved' => '1' ) );
		$this->assertSame( 'redirect', $done['type'] );
		$this->assertSame( 1, $this->wp_login_count );
	}

	public function test_continue_before_setup_logs_nobody_in() {
		$user   = $this->admin();
		$cookie = $this->start_setup( $user );
		$res    = $this->post( $cookie, 'continue', array( 'saved' => '1' ) );
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( 0, $this->wp_login_count );
		$this->assertSame( array(), $this->auth );
	}

	public function test_a_user_with_a_method_cannot_use_the_setup_step_to_skip_the_code() {
		$user = $this->admin();
		UserState::enable_email( $user->ID );
		$res = $this->password_login( $user );
		$this->assertSame( 'twostep', $this->query( $res['url'] )['step'] );
		$cookie = $this->pending_cookie();

		$this->assertSame( 'redirect', $this->screen( $cookie )['type'] );
		$this->assertSame( 'twostep', $this->query( $this->screen( $cookie )['url'] )['step'] );
		$this->assertSame( 'redirect', $this->post( $cookie, 'continue', array( 'saved' => '1' ) )['type'] );
		$this->assertSame( 'redirect', $this->post( $cookie, 'later' )['type'] );
		$this->assertSame( 0, $this->wp_login_count );
	}

	public function test_the_code_step_sends_a_setup_user_back_to_setup() {
		$user   = $this->admin();
		$cookie = $this->start_setup( $user );

		$res = Challenge::handle( 'GET', array(), array(), array( Challenge::COOKIE => $cookie ) );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 'twostep_setup', $this->query( $res['url'] )['step'] );

		$send = Challenge::handle(
			'POST',
			array(),
			array(
				'method'   => 'email',
				'send'     => '1',
				'_wpnonce' => wp_create_nonce( 'happyaccess_twostep_send' ),
			),
			array( Challenge::COOKIE => $cookie )
		);
		$this->assertSame( 'twostep_setup', $this->query( $send['url'] )['step'], 'No email code login around setup.' );
		EmailMethod::flush_queue();
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_passwordless_login_of_a_required_user_goes_to_setup() {
		Features::set( 'passwordless', true );
		$user = $this->admin();

		$this->cookies = array();
		$this->assertIsArray( LoginSteps::request_flow( $user->user_email ) );
		$pl_cookie = $this->cookies[0]['value'];
		LoginSteps::flush_queue();
		$this->assertSame( 1, preg_match( '/(\d{3}) (\d{3})/', $this->mails[0]['message'], $m ) );
		$this->cookies = array();
		$this->mails   = array();

		$res = LoginSteps::verify_flow( $pl_cookie, $m[1] . $m[2], false, '' );
		$this->assertSame( 0, $this->wp_login_count, 'The email code alone does not finish a required login.' );
		$this->assertSame( array(), $this->auth );
		$this->assertSame( 'twostep_setup', $this->query( $res['redirect'] )['step'] );
		$this->assertSame( 1, UserState::grace_logins_used( $user->ID ) );

		$later = $this->post( $this->pending_cookie(), 'later' );
		$this->assertSame( 'redirect', $later['type'] );
		$this->assertSame( 1, $this->wp_login_count );
	}

	public function test_an_app_error_is_shown_on_the_screen() {
		$user   = $this->admin();
		$cookie = $this->start_setup( $user );
		$secret = $this->shown_secret( $this->screen( $cookie )['body'] );

		// The key stays in memory for this request, but the saved copy is gone, so enable_app() refuses.
		delete_option( Secrets::OPTION );
		$res = $this->post( $cookie, 'app', array( 'pwd' => Totp::code( $secret, Totp::step_for( Clock::now() ) ) ) );

		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( 'happyaccess_no_site_key', $res['errors']->get_error_code() );
		$this->assertStringContainsString( 'The authenticator app could not be set up. Try again later.', $res['errors']->get_error_message() );
		$this->assertFalse( metadata_exists( 'user', $user->ID, UserState::META_TOTP ) );
		$this->assertSame( 0, $this->wp_login_count );
	}

	public function test_five_wrong_codes_cancel_the_login_and_drop_the_held_secret() {
		$user   = $this->admin();
		$cookie = $this->start_setup( $user );
		$secret = $this->shown_secret( $this->screen( $cookie )['body'] );
		$wrong  = '000000' === Totp::code( $secret, Totp::step_for( Clock::now() ) ) ? '111111' : '000000';

		for ( $i = 1; $i <= 4; $i++ ) {
			$this->assertSame( 'render', $this->post( $cookie, 'app', array( 'pwd' => $wrong ) )['type'] );
		}
		$res = $this->post( $cookie, 'app', array( 'pwd' => $wrong ) );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 'locked', $this->query( $res['url'] )['happyaccess_ts'] );
		$this->assertSame( array(), $this->setup_transients() );
		$this->assertFalse( UserState::app_enabled( $user->ID ) );
	}

	public function test_a_bad_nonce_changes_nothing() {
		$user   = $this->admin();
		$cookie = $this->start_setup( $user );
		$res    = $this->post( $cookie, 'later', array( '_wpnonce' => 'nope' ) );
		$this->assertSame( 'render', $res['type'] );
		$this->assertSame( 'expired_page', $res['errors']->get_error_code() );
		$this->assertSame( 0, $this->wp_login_count );
	}

	public function test_without_the_pending_cookie_the_screen_goes_back_to_the_login() {
		$res = SetupSteps::handle( 'GET', array(), array(), array() );
		$this->assertSame( 'redirect', $res['type'] );
		$this->assertSame( 'expired', $this->query( $res['url'] )['happyaccess_ts'] );
	}

	public function test_the_code_step_has_one_nav_container() {
		$secret = Totp::new_secret();
		$user   = $this->admin();
		UserState::enable_app( $user->ID, $secret );
		UserState::enable_email( $user->ID );
		BackupCodes::generate( $user->ID );
		$this->password_login( $user );

		$body = Challenge::handle( 'GET', array(), array(), array( Challenge::COOKIE => $this->pending_cookie() ) )['body'];
		$this->assertSame( 1, substr_count( $body, 'id="nav"' ) );
		$this->assertStringContainsString( 'Email me a code instead', $body );
		$this->assertStringContainsString( 'Use a backup code', $body );
	}

	public function test_no_form_posts_a_field_named_action() {
		// wp-login.php routes on $_REQUEST['action'], where a posted field would win over the query arg.
		$user   = $this->admin();
		$cookie = $this->start_setup( $user );
		$app    = $this->screen( $cookie );
		$email  = $this->screen(
			$cookie,
			array(
				'method' => 'email',
				'sent'   => '1',
			)
		);
		$secret = $this->shown_secret( $app['body'] );
		$codes  = $this->post( $cookie, 'app', array( 'pwd' => Totp::code( $secret, Totp::step_for( Clock::now() ) ) ) );
		foreach ( array( $app, $email, $codes, $this->screen( $cookie ) ) as $screen ) {
			$this->assertStringNotContainsString( 'name="action"', $screen['body'] );
			$this->assertStringContainsString( 'name="' . SetupSteps::FIELD . '"', $screen['body'] );
			$this->assertLessThanOrEqual( 1, substr_count( $screen['body'], 'id="_wpnonce"' ), 'No repeated nonce ids.' );
		}
	}

	public function test_the_setup_step_is_registered_with_the_feature() {
		$this->assertNotNull( Router::resolve( 'twostep_setup' ) );
	}
}
