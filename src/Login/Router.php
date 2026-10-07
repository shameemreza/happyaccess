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
	 * Registers a step handler. A handler that can't be called is ignored, so
	 * a typo can't turn into a fatal error on the login screen.
	 *
	 * @param string   $step    Step name.
	 * @param callable $handler Handler; it renders or redirects.
	 * @return void
	 */
	public static function add_step( $step, $handler ) {
		if ( ! is_callable( $handler ) ) {
			return;
		}
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
	 * The result is a plain URL. Wrap it in esc_url() when printing it into
	 * HTML. Extra args that aren't text or numbers are dropped, and they can
	 * never replace the action or the step.
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
		$extra = array();
		foreach ( $args as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}
			$extra[ $key ] = rawurlencode( (string) $value );
		}
		// The union keeps the left side, so an extra arg can't replace the action or the step.
		return add_query_arg( $query + $extra, wp_login_url() );
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
	 * The wp-login.php hook: runs the current step, then ends the request.
	 *
	 * @return void
	 */
	public static function dispatch() {
		self::run();
		exit;
	}

	/**
	 * Runs the current step, or sends unknown steps to the normal login. The
	 * page is marked uncacheable first, for every step, so page caches never
	 * store a screen that carries a nonce or a one-time key. Split out of
	 * dispatch() so tests can run it without ending the request.
	 *
	 * @return bool True when a step handler ran, false when the visitor was redirected.
	 */
	public static function run() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Page cache plugins read this exact constant.
		}
		nocache_headers();
		$handler = self::resolve( self::current_step() );
		if ( null === $handler ) {
			wp_safe_redirect( wp_login_url() );
			return false;
		}
		call_user_func( $handler );
		return true;
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
