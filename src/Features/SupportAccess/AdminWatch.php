<?php
/**
 * Flags administrator accounts a support pass makes or changes.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Custom and full passes may create or change administrator accounts. That is
 * allowed, but the site owner is told: one log entry and one email for each
 * account, once per request. HappyAccess's own writes never trigger it.
 */
final class AdminWatch {

	/**
	 * User ids already flagged during this request.
	 *
	 * @var int[]
	 */
	private static $seen = array();

	/**
	 * Hooks account creation, role changes and login detail changes.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'user_register', array( __CLASS__, 'check' ), 20, 2 );
		add_action( 'set_user_role', array( __CLASS__, 'check' ), 20, 3 );
		add_action( 'profile_update', array( __CLASS__, 'check_changed' ), 20, 2 );
	}

	/**
	 * Flags a new administrator account, or a user promoted to administrator.
	 *
	 * @param int $user_id Id of the user.
	 * @return void
	 */
	public static function check( $user_id ) {
		$user_id = (int) $user_id;
		$grant   = self::acting_grant( $user_id );
		if ( null === $grant ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		self::$seen[ $user_id ] = true;
		AuditLog::add(
			'admin_account_created',
			array(
				'feature'  => 'support',
				'token_id' => (int) $grant['id'],
				'user_id'  => get_current_user_id(),
				'summary'  => sprintf(
					/* translators: %s: user login. */
					__( 'Made an administrator account: %s', 'happyaccess' ),
					$user->user_login
				),
				'meta'     => array( 'user_id' => $user_id ),
			)
		);
		Notifications::admin_created( $grant, $user );
	}

	/**
	 * Flags a new email or password on an existing administrator account.
	 *
	 * @param int      $user_id       Id of the user that changed.
	 * @param \WP_User $old_user_data User before the change.
	 * @return void
	 */
	public static function check_changed( $user_id, $old_user_data = null ) {
		$user_id = (int) $user_id;
		if ( ! $old_user_data instanceof \WP_User ) {
			return;
		}
		$grant = self::acting_grant( $user_id );
		if ( null === $grant ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$fields = array();
		if ( $old_user_data->user_email !== $user->user_email ) {
			$fields[] = 'email';
		}
		if ( $old_user_data->user_pass !== $user->user_pass ) {
			$fields[] = 'password';
		}
		if ( empty( $fields ) ) {
			return;
		}

		self::$seen[ $user_id ] = true;
		AuditLog::add(
			'admin_account_changed',
			array(
				'feature'  => 'support',
				'token_id' => (int) $grant['id'],
				'user_id'  => get_current_user_id(),
				'summary'  => sprintf(
					/* translators: %s: user login. */
					__( 'Changed login details for administrator: %s', 'happyaccess' ),
					$user->user_login
				),
				'meta'     => array(
					'user_id' => $user_id,
					'fields'  => $fields,
				),
			)
		);
		Notifications::admin_changed( $grant, $user, $fields );
	}

	/**
	 * Forgets the flagged users. For tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$seen = array();
	}

	/**
	 * The grant of the acting temp user, when this change should be flagged:
	 * a temp user's own action on another administrator, not seen yet.
	 *
	 * @param int $user_id Id of the user that was made or changed.
	 * @return array|null
	 */
	private static function acting_grant( $user_id ) {
		if ( $user_id <= 0 || isset( self::$seen[ $user_id ] ) || ! ActivityTracker::tracking() ) {
			return null;
		}
		$actor = get_current_user_id();
		if ( $user_id === $actor || ! user_can( $user_id, 'manage_options' ) ) {
			return null;
		}
		$grant = Grants::get( Capabilities::grant_id( $actor ) );
		return is_array( $grant ) && ! empty( $grant['id'] ) ? $grant : null;
	}
}
