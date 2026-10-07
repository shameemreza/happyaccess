<?php
/**
 * Protected admin rules for temporary support accounts.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Internal;

defined( 'ABSPATH' ) || exit;

/**
 * A temporary support user is a full admin for support work, minus the
 * powers that would let it keep or widen its own access.
 */
final class CapabilityGuard {

	/**
	 * Caps a temp user never has.
	 */
	const ALWAYS_BLOCKED = array(
		'create_users',
		'promote_users',
		'delete_users',
		'remove_users',
		'edit_plugins',
		'edit_themes',
		'edit_files',
		'unfiltered_html',
		'unfiltered_upload',
		'erase_others_personal_data',
		'export_others_personal_data',
		'manage_network',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'manage_sites',
		'create_sites',
		'delete_sites',
		'upgrade_network',
		'setup_network',
	);

	/**
	 * Caps removed unless the grant allows installs.
	 */
	const INSTALL_CAPS = array(
		'install_plugins',
		'upload_plugins',
		'update_plugins',
		'delete_plugins',
		'install_themes',
		'upload_themes',
		'update_themes',
		'delete_themes',
		'update_core',
		'install_languages',
		'update_languages',
	);

	/**
	 * Options a temp user cannot change.
	 */
	const PROTECTED_OPTIONS = array(
		'default_role',
		'users_can_register',
		'admin_email',
		'new_admin_email',
		'siteurl',
		'home',
		'happyaccess_settings',
		'happyaccess_secret',
		'happyaccess_recaptcha_secret_key',
	);

	/**
	 * Network options a temp user cannot change. Core has no generic hook for
	 * network option writes, so each name is hooked on its own.
	 */
	const PROTECTED_SITE_OPTIONS = array(
		'site_admins',
		'admin_email',
		'registration',
	);

	/**
	 * The network list of active plugins, guarded so HappyAccess stays active.
	 */
	const NETWORK_PLUGINS_OPTION = 'active_sitewide_plugins';

	/**
	 * Every option starting with this is protected too.
	 */
	const OPTION_PREFIX = 'happyaccess_';

	/**
	 * Prefixes WordPress adds to a transient's stored option name. Longest
	 * first, so a timeout prefix is not mistaken for the shorter one.
	 */
	const TRANSIENT_PREFIXES = array( '_site_transient_timeout_', '_transient_timeout_', '_site_transient_', '_transient_' );

	/**
	 * Application password caps, denied for every target.
	 */
	const APP_PASSWORD_CAPS = array(
		'create_app_password',
		'edit_app_password',
		'list_app_passwords',
		'read_app_password',
		'delete_app_password',
		'delete_app_passwords',
	);

	/**
	 * Caps blocked on every level, including full. The file editor could
	 * rewrite HappyAccess itself. The network caps match the ones in Catalog::NEVER.
	 */
	const SELF_BLOCKED = array(
		...self::APP_PASSWORD_CAPS,
		'edit_plugins',
		'edit_themes',
		'edit_files',
		'erase_others_personal_data',
		'export_others_personal_data',
		'manage_network',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'manage_sites',
		'create_sites',
		'delete_sites',
		'upgrade_network',
		'setup_network',
	);

	/**
	 * Secret options protected on every level. Both carry the prefix too,
	 * so this list keeps them covered if the prefix rule ever changes.
	 */
	const SECRET_OPTIONS = array(
		'happyaccess_secret',
		'happyaccess_recaptcha_secret_key',
	);

	/**
	 * Caps that act on another user.
	 */
	const USER_CAPS = array(
		'edit_user',
		'delete_user',
		'remove_user',
		'promote_user',
		'edit_users',
		'delete_users',
		'remove_users',
		'promote_users',
	);

	/**
	 * Per request cache of grant rules, keyed by blog, user and grant id.
	 *
	 * @var array
	 */
	private static $rules = array();

	/**
	 * How many plugin activations are running in this request.
	 *
	 * @var int
	 */
	private static $activation_writes = 0;

