<?php
/**
 * Protected admin rules for temporary support accounts.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\Capabilities;

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
	 * Caps removed when the grant blocks installs.
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
	 * Every option starting with this is protected too.
	 */
	const OPTION_PREFIX = 'happyaccess_';

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
		add_filter( 'all_plugins', array( __CLASS__, 'hide_plugin' ) );
		add_filter( 'users_list_table_query_args', array( __CLASS__, 'hide_owner' ) );
	}

	/**
	 * Clears the per request rules cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$rules = array();
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
		$caps = (array) $caps;
		$cap  = (string) $cap;
		$deny = array( 'do_not_allow' );

		$asked = array_merge( array( $cap ), $caps );
		if ( array_intersect( $asked, self::ALWAYS_BLOCKED ) ) {
			return $deny;
		}

		if ( in_array( $cap, self::APP_PASSWORD_CAPS, true ) ) {
			return $deny;
		}

		$rules = self::rules( $user_id );
		if ( 'protected' !== $rules['protection'] && array_intersect( $asked, self::INSTALL_CAPS ) ) {
			return $deny;
		}

		$first = is_array( $args ) && isset( $args[0] ) ? $args[0] : null;

		if ( in_array( $cap, self::USER_CAPS, true ) ) {
			$target = $first instanceof \WP_User ? (int) $first->ID : ( is_scalar( $first ) ? (int) $first : 0 );
			if ( $target > 0 ) {
				if ( $target === $user_id || $target === $rules['created_by'] ) {
					return $deny;
				}
				// Self was handled above, so this check cannot loop back here for the same user.
				if ( user_can( $target, 'manage_options' ) ) {
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
	 * Removes protected options and the catch-all options page group for temp users.
	 *
	 * @param array $allowed Options by group.
	 * @return array
	 */
	public static function filter_allowed_options( $allowed ) {
		if ( ! is_array( $allowed ) || ! Capabilities::is_temp_user( get_current_user_id() ) ) {
			return $allowed;
		}
		unset( $allowed['options'] );
		foreach ( $allowed as $group => $options ) {
			if ( 0 === strpos( (string) $group, self::OPTION_PREFIX ) ) {
				unset( $allowed[ $group ] );
				continue;
			}
			if ( is_array( $options ) ) {
				$allowed[ $group ] = array_values(
					array_filter(
						$options,
						static function ( $option ) {
							return ! self::is_protected_option( $option );
						}
					)
				);
			}
		}
		return $allowed;
	}

	/**
	 * Keeps the old value of a protected option when a temp user writes it.
	 * The active plugins list may change but always keeps HappyAccess.
	 *
	 * @param mixed  $value     New value.
	 * @param string $option    Option name.
	 * @param mixed  $old_value Current value.
	 * @return mixed
	 */
	public static function keep_old_option( $value, $option = '', $old_value = null ) {
		if ( ! Capabilities::is_temp_user( get_current_user_id() ) ) {
			return $value;
		}
		if ( 'active_plugins' === $option ) {
			if ( is_array( $value ) && is_array( $old_value ) && in_array( HAPPYACCESS_PLUGIN_BASENAME, $old_value, true ) && ! in_array( HAPPYACCESS_PLUGIN_BASENAME, $value, true ) ) {
				$value[] = HAPPYACCESS_PLUGIN_BASENAME;
			}
			return $value;
		}
		return self::is_protected_option( $option ) ? $old_value : $value;
	}

	/**
	 * Stops a temp user deleting a protected option.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function block_option_delete( $option ) {
		if ( ! self::is_protected_option( $option ) || ! Capabilities::is_temp_user( get_current_user_id() ) ) {
			return;
		}
		wp_die( esc_html__( "Temporary support accounts can't change this setting.", 'happyaccess' ), '', array( 'response' => 403 ) );
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
	 * Protection level and creator of a temp user's grant. A grant that
	 * cannot be read fails closed to the no installs level.
	 *
	 * @param int $user_id Temp user id.
	 * @return array protection and created_by.
	 */
	private static function rules( $user_id ) {
		$grant_id = Capabilities::grant_id( $user_id );
		$key      = get_current_blog_id() . ':' . $user_id . ':' . $grant_id;
		if ( ! isset( self::$rules[ $key ] ) ) {
			$grant               = $grant_id > 0 ? Grants::get( $grant_id ) : null;
			self::$rules[ $key ] = array(
				'protection' => is_array( $grant ) ? $grant['protection'] : 'protected_no_installs',
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
	 * Whether an option is off limits to temp users.
	 *
	 * @param mixed $option Option name.
	 * @return bool
	 */
	private static function is_protected_option( $option ) {
		global $wpdb;
		if ( ! is_string( $option ) || '' === $option ) {
			return false;
		}
		return in_array( $option, self::PROTECTED_OPTIONS, true )
			|| 0 === strpos( $option, self::OPTION_PREFIX )
			|| $wpdb->prefix . 'user_roles' === $option;
	}
}
