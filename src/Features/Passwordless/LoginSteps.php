<?php
/**
 * Login screens for passwordless login: ask for a code, then enter it or open the link.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\Passwordless;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\ClientIp;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Mailer;
use HappyAccess\Core\RateLimiter;
use HappyAccess\Core\Settings;
use HappyAccess\Login\Router;
use HappyAccess\Login\Screens;

defined( 'ABSPATH' ) || exit;

/**
 * The request step always ends in the same redirect and the same message, so
 * nobody can tell a real account from a missing one, a support user or an
 * account over its limit. The only different answer is the IP lock notice,
 * which says nothing about any account.
 *
 * The handle_* methods take the request as arguments and return a response
 * array; the step callbacks read the request and pass it in.
 */
final class LoginSteps {

	const REQUEST_ACTION = 'passwordless_request';
	const CODE_ACTION    = 'passwordless_code';
	const LINK_ACTION    = 'passwordless_link';
	const COOKIE         = 'happyaccess_pl_request';

	/**
	 * Requests allowed per IP bucket inside an hour, then the lock lasts an hour.
	 */
	const IP_REQUEST_LIMIT = 10;

	/**
	 * Requests allowed per typed account inside 15 minutes, then the lock lasts as long.
	 */
	const ACCOUNT_REQUEST_LIMIT  = 3;
	const ACCOUNT_REQUEST_WINDOW = 900;

	/**
	 * Emails waiting for the end of the request.
	 *
	 * @var array
	 */
	private static $queue = array();

	/**
	 * Registers the request and verify steps and the link under the login form.
	 *
	 * @return void
	 */
	public static function register() {
		Router::add_step( 'request', array( __CLASS__, 'run_request' ) );
		Router::add_step( 'verify', array( __CLASS__, 'run_verify' ) );
		add_action( 'login_form', array( __CLASS__, 'print_request_link' ) );
	}

	/**
	 * Prints the "Email me a login code" link under the login form.
	 *
	 * @return void
	 */
	public static function print_request_link() {
		if ( ! Settings::get( 'passwordless.show_on.wp_login' ) || ! self::db_ready() ) {
			return;
		}

		$args = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the value is validated before it is used.
		$redirect = self::valid_redirect( isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : '' );
		if ( '' !== $redirect ) {
			$args['redirect_to'] = $redirect;
		}

		printf(
			'<p class="happyaccess-passwordless-link"><a href="%s">%s</a></p>',
			esc_url( Router::url( 'request', $args ) ),
			esc_html__( 'Email me a login code', 'happyaccess' )
		);
	}

	/**
	 * Step callback for the request screen.
	 *
	 * @return void
	 */
	public static function run_request() {
		// phpcs:ignore WordPress.Security.NonceVerification -- handle_request() verifies the nonce on POST.
		Screens::respond( self::handle_request( self::request_method(), wp_unslash( $_GET ), wp_unslash( $_POST ) ) );
	}

	/**
	 * Step callback for the verify screen.
	 *
	 * @return void
	 */
	public static function run_verify() {
		// phpcs:ignore WordPress.Security.NonceVerification -- handle_verify() verifies the nonce on POST and never logs in on GET.
		Screens::respond( self::handle_verify( self::request_method(), wp_unslash( $_GET ), wp_unslash( $_POST ), wp_unslash( $_COOKIE ) ) );
	}

