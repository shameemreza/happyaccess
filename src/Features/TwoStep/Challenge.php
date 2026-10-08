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
	 * Wrong codes allowed on the whole site inside SITE_WINDOW, then the step
	 * locks for SITE_WINDOW. Only failures count.
	 */
	const SITE_CODE_CAP = 100;
	const SITE_WINDOW   = 3600;

	/**
	 * Set while the owner already has the email about a site-wide pause.
	 */
	const SITE_LOCK_TRANSIENT = 'happyaccess_ts_site_lock_alerted';

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
	 * Whether a user whose role requires two-step login has used up the
	 * grace period and must set it up before logging in. Task 3 fills this
	 * in, with the setup screen.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function needs_setup( \WP_User $user ) {
		unset( $user );
		return false;
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

		$context = self::context();
		if ( 'xmlrpc' === $context ) {
			if ( ! Settings::get( 'two_step.block_xmlrpc' ) ) {
				return $user;
			}
			return new \WP_Error( 'happyaccess_twostep_xmlrpc', esc_html__( "This account uses two-step login, so it can't log in over XML-RPC. Use an application password instead.", 'happyaccess' ) );
		}
		if ( 'browser' !== $context ) {
			return new \WP_Error( 'happyaccess_twostep_required', esc_html__( 'This account uses two-step login. Log in on the login page.', 'happyaccess' ) );
		}
		return $user;
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
		if ( ! self::is_first_factor( $user, $password ) || 'browser' !== self::context() || ! self::applies( $user ) ) {
			return $user;
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
	 * proved access to the mailbox, so a user whose only method is email logs
	 * in directly. Anyone else goes to the step with the app and backup
	 * codes only.
	 *
	 * @param \WP_User $user     The user.
	 * @param bool     $remember Whether to keep the session.
	 * @param string   $redirect Where the user goes after the step.
	 * @return string|null The step URL, or null to log in now.
	 */
	public static function after_passwordless( \WP_User $user, $remember, $redirect ) {
		if ( ! self::applies( $user ) || array() === self::allowed_methods( $user, self::PURPOSE_AFTER_PASSWORDLESS ) ) {
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
	 * Step callback for the two-step screen.
	 *
	 * @return void
	 */
	public static function run_step() {
		// phpcs:ignore WordPress.Security.NonceVerification -- handle() verifies the nonce on POST and on the email link.
		$response = self::handle( self::request_method(), wp_unslash( $_GET ), wp_unslash( $_POST ), wp_unslash( $_COOKIE ) );

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
			return self::login_screen( '', new \WP_Error( 'updating', esc_html( self::updating_error()->get_error_message() ) ) );
		}

		$pending = self::find_pending( self::pending_key( $cookies ) );
		$user    = null === $pending ? false : get_userdata( $pending['user_id'] );
		if ( ! $user instanceof \WP_User ) {
			return self::back_to_login( 'expired', $carry );
		}

		$allowed = self::allowed_methods( $user, $pending['purpose'] );
		if ( array() === $allowed ) {
			return self::back_to_login( 'expired', $carry );
		}
		$chosen          = self::text( $input, 'method' );
		$carry['method'] = in_array( $chosen, $allowed, true ) ? $chosen : $allowed[0];

		if ( ! $is_post ) {
			if ( 'email' === $carry['method'] && '1' === self::text( $get, 'send' ) ) {
				return self::handle_send( $user, $pending, $allowed, $carry, $get );
			}
			$sent = 'email' === $carry['method'] && '1' === self::text( $get, 'sent' );
			return self::code_screen( $allowed, $carry, null, $sent );
		}

		if ( ! self::nonce_ok( $post, 'happyaccess_twostep' ) ) {
			return self::code_screen( $allowed, $carry, new \WP_Error( 'expired_page', esc_html__( 'This page expired. Try again.', 'happyaccess' ) ) );
		}

		$wait = self::ip_wait();
		if ( $wait > 0 ) {
			return self::code_screen( $allowed, $carry, self::screen_error( self::locked_error( $wait ) ) );
		}
		$wait = self::site_wait();
		if ( $wait > 0 ) {
			self::site_lock_alert( $wait );
			return self::code_screen( $allowed, $carry, self::screen_error( self::locked_error( $wait ) ) );
		}

		if ( ! self::check_code( $user, $carry['method'], self::text( $post, 'pwd' ) ) ) {
			return self::fail( $user, $pending, $allowed, $carry );
		}

		if ( ! self::consume( $pending['id'] ) ) {
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
	 * last codes: as an admin notice, or on My Account.
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
		$text = sprintf(
			/* translators: %d: backup codes left. */
			_n( 'You have %d backup code left. Make new ones on your profile.', 'You have %d backup codes left. Make new ones on your profile.', $left, 'happyaccess' ),
			$left
		);
		if ( doing_action( 'woocommerce_account_content' ) ) {
			echo '<div class="woocommerce-info">' . esc_html( $text ) . '</div>';
			return;
		}
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
	 * is email, the code is sent now.
	 *
	 * @param \WP_User $user    The user.
	 * @param string   $purpose Challenge purpose.
	 * @param array    $carry   Values the step carries through its form.
	 * @return string|\WP_Error
	 */
	private static function begin( \WP_User $user, $purpose, array $carry ) {
		$pending_id = self::start( $user, $purpose );
		if ( is_wp_error( $pending_id ) ) {
			return $pending_id;
		}

		$allowed         = self::allowed_methods( $user, $purpose );
		$carry['method'] = array() === $allowed ? '' : $allowed[0];
		if ( 'email' === $carry['method'] && true === self::send_email( $user, $pending_id ) ) {
			$carry['sent'] = 1;
		}
		return self::step_url( $carry );
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
	 * A user with no app can always ask for an email code.
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
	 * The carried values from the step's own query or form.
	 *
	 * @param array $input Unslashed $_GET or $_POST.
	 * @return array
	 */
	private static function carry_from( array $input ) {
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
	 * URL of the step with the carried values.
	 *
	 * @param array $carry Carried values.
	 * @return string
	 */
	private static function step_url( array $carry ) {
		$args = array();
		foreach ( array( 'method', 'send', 'sent', '_wpnonce', 'redirect_to', 'rememberme', 'interim-login', 'from' ) as $key ) {
			if ( isset( $carry[ $key ] ) && is_scalar( $carry[ $key ] ) && '' !== (string) $carry[ $key ] ) {
				$args[ $key ] = (string) $carry[ $key ];
			}
		}
		return Router::url( self::STEP, $args );
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
	private static function back_to_login( $reason, array $carry ) {
		return array(
			'type' => 'redirect',
			'url'  => self::login_url( $reason, $carry ),
		);
	}

	/**
	 * The email link: sends a code, then shows the email form.
	 *
	 * @param \WP_User $user    The user.
	 * @param array    $pending Pending login row.
	 * @param string[] $allowed Methods offered.
	 * @param array    $carry   Carried values, with method.
	 * @param array    $get     Unslashed $_GET.
	 * @return array
	 */
	private static function handle_send( \WP_User $user, array $pending, array $allowed, array $carry, array $get ) {
		if ( ! self::nonce_ok( $get, 'happyaccess_twostep_send' ) ) {
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
	 * Sends an email code, within the account's send limit.
	 *
	 * @param \WP_User $user       The user.
	 * @param int      $pending_id Pending login id.
	 * @return true|\WP_Error Error messages are plain text.
	 */
	private static function send_email( \WP_User $user, $pending_id ) {
		$wait = RateLimiter::attempt( self::SEND_ACTION, 'account', 'u:' . $user->ID, self::SEND_LIMIT, self::LIFETIME, self::LIFETIME );
		if ( $wait > 0 ) {
			return self::locked_error( $wait );
		}
		return EmailMethod::send( $user, (int) $pending_id );
	}

	/**
	 * Checks a typed code with one method. An app code uses up its time
	 * step, so the same code can't log in twice.
	 *
	 * @param \WP_User $user   The user.
	 * @param string   $method app, email or backup.
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
		if ( 'backup' === $method ) {
			return BackupCodes::use_code( $user->ID, $code );
		}
		return false;
	}

	/**
	 * A wrong code: counts it on the site cap and on the pending login. The
	 * fifth one cancels the pending login.
	 *
	 * @param \WP_User $user    The user.
	 * @param array    $pending Pending login row.
	 * @param string[] $allowed Methods offered.
	 * @param array    $carry   Carried values, with method.
	 * @return array
	 */
	private static function fail( \WP_User $user, array $pending, array $allowed, array $carry ) {
		// Only a wrong code counts on the site scope, and it is never cleared.
		RateLimiter::hit( self::CODE_ACTION, 'site', 'site' );
		$wait = self::site_wait();
		if ( $wait > 0 ) {
			self::site_lock_alert( $wait );
		}

		if ( self::count_failure( $pending['id'] ) >= self::MAX_ATTEMPTS ) {
			UserState::log_locked( $user->ID, $carry['method'] );
			self::send_cookie( self::COOKIE, '', Clock::now() - HOUR_IN_SECONDS );
			return self::back_to_login( 'locked', $carry );
		}

		UserState::log_failed( $user->ID, $carry['method'] );
		// One message for every failure, so it says nothing about which method or why.
		return self::code_screen( $allowed, $carry, new \WP_Error( 'happyaccess_invalid_code', esc_html__( "That code didn't work. Try again.", 'happyaccess' ) ) );
	}

	/**
	 * Logs the user in after the second step passed. The log row is written
	 * before the wp_login action, so a listener that exits can't skip it.
	 *
	 * @param \WP_User $user    The user.
	 * @param array    $pending Pending login row, used up.
	 * @param array    $carry   Carried values, with method.
	 * @return array
	 */
	private static function succeed( \WP_User $user, array $pending, array $carry ) {
		RateLimiter::clear( self::CODE_ACTION, 'ip', RateLimiter::ip_subject() );
		self::send_cookie( self::COOKIE, '', Clock::now() - HOUR_IN_SECONDS );
		if ( 'backup' === $carry['method'] ) {
			self::remember_backup_notice( $user->ID );
		}
		UserState::log_passed( $user->ID, $carry['method'] );

		wp_set_auth_cookie( $user->ID, ! empty( $carry['rememberme'] ) );
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
	 * The open pending login for a cookie key, or null.
	 *
	 * @param string $key Key from the cookie.
	 * @return array|null id, user_id and purpose.
	 */
	private static function find_pending( $key ) {
		global $wpdb;

		if ( '' === $key ) {
			return null;
		}
		$table = Installer::table( 'challenges' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, purpose FROM {$table} WHERE link_hash = %s AND purpose IN ( %s, %s ) AND used_at IS NULL AND expires_at > %s AND attempts < %d LIMIT 1", Codes::hash_key( $key ), self::PURPOSE, self::PURPOSE_AFTER_PASSWORDLESS, Clock::mysql(), self::MAX_ATTEMPTS ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'      => (int) $row['id'],
			'user_id' => (int) $row['user_id'],
			'purpose' => (string) $row['purpose'],
		);
	}

	/**
	 * Uses up a pending login. Only the call that changes the row wins.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	private static function consume( $id ) {
		global $wpdb;

		$table = Installer::table( 'challenges' );
		$now   = Clock::mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE id = %d AND used_at IS NULL AND expires_at > %s AND attempts < %d", $now, (int) $id, $now, self::MAX_ATTEMPTS ) );
	}

	/**
	 * Counts a wrong code on the pending login and cancels it at the limit.
	 *
	 * @param int $id Row id.
	 * @return int Wrong codes so far. MAX_ATTEMPTS when the row is already closed.
	 */
	private static function count_failure( $id ) {
		global $wpdb;

		$table = Installer::table( 'challenges' );
		$now   = Clock::mysql();
		// One statement: MySQL applies the assignments left to right, so the cancel sees the raised count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$counted = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1, used_at = IF( attempts >= %d, %s, used_at ) WHERE id = %d AND used_at IS NULL AND attempts < %d", self::MAX_ATTEMPTS, $now, (int) $id, self::MAX_ATTEMPTS ) );
		if ( 1 !== $counted ) {
			return self::MAX_ATTEMPTS;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * The code form.
	 *
	 * @param string[]       $allowed Methods offered.
	 * @param array          $carry   Carried values, with method.
	 * @param \WP_Error|null $errors  Errors to show.
	 * @param bool           $sent    Whether an email code was just sent.
	 * @return array
	 */
	private static function code_screen( array $allowed, array $carry, $errors = null, $sent = false ) {
		$method = $carry['method'];
		$labels = array(
			'app'    => __( 'Authenticator app code', 'happyaccess' ),
			'email'  => __( 'Email code', 'happyaccess' ),
			'backup' => __( 'Backup code', 'happyaccess' ),
		);

		$body  = '<form name="happyaccess-twostep" method="post" action="' . esc_url( Router::url( self::STEP ) ) . '">';
		$body .= '<p><label for="happyaccess-ts-code">' . esc_html( isset( $labels[ $method ] ) ? $labels[ $method ] : $labels['app'] ) . '</label>';
		if ( 'backup' === $method ) {
			$body .= '<input type="text" name="pwd" id="happyaccess-ts-code" class="input" value="" size="20" maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false" /></p>';
		} else {
			$body .= '<input type="text" name="pwd" id="happyaccess-ts-code" class="input" value="" size="20" maxlength="12" inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" /></p>';
		}
		$body .= '<input type="hidden" name="method" value="' . esc_attr( $method ) . '" />';
		$body .= self::carry_fields( $carry );
		$body .= wp_nonce_field( 'happyaccess_twostep', '_wpnonce', false, false );
		$body .= '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Log in', 'happyaccess' ) . '" /></p>';
		$body .= '</form>';
		$body .= self::method_links( $allowed, $carry );

		$message = '';
		if ( $sent ) {
			$message = '<p class="message">' . esc_html__( 'We sent a code to your email address.', 'happyaccess' ) . '</p>';
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
	private static function carry_fields( array $carry ) {
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
	 * Links to the other methods the user has.
	 *
	 * @param string[] $allowed Methods offered.
	 * @param array    $carry   Carried values, with method.
	 * @return string
	 */
	private static function method_links( array $allowed, array $carry ) {
		$links = array();
		$base  = $carry;
		unset( $base['sent'] );

		if ( 'app' !== $carry['method'] && in_array( 'app', $allowed, true ) ) {
			$links[] = array( self::step_url( array_merge( $base, array( 'method' => 'app' ) ) ), __( 'Use your authenticator app', 'happyaccess' ) );
		}
		if ( in_array( 'email', $allowed, true ) ) {
			$send    = array_merge(
				$base,
				array(
					'method'   => 'email',
					'send'     => '1',
					'_wpnonce' => wp_create_nonce( 'happyaccess_twostep_send' ),
				)
			);
			$links[] = array( self::step_url( $send ), 'email' === $carry['method'] ? __( 'Send a new code', 'happyaccess' ) : __( 'Email me a code instead', 'happyaccess' ) );
		}
		if ( 'backup' !== $carry['method'] && in_array( 'backup', $allowed, true ) ) {
			$links[] = array( self::step_url( array_merge( $base, array( 'method' => 'backup' ) ) ), __( 'Use a backup code', 'happyaccess' ) );
		}

		$html = '';
		foreach ( $links as $link ) {
			$html .= '<p id="nav" class="happyaccess-ts-switch"><a href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . '</a></p>';
		}
		return $html;
	}

	/**
	 * Sets the pending-login cookie: HttpOnly, SameSite Lax, Secure on HTTPS.
	 * The pre-filter lets tests and hosts that manage headers themselves
	 * take over.
	 *
	 * @param string $name    Cookie name.
	 * @param string $value   Value. Empty with a past time removes it.
	 * @param int    $expires Unix time.
	 * @return void
	 */
	private static function send_cookie( $name, $value, $expires ) {
		$options = array(
			'expires'  => (int) $expires,
			'path'     => COOKIEPATH,
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);

		/**
		 * Lets code take over sending the pending-login cookie.
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
	 * Seconds until the site-wide cap lets codes through again. Reads only;
	 * a wrong code adds the hit.
	 *
	 * @return int
	 */
	private static function site_wait() {
		return RateLimiter::retry_after( self::CODE_ACTION, 'site', 'site', self::SITE_CODE_CAP, self::SITE_WINDOW, self::SITE_WINDOW );
	}

	/**
	 * Emails the site owner once per pause and writes one log row.
	 *
	 * @param int $wait Seconds the pause lasts.
	 * @return void
	 */
	private static function site_lock_alert( $wait ) {
		// Transient calls can add or delete an option, so they run inside the bypass.
		$alerted = Internal::run(
			static function () {
				return get_transient( self::SITE_LOCK_TRANSIENT );
			}
		);
		if ( $alerted ) {
			return;
		}

		$wait = max( 60, (int) $wait );
		Internal::run(
			static function () use ( $wait ) {
				set_transient( self::SITE_LOCK_TRANSIENT, 1, $wait );
			}
		);

		AuditLog::add(
			'twostep_locked',
			array(
				'feature' => 'two_step',
				'user_id' => 0,
				'summary' => __( 'Two-step login paused for the whole site after too many wrong codes', 'happyaccess' ),
				'meta'    => array( 'reason' => 'site_cap' ),
			)
		);

		Mailer::send(
			(string) get_option( 'admin_email' ),
			__( 'Two-step login is paused', 'happyaccess' ),
			'twostep-lock',
			array( 'minutes' => (int) ceil( $wait / MINUTE_IN_SECONDS ) )
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
	private static function db_ready() {
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
