<?php
/**
 * The second step of a two-step login.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\ClientIp;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Internal;
use HappyAccess\Core\Mailer;
use HappyAccess\Core\OtherTwoFactor;
use HappyAccess\Core\RateLimiter;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;
use HappyAccess\Login\Router;
use HappyAccess\Login\Screens;

defined( 'ABSPATH' ) || exit;

/**
 * After a correct password the login waits here. A pending login is one row
 * in the challenges table, which holds only the hash of a key, and an
 * HttpOnly cookie that holds the key. The auth cookie is set and wp_login
 * fires only after an app, email or backup code passes on the step=twostep
 * screen.
 *
 * The password check runs through the whole authenticate filter chain first,
 * so core's spam check, lockout plugins and Support Access's own block still
 * decide. Only a WP_User at the very end of the chain is sent to the second
 * step. Nothing about it looks like a failed login, so wp_login_failed never
 * fires for it.
 *
 * The handle() method takes the request as arguments and returns a response
 * array; run_step() reads the request and passes it in.
 */
final class Challenge {

	const STEP = 'twostep';

	/**
	 * Challenge purposes: after a password, and after a passwordless code or
	 * link, where the email code already counted as one step.
	 */
	const PURPOSE                    = 'twostep_login';
	const PURPOSE_AFTER_PASSWORDLESS = 'twostep_login_pl';

	const COOKIE = 'happyaccess_ts';

	/**
	 * Seconds a pending login lives.
	 */
	const LIFETIME = 600;

	/**
	 * Wrong codes a pending login takes before it is cancelled, across all methods.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Rate limit actions: codes typed, and emailed codes asked for.
	 */
	const CODE_ACTION = 'twostep_code';
	const SEND_ACTION = 'twostep_send';

	/**
	 * Emailed codes one account may ask for inside LIFETIME.
	 */
	const SEND_LIMIT = 3;

	/**
	 * Wrong codes one account may get inside ACCOUNT_WINDOW, counted across
	 * every IP, pending login and the profile re-check. The next one pauses
	 * the account's code step for ACCOUNT_LOCK, doubled for each pause in the
	 * last day, up to ACCOUNT_LOCK_MAX. Only failures count.
	 */
	const ACCOUNT_CODE_CAP = 10;
	const ACCOUNT_WINDOW   = 3600;
	const ACCOUNT_LOCK     = 3600;
	const ACCOUNT_LOCK_MAX = 57600;

	/**
	 * Rate limit action that records each pause of an account, so the next
	 * one can last twice as long.
	 */
	const LOCK_ACTION = 'twostep_lock';

	/**
	 * Wrong codes on the whole site inside SITE_WINDOW that email the owner
	 * and write one log row. It never locks anyone: a site-wide lock would
	 * let one account pause two-step login for every other.
	 */
	const SITE_CODE_CAP = 100;
	const SITE_WINDOW   = 3600;

	/**
	 * Set while the owner already has the email about many wrong codes.
	 */
	const SITE_ALERT_TRANSIENT = 'happyaccess_ts_site_alerted';

	/**
	 * Set for a week after the owner had the email about app secrets that
	 * can't be opened.
	 */
	const SECRET_ALERT_TRANSIENT = 'happyaccess_ts_secret_alerted';

	/**
	 * Prefix of the transient that holds a backup code count to show after login.
	 */
	const NOTICE_TRANSIENT = 'happyaccess_ts_backup_';

	/**
	 * Backup codes left at or below which the notice shows.
	 */
	const NOTICE_AT = 2;

	/**
	 * Query arg on the normal login screen that names why the user is back there.
	 */
	const LOGIN_ARG = 'happyaccess_ts';

	/**
	 * Ids of users that an application password just authenticated.
	 *
	 * @var array
	 */
	private static $app_password_users = array();

	/**
	 * Sends the browser to a URL. Null means wp_safe_redirect() and exit.
	 *
	 * @var callable|null
	 */
	private static $redirector = null;

	/**
	 * Ids of users whose password was reset in this request and who need
	 * the second step. No auth cookie goes out for them until it passes.
	 * True while a login after WooCommerce's My Account reset form should
	 * still go to the step.
	 *
	 * @var array<int,bool>
	 */
	private static $reset_users = array();

	/**
	 * Request kind override for tests: browser, xmlrpc or api.
	 *
	 * @var string|null
	 */
	private static $context = null;

	/**
	 * Adds the step and the filters. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		Router::add_step( self::STEP, array( __CLASS__, 'run_step' ) );
		add_action( 'after_password_reset', array( __CLASS__, 'end_account_lock' ), 10, 1 );
		add_action( 'password_reset', array( __CLASS__, 'note_reset' ), PHP_INT_MAX, 1 );
		add_action( 'after_password_reset', array( __CLASS__, 'note_reset' ), PHP_INT_MAX, 1 );
		add_action( 'set_auth_cookie', array( __CLASS__, 'drop_reset_session' ), PHP_INT_MAX, 6 );
		add_filter( 'send_auth_cookies', array( __CLASS__, 'hold_reset_cookie' ), PHP_INT_MAX, 4 );
		add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate' ), 50, 3 );
		add_filter( 'authenticate', array( __CLASS__, 'finish_authenticate' ), PHP_INT_MAX, 3 );
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'note_application_password' ), 10, 1 );
		add_filter( 'wp_login_errors', array( __CLASS__, 'filter_login_errors' ), 10, 1 );
		add_action( 'admin_notices', array( __CLASS__, 'print_backup_notice' ) );
		add_action( 'woocommerce_account_content', array( __CLASS__, 'print_backup_notice' ), 5 );
	}

	/**
	 * Whether the user must pass the second step to log in.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function applies( \WP_User $user ) {
		if ( self::is_exempt( $user ) ) {
			return false;
		}
		return UserState::is_enabled( $user->ID ) || self::needs_setup( $user );
	}

	/**
	 * Whether the user goes to the setup screen instead of the code step: a
	 * role that requires two-step login, no method set up, and not exempt.
	 * Enforcement decides on the screen whether "Later" is still offered.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function needs_setup( \WP_User $user ) {
		return ! self::is_exempt( $user )
			&& ! UserState::is_enabled( $user->ID )
			&& Enforcement::REQUIRED === Enforcement::policy( $user );
	}

	/**
	 * Users who never get the second step: everyone while the wp-config
	 * switch is on, Support Access temp users, and users with another
	 * plugin's two-step login, so nobody is asked twice.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function is_exempt( \WP_User $user ) {
		if ( defined( 'HAPPYACCESS_DISABLE_TWOSTEP' ) && true === (bool) constant( 'HAPPYACCESS_DISABLE_TWOSTEP' ) ) {
			return true;
		}
		if ( $user->ID < 1 || Capabilities::is_temp_user( $user->ID ) ) {
			return true;
		}
		return OtherTwoFactor::user_has_2fa( $user );
	}

	/**
	 * At a login while the user's role doesn't require two-step login, drops
	 * any grace period left from when it did, so a role that becomes
	 * required again later starts a fresh one.
	 *
	 * @param \WP_User $user The user logging in.
	 * @return void
	 */
	private static function forget_stale_grace( \WP_User $user ) {
		if ( Enforcement::REQUIRED !== Enforcement::policy( $user ) ) {
			UserState::clear_grace( $user->ID );
		}
	}

	/**
	 * Remembers a user that an application password authenticated. Those
	 * logins can't show a second step and were made on purpose.
	 *
	 * @param \WP_User $user The authenticated user.
	 * @return void
	 */
	public static function note_application_password( $user ) {
		if ( $user instanceof \WP_User ) {
			self::$app_password_users[ $user->ID ] = true;
		}
	}