	/**
	 * The request screen: the form on GET, the neutral redirect on POST.
	 *
	 * @param string $method HTTP method.
	 * @param array  $get    Unslashed $_GET.
	 * @param array  $post   Unslashed $_POST.
	 * @return array Response for Screens::respond().
	 */
	public static function handle_request( $method, array $get, array $post ) {
		$redirect = self::valid_redirect( '' !== self::text( $post, 'redirect_to' ) ? self::text( $post, 'redirect_to' ) : self::text( $get, 'redirect_to' ) );

		if ( ! self::db_ready() ) {
			return self::request_screen( $redirect, new \WP_Error( 'updating', self::updating_text() ) );
		}

		if ( 'POST' !== strtoupper( (string) $method ) ) {
			return self::request_screen( $redirect );
		}

		if ( ! self::nonce_ok( $post, 'happyaccess_pl_request' ) ) {
			return self::request_screen( $redirect, new \WP_Error( 'expired_page', esc_html__( 'This page expired. Try again.', 'happyaccess' ) ) );
		}

		$typed = self::text( $post, 'log' );
		if ( '' === $typed ) {
			return self::request_screen( $redirect, new \WP_Error( 'empty', esc_html__( 'Enter your email or username.', 'happyaccess' ) ) );
		}

		$wait = RateLimiter::attempt( self::REQUEST_ACTION, 'ip', RateLimiter::ip_subject(), self::IP_REQUEST_LIMIT, HOUR_IN_SECONDS, HOUR_IN_SECONDS );
		if ( $wait > 0 ) {
			return self::request_screen( $redirect, self::locked_error( $wait ) );
		}

		// Keyed on what was typed, so a missing account is limited the same way as a real one.
		$account_wait = RateLimiter::attempt( self::REQUEST_ACTION, 'account', strtolower( $typed ), self::ACCOUNT_REQUEST_LIMIT, self::ACCOUNT_REQUEST_WINDOW, self::ACCOUNT_REQUEST_WINDOW );

		// Every request gets a fresh key and a cookie, whether or not a code was made.
		$request_key = Codes::link_key();
		self::send_cookie( self::COOKIE, $request_key, Clock::now() + self::lifetime() );

		$user = Requests::eligible( $typed );
		if ( null !== $user && $account_wait > 0 ) {
			AuditLog::add( 'passwordless_failed', self::log_args( $user->ID, __( 'Login code not sent: too many requests', 'happyaccess' ), array( 'reason' => 'account_limit' ) ) );
		} elseif ( null !== $user ) {
			$made = Requests::create( $user, $request_key );
			if ( is_array( $made ) ) {
				self::queue_email( $user, $made );
				AuditLog::add( 'passwordless_requested', self::log_args( $user->ID, __( 'Login code requested', 'happyaccess' ), array() ) );
			} else {
				AuditLog::add( 'passwordless_failed', self::log_args( $user->ID, __( 'Login code could not be made', 'happyaccess' ), array( 'reason' => 'not_created' ) ) );
			}
		}

		return array(
			'type' => 'redirect',
			'url'  => Router::url( 'verify', '' !== $redirect ? array( 'redirect_to' => $redirect ) : array() ),
		);
	}