	/**
	 * How many plugin update packages started in this request. A bulk update
	 * fires the start hook once per package but the complete hook once, so
	 * the complete hook sets this back to zero.
	 *
	 * @var int
	 */
	private static $upgrade_writes = 0;

	/**
	 * Whether the open plugin work window already logged a role change. Core
	 * saves the whole role table on every add_cap(), so one row per window
	 * is enough.
	 *
	 * @var bool
	 */
	private static $roles_logged = false;

	/**
	 * Whether a custom or full pass already logged a role change in this request.
	 *
	 * @var bool
	 */
	private static $open_roles_logged = false;

	/**
	 * Per request cache of the stored row name an option name resolves to.
	 *
	 * @var array
	 */
	private static $stored_names = array();

	/**
	 * Hooks everything the guard needs.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map' ), PHP_INT_MAX - 1, 4 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'filter_app_passwords' ), PHP_INT_MAX, 2 );
		add_action( 'delete_plugin', array( __CLASS__, 'block_plugin_delete' ) );
		add_action( 'pre_uninstall_plugin', array( __CLASS__, 'block_plugin_delete' ) );
		add_filter( 'allowed_options', array( __CLASS__, 'filter_allowed_options' ) );
		add_filter( 'pre_update_option', array( __CLASS__, 'keep_old_option' ), 10, 3 );
		add_action( 'delete_option', array( __CLASS__, 'block_option_delete' ) );
		add_action( 'add_option', array( __CLASS__, 'block_option_add' ), 10, 2 );
		foreach ( array_merge( self::PROTECTED_SITE_OPTIONS, array( self::NETWORK_PLUGINS_OPTION ) ) as $site_option ) {
			add_filter( 'pre_update_site_option_' . $site_option, array( __CLASS__, 'keep_old_site_option' ), 10, 4 );
			add_action( 'pre_delete_site_option_' . $site_option, array( __CLASS__, 'block_site_option_delete' ), 10, 2 );
		}
		add_filter( 'all_plugins', array( __CLASS__, 'hide_plugin' ) );
		add_filter( 'users_list_table_query_args', array( __CLASS__, 'hide_owner' ) );
		add_action( 'activate_plugin', array( __CLASS__, 'plugin_work_started' ), 10, 2 );
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'upgrade_work_started' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'plugin_work_finished' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'upgrade_work_finished' ) );
	}

	/**
	 * Counts a plugin activation as started.
	 *
	 * @return void
	 */
	public static function plugin_work_started() {
		++self::$activation_writes;
	}

	/**
	 * Counts a plugin update as started. This is a filter, so it returns its first argument.
	 *
	 * @param mixed $response Filter value, returned as it came.
	 * @return mixed
	 */
	public static function upgrade_work_started( $response = null ) {
		++self::$upgrade_writes;
		return $response;
	}

	/**
	 * Counts a plugin activation as finished.
	 *
	 * @return void
	 */
	public static function plugin_work_finished() {
		self::$activation_writes = max( 0, self::$activation_writes - 1 );
		self::forget_logged_roles_when_closed();
	}

	/**
	 * Closes the update window, however many packages started.
	 *
	 * @return void
	 */
	public static function upgrade_work_finished() {
		self::$upgrade_writes = 0;
		self::forget_logged_roles_when_closed();
	}

	/**
	 * Lets the next window log its own role change once no plugin work is left.
	 *
	 * @return void
	 */
	private static function forget_logged_roles_when_closed() {
		if ( ! self::plugin_work_active() ) {
			self::$roles_logged = false;
		}
	}

	/**
	 * Whether a plugin activation or update is running.
	 *
	 * @return bool
	 */
	private static function plugin_work_active() {
		return self::$activation_writes > 0 || self::$upgrade_writes > 0;
	}

