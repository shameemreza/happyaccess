<?php
/**
 * REST routes behind the two-step section of the profile.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Internal;
use HappyAccess\Core\RateLimiter;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;
use HappyAccess\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Every route acts on the logged-in user only, through the cookie and the
 * wp_rest nonce, and refuses Support Access temp users and application
 * passwords. Changing a method needs a re-check: the current password, an
 * app code or a backup code within the last 15 minutes. It is stored in
 * user meta as a map of the login session (a hash of its token) to the
 * Unix time it passed, so passing it in one browser unlocks nothing in
 * another.
 *
 * The app secret waits for its first code in a transient named after the
 * user, encrypted with the network key, for 10 minutes. Every response is
 * no-store, because two of them carry a secret or the backup codes.
 */
final class RestController {

	const BASE = '/twostep/';

	/**
	 * Prefix of the transient that holds the app secret waiting for its first code.
	 */
	const TRANSIENT = 'happyaccess_ts_profile_';

	/**
	 * Seconds a re-check lasts.
	 */
	const RECHECK_TTL = 900;

	/**
	 * Seconds the app secret waits for its first code.
	 */
	const SETUP_TTL = 600;

	/**
	 * Rate limit action of the re-check.
	 */
	const RECHECK_ACTION = 'twostep_recheck';

