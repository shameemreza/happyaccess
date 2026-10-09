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
 * account, once per request. The same goes for a full pass, or a custom pass
 * that runs on trust, giving a role admin-level caps or pointing the default
 * role at such a role. Admin-level means any cap in Catalog::TRUST.
 * HappyAccess's own writes never trigger it.
 */
final class AdminWatch {

	/**
	 * User ids already flagged during this request.
	 *
	 * @var int[]
	 */
	private static $seen = array();

	/**
	 * Role changes already flagged during this request, keyed by change and role.
	 *
	 * @var bool[]
	 */
	private static $seen_roles = array();

	/**
	 * Hooks account creation, role changes, login detail changes and role table writes.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'user_register', array( __CLASS__, 'check' ), 20, 2 );
		add_action( 'set_user_role', array( __CLASS__, 'check' ), 20, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'check_added_role' ), 20, 2 );
		add_action( 'profile_update', array( __CLASS__, 'check_changed' ), 20, 2 );
		// Last, so it sees the value the option guards let through.
		add_filter( 'pre_update_option', array( __CLASS__, 'check_option' ), PHP_INT_MAX, 3 );
		add_action( 'add_option', array( __CLASS__, 'check_added_option' ), 20, 2 );
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
				'feature'      => 'support',
				'token_id'     => (int) $grant['id'],
				'user_id'      => get_current_user_id(),
				'summary_key'  => 'admin_account_created',
				'summary_args' => array( $user->user_login ),
				'meta'         => array( 'user_id' => $user_id ),
			)
		);
		Notifications::admin_created( $grant, $user );
	}

	/**
	 * Flags a user who was given an admin-level role as an extra role.
	 *
	 * @param int    $user_id Id of the user.
	 * @param string $role    Role added.
	 * @return void
	 */
	public static function check_added_role( $user_id, $role = '' ) {
		if ( array() === self::role_admin_caps( $role ) ) {
			return;
		}
		self::check( $user_id );
	}

	/**
	 * Flags a role table or default role write. Returns the value unchanged.
	 *
	 * @param mixed  $value     New value.
	 * @param string $option    Option name.
	 * @param mixed  $old_value Current value.
	 * @return mixed
	 */
	public static function check_option( $value, $option = '', $old_value = null ) {
		self::check_role_write( $option, $value, $old_value );
		return $value;
	}

	/**
	 * Flags a role table or default role option being created.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value of the new option.
	 * @return void
	 */
	public static function check_added_option( $option, $value = null ) {
		self::check_role_write( $option, $value, false );
	}

	/**
	 * Logs and emails each role that gains an admin-level cap in a role
	 * table write, or the role the default role now points at when it is
	 * admin-level.
	 *
	 * @param mixed $option    Option name.
	 * @param mixed $value     New value.
	 * @param mixed $old_value Current value.
	 * @return void
	 */
	private static function check_role_write( $option, $value, $old_value ) {
		global $wpdb;
		if ( $value === $old_value || ! is_string( $option ) || ! ActivityTracker::tracking() ) {
			return;
		}
		if ( CapabilityGuard::resolves_to( $option, 'default_role' ) ) {
			$caps = is_string( $value ) ? self::role_admin_caps( $value ) : array();
			if ( array() !== $caps ) {
				self::role_alert( $value, 'default_role', $caps, self::role_name( $value, array() ) );
			}
			return;
		}
		if ( ! is_array( $value ) || ! CapabilityGuard::resolves_to( $option, $wpdb->prefix . 'user_roles' ) ) {
			return;
		}
		$old = is_array( $old_value ) ? $old_value : array();
		foreach ( $value as $slug => $role ) {
			$slug   = (string) $slug;
			$before = isset( $old[ $slug ]['capabilities'] ) && is_array( $old[ $slug ]['capabilities'] ) ? $old[ $slug ]['capabilities'] : array();
			$after  = is_array( $role ) && isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			$gained = array_values( array_diff( self::admin_caps_in( $after ), self::admin_caps_in( $before ) ) );
			if ( array() !== $gained ) {
				self::role_alert( $slug, 'role', $gained, self::role_name( $slug, is_array( $role ) ? $role : array() ) );
			}
		}
	}