	/**
	 * Clears the per request rules cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$rules        = array();
		self::$stored_names = array();
	}

	/**
	 * Clears the cache, both plugin work counters and the logged role flag. For tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::flush_cache();
		self::$activation_writes = 0;
		self::$upgrade_writes    = 0;
		self::$roles_logged      = false;
		self::$open_roles_logged = false;
	}

	/**
	 * Removes protected powers from a temp user.
	 *
	 * @param array  $caps    Primitive caps.
	 * @param string $cap     Requested cap.
	 * @param int    $user_id User id.
	 * @param array  $args    Extra args.
	 * @return array
	 */
	public static function map( $caps, $cap, $user_id, $args ) {
		$user_id = (int) $user_id;
		if ( ! Capabilities::is_temp_user( $user_id ) ) {
			return $caps;
		}
		$caps  = (array) $caps;
		$cap   = (string) $cap;
		$deny  = array( 'do_not_allow' );
		$asked = array_merge( array( $cap ), $caps );
		$rules = self::rules( $user_id );
		$first = is_array( $args ) && isset( $args[0] ) ? $args[0] : null;

		if ( 'protected' !== $rules['level'] ) {
			return self::map_open_level( $caps, $cap, $asked, $user_id, $first );
		}

		if ( array_intersect( $asked, self::ALWAYS_BLOCKED ) ) {
			return $deny;
		}

		if ( in_array( $cap, self::APP_PASSWORD_CAPS, true ) ) {
			return $deny;
		}

		if ( 'protected_allow_installs' !== $rules['protection'] && array_intersect( $asked, self::INSTALL_CAPS ) ) {
			return $deny;
		}

		if ( in_array( $cap, self::USER_CAPS, true ) ) {
			$target = self::target_id( $first );
			if ( $target > 0 ) {
				// Customers only. Admins, the owner and self all fail the low privilege check.
				if ( $target === $user_id || $target === $rules['created_by'] ) {
					return $deny;
				}
				$target_user = $first instanceof \WP_User ? $first : get_userdata( $target );
				if ( ! $target_user instanceof \WP_User || ! self::is_low_privilege( $target_user ) ) {
					return $deny;
				}
			}
		}

		if ( in_array( $cap, array( 'deactivate_plugin', 'delete_plugin' ), true ) && self::is_happyaccess_path( $first ) ) {
			return $deny;
		}

		return $caps;
	}

	/**
	 * Custom and full passes keep only the self-protection rules: no
	 * application passwords, privacy or network caps, no edits to the
	 * creator, and HappyAccess stays active.
	 *
	 * @param array  $caps    Primitive caps.
	 * @param string $cap     Requested cap.
	 * @param array  $asked   Requested cap plus primitive caps.
	 * @param int    $user_id Temp user id.
	 * @param mixed  $first   First extra arg.
	 * @return array
	 */
	private static function map_open_level( array $caps, $cap, array $asked, $user_id, $first ) {
		$deny = array( 'do_not_allow' );
		if ( array_intersect( $asked, self::SELF_BLOCKED ) ) {
			return $deny;
		}
		if ( in_array( $cap, self::USER_CAPS, true ) ) {
			$creator = self::creator_for( $user_id );
			if ( $creator > 0 && self::target_id( $first ) === $creator ) {
				return $deny;
			}
		}
		if ( in_array( $cap, array( 'deactivate_plugin', 'delete_plugin' ), true ) && self::is_happyaccess_path( $first ) ) {
			return $deny;
		}
		return $caps;
	}

	/**
	 * User id from a cap check argument.
	 *
	 * @param mixed $first First extra arg: a user id or a WP_User.
	 * @return int
	 */
	private static function target_id( $first ) {
		if ( $first instanceof \WP_User ) {
			return (int) $first->ID;
		}
		return is_scalar( $first ) ? (int) $first : 0;
	}

