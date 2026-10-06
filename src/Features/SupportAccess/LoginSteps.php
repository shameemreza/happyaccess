<?php
/**
 * Login screens for support access: code, link and ended.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\ClientIp;
use HappyAccess\Core\Installer;
use HappyAccess\Core\RateLimiter;
use HappyAccess\Core\Settings;
use HappyAccess\Login\Router;
use HappyAccess\Login\Screens;

defined( 'ABSPATH' ) || exit;

/**
 * Every failed login gives the same error code and text, so nobody can tell
 * a wrong code from a suspended grant, an expired one or a blocked IP. The
 * only different answers are the lock notice, which says nothing about any
 * grant, and the update notice.
 *
 * The handle_* methods take the request as arguments and return a response
 * array; the step callbacks read the request and pass it in.
 */
final class LoginSteps {

	const CODE_ACTION = 'support_code';
	const LINK_ACTION = 'support_link';
	const SITE_WINDOW = 3600;
	const SITE_LOCK   = 3600;

	/**
	 * Registers the steps and the "Have a support access code?" link.
	 *
	 * @return void
	 */
	public static function register() {
		Router::add_step( 'code', array( __CLASS__, 'run_code' ) );
		Router::add_step( 'link', array( __CLASS__, 'run_link' ) );
		Router::add_step( 'ended', array( __CLASS__, 'run_ended' ) );
		add_action( 'login_form', array( __CLASS__, 'print_code_link' ) );
	}

	/**
	 * Prints the code link under the login form while a grant exists.
	 *
	 * @return void
	 */
	public static function print_code_link() {
		if ( ! Grants::has_current() ) {
			return;
		}
		printf(
			'<p class="happyaccess-code-link"><a href="%s">%s</a></p>',
			esc_url( Router::url( 'code' ) ),
			esc_html__( 'Have a support access code?', 'happyaccess' )
		);
	}