	/**
	 * A login that isn't a person at a login form: an application password,
	 * XML-RPC or an interim re-login. The code step carries the interim flag
	 * in its form, so it is in the request too.
	 *
	 * @param \WP_User $user The user who logged in.
	 * @return bool
	 */
	public static function is_side_login( \WP_User $user ) {
		if ( isset( self::$app_password_users[ $user->ID ] ) || 'xmlrpc' === self::context() ) {
			return true;
		}
		return ! empty( $_REQUEST['interim-login'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read as a flag only.
	}

	/**
	 * The authenticate filter at priority 50, after the password checks and
	 * the role policy. Refuses the logins that can't show a second step: an
	 * XML-RPC login while the setting blocks it, and AJAX, REST or WP-CLI
	 * logins. A browser login is left alone here, so the rest of the chain
	 * still runs, and finish_authenticate() sends it to the second step.
	 *
	 * @param \WP_User|\WP_Error|null $user     Authentication result.
	 * @param string                  $username Username or email typed.
	 * @param string                  $password Password typed.
	 * @return \WP_User|\WP_Error|null
	 */
	public static function filter_authenticate( $user, $username = '', $password = '' ) {
		unset( $username );
		if ( ! self::is_first_factor( $user, $password ) || ! self::applies( $user ) ) {
			return $user;
		}
		return 'browser' === self::context() ? $user : self::outside_browser( $user );
	}

	/**
	 * The authenticate filter at the end of the chain. A browser login that
	 * is still a WP_User here starts a pending login and goes to the second
	 * step. The request ends in the redirect, so wp_signon() never sets the
	 * auth cookie and never fires wp_login.
	 *
	 * @param \WP_User|\WP_Error|null $user     Authentication result.
	 * @param string                  $username Username or email typed.
	 * @param string                  $password Password typed.
	 * @return \WP_User|\WP_Error|null
	 */
	public static function finish_authenticate( $user, $username = '', $password = '' ) {
		unset( $username );
		if ( ! self::is_first_factor( $user, $password ) ) {
			return $user;
		}
		self::forget_stale_grace( $user );
		if ( ! self::applies( $user ) ) {
			return $user;
		}
		// Asked again here, so a user that a later authenticate filter returned can't skip the step.
		if ( 'browser' !== self::context() ) {
			return self::outside_browser( $user );
		}

		$url = self::begin( $user, self::PURPOSE, self::carry_from_login() );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		self::redirect( $url );

		// Reached only when a redirector set for tests returns instead of ending the request.
		return new \WP_Error( 'happyaccess_twostep_pending', esc_html__( 'Finish logging in with your two-step login code.', 'happyaccess' ) );
	}

	/**
	 * The hand-off after a passwordless code or link. The email code already
	 * proved access to the mailbox, so a user without the app logs in
	 * directly, even with backup codes left. Only the app adds a step, and
	 * that step offers the app and backup codes only. A required user with
	 * no method goes to the setup screen, because the email code proves the
	 * inbox but setup still has to happen.
	 *
	 * @param \WP_User $user     The user.
	 * @param bool     $remember Whether to keep the session.
	 * @param string   $redirect Where the user goes after the step.
	 * @return string|null The step URL, or null to log in now.
	 */
	public static function after_passwordless( \WP_User $user, $remember, $redirect ) {
		self::forget_stale_grace( $user );
		if ( ! self::applies( $user ) ) {
			return null;
		}
		self::check_secret( $user );
		if ( ! self::needs_setup( $user ) && ! UserState::app_enabled( $user->ID ) ) {
			return null;
		}

		$carry = array( 'redirect_to' => self::valid_redirect( $redirect ) );
		if ( $remember ) {
			$carry['rememberme'] = 1;
		}
		$url = self::begin( $user, self::PURPOSE_AFTER_PASSWORDLESS, $carry );
		return is_wp_error( $url ) ? self::login_url( 'unavailable', array() ) : $url;
	}

	/**
	 * Notes a password reset of a user who needs the second step, or setup.
	 * Until the step passes, no auth cookie goes out for them in this
	 * request, whatever logs them in after the reset.
	 *
	 * Hooked to password_reset and after_password_reset. WooCommerce 9.4 to
	 * 10.8 fire only password_reset, before the new password is saved, and
	 * 10.9 added after_password_reset. Whether WooCommerce then logs the
	 * user in depends on its version: up to 11.1 it always does, and 11.2
	 * only does for a user who was already logged in as that user. So the
	 * step starts when the auth cookie is made, in drop_reset_session(),
	 * not here.
	 *
	 * @param \WP_User $user The user whose password is reset.
	 * @return void
	 */
	public static function note_reset( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		self::forget_stale_grace( $user );
		if ( ! self::applies( $user ) || isset( self::$reset_users[ (int) $user->ID ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read as a flag only; WooCommerce checked its reset nonce before the reset.
		self::$reset_users[ (int) $user->ID ] = isset( $_POST['wc_reset_password'] ) && function_exists( 'wc_get_page_permalink' );
	}

	/**
	 * When code logs in a user right after their password reset and before
	 * the second step, drops the session that wp_set_auth_cookie() just made
	 * and the current user. hold_reset_cookie() keeps the cookie back.
	 *
	 * After WooCommerce's My Account reset form, the user goes to the step,
	 * or setup, from here and the request ends. Core's reset on wp-login.php
	 * never logs in, and other code gets no session and no redirect.
	 *
	 * @param string $cookie     Cookie value.
	 * @param int    $expire     Cookie expiry.
	 * @param int    $expiration Session expiry.
	 * @param int    $user_id    User id.
	 * @param string $scheme     Cookie scheme.
	 * @param string $token      Session token.
	 * @return void
	 */
	public static function drop_reset_session( $cookie, $expire = 0, $expiration = 0, $user_id = 0, $scheme = '', $token = '' ) {
		unset( $cookie, $expire, $expiration, $scheme );
		$user_id = (int) $user_id;
		if ( ! isset( self::$reset_users[ $user_id ] ) ) {
			return;
		}
		if ( is_string( $token ) && '' !== $token ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy( $token );
		}
		if ( get_current_user_id() === $user_id ) {
			wp_set_current_user( 0 );
		}

		$user = get_userdata( $user_id );
		if ( true === self::$reset_users[ $user_id ] && $user instanceof \WP_User ) {
			// One redirect per reset, even if the redirect returns.
			self::$reset_users[ $user_id ] = false;
			self::woo_reset_step( $user );
		}
	}

	/**
	 * Sends a user that WooCommerce logs in after its My Account reset to
	 * the second step, or setup, and ends the request. WooCommerce's own
	 * steps after the login run first: the reset cookie is cleared, the
	 * owner hears about the new password and its reset action fires.
	 *
	 * @param \WP_User $user The user whose password was reset.
	 * @return void
	 */
	private static function woo_reset_step( \WP_User $user ) {
		$url = self::begin(
			$user,
			self::PURPOSE,
			array(
				'from'        => 'woo',
				'redirect_to' => (string) wc_get_page_permalink( 'myaccount' ),
			)
		);
		if ( is_wp_error( $url ) ) {
			// The password is saved, so the user logs in again once the step can run.
			$url = self::login_url( 'unavailable', array() );
		}

		if ( class_exists( 'WC_Shortcode_My_Account' ) && ! headers_sent() ) {
			\WC_Shortcode_My_Account::set_reset_password_cookie();
		}
		if ( ! apply_filters( 'woocommerce_disable_password_change_notification', false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
			wp_password_change_notification( $user );
		}
		do_action( 'woocommerce_customer_reset_password', $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook, so its listeners see the reset.

		self::redirect( $url );
	}

	/**
	 * Holds back the auth cookie of a user whose password was reset in this
	 * request and who has not passed the second step.
	 *
	 * @param bool $send       Whether to send the cookies.
	 * @param int  $expire     Cookie expiry.
	 * @param int  $expiration Session expiry.
	 * @param int  $user_id    User id.
	 * @return bool
	 */
	public static function hold_reset_cookie( $send, $expire = 0, $expiration = 0, $user_id = 0 ) {
		unset( $expire, $expiration );
		return isset( self::$reset_users[ (int) $user_id ] ) ? false : $send;
	}

	/**
	 * Step callback for the two-step screen.
	 *
	 * @return void
	 */
	public static function run_step() {
		// phpcs:ignore WordPress.Security.NonceVerification -- handle() verifies the nonce on every POST, the code and the email send.
		self::respond( self::handle( self::request_method(), wp_unslash( $_GET ), wp_unslash( $_POST ), wp_unslash( $_COOKIE ) ) );
	}

	/**
	 * Sends a step response, with the interim login layout when it applies.
	 * The setup step uses it too.
	 *
	 * @param array $response Response from a handle() method.
	 * @return void
	 */
	public static function respond( array $response ) {
		// wp-login.php sets this global after the login_form_ action, so the step sets it for the login layout.
		if ( 'interim' === $response['type'] ) {
			$GLOBALS['interim_login'] = 'success'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's interim login flag, read by login_header().
			$response                 = array(
				'type'    => 'render',
				'title'   => '',
				'body'    => '',
				'errors'  => null,
				'message' => $response['message'],
			);
		} elseif ( ! empty( $_REQUEST['interim-login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Layout only.
			$GLOBALS['interim_login'] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's interim login flag, read by login_header().
		}

		Screens::respond( $response );
	}

	/**
	 * The two-step screen: the code form on GET, the check on POST.
	 *
	 * @param string $method  HTTP method.
	 * @param array  $get     Unslashed $_GET.
	 * @param array  $post    Unslashed $_POST.
	 * @param array  $cookies Unslashed $_COOKIE.
	 * @return array Response for Screens::respond(), or type interim with message.
	 */
	public static function handle( $method, array $get, array $post, array $cookies ) {
		$is_post = 'POST' === strtoupper( (string) $method );
		$input   = $is_post ? $post : $get;
		$carry   = self::carry_from( $input );

		if ( ! self::db_ready() ) {
			return self::unavailable();
		}

		$login = self::pending_login( $cookies );
		if ( null === $login ) {
			return self::back_to_login( 'expired', $carry );
		}
		$pending = $login['pending'];
		$user    = $login['user'];

		// A user with no method would get the email code here, which would skip setup.
		if ( self::needs_setup( $user ) ) {
			return array(
				'type' => 'redirect',
				'url'  => self::step_url( $carry, SetupSteps::STEP ),
			);
		}

		$allowed = self::allowed_methods( $user, $pending['purpose'] );
		if ( array() === $allowed ) {
			return self::back_to_login( 'expired', $carry );
		}
		$chosen          = self::text( $input, 'method' );
		$carry['method'] = in_array( $chosen, $allowed, true ) ? $chosen : $allowed[0];

		if ( ! $is_post ) {
			$sent = 'email' === $carry['method'] ? self::text( $get, 'sent' ) : '';
			return self::code_screen( $allowed, $carry, null, $sent );
		}

		// A POST, so the send needs the pending-login cookie, which a cross-site form doesn't carry.
		if ( '1' === self::text( $post, 'send' ) ) {
			return self::handle_send( $user, $pending, $allowed, $carry, $post );
		}

		if ( ! self::nonce_ok( $post, 'happyaccess_twostep' ) ) {
			return self::code_screen( $allowed, $carry, new \WP_Error( 'expired_page', esc_html__( 'This page expired. Try again.', 'happyaccess' ) ) );
		}

		$attempts = self::gate( $pending['id'], $user );
		if ( is_wp_error( $attempts ) ) {
			return self::code_screen( $allowed, $carry, $attempts );
		}
		if ( $attempts < 1 ) {
			return self::back_to_login( 'expired', $carry );
		}

		$code = self::text( $post, 'pwd' );
		if ( 'backup' === $carry['method'] ) {
			return self::finish_backup( $user, $pending, $allowed, $carry, $code, $attempts );
		}
		if ( ! self::check_code( $user, $carry['method'], $code ) ) {
			return self::fail( $user, $pending, $allowed, $carry, $attempts );
		}

		if ( ! self::consume( $pending['id'] ) ) {
			return self::back_to_login( 'expired', $carry );
		}
		return self::succeed( $user, $pending, $carry );
	}

	/**
	 * Checks a backup code and logs in. The pending login is used up after
	 * the code matched but before the code is, so a login that can't finish
	 * never costs a backup code. If a parallel request used the same code
	 * first, the login stops there.
	 *
	 * @param \WP_User $user     The user.
	 * @param array    $pending  Pending login row.
	 * @param string[] $allowed  Methods offered.
	 * @param array    $carry    Carried values, with method.
	 * @param string   $code     Typed code.
	 * @param int      $attempts Checks counted on the pending login.
	 * @return array
	 */
	private static function finish_backup( \WP_User $user, array $pending, array $allowed, array $carry, $code, $attempts ) {
		$hash = '' === $code ? '' : BackupCodes::match_code( $user->ID, $code );
		if ( '' === $hash ) {
			return self::fail( $user, $pending, $allowed, $carry, $attempts );
		}
		if ( ! self::consume( $pending['id'] ) || ! BackupCodes::remove_code( $user->ID, $hash ) ) {
			return self::back_to_login( 'expired', $carry );
		}
		return self::succeed( $user, $pending, $carry );
	}

	/**
	 * Adds the notice for a user sent back to the normal login screen.
	 *
	 * @param \WP_Error $errors Login screen errors.
	 * @return \WP_Error
	 */
	public static function filter_login_errors( $errors ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the value only picks a message.
		return self::login_errors( $errors, wp_unslash( $_GET ) );
	}

	/**
	 * The notice for a reason in the query args, added to the login errors.
	 *
	 * @param \WP_Error $errors Login screen errors.
	 * @param array     $get    Unslashed $_GET.
	 * @return \WP_Error
	 */
	public static function login_errors( $errors, array $get ) {
		if ( ! $errors instanceof \WP_Error ) {
			$errors = new \WP_Error();
		}
		$reason   = sanitize_key( self::text( $get, self::LOGIN_ARG ) );
		$messages = array(
			'expired'     => __( 'Your login took too long. Log in again.', 'happyaccess' ),
			'locked'      => __( 'Too many wrong codes. Log in again.', 'happyaccess' ),
			'unavailable' => self::updating_error()->get_error_message(),
		);
		if ( isset( $messages[ $reason ] ) ) {
			$errors->add( 'happyaccess_twostep_' . $reason, esc_html( $messages[ $reason ] ), 'expired' === $reason ? 'message' : '' );
		}
		return $errors;
	}

	/**
	 * Shows the backup code count once after a login that used one of the
	 * last codes: as an admin notice, or on My Account with a link to where
	 * the user makes new ones.
	 *
	 * @return void
	 */
	public static function print_backup_notice() {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}
		$name = self::NOTICE_TRANSIENT . $user_id;
		// Transient calls can add or delete an option, so they run inside the bypass.
		$left = Internal::run(
			static function () use ( $name ) {
				return get_transient( $name );
			}
		);
		if ( false === $left ) {
			return;
		}
		Internal::run(
			static function () use ( $name ) {
				delete_transient( $name );
			}
		);

		$left = (int) $left;
		if ( doing_action( 'woocommerce_account_content' ) ) {
			echo '<div class="woocommerce-info">' . sprintf(
				/* translators: 1: backup codes left, 2: link to where new ones are made. */
				esc_html( _n( 'You have %1$d backup code left. Make new ones on %2$s.', 'You have %1$d backup codes left. Make new ones on %2$s.', $left, 'happyaccess' ) ),
				(int) $left,
				Profile::link_for( wp_get_current_user() ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Profile::link_for().
			) . '</div>';
			return;
		}
		$text = sprintf(
			/* translators: %d: backup codes left. */
			_n( 'You have %d backup code left. Make new ones on your profile.', 'You have %d backup codes left. Make new ones on your profile.', $left, 'happyaccess' ),
			$left
		);
		echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Sets the function that sends the browser on. Null restores
	 * wp_safe_redirect() and exit. For tests.
	 *
	 * @param callable|null $redirector Takes the URL.
	 * @return void
	 */
	public static function set_redirector( $redirector ) {
		self::$redirector = is_callable( $redirector ) ? $redirector : null;
	}

	/**
	 * Sets the request kind: browser, xmlrpc or api. Null reads the request.
	 * For tests.
	 *
	 * @param string|null $context Request kind.
	 * @return void
	 */
	public static function set_context( $context ) {
		self::$context = in_array( $context, array( 'browser', 'xmlrpc', 'api' ), true ) ? $context : null;
	}

	/**
	 * Clears the request state and the test overrides.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$app_password_users = array();
		self::$reset_users        = array();
		self::$redirector         = null;
		self::$context            = null;
	}

	/**
	 * Whether an authenticate result is a real first factor: a user, a typed
	 * password, and not an application password. The cookie check passes
	 * no password, so it never counts.
	 *
	 * @param mixed $user     Authentication result.
	 * @param mixed $password Password typed.
	 * @return bool
	 */
	private static function is_first_factor( $user, $password ) {
		return $user instanceof \WP_User
			&& is_string( $password )
			&& '' !== $password
			&& ! isset( self::$app_password_users[ $user->ID ] );
	}

	/**
	 * The result for a two-step user logging in where no second step can be
	 * shown: XML-RPC passes only while the setting allows it, and AJAX, REST
	 * and WP-CLI logins are refused.
	 *
	 * @param \WP_User $user The user.
	 * @return \WP_User|\WP_Error
	 */
	private static function outside_browser( \WP_User $user ) {
		if ( 'xmlrpc' === self::context() ) {
			if ( ! Settings::shared( 'two_step.block_xmlrpc' ) ) {
				return $user;
			}
			return new \WP_Error( 'happyaccess_twostep_xmlrpc', esc_html__( "This account uses two-step login, so it can't log in over XML-RPC. Use an application password instead.", 'happyaccess' ) );
		}
		if ( self::needs_setup( $user ) ) {
			return new \WP_Error( 'happyaccess_twostep_required', esc_html__( 'Your role needs two-step login. Log in on the login page to set it up.', 'happyaccess' ) );
		}
		return new \WP_Error( 'happyaccess_twostep_required', esc_html__( 'This account uses two-step login. Log in on the login page.', 'happyaccess' ) );
	}

	/**
	 * What kind of request is logging in.
	 *
	 * @return string browser, xmlrpc or api.
	 */
	private static function context() {
		if ( null !== self::$context ) {
			return self::$context;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_is_json_request() ) {
			return 'api';
		}
		return 'browser';
	}

	/**
	 * Starts a pending login and builds the step URL. When the first method
	 * is email, the code is sent now. A user who needs setup goes to the
	 * setup screen instead, and this login counts toward the grace period
	 * here, once, not on every view of the screen.
	 *
	 * @param \WP_User $user    The user.
	 * @param string   $purpose Challenge purpose.
	 * @param array    $carry   Values the step carries through its form.
	 * @return string|\WP_Error
	 */
	private static function begin( \WP_User $user, $purpose, array $carry ) {
		self::check_secret( $user );
		$pending_id = self::start( $user, $purpose );
		if ( is_wp_error( $pending_id ) ) {
			return $pending_id;
		}

		if ( self::needs_setup( $user ) ) {
			Enforcement::note_login( $user->ID );
			return self::step_url( $carry, SetupSteps::STEP );
		}

		$allowed         = self::allowed_methods( $user, $purpose );
		$carry['method'] = array() === $allowed ? '' : $allowed[0];
		if ( 'email' === $carry['method'] ) {
			$sent = self::send_email( $user, $pending_id );
			if ( true === $sent ) {
				$carry['sent'] = 1;
			} elseif ( is_wp_error( $sent ) && 'locked' === $sent->get_error_code() ) {
				// The send limit is hit, but the codes sent before still work.
				$carry['sent'] = 'limit';
			}
		}
		return self::step_url( $carry );
	}

	/**
	 * When the user's app secret can't be opened: one log row for the user
	 * and, once a week at most, an email to the owner. The step itself
	 * offers email and backup codes in its place.
	 *
	 * @param \WP_User $user The user logging in.
	 * @return void
	 */
	private static function check_secret( \WP_User $user ) {
		if ( ! UserState::secret_unreadable( $user->ID ) || ! UserState::note_unreadable( $user->ID ) ) {
			return;
		}
		// Transient calls can add or delete an option, so they run inside the bypass.
		$alerted = Internal::run(
			static function () {
				return get_transient( self::SECRET_ALERT_TRANSIENT );
			}
		);
		if ( $alerted ) {
			return;
		}
		Internal::run(
			static function () {
				set_transient( self::SECRET_ALERT_TRANSIENT, 1, WEEK_IN_SECONDS );
			}
		);
		Mailer::send(
			(string) get_option( 'admin_email' ),
			__( "Authenticator app codes can't be checked", 'happyaccess' ),
			'twostep-secret',
			array()
		);
	}

	/**
	 * Stores a pending login and sends its cookie.
	 *
	 * @param \WP_User $user    The user.
	 * @param string   $purpose Challenge purpose.
	 * @return int|\WP_Error Row id.
	 */
	private static function start( \WP_User $user, $purpose ) {
		global $wpdb;

		// Without the schema or a saved site key the pending login could not be found again.
		if ( ! self::db_ready() || ! Secrets::is_persisted() ) {
			return self::updating_error( true );
		}

		$key = Codes::link_key();
		$now = Clock::now();
		$ip  = ClientIp::get();
		if ( Settings::get( 'privacy.anonymize_ip', false ) ) {
			$ip = ClientIp::anonymize( $ip );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$stored = $wpdb->insert(
			Installer::table( 'challenges' ),
			array(
				'user_id'    => (int) $user->ID,
				'purpose'    => $purpose,
				'link_hash'  => Codes::hash_key( $key ),
				'attempts'   => 0,
				'ip'         => substr( $ip, 0, 45 ),
				'created_at' => Clock::mysql( $now ),
				'expires_at' => Clock::mysql( $now + self::LIFETIME ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $stored ) {
			return self::updating_error( true );
		}

		self::send_cookie( self::COOKIE, $key, $now + self::LIFETIME );
		return (int) $wpdb->insert_id;
	}

	/**
	 * The methods the step offers, in the order app, email, backup. After a
	 * passwordless login email is left out, because it was the first step.
	 * A user with no app, or with an app secret that can't be opened, can
	 * always ask for an email code.
	 *
	 * @param \WP_User $user    The user.
	 * @param string   $purpose Challenge purpose.
	 * @return string[]
	 */
	private static function allowed_methods( \WP_User $user, $purpose ) {
		$methods = UserState::methods( $user->ID );
		if ( self::PURPOSE_AFTER_PASSWORDLESS === $purpose ) {
			return array_values( array_diff( $methods, array( 'email' ) ) );
		}
		if ( ! in_array( 'email', $methods, true ) && ! UserState::app_enabled( $user->ID ) ) {
			$methods = array_merge( array( 'email' ), $methods );
		}
		return $methods;
	}

	/**
	 * Values from the first login form the step carries: a validated
	 * redirect, remember me and interim login. For a WooCommerce form the
	 * target is worked out the way WooCommerce does after its own login.
	 *
	 * @return array
	 */
	private static function carry_from_login() {
		// phpcs:disable WordPress.Security.NonceVerification -- Core's login form has no nonce; WooCommerce checked its own before calling wp_signon().
		$post    = wp_unslash( $_POST );
		$request = wp_unslash( $_REQUEST );
		// phpcs:enable WordPress.Security.NonceVerification

		$carry = array();
		if ( self::is_woo_form( $post ) ) {
			$carry['from']        = 'woo';
			$carry['redirect_to'] = self::woo_target( $post );
		} else {
			$carry['redirect_to'] = self::valid_redirect( self::text( $request, 'redirect_to' ) );
		}
		if ( ! empty( $post['rememberme'] ) ) {
			$carry['rememberme'] = 1;
		}
		if ( ! empty( $request['interim-login'] ) ) {
			$carry['interim-login'] = 1;
		}
		return $carry;
	}

	/**
	 * The carried values from the step's own query or form: a validated
	 * redirect, remember me, interim login and the WooCommerce marker. The
	 * setup step reads them the same way.
	 *
	 * @param array $input Unslashed $_GET or $_POST.
	 * @return array
	 */
	public static function carry_from( array $input ) {
		$carry = array( 'redirect_to' => self::valid_redirect( self::text( $input, 'redirect_to' ) ) );
		if ( ! empty( $input['rememberme'] ) ) {
			$carry['rememberme'] = 1;
		}
		if ( ! empty( $input['interim-login'] ) ) {
			$carry['interim-login'] = 1;
		}
		if ( 'woo' === self::text( $input, 'from' ) ) {
			$carry['from'] = 'woo';
		}
		return $carry;
	}

	/**
	 * Whether the posted form is WooCommerce's login form (My Account or checkout).
	 *
	 * @param array $post Unslashed $_POST.
	 * @return bool
	 */
	private static function is_woo_form( array $post ) {
		return function_exists( 'wc_get_page_permalink' ) && isset( $post['login'], $post['username'], $post['password'] );
	}

	/**
	 * Where WooCommerce would send the shopper: the form's redirect field,
	 * else the page the form was on, else My Account. Same order as
	 * WC_Form_Handler::process_login().
	 *
	 * @param array $post Unslashed $_POST.
	 * @return string
	 */
	private static function woo_target( array $post ) {
		$account = (string) wc_get_page_permalink( 'myaccount' );
		$target  = self::text( $post, 'redirect' );
		if ( '' === $target && function_exists( 'wc_get_raw_referer' ) ) {
			$target = (string) wc_get_raw_referer();
		}
		if ( '' === $target ) {
			$target = $account;
		}
		$target = remove_query_arg( array( 'wc_error', 'password-reset' ), $target );
		return (string) wp_validate_redirect( $target, $account );
	}

	/**
	 * URL of the code step, or another two-step screen, with the carried values.
	 *
	 * @param array  $carry Carried values.
	 * @param string $step  Step name.
	 * @return string
	 */
	public static function step_url( array $carry, $step = self::STEP ) {
		$args = array();
		foreach ( array( 'method', 'sent', 'redirect_to', 'rememberme', 'interim-login', 'from' ) as $key ) {
			if ( isset( $carry[ $key ] ) && is_scalar( $carry[ $key ] ) && '' !== (string) $carry[ $key ] ) {
				$args[ $key ] = (string) $carry[ $key ];
			}
		}
		return Router::url( $step, $args );
	}

	/**
	 * The normal login URL with a reason for the notice.
	 *
	 * @param string $reason expired, locked or unavailable.
	 * @param array  $carry  Carried values.
	 * @return string
	 */
	private static function login_url( $reason, array $carry ) {
		$redirect = isset( $carry['redirect_to'] ) ? self::valid_redirect( $carry['redirect_to'] ) : '';
		$args     = array( self::LOGIN_ARG => $reason );
		if ( ! empty( $carry['interim-login'] ) ) {
			$args['interim-login'] = '1';
		}
		return add_query_arg( $args, wp_login_url( $redirect ) );
	}

	/**
	 * A redirect to the normal login with a reason.
	 *
	 * @param string $reason expired or locked.
	 * @param array  $carry  Carried values.
	 * @return array
	 */
	public static function back_to_login( $reason, array $carry ) {
		return array(
			'type' => 'redirect',
			'url'  => self::login_url( $reason, $carry ),
		);
	}

	/**
	 * The "Email me a code" button: sends a code, then shows the email form.
	 *
	 * @param \WP_User $user    The user.
	 * @param array    $pending Pending login row.
	 * @param string[] $allowed Methods offered.
	 * @param array    $carry   Carried values, with method.
	 * @param array    $post    Unslashed $_POST.
	 * @return array
	 */
	private static function handle_send( \WP_User $user, array $pending, array $allowed, array $carry, array $post ) {
		if ( 'email' !== $carry['method'] ) {
			return self::code_screen( $allowed, $carry );
		}
		if ( ! self::nonce_ok( $post, 'happyaccess_twostep_send' ) ) {
			return self::code_screen( $allowed, $carry, new \WP_Error( 'expired_page', esc_html__( 'This page expired. Try again.', 'happyaccess' ) ) );
		}
		$sent = self::send_email( $user, $pending['id'] );
		if ( is_wp_error( $sent ) ) {
			return self::code_screen( $allowed, $carry, self::screen_error( $sent ) );
		}
		$carry['sent'] = 1;
		return array(
			'type' => 'redirect',
			'url'  => self::step_url( $carry ),
		);
	}

	/**
	 * Sends an email code, within the account's send limit. The setup step
	 * sends its code through here too, so both share the limit.
	 *
	 * @param \WP_User $user       The user.
	 * @param int      $pending_id Pending login id.
	 * @return true|\WP_Error Error messages are plain text.
	 */
	public static function send_email( \WP_User $user, $pending_id ) {
		$wait = RateLimiter::attempt( self::SEND_ACTION, 'account', 'u:' . $user->ID, self::SEND_LIMIT, self::LIFETIME, self::LIFETIME );
		if ( $wait > 0 ) {
			return self::locked_error( $wait );
		}
		return EmailMethod::send( $user, (int) $pending_id );
	}

	/**
	 * Checks a typed app or email code. An app code uses up its time step,
	 * so the same code can't log in twice. Backup codes go through
	 * finish_backup().
	 *
	 * @param \WP_User $user   The user.
	 * @param string   $method app or email.
	 * @param string   $code   Typed code.
	 * @return bool
	 */
	private static function check_code( \WP_User $user, $method, $code ) {
		if ( '' === $code ) {
			return false;
		}
		if ( 'app' === $method ) {
			$secret = UserState::totp_secret( $user->ID );
			if ( null === $secret ) {
				return false;
			}
			$step = Totp::match( $secret, $code, Clock::now(), UserState::last_step( $user->ID ) );
			return false !== $step && UserState::consume_step( $user->ID, $step );
		}
		if ( 'email' === $method ) {
			return true === EmailMethod::verify( $user->ID, $code );
		}
		return false;
	}

	/**
	 * A wrong code: counts it on the account and the site. The try is
	 * already counted on the pending login, and the fifth one cancels it.
	 *
	 * @param \WP_User $user     The user.
	 * @param array    $pending  Pending login row.
	 * @param string[] $allowed  Methods offered.
	 * @param array    $carry    Carried values, with method.
	 * @param int      $attempts Checks counted on the pending login.
	 * @return array
	 */
	private static function fail( \WP_User $user, array $pending, array $allowed, array $carry, $attempts ) {
		$cancelled = self::wrong_code( $user, $pending, $carry, $attempts );
		if ( null !== $cancelled ) {
			return $cancelled;
		}
		return self::code_screen( $allowed, $carry, self::invalid_code_error() );
	}

	/**
	 * Counts one wrong code on the account. The tenth inside the window
	 * pauses the account's code step, emails the user and writes a log row.
	 * The profile re-check counts its wrong codes here too. Only a password
	 * reset or the end of the window clears the count; a right password or
	 * code doesn't.
	 *
	 * @param \WP_User $user The user the code was for.
	 * @return void
	 */
	public static function count_account_wrong_code( \WP_User $user ) {
		$subject = self::account_subject( $user->ID );
		RateLimiter::hit( self::CODE_ACTION, 'account', $subject );
		if ( self::account_wait( $user->ID ) > 0 ) {
			return;
		}
		if ( RateLimiter::count( self::CODE_ACTION, 'account', $subject, self::ACCOUNT_WINDOW ) < self::ACCOUNT_CODE_CAP ) {
			return;
		}
		// The pause is one row on its own action, and the next pause needs ten new wrong codes.
		RateLimiter::hit( self::LOCK_ACTION, 'account', $subject );
		RateLimiter::clear( self::CODE_ACTION, 'account', $subject );
		self::account_lock_alert( $user, self::account_wait( $user->ID ) );
	}

	/**
	 * Seconds until the account's code step takes codes again, 0 when it
	 * isn't paused. Each pause in the last day doubles the length.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function account_wait( $user_id ) {
		$subject = self::account_subject( $user_id );
		$pauses  = RateLimiter::count( self::LOCK_ACTION, 'account', $subject, DAY_IN_SECONDS );
		if ( $pauses < 1 ) {
			return 0;
		}
		$length = (int) min( self::ACCOUNT_LOCK_MAX, self::ACCOUNT_LOCK * pow( 2, min( 10, $pauses - 1 ) ) );
		return RateLimiter::retry_after( self::LOCK_ACTION, 'account', $subject, 1, $length, $length );
	}

	/**
	 * Ends a pause and forgets the wrong codes after a password reset: the
	 * person who reset it has the mailbox, and the old password is gone.
	 *
	 * @param \WP_User $user The user whose password was reset.
	 * @return void
	 */
	public static function end_account_lock( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$subject = self::account_subject( $user->ID );
		RateLimiter::clear( self::CODE_ACTION, 'account', $subject );
		RateLimiter::clear( self::LOCK_ACTION, 'account', $subject );
	}

	/**
	 * Rate limit subject of an account.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function account_subject( $user_id ) {
		return 'u:' . (int) $user_id;
	}

	/**
	 * Counts one wrong code on the site and tells the owner once an hour when
	 * there are many. It never locks anyone.
	 *
	 * @return void
	 */
	private static function count_site_wrong_code() {
		// Only a wrong code counts on the site scope, and it is never cleared.
		RateLimiter::hit( self::CODE_ACTION, 'site', 'site' );
		if ( RateLimiter::count( self::CODE_ACTION, 'site', 'site', self::SITE_WINDOW ) >= self::SITE_CODE_CAP ) {
			self::site_alert();
		}
	}

	/**
	 * Counts a wrong code on the account and the site and logs it. The try is already
	 * counted on the pending login, and the fifth one cancels it. The setup
	 * step uses this for its codes too.
	 *
	 * @param \WP_User $user     The user.
	 * @param array    $pending  Pending login row.
	 * @param array    $carry    Carried values, with method.
	 * @param int      $attempts Checks counted on the pending login.
	 * @return array|null The redirect to the login when the pending login was cancelled, else null.
	 */
	public static function wrong_code( \WP_User $user, array $pending, array $carry, $attempts ) {
		self::count_account_wrong_code( $user );
		self::count_site_wrong_code();

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			self::cancel( $pending['id'] );
			// The user starts again from the password, so this login's tries no longer hold the IP back. Other tries stay.
			RateLimiter::forget( self::CODE_ACTION, 'ip', RateLimiter::ip_subject(), self::MAX_ATTEMPTS, $pending['created_at'] );
			UserState::log_locked( $user->ID, $carry['method'] );
			self::send_cookie( self::COOKIE, '', Clock::now() - HOUR_IN_SECONDS );
			return self::back_to_login( 'locked', $carry );
		}

		UserState::log_failed( $user->ID, $carry['method'] );
		return null;
	}

	/**
	 * The error for a wrong code, made safe for the login screen. One message
	 * for every failure, so it says nothing about which method or why.
	 *
	 * @return \WP_Error
	 */
	public static function invalid_code_error() {
		return new \WP_Error( 'happyaccess_invalid_code', esc_html__( "That code didn't work. Try again.", 'happyaccess' ) );
	}

	/**
	 * The checks before any code is looked at: the IP limit, the account's
	 * pause, then one try counted on the pending login, so parallel requests
	 * can't check more than MAX_ATTEMPTS codes.
	 *
	 * @param int      $pending_id Pending login id.
	 * @param \WP_User $user       The user of the pending login.
	 * @return int|\WP_Error Checks counted, this one included, or 0 when the
	 *                       pending login is gone. A lock error is made safe for the screen.
	 */
	public static function gate( $pending_id, \WP_User $user ) {
		$wait = self::ip_wait();
		if ( $wait > 0 ) {
			return self::screen_error( self::locked_error( $wait ) );
		}
		$wait = self::account_wait( $user->ID );
		if ( $wait > 0 ) {
			return self::screen_error( self::locked_error( $wait ) );
		}
		return self::count_check( $pending_id );
	}

	/**
	 * Uses up the pending login and logs the user in. The setup step calls
	 * this once setup is done, or when the user puts it off.
	 *
	 * @param \WP_User $user    The user.
	 * @param array    $pending Pending login row.
	 * @param array    $carry   Carried values, with method.
	 * @param bool     $passed  Whether a second step passed, which is logged.
	 * @return array
	 */
	public static function finish( \WP_User $user, array $pending, array $carry, $passed = true ) {
		if ( ! self::consume( $pending['id'] ) ) {
			return self::back_to_login( 'expired', $carry );
		}
		return self::succeed( $user, $pending, $carry, $passed );
	}

	/**
	 * Logs the user in after the second step passed. The log row is written
	 * before the wp_login action, so a listener that exits can't skip it.
	 *
	 * @param \WP_User $user    The user.
	 * @param array    $pending Pending login row, used up.
	 * @param array    $carry   Carried values, with method.
	 * @param bool     $passed  Whether a second step passed. False when setup was put off.
	 * @return array
	 */
	private static function succeed( \WP_User $user, array $pending, array $carry, $passed = true ) {
		unset( self::$reset_users[ (int) $user->ID ] );
		RateLimiter::clear( self::CODE_ACTION, 'ip', RateLimiter::ip_subject() );
		self::send_cookie( self::COOKIE, '', Clock::now() - HOUR_IN_SECONDS );
		if ( 'backup' === $carry['method'] ) {
			self::remember_backup_notice( $user->ID );
		}
		if ( $passed ) {
			UserState::log_passed( $user->ID, $carry['method'] );
		}

		wp_set_auth_cookie( $user->ID, ! empty( $carry['rememberme'] ) );
		self::clear_reset_key( $user );
		wp_set_current_user( $user->ID );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, so login listeners see this sign-in.

		$woo = 'woo' === ( isset( $carry['from'] ) ? $carry['from'] : '' ) && function_exists( 'wc_get_page_permalink' );
		// WooCommerce adds a shopper to the site after its own login on a network.
		if ( $woo && is_multisite() && ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
			add_user_to_blog( get_current_blog_id(), $user->ID, 'customer' );
		}

		if ( ! empty( $carry['interim-login'] ) ) {
			return array(
				'type'    => 'interim',
				'message' => '<p class="message">' . esc_html__( 'You have logged in successfully.', 'happyaccess' ) . '</p>',
			);
		}

		return array(
			'type' => 'redirect',
			'url'  => self::destination( $user, $pending['purpose'], $carry, $woo ),
		);
	}

	/**
	 * Clears a pending password reset after a login, as wp_signon() does,
	 * so an old reset link stops working once the user is back in.
	 *
	 * @param \WP_User $user The user who logged in.
	 * @return void
	 */
	private static function clear_reset_key( \WP_User $user ) {
		global $wpdb;

		if ( empty( $user->user_activation_key ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same write as wp_signon().
		$wpdb->update( $wpdb->users, array( 'user_activation_key' => '' ), array( 'ID' => $user->ID ) );
		$user->user_activation_key = '';
	}

	/**
	 * Where the user goes after the step, the same way the first login would
	 * have sent them: core's wp-login.php rules, WooCommerce's rules for its
	 * forms, or the target the passwordless step already worked out.
	 *
	 * @param \WP_User $user    The user.
	 * @param string   $purpose Challenge purpose.
	 * @param array    $carry   Carried values.
	 * @param bool     $woo     Whether the login came from a WooCommerce form.
	 * @return string
	 */
	private static function destination( \WP_User $user, $purpose, array $carry, $woo ) {
		$requested = isset( $carry['redirect_to'] ) ? self::valid_redirect( $carry['redirect_to'] ) : '';

		if ( self::PURPOSE_AFTER_PASSWORDLESS === $purpose && '' !== $requested ) {
			return $requested;
		}

		if ( $woo ) {
			$account = (string) wc_get_page_permalink( 'myaccount' );
			$target  = '' !== $requested ? $requested : $account;
			// Same filter and fallback as WC_Form_Handler::process_login().
			return (string) wp_validate_redirect( apply_filters( 'woocommerce_login_redirect', $target, $user ), $account ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
		}

		// Same steps as the login case of wp-login.php.
		$redirect_to = '' !== $requested ? $requested : admin_url();
		$redirect_to = apply_filters( 'login_redirect', $redirect_to, $requested, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		if ( empty( $redirect_to ) || 'wp-admin/' === $redirect_to || admin_url() === $redirect_to ) {
			if ( is_multisite() && ! get_active_blog_for_user( $user->ID ) && ! is_super_admin( $user->ID ) ) {
				$redirect_to = user_admin_url();
			} elseif ( is_multisite() && ! $user->has_cap( 'read' ) ) {
				$redirect_to = get_dashboard_url( $user->ID );
			} elseif ( ! $user->has_cap( 'edit_posts' ) ) {
				$redirect_to = $user->has_cap( 'read' ) ? admin_url( 'profile.php' ) : home_url();
			}
		}
		return (string) $redirect_to;
	}

	/**
	 * Keeps the backup code count for the notice after login, when few are left.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private static function remember_backup_notice( $user_id ) {
		$left = BackupCodes::remaining( $user_id );
		if ( $left > self::NOTICE_AT ) {
			return;
		}
		$name = self::NOTICE_TRANSIENT . (int) $user_id;
		Internal::run(
			static function () use ( $name, $left ) {
				set_transient( $name, $left, DAY_IN_SECONDS );
			}
		);
	}

	/**
	 * The open pending login of the browser, with its user.
	 *
	 * @param array $cookies Unslashed $_COOKIE.
	 * @return array|null pending (see find_pending()), user, and hash: the
	 *                    stored hash of the cookie key, which names this login.
	 */
	public static function pending_login( array $cookies ) {
		$key     = self::pending_key( $cookies );
		$pending = self::find_pending( $key );
		$user    = null === $pending ? false : get_userdata( $pending['user_id'] );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}
		return array(
			'pending' => $pending,
			'user'    => $user,
			'hash'    => Codes::hash_key( $key ),
		);
	}

	/**
	 * The open pending login for a cookie key, or null.
	 *
	 * @param string $key Key from the cookie.
	 * @return array|null id, user_id, purpose and created_at (Unix time).
	 */
	private static function find_pending( $key ) {
		global $wpdb;

		if ( '' === $key ) {
			return null;
		}
		$table = Installer::table( 'challenges' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, purpose, created_at FROM {$table} WHERE link_hash = %s AND purpose IN ( %s, %s ) AND used_at IS NULL AND expires_at > %s AND attempts < %d LIMIT 1", Codes::hash_key( $key ), self::PURPOSE, self::PURPOSE_AFTER_PASSWORDLESS, Clock::mysql(), self::MAX_ATTEMPTS ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'         => (int) $row['id'],
			'user_id'    => (int) $row['user_id'],
			'purpose'    => (string) $row['purpose'],
			'created_at' => Clock::from_mysql( $row['created_at'] ),
		);
	}

	/**
	 * Uses up a pending login. Only the call that changes the row wins. The
	 * check that led here is already counted, so the count may be at the
	 * limit.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	private static function consume( $id ) {
		global $wpdb;

		$table = Installer::table( 'challenges' );
		$now   = Clock::mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE id = %d AND used_at IS NULL AND expires_at > %s AND attempts <= %d", $now, (int) $id, $now, self::MAX_ATTEMPTS ) );
	}

	/**
	 * Counts one check on the pending login, before the code is checked.
	 * Only a live row under the limit takes the count.
	 *
	 * @param int $id Row id.
	 * @return int Checks so far, this one included. 0 when the row took no count.
	 */
	private static function count_check( $id ) {
		global $wpdb;

		$table = Installer::table( 'challenges' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$counted = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d AND used_at IS NULL AND expires_at > %s AND attempts < %d", (int) $id, Clock::mysql(), self::MAX_ATTEMPTS ) );
		if ( 1 !== $counted ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return max( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", (int) $id ) ) );
	}

	/**
	 * Cancels a pending login after too many wrong codes.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	private static function cancel( $id ) {
		global $wpdb;

		$table = Installer::table( 'challenges' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE id = %d AND used_at IS NULL", Clock::mysql(), (int) $id ) );
	}

	/**
	 * The code form.
	 *
	 * @param string[]       $allowed Methods offered.
	 * @param array          $carry   Carried values, with method.
	 * @param \WP_Error|null $errors  Errors to show.
	 * @param string         $sent    1 when an email code was just sent, limit when the send limit stopped one.
	 * @return array
	 */
	private static function code_screen( array $allowed, array $carry, $errors = null, $sent = '' ) {
		$method = $carry['method'];
		$labels = array(
			'app'    => __( 'Authenticator app code', 'happyaccess' ),
			'email'  => __( 'Email code', 'happyaccess' ),
			'backup' => __( 'Backup code', 'happyaccess' ),
		);

		// After an error the field points at it and takes focus, as core's login field does.
		$after = null === $errors ? '' : ' aria-describedby="login_error" autofocus';
		$body  = '<form name="happyaccess-twostep" method="post" action="' . esc_url( Router::url( self::STEP ) ) . '">';
		$body .= '<p><label for="happyaccess-ts-code">' . esc_html( isset( $labels[ $method ] ) ? $labels[ $method ] : $labels['app'] ) . '</label>';
		if ( 'backup' === $method ) {
			$body .= '<input type="text" name="pwd" id="happyaccess-ts-code" class="input" value="" size="20" maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false"' . $after . ' /></p>';
		} else {
			$body .= '<input type="text" name="pwd" id="happyaccess-ts-code" class="input" value="" size="20" maxlength="12" inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false"' . $after . ' /></p>';
		}
		$body .= '<input type="hidden" name="method" value="' . esc_attr( $method ) . '" />';
		$body .= self::carry_fields( $carry );
		$body .= wp_nonce_field( 'happyaccess_twostep', '_wpnonce', false, false );
		$body .= '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Log in', 'happyaccess' ) . '" /></p>';
		$body .= '</form>';
		$body .= self::method_links( $allowed, $carry );

		$message = '';
		if ( '1' === (string) $sent ) {
			$message = '<p class="message">' . esc_html__( 'We sent a code to your email address.', 'happyaccess' ) . '</p>';
		} elseif ( 'limit' === $sent ) {
			$message = '<p class="message">' . esc_html__( 'We already sent several codes. Check your email, or wait a few minutes and try again.', 'happyaccess' ) . '</p>';
		} elseif ( null === $errors && 'app' === $method ) {
			$message = '<p class="message">' . esc_html__( 'Enter the code from your authenticator app.', 'happyaccess' ) . '</p>';
		}

		return array(
			'type'    => 'render',
			'title'   => __( 'Two-step login', 'happyaccess' ),
			'body'    => $body,
			'errors'  => $errors,
			'message' => $message,
		);
	}

	/**
	 * The screen while the schema is updating.
	 *
	 * @return array
	 */
	public static function unavailable() {
		return self::login_screen( '', new \WP_Error( 'updating', esc_html( self::updating_error()->get_error_message() ) ) );
	}

	/**
	 * A login-styled screen with only a notice, for when the step can't run.
	 *
	 * @param string    $body   Body markup.
	 * @param \WP_Error $errors Errors to show.
	 * @return array
	 */
	private static function login_screen( $body, \WP_Error $errors ) {
		return array(
			'type'    => 'render',
			'title'   => __( 'Two-step login', 'happyaccess' ),
			'body'    => $body . '<p id="nav"><a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'Log in again', 'happyaccess' ) . '</a></p>',
			'errors'  => $errors,
			'message' => '',
		);
	}

	/**
	 * Hidden fields for the carried values.
	 *
	 * @param array $carry Carried values.
	 * @return string
	 */
	public static function carry_fields( array $carry ) {
		$html = '';
		if ( ! empty( $carry['redirect_to'] ) ) {
			$html .= '<input type="hidden" name="redirect_to" value="' . esc_attr( $carry['redirect_to'] ) . '" />';
		}
		foreach ( array( 'rememberme', 'interim-login' ) as $flag ) {
			if ( ! empty( $carry[ $flag ] ) ) {
				$html .= '<input type="hidden" name="' . esc_attr( $flag ) . '" value="1" />';
			}
		}
		if ( isset( $carry['from'] ) && 'woo' === $carry['from'] ) {
			$html .= '<input type="hidden" name="from" value="woo" />';
		}
		return $html;
	}

	/**
	 * Links to the other methods the user has, in one nav container. Asking
	 * for an email code sends mail, so it is a POST: a button drawn as a
	 * link, tied by its form attribute to a hidden form after the container.
	 *
	 * @param string[] $allowed Methods offered.
	 * @param array    $carry   Carried values, with method.
	 * @return string
	 */
	private static function method_links( array $allowed, array $carry ) {
		$base  = $carry;
		$links = array();
		$form  = '';
		unset( $base['sent'] );

		if ( 'app' !== $carry['method'] && in_array( 'app', $allowed, true ) ) {
			$links[] = '<a href="' . esc_url( self::step_url( array_merge( $base, array( 'method' => 'app' ) ) ) ) . '">' . esc_html__( 'Use your authenticator app', 'happyaccess' ) . '</a>';
		}
		if ( in_array( 'email', $allowed, true ) ) {
			$label   = 'email' === $carry['method'] ? __( 'Send a new code', 'happyaccess' ) : __( 'Email me a code instead', 'happyaccess' );
			$links[] = '<button type="submit" form="happyaccess-ts-send" class="button-link">' . esc_html( $label ) . '</button>';
			$form   .= '<form id="happyaccess-ts-send" method="post" action="' . esc_url( Router::url( self::STEP ) ) . '" hidden>';
			$form   .= '<input type="hidden" name="method" value="email" /><input type="hidden" name="send" value="1" />';
			$form   .= self::carry_fields( $base );
			$form   .= self::nonce_input( 'happyaccess_twostep_send' );
			$form   .= '</form>';
		}
		if ( 'backup' !== $carry['method'] && in_array( 'backup', $allowed, true ) ) {
			$links[] = '<a href="' . esc_url( self::step_url( array_merge( $base, array( 'method' => 'backup' ) ) ) ) . '">' . esc_html__( 'Use a backup code', 'happyaccess' ) . '</a>';
		}
		return self::nav( $links ) . $form;
	}

	/**
	 * A nonce field without an id. wp_nonce_field() gives every form the
	 * same id, and the screen has more than one form.
	 *
	 * @param string $action Nonce action.
	 * @return string
	 */
	public static function nonce_input( $action ) {
		return '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	}

	/**
	 * The one nav container under a two-step form: each link on its own line.
	 * The setup step uses it too.
	 *
	 * @param string[] $links Link or button markup, already escaped.
	 * @return string
	 */
	public static function nav( array $links ) {
		if ( array() === $links ) {
			return '';
		}
		return '<p id="nav" class="happyaccess-ts-methods">' . implode( '<br />', $links ) . '</p>';
	}

	/**
	 * Sets a two-step cookie, the pending login or the device id: HttpOnly,
	 * SameSite Lax, Secure on HTTPS. The pre-filter lets tests and hosts
	 * that manage headers themselves take over.
	 *
	 * @param string $name    Cookie name.
	 * @param string $value   Value. Empty with a past time removes it.
	 * @param int    $expires Unix time.
	 * @return void
	 */
	public static function send_cookie( $name, $value, $expires ) {
		$options = array(
			'expires'  => (int) $expires,
			'path'     => COOKIEPATH,
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);

		/**
		 * Lets code take over sending a two-step cookie.
		 *
		 * @param bool   $handled Return true when the cookie was sent elsewhere.
		 * @param string $name    Cookie name.
		 * @param string $value   Cookie value.
		 * @param array  $options expires, path, domain, secure, httponly and samesite.
		 */
		if ( apply_filters( 'happyaccess_twostep_send_cookie', false, $name, $value, $options ) ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		setcookie( $name, $value, $options );
	}

	/**
	 * Sends the browser to a URL and ends the request.
	 *
	 * @param string $url URL.
	 * @return void
	 */
	private static function redirect( $url ) {
		if ( null !== self::$redirector ) {
			call_user_func( self::$redirector, $url );
			return;
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Counts one try on the IP scope and returns the seconds to wait.
	 *
	 * @return int
	 */
	private static function ip_wait() {
		return RateLimiter::attempt(
			self::CODE_ACTION,
			'ip',
			RateLimiter::ip_subject(),
			(int) Settings::get( 'security.max_attempts' ),
			(int) Settings::get( 'security.attempt_window' ),
			(int) Settings::get( 'security.lockout_duration' )
		);
	}

	/**
	 * Emails the site owner about many wrong codes and writes one log row, at
	 * most once per SITE_WINDOW.
	 *
	 * @return void
	 */
	private static function site_alert() {
		// Transient calls can add or delete an option, so they run inside the bypass.
		$alerted = Internal::run(
			static function () {
				return get_transient( self::SITE_ALERT_TRANSIENT );
			}
		);
		if ( $alerted ) {
			return;
		}
		Internal::run(
			static function () {
				set_transient( self::SITE_ALERT_TRANSIENT, 1, self::SITE_WINDOW );
			}
		);

		AuditLog::add(
			'twostep_site_alert',
			array(
				'feature' => 'two_step',
				'user_id' => 0,
				'summary' => __( 'Many wrong two-step login codes on the site in the last hour', 'happyaccess' ),
				'meta'    => array( 'reason' => 'site_cap' ),
			)
		);

		Mailer::send(
			(string) get_option( 'admin_email' ),
			__( 'Many wrong two-step login codes', 'happyaccess' ),
			'twostep-site-alert',
			array( 'count' => self::SITE_CODE_CAP )
		);
	}

	/**
	 * Emails the user once when their account's code step pauses, and
	 * writes one log row.
	 *
	 * @param \WP_User $user The user.
	 * @param int      $wait Seconds the pause lasts.
	 * @return void
	 */
	private static function account_lock_alert( \WP_User $user, $wait ) {
		$minutes = max( 1, (int) ceil( $wait / MINUTE_IN_SECONDS ) );
		AuditLog::add(
			'twostep_locked',
			array(
				'feature' => 'two_step',
				'user_id' => (int) $user->ID,
				'summary' => __( 'Two-step login paused for this account after too many wrong codes', 'happyaccess' ),
				'meta'    => array(
					'reason'  => 'account_cap',
					'minutes' => $minutes,
				),
			)
		);

		Mailer::send(
			(string) $user->user_email,
			__( 'Two-step login is paused for your account', 'happyaccess' ),
			'twostep-account-lock',
			array(
				'minutes'   => $minutes,
				'reset_url' => wp_lostpassword_url(),
			)
		);
	}

	/**
	 * The pending-login key from the browser's cookies, or an empty string.
	 *
	 * @param array $cookies Unslashed $_COOKIE.
	 * @return string
	 */
	private static function pending_key( array $cookies ) {
		return isset( $cookies[ self::COOKIE ] ) && is_string( $cookies[ self::COOKIE ] ) ? substr( trim( $cookies[ self::COOKIE ] ), 0, 128 ) : '';
	}

	/**
	 * A redirect target that stays on this site, or an empty string.
	 *
	 * @param string $url Target from the request.
	 * @return string
	 */
	private static function valid_redirect( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return '';
		}
		return (string) wp_validate_redirect( trim( $url ), '' );
	}

	/**
	 * Whether the schema is at the version this code expects.
	 *
	 * @return bool
	 */
	public static function db_ready() {
		return get_option( 'happyaccess_db_version' ) === Installer::DB_VERSION;
	}

	/**
	 * Verifies a nonce field.
	 *
	 * @param array  $source Unslashed request array.
	 * @param string $action Nonce action.
	 * @return bool
	 */
	private static function nonce_ok( array $source, $action ) {
		$nonce = self::text( $source, '_wpnonce' );
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
	}

	/**
	 * A request value as a string. Arrays and other types become empty.
	 *
	 * @param array  $source Request array.
	 * @param string $key    Key.
	 * @return string
	 */
	private static function text( array $source, $key ) {
		return isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? trim( $source[ $key ] ) : '';
	}

	/**
	 * Request method, upper-case.
	 *
	 * @return string
	 */
	private static function request_method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * Lock notice with minutes rounded up, in plain text.
	 *
	 * @param int $wait Seconds.
	 * @return \WP_Error
	 */
	private static function locked_error( $wait ) {
		$minutes = max( 1, (int) ceil( $wait / MINUTE_IN_SECONDS ) );
		return new \WP_Error(
			'locked',
			sprintf(
				/* translators: %d: minutes. */
				_n( 'Too many attempts. Try again in %d minute.', 'Too many attempts. Try again in %d minutes.', $minutes, 'happyaccess' ),
				$minutes
			)
		);
	}

	/**
	 * The error while the step can't run because the schema is updating or
	 * the site key isn't saved.
	 *
	 * @param bool $escaped Whether to escape the message for the login screen.
	 * @return \WP_Error
	 */
	private static function updating_error( $escaped = false ) {
		$message = __( 'Login is updating. Try again in a minute.', 'happyaccess' );
		return new \WP_Error( 'happyaccess_twostep_unavailable', $escaped ? esc_html( $message ) : $message );
	}

	/**
	 * A plain text error made safe for the login screen, which prints error
	 * messages as HTML.
	 *
	 * @param \WP_Error $error Error with a plain text message.
	 * @return \WP_Error
	 */
	private static function screen_error( \WP_Error $error ) {
		return new \WP_Error( $error->get_error_code(), esc_html( $error->get_error_message() ) );
	}
}