	/**
	 * The verify screen: the code form, or the link confirm page when a key
	 * is in the request.
	 *
	 * @param string $method  HTTP method.
	 * @param array  $get     Unslashed $_GET.
	 * @param array  $post    Unslashed $_POST.
	 * @param array  $cookies Unslashed $_COOKIE.
	 * @return array Response for Screens::respond().
	 */
	public static function handle_verify( $method, array $get, array $post, array $cookies ) {
		$post_method = 'POST' === strtoupper( (string) $method );

		// The link uses k, not key: wp-login.php sends any request with a key in the URL to the password reset flow.
		$key = self::text( $get, 'k' );
		if ( '' === $key ) {
			$key = self::text( $post, 'k' );
		}

		if ( '' !== $key ) {
			return self::handle_link( $post_method, $key, $post );
		}

		$redirect = self::valid_redirect( '' !== self::text( $post, 'redirect_to' ) ? self::text( $post, 'redirect_to' ) : self::text( $get, 'redirect_to' ) );

		if ( ! self::db_ready() ) {
			return self::verify_screen( $redirect, new \WP_Error( 'updating', self::updating_text() ) );
		}

		if ( ! $post_method ) {
			return self::verify_screen( $redirect );
		}

		if ( ! self::nonce_ok( $post, 'happyaccess_pl_verify' ) ) {
			AuditLog::add( 'passwordless_failed', self::log_args( 0, __( 'Login code form expired', 'happyaccess' ), array( 'reason' => 'nonce' ) ) );
			return self::verify_screen( $redirect, new \WP_Error( 'expired_page', esc_html__( 'This page expired. Try again.', 'happyaccess' ) ) );
		}

		$wait = self::ip_wait( self::CODE_ACTION );
		if ( $wait > 0 ) {
			return self::verify_screen( $redirect, self::locked_error( $wait ) );
		}

		$request_key = isset( $cookies[ self::COOKIE ] ) && is_string( $cookies[ self::COOKIE ] ) ? substr( trim( $cookies[ self::COOKIE ] ), 0, 128 ) : '';
		$result      = Requests::verify_code( $request_key, self::text( $post, 'pwd' ) );
		if ( is_wp_error( $result ) ) {
			if ( 'happyaccess_code_locked' === $result->get_error_code() ) {
				AuditLog::add( 'passwordless_locked', self::log_args( 0, __( 'Login code cancelled after too many wrong tries', 'happyaccess' ), array( 'reason' => 'attempts' ) ) );
			} else {
				AuditLog::add( 'passwordless_failed', self::log_args( 0, __( 'Login code did not work', 'happyaccess' ), array( 'reason' => 'invalid_code' ) ) );
			}
			return self::verify_screen( $redirect, new \WP_Error( $result->get_error_code(), esc_html( $result->get_error_message() ) ) );
		}

		return self::log_in( $result, 'code', ! empty( $post['rememberme'] ), $redirect );
	}

	/**
	 * The emailed link: a confirm page on GET, the login on POST.
	 *
	 * @param bool   $post_method Whether the request is a POST.
	 * @param string $key         Link key.
	 * @param array  $post        Unslashed $_POST.
	 * @return array Response for Screens::respond().
	 */
	private static function handle_link( $post_method, $key, array $post ) {
		if ( ! self::db_ready() ) {
			return self::link_error( new \WP_Error( 'updating', self::updating_text() ) );
		}

		if ( ! $post_method ) {
			$user = self::user_for_link( $key );
			return null === $user ? self::link_error() : self::link_confirm( $user, $key );
		}

		if ( ! self::nonce_ok( $post, 'happyaccess_pl_link' ) ) {
			// A stale page with a key that still works gets a fresh confirm screen instead of a dead end.
			$user = self::user_for_link( $key );
			if ( null === $user ) {
				return self::link_error();
			}
			return self::link_confirm( $user, $key, new \WP_Error( 'expired_page', esc_html__( 'This page expired. Select Log in again.', 'happyaccess' ) ) );
		}

		$wait = self::ip_wait( self::LINK_ACTION );
		if ( $wait > 0 ) {
			return self::link_error( self::locked_error( $wait ) );
		}

		$result = Requests::consume_link( $key );
		if ( is_wp_error( $result ) ) {
			AuditLog::add( 'passwordless_failed', self::log_args( 0, __( 'Login link did not work', 'happyaccess' ), array( 'reason' => 'invalid_link' ) ) );
			return self::link_error();
		}

		return self::log_in( $result, 'link', false, '' );
	}

	/**
	 * Signs a user in after a code or link was used up. The log row is
	 * written before the wp_login action, so a listener that exits can't
	 * skip it.
	 *
	 * @param \WP_User $user     The user.
	 * @param string   $method   code or link.
	 * @param bool     $remember Whether to keep the session.
	 * @param string   $redirect Valid redirect target from the request screen, or empty.
	 * @return array Redirect response.
	 */
	private static function log_in( \WP_User $user, $method, $remember, $redirect ) {
		wp_set_auth_cookie( $user->ID, $remember );
		wp_set_current_user( $user->ID );

		RateLimiter::clear( 'link' === $method ? self::LINK_ACTION : self::CODE_ACTION, 'ip', RateLimiter::ip_subject() );
		self::send_cookie( self::COOKIE, '', Clock::now() - HOUR_IN_SECONDS );

		AuditLog::add( 'passwordless_login', self::log_args( $user->ID, __( 'Logged in with a login code or link', 'happyaccess' ), array( 'method' => $method ) ) );

		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, so login listeners see this sign-in.

		return array(
			'type' => 'redirect',
			'url'  => self::destination( $user, $redirect ),
		);
	}

