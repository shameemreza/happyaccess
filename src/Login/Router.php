<?php
/**
 * Routes wp-login.php?action=happyaccess&step=... to step handlers.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Login;

defined( 'ABSPATH' ) || exit;

/**
 * Every HappyAccess login screen lives under wp-login.php so hidden-login
 * plugins, custom login styles and page cache exclusions keep working.
 */
final class Router {

	const ACTION = 'happyaccess';

	/**
	 * Step handlers keyed by step name.
	 *
	 * @var array
	 */
	private static $steps = array();

	/**
	 * Hooks the wp-login.php action.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'login_form_' . self::ACTION, array( __CLASS__, 'dispatch' ) );
	}

	/**
	 * Registers a step handler.
	 *
	 * @param string   $step    Step name.
	 * @param callable $handler Handler; it renders or redirects.
	 * @return void
	 */
	public static function add_step( $step, $handler ) {
		self::$steps[ sanitize_key( $step ) ] = $handler;
	}

	/**
	 * Handler for a step, or null.
	 *
	 * @param string $step Step name.
	 * @return callable|null
	 */
	public static function resolve( $step ) {
		$step = sanitize_key( $step );
		return isset( self::$steps[ $step ] ) ? self::$steps[ $step ] : null;
	}

	/**
	 * URL of a step, built on wp_login_url() so login URL filters apply.
	 *
	 * @param string $step Step name.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $step, array $args = array() ) {
		$query = array(
			'action' => self::ACTION,
			'step'   => sanitize_key( $step ),
		);
		foreach ( $args as $key => $value ) {
			$query[ sanitize_key( $key ) ] = rawurlencode( (string) $value );
		}
		return add_query_arg( $query, wp_login_url() );
	}

	/**
	 * Step from the request.
	 *
	 * @return string
	 */
	public static function current_step() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; each step checks its own nonce or signed key.
		return isset( $_REQUEST['step'] ) ? sanitize_key( wp_unslash( $_REQUEST['step'] ) ) : '';
	}

	/**
	 * Runs the current step, or sends unknown steps to the normal login.
	 *
	 * @return void
	 */
	public static function dispatch() {
		nocache_headers();
		$handler = self::resolve( self::current_step() );
		if ( null === $handler ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		call_user_func( $handler );
		exit;
	}

	/**
	 * Removes all steps.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$steps = array();
	}
}
