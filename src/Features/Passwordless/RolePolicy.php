<?php
/**
 * Per-role login policy for passwordless login.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\Passwordless;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A role can be set to "email code only". A password login for such an
 * account is refused, in wp-login.php and in XML-RPC alike, because both go
 * through the authenticate filter. Application passwords are separate keys
 * the user made on purpose, so they are left alone. Setting
 * HAPPYACCESS_ALLOW_PASSWORD_LOGIN to true in wp-config.php turns the policy
 * off, which is the way back in when email delivery breaks. The policy also
 * stands aside while no login code can be made, because the schema is
 * updating or the site key is not saved. The policy only exists while the
 * feature is on, because Feature::register() adds the filter.
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
	 * Whether the user's password logs them in: the role is not email-only,
	 * or the wp-config switch turned the policy off.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	public static function password_works( \WP_User $user ) {
		return self::EMAIL_ONLY !== self::for_user( $user ) || self::password_login_allowed();
	}

	/**
	 * Refuses a password login for an email-only account. A WP_User result
	 * (the password was right) and an incorrect_password error (it was wrong)
	 * both get core's wrong-password error, so the message tells an attacker
	 * neither whether a guessed password is correct nor that the account
	 * uses email codes. Other errors and null pass
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
		// A user who can't get a login code, for example one with another plugin's two-step login, keeps the password.
		if ( ! Requests::allowed( $account ) ) {
			return $user;
		}

		// While no login code can reach the account, refusing the password would lock it out.
		if ( ! LoginSteps::db_ready() || ! Secrets::is_persisted() ) {
			if ( $user instanceof \WP_User ) {
				AuditLog::add(
					'passwordless_failed',
					array(
						'feature'     => 'passwordless',
						'user_id'     => (int) $account->ID,
						'summary_key' => 'passwordless_policy_paused',
						'meta'        => array( 'reason' => 'policy_suspended' ),
					)
				);
			}
			return $user;
		}

		return self::refusal( $username );
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
	 * Core's error for a wrong password, word for word and in core's own
	 * translation, for what was typed. A right password on an email-only
	 * account gets the same error as a wrong one, and neither says the
	 * account uses email codes, so the error never confirms the policy.
	 * The "Email me a login code" link under the form stays. The strings
	 * are core's own and so carry no text domain on purpose. The lost
	 * password URL is escaped, which matches core's output for any URL
	 * without characters that need escaping.
	 *
	 * @param mixed $username Username or email typed, as core passed it.
	 * @return \WP_Error
	 */
	private static function refusal( $username ) {
		$typed = is_string( $username ) ? $username : '';
		// Same order as core: a match by login gets the username wording, else an email address gets the email wording.
		$by_email = ! get_user_by( 'login', $typed ) && is_email( $typed );
		// phpcs:disable WordPress.WP.I18n.MissingArgDomain -- Core's own strings, so the text matches core's in every language.
		if ( $by_email ) {
			/* translators: %s: Email address. */
			$text = __( '<strong>Error:</strong> The password you entered for the email address %s is incorrect.' );
		} else {
			/* translators: %s: User name. */
			$text = __( '<strong>Error:</strong> The password you entered for the username %s is incorrect.' );
		}
		$text = sprintf( $text, '<strong>' . esc_html( $typed ) . '</strong>' ) . ' <a href="' . esc_url( wp_lostpassword_url() ) . '">' . __( 'Lost your password?' ) . '</a>';
		// phpcs:enable WordPress.WP.I18n.MissingArgDomain
		return new \WP_Error( 'incorrect_password', $text );
	}
}