	/**
	 * Step callback for the code screen.
	 *
	 * @return void
	 */
	public static function run_code() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_code() verifies the nonce on POST.
		Screens::respond( self::handle_code( self::request_method(), wp_unslash( $_POST ) ) );
	}

	/**
	 * Step callback for the link screen.
	 *
	 * @return void
	 */
	public static function run_link() {
		// phpcs:ignore WordPress.Security.NonceVerification -- handle_link() verifies the nonce on POST and never logs in on GET.
		Screens::respond( self::handle_link( self::request_method(), wp_unslash( $_GET ), wp_unslash( $_POST ) ) );
	}

	/**
	 * Step callback for the ended screen.
	 *
	 * @return void
	 */
	public static function run_ended() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only message choice.
		Screens::respond( self::handle_ended( wp_unslash( $_GET ) ) );
	}

	/**
	 * The code screen.
	 *
	 * @param string $method HTTP method.
	 * @param array  $post   Unslashed $_POST.
	 * @return array Response for Screens::respond().
	 */
	public static function handle_code( $method, array $post ) {
		if ( ! self::db_ready() ) {
			return self::code_screen( new \WP_Error( 'updating', self::updating_text() ) );
		}

		if ( 'POST' !== strtoupper( (string) $method ) ) {
			return self::code_screen();
		}

		if ( ! self::nonce_ok( $post, 'happyaccess_code' ) ) {
			return self::code_screen( self::code_error() );
		}

		if ( Settings::get( 'security.recaptcha_enabled', false ) && ! apply_filters( 'happyaccess_verify_captcha', true, 'code' ) ) {
			return self::code_screen( self::code_error() );
		}

		$wait = RateLimiter::attempt(
			self::CODE_ACTION,
			'ip',
			RateLimiter::ip_subject(),
			(int) Settings::get( 'security.max_attempts' ),
			(int) Settings::get( 'security.attempt_window' ),
			(int) Settings::get( 'security.lockout_duration' )
		);
		if ( $wait > 0 ) {
			return self::code_screen( self::locked_error( $wait ) );
		}

		$wait = RateLimiter::attempt( self::CODE_ACTION, 'site', 'site', (int) Settings::get( 'security.site_code_cap' ), self::SITE_WINDOW, self::SITE_LOCK );
		if ( $wait > 0 ) {
			Notifications::site_lock( $wait );
			return self::code_screen( self::locked_error( $wait ) );
		}

		$code  = self::text( $post, 'pwd' );
		$grant = '' === $code ? null : Grants::find_by_code( $code );
		if ( ! self::may_log_in( $grant ) ) {
			return self::code_screen( self::code_error() );
		}

		if ( ! Grants::record_login( $grant['id'] ) ) {
			return self::code_screen( self::code_error() );
		}

		$response = self::log_in( $grant, 'code' );
		return null === $response ? self::code_screen( self::code_error() ) : $response;
	}

	/**
	 * The link screen: a confirm page on GET, the login on POST.
	 *
	 * @param string $method HTTP method.
	 * @param array  $get    Unslashed $_GET.
	 * @param array  $post   Unslashed $_POST.
	 * @return array Response for Screens::respond().
	 */
	public static function handle_link( $method, array $get, array $post ) {
		if ( ! self::db_ready() ) {
			return self::link_error( new \WP_Error( 'updating', self::updating_text() ) );
		}

		if ( 'POST' !== strtoupper( (string) $method ) ) {
			$grant = self::grant_for_link( self::text( $get, 'k' ) );
			if ( null === $grant ) {
				return self::link_error();
			}
			return self::link_confirm( $grant, self::text( $get, 'k' ) );
		}

		if ( ! self::nonce_ok( $post, 'happyaccess_link' ) ) {
			return self::link_error();
		}

		$wait = RateLimiter::attempt(
			self::LINK_ACTION,
			'ip',
			RateLimiter::ip_subject(),
			(int) Settings::get( 'security.max_attempts' ),
			(int) Settings::get( 'security.attempt_window' ),
			(int) Settings::get( 'security.lockout_duration' )
		);
		if ( $wait > 0 ) {
			return self::link_error( self::locked_error( $wait ) );
		}

		$grant = self::grant_for_link( self::text( $post, 'k' ) );
		if ( null === $grant || ! Grants::record_login( $grant['id'] ) ) {
			return self::link_error();
		}

		$response = self::log_in( $grant, 'link' );
		return null === $response ? self::link_error() : $response;
	}

	/**
	 * The screen shown after a temp session ends.
	 *
	 * @param array $get Unslashed $_GET.
	 * @return array Response for Screens::respond().
	 */
	public static function handle_ended( array $get ) {
		$reason = sanitize_key( self::text( $get, 'reason' ) );

		switch ( $reason ) {
			case 'expired':
				$text = __( 'Your support access has expired.', 'happyaccess' );
				break;
			case 'revoked':
				$text = __( 'Your support access was revoked by the site owner.', 'happyaccess' );
				break;
			case 'suspended':
				$text = __( 'Your support access is paused by the site owner.', 'happyaccess' );
				break;
			default:
				$text = __( 'Your support access has ended.', 'happyaccess' );
				break;
		}

		return array(
			'type'    => 'render',
			'title'   => __( 'Support access ended', 'happyaccess' ),
			'body'    => '<p>' . esc_html( $text ) . '</p>',
			'errors'  => null,
			'message' => '',
		);
	}

	/**
	 * Signs the grant's temp user in. Call only after record_login() counted
	 * the login.
	 *
	 * @param array  $grant  Grant.
	 * @param string $method code or link.
	 * @return array|null Redirect response, or null when the user could not be created.
	 */
	public static function log_in( array $grant, $method ) {
		try {
			$user_id = TempUsers::get_or_create( $grant );
		} catch ( \Exception $e ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( false === $user ) {
			return null;
		}

		wp_set_auth_cookie( $user_id, false );
		wp_set_current_user( $user_id );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, so login listeners see this sign-in.

		$action = 'link' === $method ? self::LINK_ACTION : self::CODE_ACTION;
		RateLimiter::clear( $action, 'ip', RateLimiter::ip_subject() );

		AuditLog::add(
			'login_success',
			array(
				'token_id' => $grant['id'],
				'user_id'  => $user_id,
				'summary'  => sprintf(
					/* translators: %s: grant label. */
					__( 'Support access used: %s', 'happyaccess' ),
					$grant['label']
				),
				'meta'     => array( 'method' => $method ),
			)
		);

		$fresh = Grants::get( $grant['id'] );
		$fresh = null === $fresh ? $grant : $fresh;
		Notifications::login( $fresh, 1 === (int) $fresh['login_count'], $method );

		$to = '' !== $grant['redirect_to'] ? $grant['redirect_to'] : admin_url();
		return array(
			'type' => 'redirect',
			'url'  => $to,
		);
	}

	/**
	 * Whether a looked-up grant may be used from this client.
	 *
	 * @param array|null $grant Grant or null.
	 * @return bool
	 */
	private static function may_log_in( $grant ) {
		return is_array( $grant ) && 'active' === $grant['status'] && self::ip_allowed( $grant );
	}

	/**
	 * Checks the grant's IP allowlist against the client. An empty list
	 * allows every client. Both sides go through inet_pton(), so notation
	 * differences can't cause a false match or a false miss.
	 *
	 * @param array $grant Grant.
	 * @return bool
	 */
	private static function ip_allowed( array $grant ) {
		$allowed = isset( $grant['restrictions']['ips'] ) ? (array) $grant['restrictions']['ips'] : array();
		if ( empty( $allowed ) ) {
			return true;
		}

		$client = ClientIp::valid( ClientIp::get() ) ? inet_pton( ClientIp::canonical( ClientIp::get() ) ) : false;
		if ( false === $client ) {
			return false;
		}
		foreach ( $allowed as $ip ) {
			$packed = is_string( $ip ) && ClientIp::valid( $ip ) ? inet_pton( $ip ) : false;
			if ( false !== $packed && hash_equals( $packed, $client ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Active grant for a link key, or null.
	 *
	 * @param string $key Link key.
	 * @return array|null
	 */
	private static function grant_for_link( $key ) {
		$grant = '' === $key ? null : Grants::find_by_link( $key );
		return self::may_log_in( $grant ) ? $grant : null;
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
	 * The one error every failed code try shows.
	 *
	 * @return \WP_Error
	 */
	private static function code_error() {
		return new \WP_Error( 'invalid_code', __( "That code didn't work. Check it and try again.", 'happyaccess' ) );
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
			sprintf(
				/* translators: %d: minutes. */
				_n( 'Too many attempts. Try again in %d minute.', 'Too many attempts. Try again in %d minutes.', $minutes, 'happyaccess' ),
				$minutes
			)
		);
	}

	/**
	 * Text for the migration gate.
	 *
	 * @return string
	 */
	private static function updating_text() {
		return __( 'Support access is updating. Try again in a minute.', 'happyaccess' );
	}

	/**
	 * Code form response.
	 *
	 * @param \WP_Error|null $errors Errors to show.
	 * @return array
	 */
	private static function code_screen( $errors = null ) {
		$body  = '<form name="happyaccess-code" method="post" action="' . esc_url( Router::url( 'code' ) ) . '">';
		$body .= '<p><label for="happyaccess-pwd">' . esc_html__( 'Support access code', 'happyaccess' ) . '</label>';
		$body .= '<input type="text" name="pwd" id="happyaccess-pwd" class="input" value="" size="20" inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" /></p>';
		$body .= wp_nonce_field( 'happyaccess_code', '_wpnonce', false, false );
		$body .= '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Log in', 'happyaccess' ) . '" /></p>';
		$body .= '</form>';

		return array(
			'type'    => 'render',
			'title'   => __( 'Support access', 'happyaccess' ),
			'body'    => $body,
			'errors'  => $errors,
			'message' => '',
		);
	}

	/**
	 * Link confirm page. Logging in needs a POST from this form.
	 *
	 * @param array  $grant Grant.
	 * @param string $key   Link key from the URL.
	 * @return array
	 */
	private static function link_confirm( array $grant, $key ) {
		$question = sprintf(
			/* translators: 1: site name, 2: grant label. */
			__( 'Log in to %1$s as %2$s?', 'happyaccess' ),
			get_bloginfo( 'name' ),
			$grant['label']
		);

		$body  = '<form name="happyaccess-link" method="post" action="' . esc_url( Router::url( 'link' ) ) . '">';
		$body .= '<p>' . esc_html( $question ) . '</p>';
		$body .= '<input type="hidden" name="k" value="' . esc_attr( $key ) . '" />';
		$body .= wp_nonce_field( 'happyaccess_link', '_wpnonce', false, false );
		$body .= '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Log in', 'happyaccess' ) . '" /></p>';
		$body .= '</form>';

		return array(
			'type'    => 'render',
			'title'   => __( 'Support access', 'happyaccess' ),
			'body'    => $body,
			'errors'  => null,
			'message' => '',
		);
	}

	/**
	 * Link failure response.
	 *
	 * @param \WP_Error|null $errors Error to show. Defaults to the generic link error.
	 * @return array
	 */
	private static function link_error( $errors = null ) {
		if ( null === $errors ) {
			$errors = new \WP_Error( 'invalid_link', __( "This link has expired or isn't valid anymore.", 'happyaccess' ) );
		}
		return array(
			'type'    => 'render',
			'title'   => __( 'Support access', 'happyaccess' ),
			'body'    => '',
			'errors'  => $errors,
			'message' => '',
		);
	}
}
