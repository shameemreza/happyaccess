<?php
/**
 * Per-role login policy for passwordless login.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\Passwordless;

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Settings;
use HappyAccess\Login\Router;

defined( 'ABSPATH' ) || exit;

/**
 * A role can be set to "email code only". A password login for such an
 * account is refused, in wp-login.php and in XML-RPC alike, because both go
 * through the authenticate filter. Application passwords are separate keys
 * the user made on purpose, so they are left alone. Setting
 * HAPPYACCESS_ALLOW_PASSWORD_LOGIN to true in wp-config.php turns the policy
 * off, which is the way back in when email delivery breaks. The policy only
 * exists while the feature is on, because Feature::register() adds the filter.
 */
final class RolePolicy {

	const EITHER     = 'either';
	const EMAIL_ONLY = 'email_only';

	/**
	 * Ids of users that an application password just authenticated.
	 *
	 * @var array
	 */
	private static $app_password_users = array();

	/**
	 * Adds the authenticate filter. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate' ), 30, 3 );
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'note_application_password' ), 10, 1 );
	}

	/**
	 * Remembers a user that an application password authenticated, so the
	 * policy does not refuse it later in the same authenticate run.
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
	 * The policy for a user: email_only when any of the user's roles is set to
	 * it, otherwise either. A super admin on multisite is always either.
	 *
	 * @param \WP_User $user User.
	 * @return string
	 */
	public static function for_user( \WP_User $user ) {
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return self::EITHER;
		}

		$policy = Settings::get( 'passwordless.role_policy', array() );
		if ( ! is_array( $policy ) ) {
			return self::EITHER;
		}

		foreach ( (array) $user->roles as $role ) {
			if ( isset( $policy[ $role ] ) && self::EMAIL_ONLY === $policy[ $role ] ) {
				return self::EMAIL_ONLY;
			}
		}
		return self::EITHER;
	}

	/**
	 * Refuses a password login for an email-only account. A WP_User result
	 * (the password was right) and an incorrect_password error (it was wrong)
	 * both get the same refusal, so the message does not tell an attacker
	 * whether a guessed password is correct. Other errors and null pass
	 * through. Temp support users are left to Session::block_core_auth() at
	 * priority 99, so they never see this message. A result with no password
	 * is not a password login (the cookie reauth check, single sign-on
	 * plugins), and an application password login was made on purpose, so
	 * neither is refused.
	 *
	 * @param \WP_User|\WP_Error|null $user     Authentication result.
	 * @param string                  $username Username or email typed.
	 * @param string                  $password Password typed.
	 * @return \WP_User|\WP_Error|null
	 */
	public static function filter_authenticate( $user, $username = '', $password = '' ) {
		if ( $user instanceof \WP_User ) {
			$account = $user;
		} elseif ( $user instanceof \WP_Error && in_array( 'incorrect_password', $user->get_error_codes(), true ) ) {
			$account = self::user_from_login( $username );
		} else {
			return $user;
		}

		if ( ! $account instanceof \WP_User ) {
			return $user;
		}
		if ( self::password_login_allowed() ) {
			return $user;
		}
		if ( ! is_string( $password ) || '' === $password ) {
			return $user;
		}
		if ( isset( self::$app_password_users[ $account->ID ] ) || Capabilities::is_temp_user( $account->ID ) ) {
			return $user;
		}
		if ( self::EMAIL_ONLY !== self::for_user( $account ) ) {
			return $user;
		}

		return new \WP_Error( 'happyaccess_email_only', self::refusal_message() );
	}

	/**
	 * Finds the account a typed username or email points at, the way core
	 * does: by email when it looks like one, otherwise by login.
	 *
	 * @param mixed $username Username or email typed.
	 * @return \WP_User|null
	 */
	private static function user_from_login( $username ) {
		if ( ! is_string( $username ) || '' === $username ) {
			return null;
		}

		$user = false;
		if ( is_email( $username ) ) {
			$user = get_user_by( 'email', $username );
		}
		if ( ! $user ) {
			$user = get_user_by( 'login', $username );
		}
		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * The wp-config switch that restores password login for every role.
	 *
	 * @return bool
	 */
	private static function password_login_allowed() {
		return defined( 'HAPPYACCESS_ALLOW_PASSWORD_LOGIN' ) && true === (bool) constant( 'HAPPYACCESS_ALLOW_PASSWORD_LOGIN' );
	}

	/**
	 * The error text. login_header() prints errors raw, so the text is
	 * escaped here and the link is built with esc_url().
	 *
	 * @return string
	 */
	private static function refusal_message() {
		$args = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the value is validated before it is used.
		$redirect = LoginSteps::valid_redirect( isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : '' );
		if ( '' !== $redirect ) {
			$args['redirect_to'] = $redirect;
		}

		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Router::url( 'request', $args ) ),
			esc_html__( 'Email me a login code', 'happyaccess' )
		);

		return sprintf(
			/* translators: %s: link with the text "Email me a login code". */
			esc_html__( "This account logs in with an email code. Use '%s' below.", 'happyaccess' ),
			$link
		);
	}
}
