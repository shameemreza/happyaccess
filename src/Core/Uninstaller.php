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
	 * User meta that marks and locates a temp user.
	 */
	const IDENTITY_META = array( 'happyaccess_temp_user', 'happyaccess_token_id', 'happyaccess_blog_id' );

	/**
	 * Accounts that could not be deleted and were stripped instead, by user id.
	 * A stripped account no longer belongs to any site, so a later sweep must
	 * not take it for an account of a deleted site.
	 *
	 * @var array<int,bool>
	 */
	private static $kept = array();

	/**
	 * Revokes passes and clears events on every site, then deletes the data of
	 * the sites that asked for it. The data comes second because user meta is
	 * shared across a network: removing it early would hide the temp users of
	 * the sites still to come.
	 *
	 * @return void
	 */
	public static function run() {
		$delete     = array();
		self::$kept = array();
		self::each_site(
			static function ( $site_id ) use ( &$delete ) {
				// Read the choice before anything is deleted: the settings option is part of the data.
				$delete[ $site_id ] = self::delete_requested();
				self::end_passes();
				self::clear_events();
			}
		);

		// Before any meta is swept, so an account can't lose its marker and stay behind as a normal user.
		self::delete_stranded_users();

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
	 * Whether this site asked for its data to be deleted. A database that
	 * never reached 1.1.0 has no settings option yet, so the 1.0.6 choice counts.
	 *
	 * @return bool
	 */
	private static function delete_requested() {
		if ( false === get_option( Settings::OPTION, false ) ) {
			return (bool) get_option( 'happyaccess_delete_on_uninstall', false );
		}
		return (bool) Settings::get( 'privacy.delete_on_uninstall', false );
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

		foreach ( self::leftover_temp_users() as $user_id ) {
			self::delete_user( $user_id );
		}
	}

	/**
	 * Ids of every user that carries the temp user marker or a pass link.
	 * Usermeta is shared across a network, so these come from all sites.
	 *
	 * @return int[]
	 */
	private static function linked_user_ids() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall lookup by marker meta.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ( %s, %s )", 'happyaccess_temp_user', 'happyaccess_token_id' ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Ids of temp users that belong to this site. A user with the marker and
	 * no recorded site counts for the site it is found on. A user with only a
	 * pass link counts when the link points at a grant row of this site, or
	 * when the site has no grants table anymore.
	 *
	 * @return int[]
	 */
	private static function leftover_temp_users() {
		$found = array();
		foreach ( self::linked_user_ids() as $user_id ) {
			if ( false === get_userdata( $user_id ) ) {
				continue;
			}
			$blog_id = (int) get_user_meta( $user_id, 'happyaccess_blog_id', true );
			if ( 0 !== $blog_id && get_current_blog_id() !== $blog_id ) {
				continue;
			}
			if ( ! Capabilities::is_temp_user( $user_id ) && ! self::link_matches_this_site( $user_id, $blog_id ) ) {
				continue;
			}
			$found[] = $user_id;
		}
		return $found;
	}

	/**
	 * Whether a user's pass link, with no marker, makes it a temp user of this
	 * site. Only HappyAccess writes that meta, so a purged grant row doesn't
	 * matter. Grant ids repeat across the sites of a network, so there the user
	 * must name a site, and the caller has already matched it to this one.
	 *
	 * @param int $user_id User id.
	 * @param int $blog_id Site recorded on the user, 0 when none.
	 * @return bool
	 */
	private static function link_matches_this_site( $user_id, $blog_id ) {
		return (int) get_user_meta( $user_id, 'happyaccess_token_id', true ) > 0 && ! ( is_multisite() && 0 === $blog_id );
	}

	/**
	 * Deletes the temp users that no site pass could remove: accounts of a
	 * deleted site, accounts on more than one site, and ones with a site id
	 * that matches nothing. Runs after every site is done and before the meta
	 * sweep.
	 *
	 * @return void
	 */
	private static function delete_stranded_users() {
		foreach ( self::linked_user_ids() as $user_id ) {
			if ( isset( self::$kept[ $user_id ] ) || false === get_userdata( $user_id ) ) {
				continue;
			}
			if ( ! Capabilities::is_temp_user( $user_id ) ) {
				// A pass link alone is only proof once the site it names is gone.
				$blog_id = (int) get_user_meta( $user_id, 'happyaccess_blog_id', true );
				if ( ! is_multisite() || $blog_id < 1 || null !== get_site( $blog_id ) ) {
					continue;
				}
			}
			self::delete_everywhere( $user_id );
		}
	}

	/**
	 * Takes the role and every capability off a temp user, so an account that
	 * can't be deleted right now can do nothing.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private static function strip( $user_id ) {
		$user = new \WP_User( $user_id );
		$user->set_role( '' );
		$user->remove_all_caps();
	}

	/**
	 * Deletes one temp user from this site, and from the network when no other
	 * site uses the account. Its content goes to someone else. With nobody to
	 * give it to, the account stays, without a role, and keeps its posts.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private static function delete_user( $user_id ) {
		TempUsers::destroy_sessions( $user_id );
		TempUsers::delete_wc_api_keys( $user_id );

		$target = TempUsers::reassign_target( $user_id );
		if ( null === $target || ! TempUsers::remove_user( $user_id, $target ) ) {
			self::keep( $user_id );
		}
	}

	/**
	 * Leaves an account that can't be deleted now with no role and no
	 * capabilities, and remembers it.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private static function keep( $user_id ) {
		self::strip( $user_id );
		self::$kept[ $user_id ] = true;
	}

	/**
	 * Deletes a temp user from every site it belongs to, then from the network.
	 * Stops and leaves the account if any site has nobody to inherit its
	 * content, or if taking the account off a site fails.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private static function delete_everywhere( $user_id ) {
		if ( ! is_multisite() ) {
			self::delete_user( $user_id );
			return;
		}
		if ( ! function_exists( 'wpmu_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}

		TempUsers::destroy_sessions( $user_id );
		$targets = array();
		foreach ( array_keys( (array) get_blogs_of_user( $user_id ) ) as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			try {
				TempUsers::delete_wc_api_keys( $user_id );
				$targets[ (int) $blog_id ] = TempUsers::reassign_target( $user_id );
				if ( null === $targets[ (int) $blog_id ] ) {
					self::keep( $user_id );
				}
			} finally {
				restore_current_blog();
			}
			if ( null === $targets[ (int) $blog_id ] ) {
				return;
			}
		}

		foreach ( $targets as $blog_id => $target ) {
			// Taking the account off the site first hands its posts to the target; wpmu_delete_user alone would delete them.
			if ( is_wp_error( remove_user_from_blog( $user_id, $blog_id, $target ) ) ) {
				return;
			}
		}
		wpmu_delete_user( $user_id );
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
	 * shared, so the keys go for every site. An account that still has the temp
	 * user marker, because it could not be deleted, keeps its marker and links
	 * so it never turns into a normal account.
	 *
	 * @return void
	 */
	private static function delete_user_meta() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall sweep.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'happyaccess_' ) . '%' ), ARRAY_A );

		$marked = array();
		foreach ( $rows as $row ) {
			if ( 'happyaccess_temp_user' === $row['meta_key'] ) {
				$marked[ (int) $row['user_id'] ] = true;
			}
		}
		foreach ( $rows as $row ) {
			if ( isset( $marked[ (int) $row['user_id'] ] ) && in_array( $row['meta_key'], self::IDENTITY_META, true ) ) {
				continue;
			}
			delete_user_meta( (int) $row['user_id'], $row['meta_key'] );
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
