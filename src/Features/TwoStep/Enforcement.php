<?php
/**
 * Per-role two-step policy and the grace period.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\Clock;
use HappyAccess\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Each role is off, optional or required. A user with a required role and
 * no method set up gets the setup screen at login. During the grace period
 * that screen can be put off with "Later"; after it, the login finishes only
 * once setup is done.
 *
 * The grace period starts at the first login that reaches the setup screen.
 * With grace by logins, every such login counts one, and the count includes
 * the login in progress: with 3 grace logins, logins 1 to 3 may skip and
 * login 4 may not. With grace by days, it ends that many days after it
 * started.
 */
final class Enforcement {

	const OFF      = 'off';
	const OPTIONAL = 'optional';
	const REQUIRED = 'required';

	/**
	 * Policies worked out in this request, by site (or main, for a super
	 * admin), user and roles.
	 *
	 * @var array<string,string>
	 */
	private static $policies = array();

	/**
	 * The policy for a user: required when any of their roles is required,
	 * else optional when any is optional or missing from the setting, else
	 * off. While HappyAccess is network active, the setting is the main
	 * site's, applied to the user's roles on this site. A super admin on
	 * multisite follows the main site's policy, with administrator added to
	 * their roles there, because they act as one on every site.
	 *
	 * The answer is kept for the rest of the request, so one login doesn't
	 * switch to the main site again on every check. A settings or role
	 * change clears it.
	 *
	 * @param \WP_User $user The user.
	 * @return string off, optional or required.
	 */
	public static function policy( \WP_User $user ) {
		$super = is_multisite() && is_super_admin( $user->ID );
		$key   = ( $super ? 'main' : (string) get_current_blog_id() ) . ':' . (int) $user->ID . ':' . implode( ',', (array) $user->roles );
		if ( isset( self::$policies[ $key ] ) ) {
			return self::$policies[ $key ];
		}
		self::watch();

		if ( $super ) {
			switch_to_blog( get_main_site_id() );
			try {
				$main   = get_userdata( $user->ID );
				$roles  = $main instanceof \WP_User ? (array) $main->roles : array();
				$policy = self::resolve( Settings::shared( 'two_step.role_policy', array() ), array_merge( $roles, array( 'administrator' ) ) );
			} finally {
				restore_current_blog();
			}
		} else {
			$policy = self::resolve( Settings::shared( 'two_step.role_policy', array() ), (array) $user->roles );
		}

		self::$policies[ $key ] = $policy;
		return $policy;
	}

	/**
	 * Forgets the policies worked out in this request.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$policies = array();
	}

	/**
	 * Clears the kept policies when the settings or a user's roles change.
	 * Adding the same hooks again is harmless.
	 *
	 * @return void
	 */
	private static function watch() {
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $hook ) {
			add_action( $hook . Settings::OPTION, array( __CLASS__, 'flush_cache' ) );
		}
		foreach ( array( 'set_user_role', 'add_user_role', 'remove_user_role', 'granted_super_admin', 'revoked_super_admin' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_cache' ) );
		}
	}

	/**
	 * Notes one login that reached the setup screen: starts the grace period
	 * and, with grace by logins, counts the login. The count stops one past
	 * the limit, so it can't grow without end.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function note_login( $user_id ) {
		$user_id = (int) $user_id;
		UserState::start_grace( $user_id, Clock::now() );
		if ( 'logins' !== self::grace_type() ) {
			return;
		}
		if ( UserState::grace_logins_used( $user_id ) <= self::grace_logins() ) {
			UserState::count_grace_login( $user_id );
		}
	}

	/**
	 * Whether the user may still put setup off. False before the grace
	 * period started, so a request that skipped note_login() never gets
	 * "Later".
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function in_grace( $user_id ) {
		if ( UserState::grace_started_at( $user_id ) < 1 ) {
			return false;
		}
		if ( 'days' === self::grace_type() ) {
			return Clock::now() < self::grace_ends_at( $user_id );
		}
		return UserState::grace_logins_used( $user_id ) <= self::grace_logins();
	}

	/**
	 * Logins still allowed to skip setup after this one, with grace by logins.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function skips_left( $user_id ) {
		return max( 0, self::grace_logins() - UserState::grace_logins_used( $user_id ) );
	}

	/**
	 * Unix time the grace period ends, with grace by days. 0 before it started.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function grace_ends_at( $user_id ) {
		$started = UserState::grace_started_at( $user_id );
		return $started < 1 ? 0 : $started + self::grace_days() * DAY_IN_SECONDS;
	}

	/**
	 * The grace type setting: logins or days.
	 *
	 * @return string
	 */
	public static function grace_type() {
		return 'days' === Settings::shared( 'two_step.grace_type' ) ? 'days' : 'logins';
	}

	/**
	 * Logins in the grace period.
	 *
	 * @return int
	 */
	private static function grace_logins() {
		return max( 1, (int) Settings::shared( 'two_step.grace_logins' ) );
	}

	/**
	 * Days in the grace period.
	 *
	 * @return int
	 */
	private static function grace_days() {
		return max( 1, (int) Settings::shared( 'two_step.grace_days' ) );
	}

	/**
	 * The strongest policy among the roles. A role missing from the policy
	 * counts as optional, and so does a user with no roles on the site, so
	 * off is only ever a choice someone made.
	 *
	 * @param mixed    $policy Role policy setting, role slug to choice.
	 * @param string[] $roles  Role slugs.
	 * @return string
	 */
	private static function resolve( $policy, array $roles ) {
		$policy = is_array( $policy ) ? $policy : array();
		if ( array() === $roles ) {
			return self::OPTIONAL;
		}
		$found = self::OFF;
		foreach ( $roles as $role ) {
			$choice = isset( $policy[ $role ] ) ? $policy[ $role ] : self::OPTIONAL;
			if ( self::REQUIRED === $choice ) {
				return self::REQUIRED;
			}
			if ( self::OFF !== $choice ) {
				$found = self::OPTIONAL;
			}
		}
		return $found;
	}
}