	/**
	 * Hooks the routes and the no-store header. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store' ), 10, 3 );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			Routes::NS,
			self::BASE . 'recheck',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'recheck' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'args'                => array(
					// A password is checked as typed, so it is not cleaned like text.
					'password' => array(
						'type'    => 'string',
						'default' => '',
					),
					'code'     => self::code_arg(),
				),
			)
		);

		$changes = array(
			'app/begin'         => 'app_begin',
			'app/confirm'       => 'app_confirm',
			'app/disable'       => 'app_disable',
			'email/enable'      => 'email_enable',
			'email/disable'     => 'email_disable',
			'backup/regenerate' => 'backup_regenerate',
		);
		foreach ( $changes as $path => $callback ) {
			register_rest_route(
				Routes::NS,
				self::BASE . $path,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => array( __CLASS__, 'can_change' ),
					'args'                => 'app/confirm' === $path ? array( 'code' => self::code_arg() ) : array(),
				)
			);
		}
	}

	/**
	 * Lets in a logged-in user who is not a Support Access temp user and did
	 * not sign the request with an application password.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_use() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', __( 'Log in to change two-step login.', 'happyaccess' ), array( 'status' => rest_authorization_required_code() ) );
		}
		if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			return new \WP_Error( 'happyaccess_app_password', __( "An application password can't change two-step login.", 'happyaccess' ), array( 'status' => 403 ) );
		}
		if ( Capabilities::is_temp_user( get_current_user_id() ) ) {
			return new \WP_Error( 'happyaccess_forbidden', __( "Temporary access can't change two-step login.", 'happyaccess' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Lets in a user who may use the routes and passed the re-check within
	 * the last 15 minutes.
	 *
	 * @return true|\WP_Error
	 */
	public static function can_change() {
		$allowed = self::can_use();
		if ( true !== $allowed ) {
			return $allowed;
		}
		if ( ! self::recheck_fresh( get_current_user_id() ) ) {
			return new \WP_Error( 'happyaccess_recheck_required', __( "Confirm it's you first.", 'happyaccess' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Whether the user passed the re-check in this login session within the
	 * last 15 minutes. Never without a session.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function recheck_fresh( $user_id ) {
		$key = self::session_key();
		if ( '' === $key ) {
			return false;
		}
		$map = self::recheck_map( $user_id );
		return isset( $map[ $key ] ) && self::is_fresh( $map[ $key ] );
	}

	/**
	 * POST twostep/recheck: the current password, an app code or a backup
	 * code. Every try counts on the IP limit before anything is checked,
	 * and a pass clears only the IP scope.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function recheck( \WP_REST_Request $request ) {
		$user     = wp_get_current_user();
		$password = (string) $request->get_param( 'password' );
		$code     = trim( (string) $request->get_param( 'code' ) );
		if ( '' === $password && '' === $code ) {
			return new \WP_Error( 'happyaccess_bad_request', __( 'Enter your password or a code.', 'happyaccess' ), array( 'status' => 400 ) );
		}
		$session = self::session_key();
		if ( '' === $session ) {
			return new \WP_Error( 'happyaccess_no_session', __( 'Log in again to change two-step login.', 'happyaccess' ), array( 'status' => 403 ) );
		}

		$wait = RateLimiter::attempt(
			self::RECHECK_ACTION,
			'ip',
			RateLimiter::ip_subject(),
			(int) Settings::get( 'security.max_attempts' ),
			(int) Settings::get( 'security.attempt_window' ),
			(int) Settings::get( 'security.lockout_duration' )
		);
		if ( $wait > 0 ) {
			$minutes = max( 1, (int) ceil( $wait / MINUTE_IN_SECONDS ) );
			return new \WP_Error(
				'happyaccess_locked',
				sprintf(
					/* translators: %d: minutes. */
					_n( 'Too many attempts. Try again in %d minute.', 'Too many attempts. Try again in %d minutes.', $minutes, 'happyaccess' ),
					$minutes
				),
				array( 'status' => 429 )
			);
		}

		$passed = '' !== $password
			? wp_check_password( $password, $user->user_pass, $user->ID )
			: self::check_code( $user->ID, $code );
		if ( ! $passed ) {
			$error = new \WP_Error( 'happyaccess_recheck_failed', __( "That password or code didn't work.", 'happyaccess' ), array( 'status' => 403 ) );
			// A wrong code also counts on the site-wide cap of the login step.
			if ( '' === $password ) {
				Challenge::count_wrong_code();
			}
			do_action( 'wp_login_failed', $user->user_login, $error ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, so security plugins see a wrong re-check as a failed login of this account.
			return $error;
		}

		RateLimiter::clear( self::RECHECK_ACTION, 'ip', RateLimiter::ip_subject() );
		self::remember_recheck( $user->ID, $session );
		return self::respond( array( 'expires_in' => self::RECHECK_TTL ) );
	}

	/**
	 * POST twostep/app/begin: a new secret and its otpauth address. Nothing
	 * is on until app/confirm gets a right code.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function app_begin() {
		$user    = wp_get_current_user();
		$refused = self::refuse_unless_offered( $user );
		if ( null !== $refused ) {
			return $refused;
		}
		// A secret sealed with a key held in memory only could not be opened on the next request.
		if ( ! Secrets::is_network_persisted() ) {
			return self::no_site_key();
		}

		$secret = Totp::new_secret();
		$name   = self::TRANSIENT . $user->ID;
		$sealed = Secrets::encrypt_network( $secret );
		Internal::run(
			static function () use ( $name, $sealed ) {
				set_transient( $name, $sealed, self::SETUP_TTL );
			}
		);

		return self::respond(
			array(
				'secret' => $secret,
				'uri'    => SetupSteps::app_uri( $user, $secret ),
			)
		);
	}

	/**
	 * POST twostep/app/confirm: the first code from the app turns it on. The
	 * code's time step counts as used. The backup codes are made when the
	 * user has none.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function app_confirm( \WP_REST_Request $request ) {
		$user    = wp_get_current_user();
		$refused = self::refuse_unless_offered( $user );
		if ( null !== $refused ) {
			return $refused;
		}

		$name   = self::TRANSIENT . $user->ID;
		$sealed = Internal::run(
			static function () use ( $name ) {
				return get_transient( $name );
			}
		);
		$secret = is_string( $sealed ) && '' !== $sealed ? Secrets::decrypt_network( $sealed ) : null;
		if ( null === $secret || '' === $secret ) {
			return new \WP_Error( 'happyaccess_setup_expired', __( 'The setup key expired. Start again.', 'happyaccess' ), array( 'status' => 400 ) );
		}

		$code = (string) $request->get_param( 'code' );
		$step = '' === $code ? false : Totp::match( $secret, $code, Clock::now(), 0 );
		if ( false === $step ) {
			return new \WP_Error( 'happyaccess_invalid_code', __( "That code didn't work. Try again.", 'happyaccess' ), array( 'status' => 400 ) );
		}

		$enabled = UserState::enable_app( $user->ID, $secret );
		if ( is_wp_error( $enabled ) ) {
			return self::no_site_key();
		}
		UserState::consume_step( $user->ID, $step );
		Internal::run(
			static function () use ( $name ) {
				delete_transient( $name );
			}
		);
		return self::respond( array( 'codes' => self::first_codes( $user->ID ) ) );
	}

	/**
	 * POST twostep/app/disable.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function app_disable() {
		$user = wp_get_current_user();
		return self::respond_after(
			UserState::locked(
				$user->ID,
				static function () use ( $user ) {
					if ( UserState::app_enabled( $user->ID ) && self::is_last_required( $user, 'app' ) ) {
						return self::role_requires();
					}
					UserState::disable_app( $user->ID );
					self::drop_unused_backup_codes( $user->ID );
					return true;
				}
			)
		);
	}

	/**
	 * POST twostep/email/enable. The backup codes are made when the user has none.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function email_enable() {
		$user    = wp_get_current_user();
		$refused = self::refuse_unless_offered( $user );
		if ( null !== $refused ) {
			return $refused;
		}
		UserState::enable_email( $user->ID );
		return self::respond( array( 'codes' => self::first_codes( $user->ID ) ) );
	}

	/**
	 * POST twostep/email/disable.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function email_disable() {
		$user = wp_get_current_user();
		return self::respond_after(
			UserState::locked(
				$user->ID,
				static function () use ( $user ) {
					if ( UserState::email_enabled( $user->ID ) && self::is_last_required( $user, 'email' ) ) {
						return self::role_requires();
					}
					UserState::disable_email( $user->ID );
					self::drop_unused_backup_codes( $user->ID );
					return true;
				}
			)
		);
	}

	/**
	 * POST twostep/backup/regenerate: a new set, which replaces the old one.
	 * The codes are in this response only.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function backup_regenerate() {
		$user = wp_get_current_user();
		if ( ! UserState::is_enabled( $user->ID ) ) {
			return new \WP_Error( 'happyaccess_no_method', __( 'Turn on the authenticator app or email codes first.', 'happyaccess' ), array( 'status' => 409 ) );
		}
		return self::respond( array( 'codes' => self::formatted( BackupCodes::generate( $user->ID ) ) ) );
	}

	/**
	 * Adds Cache-Control: no-store to every response of these routes,
	 * including the refusals WordPress makes before a callback runs.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public static function no_store( $response, $server, $request ) {
		unset( $server );
		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 0 === strpos( (string) $request->get_route(), '/' . Routes::NS . self::BASE ) ) {
			$response->header( 'Cache-Control', 'no-store, max-age=0' );
		}
		return $response;
	}

	/**
	 * Whether turning this method off would leave a user whose role
	 * requires two-step login with no method.
	 *
	 * @param \WP_User $user   The user.
	 * @param string   $method app or email.
	 * @return bool
	 */
	public static function is_last_required( \WP_User $user, $method ) {
		if ( Enforcement::REQUIRED !== Enforcement::policy( $user ) ) {
			return false;
		}
		return 'app' === $method ? ! UserState::email_enabled( $user->ID ) : ! UserState::app_enabled( $user->ID );
	}

	/**
	 * Whether the user's role offers two-step login at all.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function is_offered( \WP_User $user ) {
		return Enforcement::OFF !== Enforcement::policy( $user );
	}

	/**
	 * Checks a re-check code: an app code when it has 6 digits and the app
	 * is on, else a backup code. Either one is used up.
	 *
	 * @param int    $user_id User id.
	 * @param string $code    Typed code.
	 * @return bool
	 */
	private static function check_code( $user_id, $code ) {
		$digits = str_replace( ' ', '', $code );
		if ( 6 === strlen( $digits ) && ctype_digit( $digits ) ) {
			$secret = UserState::totp_secret( $user_id );
			if ( null === $secret ) {
				return false;
			}
			$step = Totp::match( $secret, $digits, Clock::now(), UserState::last_step( $user_id ) );
			return false !== $step && UserState::consume_step( $user_id, $step );
		}
		return BackupCodes::use_code( $user_id, $code );
	}

	/**
	 * With no method left, the backup codes go too, so a set saved long ago
	 * can't come back to life when a method is turned on again.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private static function drop_unused_backup_codes( $user_id ) {
		if ( ! UserState::is_enabled( $user_id ) ) {
			delete_user_meta( (int) $user_id, UserState::META_BACKUP );
		}
	}

	/**
	 * A new set of backup codes when the user has none left, else none.
	 *
	 * @param int $user_id User id.
	 * @return string[] Formatted codes.
	 */
	private static function first_codes( $user_id ) {
		if ( BackupCodes::remaining( $user_id ) > 0 ) {
			return array();
		}
		return self::formatted( BackupCodes::generate( $user_id ) );
	}

	/**
	 * Codes as they are shown: two groups of 5.
	 *
	 * @param string[] $codes Plain codes.
	 * @return string[]
	 */
	private static function formatted( array $codes ) {
		$out = array();
		foreach ( $codes as $code ) {
			$out[] = substr( $code, 0, 5 ) . '-' . substr( $code, 5 );
		}
		return $out;
	}

	/**
	 * The refusal for a role with two-step login off, or null.
	 *
	 * @param \WP_User $user The user.
	 * @return \WP_Error|null
	 */
	private static function refuse_unless_offered( \WP_User $user ) {
		if ( self::is_offered( $user ) ) {
			return null;
		}
		return new \WP_Error( 'happyaccess_not_offered', __( "Two-step login isn't on for your role.", 'happyaccess' ), array( 'status' => 403 ) );
	}

	/**
	 * The refusal to turn off the last method of a required role.
	 *
	 * @return \WP_Error
	 */
	private static function role_requires() {
		return new \WP_Error( 'happyaccess_role_requires', __( 'Your role needs two-step login.', 'happyaccess' ), array( 'status' => 403 ) );
	}

	/**
	 * The error while the site key isn't saved.
	 *
	 * @return \WP_Error
	 */
	private static function no_site_key() {
		return new \WP_Error( 'happyaccess_no_site_key', __( 'The authenticator app could not be set up. Try again later.', 'happyaccess' ), array( 'status' => 503 ) );
	}

	/**
	 * The response of a turn-off: the refusal, or a 200 with no codes.
	 *
	 * @param true|\WP_Error $result Result of the locked change.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function respond_after( $result ) {
		return is_wp_error( $result ) ? $result : self::respond( array( 'codes' => array() ) );
	}

	/**
	 * Hash of the current login session's token, or an empty string when the
	 * request has no session. The token itself is never stored.
	 *
	 * @return string
	 */
	private static function session_key() {
		$token = wp_get_session_token();
		return is_string( $token ) && '' !== $token ? hash( 'sha256', $token ) : '';
	}

	/**
	 * The stored re-checks, session key to Unix time. A value from before
	 * the map, a bare time, counts for no session.
	 *
	 * @param int $user_id User id.
	 * @return array<string,int>
	 */
	private static function recheck_map( $user_id ) {
		$map = get_user_meta( (int) $user_id, UserState::META_RECHECK, true );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Whether a re-check time is inside the last 15 minutes.
	 *
	 * @param mixed $at Unix time.
	 * @return bool
	 */
	private static function is_fresh( $at ) {
		$at = (int) $at;
		return $at > 0 && $at <= Clock::now() && Clock::now() - $at < self::RECHECK_TTL;
	}

	/**
	 * Stores a passed re-check for this session, and drops the ones that
	 * are no longer fresh.
	 *
	 * @param int    $user_id User id.
	 * @param string $session Session key.
	 * @return void
	 */
	private static function remember_recheck( $user_id, $session ) {
		$map = array();
		foreach ( self::recheck_map( $user_id ) as $key => $at ) {
			if ( is_string( $key ) && self::is_fresh( $at ) ) {
				$map[ $key ] = (int) $at;
			}
		}
		$map[ $session ] = Clock::now();
		update_user_meta( (int) $user_id, UserState::META_RECHECK, $map );
	}

	/**
	 * A 200 response with the methods now on.
	 *
	 * @param array $data Response data.
	 * @return \WP_REST_Response
	 */
	private static function respond( array $data ) {
		$data['methods'] = UserState::methods( get_current_user_id() );
		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * The code argument.
	 *
	 * @return array
	 */
	private static function code_arg() {
		return array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		);
	}
}