	/**
	 * Access level of a temp user's grant. Anyone else, or a grant that
	 * can't be read, gets protected.
	 *
	 * @param int $user_id User id.
	 * @return string protected, custom or full.
	 */
	public static function level_for( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! Capabilities::is_temp_user( $user_id ) ) {
			return 'protected';
		}
		return self::rules( $user_id )['level'];
	}

	/**
	 * Creator of a temp user's grant, or 0 when there is none or the account is gone.
	 *
	 * @param int $user_id Temp user id.
	 * @return int
	 */
	public static function creator_for( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! Capabilities::is_temp_user( $user_id ) ) {
			return 0;
		}
		$creator = self::rules( $user_id )['created_by'];
		return $creator > 0 && false !== get_userdata( $creator ) ? $creator : 0;
	}

	/**
	 * Whether a user's caps go no further than read, like a customer or subscriber.
	 * Role names and legacy level_N caps are ignored.
	 *
	 * @param \WP_User $user User to check.
	 * @return bool
	 */
	public static function is_low_privilege( \WP_User $user ) {
		return array() === array_diff( self::true_caps( (array) $user->allcaps ), array( 'read' ) );
	}

	/**
	 * Whether a role's caps go no further than read. A role that doesn't exist is not.
	 *
	 * @param mixed $role Role slug.
	 * @return bool
	 */
	public static function is_low_privilege_role( $role ) {
		$object = is_string( $role ) && '' !== $role ? get_role( $role ) : null;
		if ( ! $object ) {
			return false;
		}
		return array() === array_diff( self::true_caps( (array) $object->capabilities ), array( 'read' ) );
	}

	/**
	 * Names of the granted caps in a cap list, without role names and legacy level_N caps.
	 *
	 * @param array $capabilities Cap name to granted flag.
	 * @return string[]
	 */
	public static function true_caps( array $capabilities ) {
		$roles = array_keys( wp_roles()->roles );
		$caps  = array();
		foreach ( $capabilities as $name => $granted ) {
			$name = (string) $name;
			if ( ! $granted || in_array( $name, $roles, true ) || ( 0 === strpos( $name, 'level_' ) && ctype_digit( substr( $name, 6 ) ) ) ) {
				continue;
			}
			$caps[] = $name;
		}
		return $caps;
	}

	/**
	 * Turns off application passwords for temp users.
	 *
	 * @param bool     $available Whether they are available.
	 * @param \WP_User $user      User.
	 * @return bool
	 */
	public static function filter_app_passwords( $available, $user ) {
		if ( $user instanceof \WP_User && Capabilities::is_temp_user( (int) $user->ID ) ) {
			return false;
		}
		return $available;
	}

	/**
	 * Stops a temp user deleting or uninstalling HappyAccess.
	 *
	 * @param string $plugin_file Plugin file about to be deleted.
	 * @return void
	 */
	public static function block_plugin_delete( $plugin_file ) {
		if ( ! self::is_happyaccess_path( $plugin_file ) || ! Capabilities::is_temp_user( get_current_user_id() ) ) {
			return;
		}
		wp_die( esc_html__( "Temporary support accounts can't remove HappyAccess.", 'happyaccess' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Removes protected options for temp users, and on the protected and
	 * custom levels the catch-all options page group too.
	 *
	 * @param array $allowed Options by group.
	 * @return array
	 */
	public static function filter_allowed_options( $allowed ) {
		$user_id = get_current_user_id();
		if ( ! is_array( $allowed ) || ! Capabilities::is_temp_user( $user_id ) ) {
			return $allowed;
		}
		$level = self::level_for( $user_id );
		if ( 'full' !== $level ) {
			unset( $allowed['options'] );
		}
		foreach ( $allowed as $group => $options ) {
			if ( 0 === strpos( (string) $group, self::OPTION_PREFIX ) ) {
				unset( $allowed[ $group ] );
				continue;
			}
			if ( is_array( $options ) ) {
				$allowed[ $group ] = array_values(
					array_filter(
						$options,
						static function ( $option ) use ( $level ) {
							return self::is_ascii_name( $option ) && ! self::is_guarded_name( $option, $level );
						}
					)
				);
			}
		}
		return $allowed;
	}

	/**
	 * Keeps the old value of a protected option when a temp user writes it.
	 * The active plugins list may change but always keeps HappyAccess, and
	 * on a custom pass only when the pass may turn plugins on and off.
	 * Protected and custom passes write the role table only while a plugin
	 * is activated or updated, and a custom pass may point default_role
	 * only at a role with no more than read.
	 *
	 * @param mixed  $value     New value.
	 * @param string $option    Option name.
	 * @param mixed  $old_value Current value.
	 * @return mixed
	 */
	public static function keep_old_option( $value, $option = '', $old_value = null ) {
		if ( Internal::active() ) {
			return $value;
		}
		$user_id = get_current_user_id();
		if ( ! Capabilities::is_temp_user( $user_id ) ) {
			return $value;
		}
		$level = self::level_for( $user_id );
		if ( 'active_plugins' === $option ) {
			if ( 'custom' === $level && ! user_can( $user_id, 'activate_plugins' ) ) {
				return $old_value;
			}
			return self::keep_plugin_active( $value, $old_value );
		}
		if ( 'full' === $level ) {
			if ( self::is_guarded_option( $option, $level ) ) {
				return $old_value;
			}
			if ( self::is_roles_option( $option ) || self::is_roles_option( self::stored_option_name( $option ) ) ) {
				self::log_open_role_change( $user_id, $value, $old_value );
			}
			return $value;
		}
		if ( self::plugin_work_active() && self::is_roles_option( $option ) ) {
			if ( ! self::$roles_logged && $value !== $old_value ) {
				self::$roles_logged = true;
				AuditLog::add(
					'roles_changed',
					array(
						'feature'  => 'support',
						'token_id' => Capabilities::grant_id( $user_id ),
						'user_id'  => $user_id,
						'summary'  => 'Roles changed while activating or updating a plugin',
					)
				);
			}
			return $value;
		}
		if ( self::is_guarded_option( $option, $level ) ) {
			return $old_value;
		}
		if ( 'custom' === $level && self::resolves_to( $option, 'default_role' ) && ! self::is_low_privilege_role( $value ) ) {
			return $old_value;
		}
		return $value;
	}

	/**
	 * Whether an option name writes the row of another option name, as the
	 * options table compares names: in any letter case, or through the
	 * stored row it matches. Names that aren't printable ASCII never match.
	 *
	 * @param mixed  $option Option name being written.
	 * @param string $name   Lowercase option name to compare with.
	 * @return bool
	 */
	public static function resolves_to( $option, $name ) {
		if ( ! self::is_ascii_name( $option ) ) {
			return false;
		}
		$name = strtolower( (string) $name );
		if ( strtolower( $option ) === $name ) {
			return true;
		}
		$stored = self::stored_option_name( $option );
		return '' !== $stored && strtolower( $stored ) === $name;
	}

	/**
	 * Logs the first role table change a full pass makes in a request.
	 *
	 * @param int   $user_id   Temp user id.
	 * @param mixed $value     New role table.
	 * @param mixed $old_value Current role table.
	 * @return void
	 */
	private static function log_open_role_change( $user_id, $value, $old_value ) {
		if ( self::$open_roles_logged || $value === $old_value ) {
			return;
		}
		self::$open_roles_logged = true;
		AuditLog::add(
			'roles_changed',
			array(
				'feature'  => 'support',
				'token_id' => Capabilities::grant_id( $user_id ),
				'user_id'  => $user_id,
				'summary'  => 'Changed role permissions',
			)
		);
	}

	/**
	 * Stops a temp user deleting a protected option or the active plugins list.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function block_option_delete( $option ) {
		$user_id = get_current_user_id();
		if ( Internal::active() || ! Capabilities::is_temp_user( $user_id ) ) {
			return;
		}
		if ( 'active_plugins' !== $option && ! self::is_guarded_option( $option, self::level_for( $user_id ) ) ) {
			return;
		}
		wp_die( esc_html__( "Temporary support accounts can't change this setting.", 'happyaccess' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Stops a temp user creating a protected option, such as a new HappyAccess
	 * transient, before it exists to be kept by the update guard.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value of the new option.
	 * @return void
	 */
	public static function block_option_add( $option, $value = null ) {
		$user_id = get_current_user_id();
		if ( Internal::active() || ! Capabilities::is_temp_user( $user_id ) ) {
			return;
		}
		$level   = self::level_for( $user_id );
		$blocked = self::is_guarded_option( $option, $level )
			|| ( 'custom' === $level && self::resolves_to( $option, 'default_role' ) && ! self::is_low_privilege_role( $value ) );
		if ( ! $blocked ) {
			return;
		}
		wp_die( esc_html__( "Temporary support accounts can't change this setting.", 'happyaccess' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Keeps the old value when a temp user writes a protected network option.
	 *
	 * @param mixed  $value      New value.
	 * @param mixed  $old_value  Current value.
	 * @param string $option     Option name.
	 * @param int    $network_id Network id.
	 * @return mixed
	 */
	public static function keep_old_site_option( $value, $old_value = null, $option = '', $network_id = 0 ) {
		$user_id = get_current_user_id();
		if ( Internal::active() || ! Capabilities::is_temp_user( $user_id ) ) {
			return $value;
		}
		if ( self::NETWORK_PLUGINS_OPTION === $option ) {
			return self::keep_network_plugin_active( $value, $old_value );
		}
		return self::is_guarded_option( $option, self::level_for( $user_id ), true, $network_id ) ? $old_value : $value;
	}

	/**
	 * Stops a temp user deleting a protected network option or the network
	 * active plugins list.
	 *
	 * @param string $option     Option name.
	 * @param int    $network_id Network id.
	 * @return void
	 */
	public static function block_site_option_delete( $option, $network_id = 0 ) {
		$user_id = get_current_user_id();
		if ( Internal::active() || ! Capabilities::is_temp_user( $user_id ) ) {
			return;
		}
		if ( self::NETWORK_PLUGINS_OPTION !== $option && ! self::is_guarded_option( $option, self::level_for( $user_id ), true, $network_id ) ) {
			return;
		}
		wp_die( esc_html__( "Temporary support accounts can't change this setting.", 'happyaccess' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Keeps HappyAccess in the active plugins list when it was there. A value
	 * that isn't a list keeps the old list.
	 *
	 * @param mixed $value     New list.
	 * @param mixed $old_value Current list.
	 * @return mixed
	 */
	private static function keep_plugin_active( $value, $old_value ) {
		if ( ! is_array( $old_value ) || ! in_array( HAPPYACCESS_PLUGIN_BASENAME, $old_value, true ) ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return $old_value;
		}
		if ( ! in_array( HAPPYACCESS_PLUGIN_BASENAME, $value, true ) ) {
			$value[] = HAPPYACCESS_PLUGIN_BASENAME;
		}
		return $value;
	}

	/**
	 * Keeps HappyAccess in the network active plugins list, which is keyed by
	 * plugin file, when it was there. A value that isn't a list keeps the old list.
	 *
	 * @param mixed $value     New list.
	 * @param mixed $old_value Current list.
	 * @return mixed
	 */
	private static function keep_network_plugin_active( $value, $old_value ) {
		if ( ! is_array( $old_value ) || ! isset( $old_value[ HAPPYACCESS_PLUGIN_BASENAME ] ) ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return $old_value;
		}
		if ( ! isset( $value[ HAPPYACCESS_PLUGIN_BASENAME ] ) ) {
			$value[ HAPPYACCESS_PLUGIN_BASENAME ] = $old_value[ HAPPYACCESS_PLUGIN_BASENAME ];
		}
		return $value;
	}

	/**
	 * Whether a temp user's write or delete of this option must be refused.
	 * The options tables compare names loosely (case, accents, full width,
	 * ignorable characters, trailing spaces), so two layers decide: a name
	 * that isn't printable ASCII is refused, and an ASCII name is checked
	 * both as given and as the stored row it would hit.
	 *
	 * @param mixed  $option     Option name.
	 * @param string $level      Access level of the grant.
	 * @param bool   $network    Whether this is a network option.
	 * @param int    $network_id Network id, for network options.
	 * @return bool
	 */
	private static function is_guarded_option( $option, $level, $network = false, $network_id = 0 ) {
		if ( ! self::is_ascii_name( $option ) ) {
			return true;
		}
		if ( self::is_guarded_name( $option, $level, $network ) ) {
			return true;
		}
		$stored = self::stored_option_name( $option, $network, $network_id );
		if ( '' === $stored || $stored === $option ) {
			return false;
		}
		return self::is_guarded_name( $stored, $level, $network )
			|| in_array( strtolower( $stored ), array( 'active_plugins', self::NETWORK_PLUGINS_OPTION ), true );
	}

	/**
	 * Whether an option name, compared in lowercase, is protected on this
	 * level, or is another spelling of a plugin list.
	 *
	 * @param string $option  Option name.
	 * @param string $level   Access level of the grant.
	 * @param bool   $network Whether this is a network option.
	 * @return bool
	 */
	private static function is_guarded_name( $option, $level, $network = false ) {
		$lower = strtolower( $option );
		if ( $network ? self::is_protected_site_option( $lower, $level ) : self::is_protected_option( $lower, $level ) ) {
			return true;
		}
		return $lower !== $option && in_array( $lower, array( 'active_plugins', self::NETWORK_PLUGINS_OPTION ), true );
	}

	/**
	 * Whether an option name is all printable ASCII, with no spaces.
	 *
	 * @param mixed $option Option name.
	 * @return bool
	 */
	private static function is_ascii_name( $option ) {
		return is_string( $option ) && 1 === preg_match( '/^[\x21-\x7E]+\z/', $option );
	}

	/**
	 * Name of the stored row an option name resolves to under the table's
	 * collation, or an empty string when there is none. Cached per request.
	 *
	 * @param string $option     Option name.
	 * @param bool   $network    Whether this is a network option.
	 * @param int    $network_id Network id, for network options.
	 * @return string
	 */
	private static function stored_option_name( $option, $network = false, $network_id = 0 ) {
		global $wpdb;
		$sitemeta = $network && is_multisite();
		if ( $sitemeta ) {
			$network_id = (int) $network_id > 0 ? (int) $network_id : (int) get_current_network_id();
		}
		$key = ( $sitemeta ? 'network:' . $network_id : 'blog:' . get_current_blog_id() ) . ':' . $option;
		if ( ! array_key_exists( $key, self::$stored_names ) ) {
			if ( $sitemeta ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see the row the table itself matches; cached per request above.
				$name = $wpdb->get_var( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key = %s AND site_id = %d LIMIT 1", $option, $network_id ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see the row the table itself matches; cached per request above.
				$name = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
			}
			self::$stored_names[ $key ] = is_string( $name ) ? $name : '';
		}
		return self::$stored_names[ $key ];
	}

	/**
	 * Hides HappyAccess from the plugins list for temp users.
	 *
	 * @param array $plugins All plugins.
	 * @return array
	 */
	public static function hide_plugin( $plugins ) {
		if ( is_array( $plugins ) && Capabilities::is_temp_user( get_current_user_id() ) ) {
			unset( $plugins[ HAPPYACCESS_PLUGIN_BASENAME ] );
		}
		return $plugins;
	}

	/**
	 * Hides the admin who created the grant from the users list.
	 *
	 * @param array $args User query args.
	 * @return array
	 */
	public static function hide_owner( $args ) {
		$user_id = get_current_user_id();
		if ( ! Capabilities::is_temp_user( $user_id ) ) {
			return $args;
		}
		$owner = self::rules( $user_id )['created_by'];
		if ( $owner > 0 ) {
			$exclude         = isset( $args['exclude'] ) ? array_map( 'intval', (array) $args['exclude'] ) : array();
			$exclude[]       = $owner;
			$args['exclude'] = array_values( array_unique( $exclude ) );
		}
		return $args;
	}

	/**
	 * Protection, access level and creator of a temp user's grant. A grant
	 * that cannot be read fails closed to the protected level, which blocks installs.
	 *
	 * @param int $user_id Temp user id.
	 * @return array protection, level and created_by.
	 */
	private static function rules( $user_id ) {
		$grant_id = Capabilities::grant_id( $user_id );
		$key      = get_current_blog_id() . ':' . $user_id . ':' . $grant_id;
		if ( ! isset( self::$rules[ $key ] ) ) {
			$grant               = $grant_id > 0 ? Grants::get( $grant_id ) : null;
			self::$rules[ $key ] = array(
				'protection' => is_array( $grant ) ? $grant['protection'] : 'protected',
				'level'      => is_array( $grant ) && isset( $grant['level'] ) && in_array( $grant['level'], array( 'custom', 'full' ), true ) ? $grant['level'] : 'protected',
				'created_by' => is_array( $grant ) ? (int) $grant['created_by'] : 0,
			);
		}
		return self::$rules[ $key ];
	}

	/**
	 * Whether a plugin file belongs to HappyAccess. Matches the main file and
	 * anything inside the plugin folder, whatever the case or slashes.
	 *
	 * @param mixed $file Plugin file or path.
	 * @return bool
	 */
	private static function is_happyaccess_path( $file ) {
		if ( ! is_scalar( $file ) ) {
			return false;
		}
		$file = plugin_basename( trim( (string) $file ) );
		if ( '' === $file ) {
			return false;
		}
		if ( HAPPYACCESS_PLUGIN_BASENAME === $file ) {
			return true;
		}
		$folder = dirname( HAPPYACCESS_PLUGIN_BASENAME );
		return '.' !== $folder && strtolower( (string) strtok( $file, '/' ) ) === strtolower( $folder );
	}

	/**
	 * Whether an option is the role table of this site.
	 *
	 * @param mixed $option Option name.
	 * @return bool
	 */
	private static function is_roles_option( $option ) {
		global $wpdb;
		return is_string( $option ) && strtolower( $wpdb->prefix . 'user_roles' ) === strtolower( $option );
	}

	/**
	 * Whether an option is off limits to temp users. Full passes are kept
	 * only from HappyAccess's own options, and custom passes from the role
	 * table too. Call it through is_guarded_option(), which lowercases the
	 * name first.
	 *
	 * @param mixed  $option Lowercased option name.
	 * @param string $level  Access level of the grant.
	 * @return bool
	 */
	private static function is_protected_option( $option, $level = 'protected' ) {
		if ( ! is_string( $option ) || '' === $option ) {
			return false;
		}
		if ( 'full' === $level ) {
			return self::is_own_name( $option ) || in_array( $option, self::SECRET_OPTIONS, true );
		}
		if ( 'custom' === $level ) {
			return self::is_own_name( $option ) || in_array( $option, self::SECRET_OPTIONS, true ) || self::is_roles_option( $option );
		}
		return in_array( $option, self::PROTECTED_OPTIONS, true )
			|| self::is_own_name( $option )
			|| self::is_roles_option( $option );
	}

	/**
	 * Whether a network option is off limits to temp users. Custom and full
	 * passes are kept only from HappyAccess's own network options. Call it
	 * through is_guarded_option(), which lowercases the name first.
	 *
	 * @param mixed  $option Lowercased option name.
	 * @param string $level  Access level of the grant.
	 * @return bool
	 */
	private static function is_protected_site_option( $option, $level = 'protected' ) {
		if ( ! is_string( $option ) || '' === $option ) {
			return false;
		}
		if ( 'protected' !== $level ) {
			return self::is_own_name( $option );
		}
		return in_array( $option, self::PROTECTED_SITE_OPTIONS, true ) || self::is_own_name( $option );
	}

	/**
	 * Whether a lowercased option name is HappyAccess's own: it starts with
	 * the plugin prefix, once one transient prefix is taken off. That covers
	 * every HappyAccess transient, now and later.
	 *
	 * @param string $option Lowercased option name.
	 * @return bool
	 */
	private static function is_own_name( $option ) {
		foreach ( self::TRANSIENT_PREFIXES as $prefix ) {
			if ( 0 === strpos( $option, $prefix ) ) {
				$option = substr( $option, strlen( $prefix ) );
				break;
			}
		}
		return 0 === strpos( $option, self::OPTION_PREFIX );
	}
}