	/**
	 * Where a signed-in user goes: the validated target from the request
	 * screen, else what the login_redirect filter returns. A customer starts
	 * at My Account when WooCommerce is active.
	 *
	 * @param \WP_User $user     The user.
	 * @param string   $redirect Valid redirect target, or empty.
	 * @return string
	 */
	private static function destination( \WP_User $user, $redirect ) {
		$redirect = self::valid_redirect( $redirect );
		if ( '' !== $redirect ) {
			return $redirect;
		}

		$default = admin_url();
		if ( function_exists( 'wc_get_page_permalink' ) && in_array( 'customer', (array) $user->roles, true ) ) {
			$account = wc_get_page_permalink( 'myaccount' );
			if ( is_string( $account ) && '' !== $account ) {
				$default = $account;
			}
		}

		// Same filter and arguments as wp-login.php, with no requested target.
		return (string) apply_filters( 'login_redirect', $default, '', $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	/**
	 * Queues the code email for the end of the request.
	 *
	 * @param \WP_User $user User asking.
	 * @param array    $made Result of Requests::create().
	 * @return void
	 */
	private static function queue_email( \WP_User $user, array $made ) {
		$ip = ClientIp::get();
		if ( Settings::get( 'privacy.anonymize_ip', false ) ) {
			$ip = ClientIp::anonymize( $ip );
		}

		self::$queue[] = array(
			'to'         => $user->user_email,
			'code'       => $made['code'],
			'link_key'   => $made['link_key'],
			'expires_at' => $made['expires_at'],
			'ip'         => $ip,
		);
		add_action( 'shutdown', array( __CLASS__, 'flush_queue' ) );
	}

	/**
	 * Sends the queued emails. Runs on shutdown, after the response has gone
	 * to the visitor where the server allows it, so the response time doesn't
	 * depend on wp_mail().
	 *
	 * @return void
	 */
	public static function flush_queue() {
		if ( empty( self::$queue ) ) {
			return;
		}

		$queue       = self::$queue;
		self::$queue = array();

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		foreach ( $queue as $item ) {
			$minutes = max( 1, (int) ceil( max( 0, $item['expires_at'] - Clock::now() ) / MINUTE_IN_SECONDS ) );
			Mailer::send(
				$item['to'],
				__( 'Your login code', 'happyaccess' ),
				'passwordless-code',
				array(
					'code'    => Codes::format_code( $item['code'] ),
					'link'    => Router::url( 'verify', array( 'k' => $item['link_key'] ) ),
					'minutes' => $minutes,
					'ip'      => $item['ip'],
				)
			);
		}
	}

	/**
	 * Sets a cookie with the options the request key needs: HttpOnly, SameSite
	 * Lax, Secure on HTTPS. The pre-filter lets tests and hosts that manage
	 * headers themselves take over.
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
		 * Lets code take over sending the request cookie.
		 *
		 * @param bool   $handled Return true when the cookie was sent elsewhere.
		 * @param string $name    Cookie name.
		 * @param string $value   Cookie value.
		 * @param array  $options expires, path, domain, secure, httponly and samesite.
		 */
		if ( apply_filters( 'happyaccess_passwordless_send_cookie', false, $name, $value, $options ) ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		setcookie( $name, $value, $options );
	}

	/**
	 * Counts one try on the IP scope and returns the seconds to wait.
	 *
	 * @param string $action Rate limit action.
	 * @return int
	 */
	private static function ip_wait( $action ) {
		return RateLimiter::attempt(
			$action,
			'ip',
			RateLimiter::ip_subject(),
			(int) Settings::get( 'security.max_attempts' ),
			(int) Settings::get( 'security.attempt_window' ),
			(int) Settings::get( 'security.lockout_duration' )
		);
	}

	/**
	 * Arguments for a passwordless log row. Never pass a typed email, a
	 * username, a code or a key.
	 *
	 * @param int    $user_id User id, 0 when unknown.
	 * @param string $summary Summary.
	 * @param array  $meta    Meta.
	 * @return array
	 */
	private static function log_args( $user_id, $summary, array $meta ) {
		return array(
			'feature' => 'passwordless',
			'user_id' => (int) $user_id,
			'summary' => $summary,
			'meta'    => $meta,
		);
	}

	/**
	 * The user behind an open link, or null.
	 *
	 * @param string $key Link key.
	 * @return \WP_User|null
	 */
	private static function user_for_link( $key ) {
		$row  = Requests::find_by_link( $key );
		$user = null === $row ? false : get_userdata( $row['user_id'] );
		return $user instanceof \WP_User ? $user : null;
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
	 * Code lifetime in seconds.
	 *
	 * @return int
	 */
	private static function lifetime() {
		return (int) Settings::get( 'passwordless.code_lifetime' );
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
	 * Verifies the nonce field of a posted form.
	 *
	 * @param array  $post   Unslashed $_POST.
	 * @param string $action Nonce action.
	 * @return bool
	 */
	private static function nonce_ok( array $post, $action ) {
		$nonce = self::text( $post, '_wpnonce' );
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
	 * Lock notice with minutes rounded up.
	 *
	 * @param int $wait Seconds.
	 * @return \WP_Error
	 */
	private static function locked_error( $wait ) {
		$minutes = max( 1, (int) ceil( $wait / MINUTE_IN_SECONDS ) );
		return new \WP_Error(
			'locked',
			esc_html(
				sprintf(
					/* translators: %d: minutes. */
					_n( 'Too many attempts. Try again in %d minute.', 'Too many attempts. Try again in %d minutes.', $minutes, 'happyaccess' ),
					$minutes
				)
			)
		);
	}

	/**
	 * Text for the migration gate.
	 *
	 * @return string
	 */
	private static function updating_text() {
		return esc_html__( 'Login is updating. Try again in a minute.', 'happyaccess' );
	}

	/**
	 * The one message every request gets, shown on the verify screen.
	 *
	 * @return string Markup for the login screen message area.
	 */
	private static function neutral_message() {
		$minutes = max( 1, (int) ceil( self::lifetime() / MINUTE_IN_SECONDS ) );
		$text    = sprintf(
			/* translators: %d: minutes until the login code expires. */
			_n(
				'If that account exists, we sent a login code to its email address. It expires in %d minute.',
				'If that account exists, we sent a login code to its email address. It expires in %d minutes.',
				$minutes,
				'happyaccess'
			),
			$minutes
		);
		return '<p class="message">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Hidden field that carries a validated redirect target.
	 *
	 * @param string $redirect Valid redirect target, or empty.
	 * @return string
	 */
	private static function redirect_field( $redirect ) {
		return '' === $redirect ? '' : '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '" />';
	}

	/**
	 * Request form response.
	 *
	 * @param string         $redirect Valid redirect target, or empty.
	 * @param \WP_Error|null $errors   Errors to show.
	 * @return array
	 */
	private static function request_screen( $redirect, $errors = null ) {
		$body  = '<form name="happyaccess-request" method="post" action="' . esc_url( Router::url( 'request' ) ) . '">';
		$body .= '<p><label for="happyaccess-log">' . esc_html__( 'Email or username', 'happyaccess' ) . '</label>';
		$body .= '<input type="text" name="log" id="happyaccess-log" class="input" value="" size="20" autocomplete="username" autocapitalize="off" spellcheck="false" /></p>';
		$body .= wp_nonce_field( 'happyaccess_pl_request', '_wpnonce', false, false );
		$body .= self::redirect_field( $redirect );
		$body .= '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Send login code', 'happyaccess' ) . '" /></p>';
		$body .= '</form>';
		$body .= '<p id="nav"><a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'Log in with a password', 'happyaccess' ) . '</a></p>';

		return array(
			'type'    => 'render',
			'title'   => __( 'Log in with a code', 'happyaccess' ),
			'body'    => $body,
			'errors'  => $errors,
			'message' => '',
		);
	}

	/**
	 * Code form response, with the neutral message.
	 *
	 * @param string         $redirect Valid redirect target, or empty.
	 * @param \WP_Error|null $errors   Errors to show.
	 * @return array
	 */
	private static function verify_screen( $redirect, $errors = null ) {
		$body  = '<form name="happyaccess-verify" method="post" action="' . esc_url( Router::url( 'verify' ) ) . '">';
		$body .= '<p><label for="happyaccess-pwd">' . esc_html__( 'Login code', 'happyaccess' ) . '</label>';
		$body .= '<input type="text" name="pwd" id="happyaccess-pwd" class="input" value="" size="20" maxlength="12" inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" /></p>';
		$body .= '<p class="forgetmenot"><input name="rememberme" type="checkbox" id="happyaccess-rememberme" value="forever" /> <label for="happyaccess-rememberme">' . esc_html__( 'Remember me', 'happyaccess' ) . '</label></p>';
		$body .= wp_nonce_field( 'happyaccess_pl_verify', '_wpnonce', false, false );
		$body .= self::redirect_field( $redirect );
		$body .= '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Log in', 'happyaccess' ) . '" /></p>';
		$body .= '</form>';
		$body .= '<p id="nav"><a href="' . esc_url( Router::url( 'request', '' !== $redirect ? array( 'redirect_to' => $redirect ) : array() ) ) . '">' . esc_html__( 'Send a new code', 'happyaccess' ) . '</a></p>';

		return array(
			'type'    => 'render',
			'title'   => __( 'Log in with a code', 'happyaccess' ),
			'body'    => $body,
			'errors'  => $errors,
			'message' => null === $errors ? self::neutral_message() : '',
		);
	}

	/**
	 * Link confirm page. Logging in needs a POST from this form.
	 *
	 * @param \WP_User       $user   The user the link is for.
	 * @param string         $key    Link key from the URL.
	 * @param \WP_Error|null $errors Errors to show.
	 * @return array
	 */
	private static function link_confirm( \WP_User $user, $key, $errors = null ) {
		$question = sprintf(
			/* translators: 1: site name, 2: display name of the account. */
			__( 'Log in to %1$s as %2$s', 'happyaccess' ),
			get_bloginfo( 'name' ),
			$user->display_name
		);

		$body  = '<form name="happyaccess-link" method="post" action="' . esc_url( Router::url( 'verify' ) ) . '">';
		$body .= '<p>' . esc_html( $question ) . '</p>';
		$body .= '<input type="hidden" name="k" value="' . esc_attr( $key ) . '" />';
		$body .= wp_nonce_field( 'happyaccess_pl_link', '_wpnonce', false, false );
		$body .= '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Log in', 'happyaccess' ) . '" /></p>';
		$body .= '</form>';

		return array(
			'type'    => 'render',
			'title'   => __( 'Log in with a link', 'happyaccess' ),
			'body'    => $body,
			'errors'  => $errors,
			'message' => '',
		);
	}

	/**
	 * Link failure response, with a way to ask for a new code.
	 *
	 * @param \WP_Error|null $errors Error to show. Defaults to the expired or used message.
	 * @return array
	 */
	private static function link_error( $errors = null ) {
		if ( null === $errors ) {
			$errors = new \WP_Error( 'invalid_link', esc_html__( 'This login link has expired or was already used.', 'happyaccess' ) );
		}
		return array(
			'type'    => 'render',
			'title'   => __( 'Log in with a link', 'happyaccess' ),
			'body'    => '<p id="nav"><a href="' . esc_url( Router::url( 'request' ) ) . '">' . esc_html__( 'Send a new login code', 'happyaccess' ) . '</a></p>',
			'errors'  => $errors,
			'message' => '',
		);
	}
}
