<?php
/**
 * Cleanup when the plugin is deleted.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

defined( 'ABSPATH' ) || exit;

/**
 * Runs from uninstall.php, when the plugin is not booted: it uses the
 * autoloader and calls its helpers directly, and registers no hooks. Support
 * passes and scheduled events always go. The tables, options and user meta
 * go only when "Delete data on uninstall" is on for the site.
 */
final class Uninstaller {

	/**
	 * Short names of every table the plugin has ever created.
	 */
	const TABLES = array( 'tokens', 'logs', 'attempts', 'challenges' );

	/**
	 * Revokes passes and clears events on every site, then deletes the data of
	 * the sites that asked for it. The data comes second because user meta is
	 * shared across a network: removing it early would hide the temp users of
	 * the sites still to come.
	 *
	 * @return void
	 */
	public static function run() {
		$delete = array();
		self::each_site(
			static function ( $site_id ) use ( &$delete ) {
				// Read the choice before anything is deleted: the settings option is part of the data.
				$delete[ $site_id ] = (bool) Settings::get( 'privacy.delete_on_uninstall', false );
				self::end_passes();
				self::clear_events();
			}
		);

		self::each_site(
			static function ( $site_id ) use ( $delete ) {
				if ( empty( $delete[ $site_id ] ) ) {
					return;
				}
				self::drop_site_tables();
				self::delete_site_options();
				self::delete_user_meta();
			}
		);

		// Network wide options follow the main site's choice.
		if ( is_multisite() && ! empty( $delete[ (int) get_main_site_id() ] ) ) {
			self::delete_network_data();
		}
	}

	/**
	 * Filter callback for wpmu_drop_tables: a deleted subsite takes our
	 * tables with it, whatever its uninstall setting says.
	 *
	 * @param array $tables  Tables to drop, by name.
	 * @param int   $site_id Site being deleted.
	 * @return array
	 */
	public static function drop_tables( $tables, $site_id ) {
		global $wpdb;
		$tables = (array) $tables;
		$prefix = $wpdb->get_blog_prefix( (int) $site_id );
		foreach ( array_merge( self::TABLES, Installer::LEGACY_TABLES ) as $name ) {
			$tables[ $prefix . 'happyaccess_' . $name ] = $prefix . 'happyaccess_' . $name;
		}
		return $tables;
	}

	/**
	 * Runs a callback once for the site, or once per site on a network.
	 *
	 * @param callable $callback Receives the site id.
	 * @return void
	 */
	private static function each_site( callable $callback ) {
		if ( ! is_multisite() ) {
			$callback( get_current_blog_id() );
			return;
		}
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				$callback( (int) $site_id );
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Revokes every pass and deletes every temp user left on the site,
	 * including accounts that no grant row points to anymore.
	 *
	 * @return void
	 */
	private static function end_passes() {
		if ( Installer::table_exists( 'tokens' ) ) {
			Grants::revoke_all( 'plugin_deleted' );
		}

		$inheritor = self::inheritor();
		foreach ( self::leftover_temp_users() as $user_id ) {
			self::delete_user( $user_id, $inheritor );
		}
	}

	/**
	 * Ids of temp users that belong to this site, found by their meta. A user
	 * with no recorded site counts for the site it is found on.
	 *
	 * @return int[]
	 */
	private static function leftover_temp_users() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall lookup by marker meta.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", 'happyaccess_temp_user' ) );

		$found = array();
		foreach ( (array) $ids as $user_id ) {
			$user_id = (int) $user_id;
			if ( ! Capabilities::is_temp_user( $user_id ) || false === get_userdata( $user_id ) ) {
				continue;
			}
			$blog_id = (int) get_user_meta( $user_id, 'happyaccess_blog_id', true );
			if ( 0 !== $blog_id && get_current_blog_id() !== $blog_id ) {
				continue;
			}
			$found[] = $user_id;
		}
		return $found;
	}

	/**
	 * The administrator who inherits a temp user's content, so removing the
	 * account never removes posts.
	 *
	 * @return int|null User id, or null when the site has no other administrator.
	 */
	private static function inheritor() {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 20,
			)
		);
		foreach ( $admins as $user_id ) {
			if ( ! Capabilities::is_temp_user( (int) $user_id ) ) {
				return (int) $user_id;
			}
		}
		return null;
	}

	/**
	 * Deletes one temp user from this site, and from the network when no other
	 * site uses the account.
	 *
	 * @param int      $user_id   User id.
	 * @param int|null $reassign  Who gets the user's content.
	 * @return void
	 */
	private static function delete_user( $user_id, $reassign ) {
		TempUsers::destroy_sessions( $user_id );
		TempUsers::delete_wc_api_keys( $user_id );

		if ( is_multisite() ) {
			if ( ! function_exists( 'wpmu_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
			}
			$other_blogs = array_diff( array_keys( (array) get_blogs_of_user( $user_id ) ), array( get_current_blog_id() ) );
			remove_user_from_blog( $user_id, get_current_blog_id(), $reassign );
			if ( empty( $other_blogs ) ) {
				wpmu_delete_user( $user_id );
			}
			return;
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_delete_user( $user_id, $reassign );
	}

	/**
	 * Clears every scheduled event this site has, current and from 1.0.x.
	 *
	 * @return void
	 */
	private static function clear_events() {
		foreach ( array_merge( array( Cron::HOOK, Installer::NETWORK_HOOK ), Installer::LEGACY_CRON_HOOKS ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Drops the plugin's tables on the current site.
	 *
	 * @return void
	 */
	private static function drop_site_tables() {
		global $wpdb;
		foreach ( array_merge( self::TABLES, Installer::LEGACY_TABLES ) as $name ) {
			$table = Installer::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table names.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * Deletes every happyaccess_ option and transient of the current site.
	 *
	 * @return void
	 */
	private static function delete_site_options() {
		global $wpdb;
		$like = array(
			$wpdb->esc_like( 'happyaccess_' ) . '%',
			$wpdb->esc_like( '_transient_happyaccess_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_happyaccess_' ) . '%',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall sweep.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like[0], $like[1], $like[2] ) );

		Internal::run(
			static function () use ( $names ) {
				foreach ( (array) $names as $name ) {
					delete_option( $name );
				}
			}
		);
	}

	/**
	 * Deletes every happyaccess_ user meta key. On multisite the table is
	 * shared, so the keys go for every site.
	 *
	 * @return void
	 */
	private static function delete_user_meta() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall sweep.
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'happyaccess_' ) . '%' ) );
		foreach ( (array) $keys as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
	}

	/**
	 * Deletes the network options and network transients.
	 *
	 * @return void
	 */
	private static function delete_network_data() {
		global $wpdb;
		$like = array(
			$wpdb->esc_like( 'happyaccess_' ) . '%',
			$wpdb->esc_like( '_site_transient_happyaccess_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_happyaccess_' ) . '%',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall sweep.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s", $like[0], $like[1], $like[2] ) );

		Internal::run(
			static function () use ( $names ) {
				foreach ( (array) $names as $name ) {
					delete_site_option( $name );
				}
			}
		);
	}
}
