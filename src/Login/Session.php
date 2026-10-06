<?php
/**
 * Session rules for temporary users.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Login;

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Caps the auth cookie at the grant's expiry and checks the grant on every
 * logged-in request, so access ends on time without WP-Cron. A temp user
 * whose grant can't be found is logged out. Three hooks cover the ways in:
 * enforce() on init handles cookie sessions, the determine_current_user
 * filter handles re-resolution after init such as REST, and the authenticate
 * filter keeps temp users out of core's password, application password and
 * XML-RPC logins entirely.
 */
final class Session {

	/**
	 * Grant resolver: function ( int $user_id ): ?array.
	 *
	 * @var callable|null
	 */
	private static $resolver = null;

	/**
	 * Per-request cache of resolved grants.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Hooks the cookie filter and the per-request check.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'cap_cookie' ), 99, 3 );
		add_action( 'init', array( __CLASS__, 'enforce' ), 1 );
		add_filter( 'determine_current_user', array( __CLASS__, 'filter_current_user' ), 99 );
		add_filter( 'authenticate', array( __CLASS__, 'block_core_auth' ), 99, 1 );
	}

	/**
	 * Keeps temp users out of core's authenticate chain (password, application
	 * password, wp-login.php, XML-RPC). They sign in through the HappyAccess
	 * link or code flow, which sets the auth cookie directly.
	 *
	 * @param \WP_User|\WP_Error|null $user Authentication result.
	 * @return \WP_User|\WP_Error|null
	 */
	public static function block_core_auth( $user ) {
		if ( $user instanceof \WP_User && Capabilities::is_temp_user( $user->ID ) ) {
			return new \WP_Error(
				'happyaccess_temp_user',
				__( 'Temporary support accounts sign in with their access link or code.', 'happyaccess' )
			);
		}
		return $user;
	}

	/**
	 * Drops an ended temp user when the current user is resolved again after
	 * init, such as a REST request. XML-RPC and password logins are covered by
	 * block_core_auth().
	 *
	 * The filter only acts after init, so enforce() keeps the logout path
	 * (wp_logout, the ended action and the redirect). The grant resolver must
	 * never call get_current_user_id(), wp_get_current_user() or
	 * current_user_can(), because that would re-enter user resolution.
	 *
	 * @param int|false $user_id User id or false.
	 * @return int|false
	 */
	public static function filter_current_user( $user_id ) {
		if ( ! did_action( 'init' ) ) {
			return $user_id;
		}
		if ( empty( $user_id ) || ! Capabilities::is_temp_user( (int) $user_id ) ) {
			return $user_id;
		}
		if ( null !== self::end_reason( (int) $user_id ) ) {
			return false;
		}
		return $user_id;
	}

	/**
	 * Sets the grant resolver. Support Access provides it in Stage 2.
	 *
	 * @param callable|null $resolver Resolver or null.
	 * @return void
	 */
	public static function set_resolver( $resolver ) {
		self::$resolver = is_callable( $resolver ) ? $resolver : null;
		self::$cache    = array();
	}

	/**
	 * Clears the cached grant states and keeps the resolver, so a change to a
	 * grant takes effect within the same request.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = array();
	}

	/**
	 * Clears the resolver and cache.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$resolver = null;
		self::$cache    = array();
	}

	/**
	 * Grant state for a temp user, null for anyone else.
	 *
	 * @param int $user_id User id.
	 * @return array|null array( expires_at, state ).
	 */
	public static function grant( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! Capabilities::is_temp_user( $user_id ) ) {
			return null;
		}
		if ( ! array_key_exists( $user_id, self::$cache ) ) {
			// Fail closed while the resolver runs, so a resolver that asks for this grant again gets "revoked" instead of recursing.
			self::$cache[ $user_id ] = array(
				'expires_at' => 0,
				'state'      => 'revoked',
			);
			$grant                   = self::$resolver ? call_user_func( self::$resolver, $user_id ) : null;
			self::$cache[ $user_id ] = wp_parse_args(
				is_array( $grant ) ? $grant : array(),
				array(
					'expires_at' => 0,
					'state'      => 'revoked',
				)
			);
		}
		return self::$cache[ $user_id ];
	}

	/**
	 * Why a session must end, or null to keep it.
	 *
	 * @param int $user_id User id.
	 * @return string|null expired, revoked or suspended.
	 */
	public static function end_reason( $user_id ) {
		$grant = self::grant( $user_id );
		if ( null === $grant ) {
			return null;
		}
		if ( 'active' !== $grant['state'] ) {
			return in_array( $grant['state'], array( 'revoked', 'suspended' ), true ) ? $grant['state'] : 'revoked';
		}
		if ( (int) $grant['expires_at'] <= Clock::now() ) {
			return 'expired';
		}
		return null;
	}

	/**
	 * Sets the auth cookie length of a temp user to the time left on the
	 * grant, even when that is longer than the default. enforce() still checks
	 * the grant on every request. Other users keep the length they asked for.
	 *
	 * @param int  $length   Cookie length in seconds.
	 * @param int  $user_id  User id.
	 * @param bool $remember Remember me.
	 * @return int
	 */
	public static function cap_cookie( $length, $user_id, $remember ) {
		unset( $remember );
		$grant = self::grant( $user_id );
		if ( null === $grant ) {
			return (int) $length;
		}
		if ( 'active' !== $grant['state'] ) {
			return 1;
		}
		return max( 1, (int) $grant['expires_at'] - Clock::now() );
	}

	/**
	 * Logs out a temp user whose access has ended. WP-CLI is deliberately exempt.
	 *
	 * @return void
	 */
	public static function enforce() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		$user_id = get_current_user_id();
		$reason  = $user_id ? self::end_reason( $user_id ) : null;
		if ( null === $reason ) {
			return;
		}

		wp_logout();
		do_action( 'happyaccess_session_ended', $user_id, $reason );

		if ( wp_doing_ajax() || wp_doing_cron() || self::is_rest_request() ) {
			return;
		}

		wp_safe_redirect( Router::url( 'ended', array( 'reason' => $reason ) ) );
		exit;
	}

	/**
	 * REST requests are not known yet on init, so check the URL.
	 *
	 * @return bool
	 */
	private static function is_rest_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		if ( isset( $_GET['rest_route'] ) ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' );
	}
}
