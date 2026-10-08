<?php
/**
 * WP-CLI recovery for two-step login.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

defined( 'ABSPATH' ) || exit;

/**
 * Turns off two-step login for a user who is locked out.
 */
final class Cli {

	/**
	 * Registers the command when WP-CLI is running.
	 *
	 * @return void
	 */
	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'happyaccess twostep', self::class );
		}
	}

	/**
	 * Turns off two-step login for one user.
	 *
	 * The app, email codes, backup codes and the grace period all start over.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User id, login or email address.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp happyaccess twostep reset 12
	 *     $ wp happyaccess twostep reset sam
	 *     $ wp happyaccess twostep reset sam@example.com
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function reset( $args, $assoc_args ) {
		unset( $assoc_args );
		$result = self::reset_user( 1 === count( $args ) ? (string) reset( $args ) : '' );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}
		\WP_CLI::success( $result );
	}

	/**
	 * Resets one user's two-step login and logs it.
	 *
	 * @param string $which User id, login or email address.
	 * @return string|\WP_Error The message to print.
	 */
	public static function reset_user( $which ) {
		$user = self::find_user( $which );
		if ( null === $user ) {
			return new \WP_Error( 'happyaccess_no_user', __( 'No user matches that id, login or email address.', 'happyaccess' ) );
		}

		UserState::reset(
			$user->ID,
			array(
				'source'  => 'cli',
				'user_id' => get_current_user_id(),
			)
		);

		/* translators: %s: user login. */
		return sprintf( __( 'Two-step login is off for %s.', 'happyaccess' ), $user->user_login );
	}

	/**
	 * The user named by an id, a login or an email address.
	 *
	 * @param string $which What was typed.
	 * @return \WP_User|null
	 */
	private static function find_user( $which ) {
		$which = trim( (string) $which );
		if ( '' === $which ) {
			return null;
		}
		$user = false;
		if ( ctype_digit( $which ) ) {
			$user = get_user_by( 'id', (int) $which );
		}
		if ( false === $user && is_email( $which ) ) {
			$user = get_user_by( 'email', $which );
		}
		if ( false === $user ) {
			$user = get_user_by( 'login', $which );
		}
		return $user instanceof \WP_User ? $user : null;
	}
}