	/**
	 * Logs one admin-level role change and emails the owner, once per role
	 * and change in a request. Only full passes and custom passes that run
	 * on trust are flagged; other passes can't make these changes.
	 *
	 * @param string   $role   Role slug.
	 * @param string   $change role or default_role.
	 * @param string[] $caps   Admin-level caps the role gained or holds.
	 * @param string   $name   Readable role name.
	 * @return void
	 */
	private static function role_alert( $role, $change, array $caps, $name ) {
		$key = $change . ':' . $role;
		if ( isset( self::$seen_roles[ $key ] ) ) {
			return;
		}
		$grant = Grants::get( Capabilities::grant_id( get_current_user_id() ) );
		if ( ! is_array( $grant ) || empty( $grant['id'] ) ) {
			return;
		}
		if ( 'full' !== $grant['level'] && ! ( 'custom' === $grant['level'] && Catalog::needs_trust( $grant['caps'] ) ) ) {
			return;
		}

		self::$seen_roles[ $key ] = true;
		AuditLog::add(
			'admin_role_granted',
			array(
				'feature'      => 'support',
				'token_id'     => (int) $grant['id'],
				'user_id'      => get_current_user_id(),
				'summary_key'  => 'default_role' === $change ? 'admin_role_default' : 'admin_role_granted',
				'summary_args' => array( $name ),
				'meta'         => array(
					'role'   => $role,
					'change' => $change,
					'caps'   => $caps,
				),
			)
		);
		Notifications::admin_role( $grant, $name, $change, $caps );
	}

	/**
	 * Admin-level caps a role holds now. An unknown role holds none.
	 *
	 * @param mixed $role Role slug.
	 * @return string[]
	 */
	private static function role_admin_caps( $role ) {
		$object = is_string( $role ) && '' !== $role ? get_role( $role ) : null;
		return $object ? self::admin_caps_in( (array) $object->capabilities ) : array();
	}

	/**
	 * Admin-level caps granted in a cap list.
	 *
	 * @param array $capabilities Cap name to granted flag.
	 * @return string[]
	 */
	private static function admin_caps_in( array $capabilities ) {
		return array_values( array_intersect( Catalog::TRUST, CapabilityGuard::true_caps( $capabilities ) ) );
	}

	/**
	 * Readable, translated name of a role.
	 *
	 * @param string $slug Role slug.
	 * @param array  $role Role data from the role table being written, if any.
	 * @return string
	 */
	private static function role_name( $slug, array $role ) {
		if ( isset( $role['name'] ) && is_string( $role['name'] ) && '' !== $role['name'] ) {
			return translate_user_role( $role['name'] );
		}
		$roles = wp_roles()->roles;
		return isset( $roles[ $slug ]['name'] ) ? translate_user_role( (string) $roles[ $slug ]['name'] ) : (string) $slug;
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
				'feature'      => 'support',
				'token_id'     => (int) $grant['id'],
				'user_id'      => get_current_user_id(),
				'summary_key'  => 'admin_account_changed',
				'summary_args' => array( $user->user_login ),
				'meta'         => array(
					'user_id' => $user_id,
					'fields'  => $fields,
				),
			)
		);
		Notifications::admin_changed( $grant, $user, $fields, (string) $old_user_data->user_email );
	}

	/**
	 * Forgets the flagged users. For tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$seen       = array();
		self::$seen_roles = array();
	}

	/**
	 * The grant of the acting temp user, when this change should be flagged:
	 * a temp user's own action on another admin-level account, not seen yet.
	 *
	 * @param int $user_id Id of the user that was made or changed.
	 * @return array|null
	 */
	private static function acting_grant( $user_id ) {
		if ( $user_id <= 0 || isset( self::$seen[ $user_id ] ) || ! ActivityTracker::tracking() ) {
			return null;
		}
		$actor = get_current_user_id();
		if ( $user_id === $actor || ! self::is_admin_level( $user_id ) ) {
			return null;
		}
		$grant = Grants::get( Capabilities::grant_id( $actor ) );
		return is_array( $grant ) && ! empty( $grant['id'] ) ? $grant : null;
	}

	/**
	 * Whether a user holds any admin-level cap.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private static function is_admin_level( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		foreach ( Catalog::TRUST as $cap ) {
			if ( ! empty( $user->allcaps[ $cap ] ) || user_can( $user, $cap ) ) {
				return true;
			}
		}
		return false;
	}
}
