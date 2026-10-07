<?php
/**
 * Temporary support users.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and removes the WordPress user behind a support grant.
 */
final class TempUsers {

	/**
	 * Whether any temp user exists, read once per request. Null when unknown.
	 *
	 * @var bool|null
	 */
	private static $any = null;

	/**
	 * Why the last delete() kept the user, or an empty string.
	 *
	 * @var string
	 */
	private static $last_failure = '';

	/**
	 * Creates the temp user for a grant and links it on the grant row.
	 *
	 * A custom pass gets no role, only its chosen capabilities.
	 *
	 * @param array $grant Grant with id, role, level, caps, label and created_by.
	 * @return int New user id.
	 * @throws \RuntimeException When WordPress cannot create the user.
	 */
	public static function create( array $grant ) {
		$username = '';
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$candidate = 'happyaccess_' . strtolower( wp_generate_password( 8, false, false ) );
			if ( ! username_exists( $candidate ) ) {
				$username = $candidate;
				break;
			}
		}
		if ( '' === $username ) {
			throw new \RuntimeException( 'HappyAccess could not find a free username.' );
		}

		$label = isset( $grant['label'] ) ? trim( (string) $grant['label'] ) : '';
		$name  = '' === $label
			? __( 'Support access', 'happyaccess' )
			/* translators: %s: label of the support grant. */
			: sprintf( __( 'Support access: %s', 'happyaccess' ), $label );

		$custom = isset( $grant['level'] ) && 'custom' === $grant['level'];
		$role   = $custom ? '' : sanitize_key( $grant['role'] );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $username . '@happyaccess.invalid',
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $name,
				'nickname'     => $name,
				'role'         => $role,
				'meta_input'   => array(
					'happyaccess_temp_user' => 1,
					'happyaccess_token_id'  => (int) $grant['id'],
					'happyaccess_blog_id'   => get_current_blog_id(),
				),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new \RuntimeException( esc_html( $user_id->get_error_message() ) );
		}
		$user_id = (int) $user_id;

		if ( $custom ) {
			self::give_caps( $user_id, isset( $grant['caps'] ) ? (array) $grant['caps'] : array() );
		}

		return self::link_or_yield( $grant, $user_id );
	}

	/**
	 * Gives a custom pass's user its capabilities and nothing else.
	 *
	 * The stored list is cut down to what the catalog allows, in case it was
	 * edited in the database. The full catalog is used, whoever is logged in,
	 * because the creator check already ran when the pass was made. An empty
	 * result leaves the user with read only.
	 * WordPress treats an empty role string as "no role", not the default role.
	 *
	 * @param int   $user_id User id.
	 * @param array $caps    Capabilities stored on the grant.
	 * @return void
	 */
	private static function give_caps( $user_id, array $caps ) {
		$allowed = array_merge( Catalog::grantable( false ), Catalog::ALWAYS );
		$caps    = array_values( array_unique( array_intersect( array_map( 'strval', $caps ), $allowed ) ) );
		if ( ! in_array( 'read', $caps, true ) ) {
			$caps[] = 'read';
		}

		ActivityTracker::quietly(
			static function () use ( $user_id, $caps ) {
				$user = new \WP_User( $user_id );
				foreach ( $caps as $cap ) {
					$user->add_cap( $cap );
				}
			}
		);
	}

	/**
	 * Reuses the grant's temp user when it still exists, otherwise creates one.
	 *
	 * @param array $grant Grant with id, role, label, created_by and user_id.
	 * @return int User id.
	 * @throws \RuntimeException When a new user cannot be created.
	 */
	public static function get_or_create( array $grant ) {
		$user_id = isset( $grant['user_id'] ) ? (int) $grant['user_id'] : 0;
		if ( self::usable( $user_id, $grant ) ) {
			return $user_id;
		}
		return self::create( $grant );
	}

	/**
	 * Links a freshly created user to the grant, unless another request linked
	 * one first. A losing request deletes its own user and returns the winner's,
	 * so parallel first logins end up with one temp user.
	 *
	 * @param array $grant   Grant with id.
	 * @param int   $user_id Newly created user.
	 * @return int User id that is linked to the grant.
	 * @throws \RuntimeException When no usable user could be linked.
	 */
	private static function link_or_yield( array $grant, $user_id ) {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		$id    = (int) $grant['id'];

		for ( $try = 0; $try < 2; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET user_id = %d WHERE id = %d AND ( user_id IS NULL OR user_id = 0 )", $user_id, $id ) );
			if ( 1 === $changed ) {
				return $user_id;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE id = %d", $id ) );
			if ( self::usable( $current, $grant ) ) {
				self::remove_user( $user_id, null );
				return $current;
			}

			// The row points at a user that is gone or not usable: take it over only if it is still that value.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET user_id = %d WHERE id = %d AND user_id = %d", $user_id, $id, $current ) );
			if ( 1 === $changed ) {
				return $user_id;
			}
		}

		self::remove_user( $user_id, null );
		throw new \RuntimeException( 'HappyAccess could not link the support user to the grant.' );
	}

	/**
	 * Whether a user id is a live temp user of this grant on this site.
	 *
	 * @param int   $user_id User id.
	 * @param array $grant   Grant with id.
	 * @return bool
	 */
	private static function usable( $user_id, array $grant ) {
		if ( $user_id < 1 || false === get_userdata( $user_id ) || ! self::belongs_to_grant( $user_id, $grant ) ) {
			return false;
		}
		$blog_id = (int) get_user_meta( $user_id, 'happyaccess_blog_id', true );
		return 0 === $blog_id || get_current_blog_id() === $blog_id;
	}

	/**
	 * Deletes the grant's temp user. Never touches a normal user. Posts and
	 * links go to reassign_target(). With nobody to inherit them, the user
	 * is stripped and kept with its marker and its posts, and
	 * last_delete_failure() says no_inheritor.
	 *
	 * @param array $grant Grant with id, created_by and user_id.
	 * @return bool True when a temp user was removed.
	 */
	public static function delete( array $grant ) {
		self::$last_failure = '';
		$user_id            = isset( $grant['user_id'] ) ? (int) $grant['user_id'] : 0;
		if ( $user_id < 1 ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( false === $user ) {
			// Already gone: clear the stale link.
			self::set_grant_user( (int) $grant['id'], 0 );
			return false;
		}
		if ( ! self::belongs_to_grant( $user_id, $grant ) ) {
			return false;
		}

		self::destroy_sessions( $user_id );
		self::delete_wc_api_keys( $user_id );

		$reassign = self::reassign_target( $user_id );
		if ( null === $reassign ) {
			self::strip( $user_id );
			self::$last_failure = 'no_inheritor';
			return false;
		}

		$login   = $user->user_login;
		$deleted = self::remove_user( $user_id, $reassign );
		if ( ! $deleted ) {
			return false;
		}

		self::set_grant_user( (int) $grant['id'], 0 );

		AuditLog::add(
			'temp_user_deleted',
			array(
				'feature'  => 'support',
				'token_id' => (int) $grant['id'],
				'user_id'  => 0,
				'meta'     => array( 'user_login' => $login ),
			)
		);

		return true;
	}

	/**
	 * Why the last delete() kept the user: no_inheritor, or an empty string.
	 *
	 * @return string
	 */
	public static function last_delete_failure() {
		return self::$last_failure;
	}

	/**
	 * Removes a user from this site, and from the network when no other site
	 * uses it.
	 *
	 * @param int      $user_id  User id.
	 * @param int|null $reassign Who gets the user's posts and links, or null.
	 * @return bool
	 */
	public static function remove_user( $user_id, $reassign ) {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( is_multisite() && ! function_exists( 'wpmu_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}

		if ( is_multisite() ) {
			$other_blogs = array_diff( array_keys( (array) get_blogs_of_user( $user_id ) ), array( get_current_blog_id() ) );
			// Removing from the blog first reassigns posts and links; wpmu_delete_user alone would delete them.
			$removed = remove_user_from_blog( $user_id, get_current_blog_id(), $reassign );
			$deleted = ! is_wp_error( $removed );
			if ( $deleted && empty( $other_blogs ) ) {
				$deleted = wpmu_delete_user( $user_id );
			}
			return (bool) $deleted;
		}
		return (bool) wp_delete_user( $user_id, $reassign );
	}

	/**
	 * Takes the role, every capability, the sessions and the WooCommerce API
	 * keys off a user, so an account that has to stay can do nothing.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function strip( $user_id ) {
		$user = new \WP_User( (int) $user_id );
		$user->set_role( '' );
		$user->remove_all_caps();
		self::delete_wc_api_keys( $user_id );
		self::destroy_sessions( $user_id );
	}

	/**
	 * Cron cleanup for leftover accounts on this site. An account with the
	 * temp user marker or a grant link is HappyAccess's, because only
	 * HappyAccess writes that meta. It is retired when its grant was
	 * revoked, or, once it is older than the given time, when its grant row
	 * is gone or doesn't link to it. Accounts of live grants are skipped.
	 *
	 * @param int $registered_before Unix time; younger accounts of a missing or unlinked grant wait.
	 * @return int How many accounts were deleted.
	 */
	public static function sweep_leftovers( $registered_before ) {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Cron cleanup, 50 rows at most; table names from the prefix.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT k.user_id FROM {$wpdb->usermeta} k
				INNER JOIN {$wpdb->users} u ON u.ID = k.user_id
				LEFT JOIN {$wpdb->usermeta} b ON b.user_id = k.user_id AND b.meta_key = %s
				WHERE k.meta_key IN ( %s, %s ) AND ( b.meta_value IS NULL OR b.meta_value = %s )
				AND k.user_id NOT IN ( SELECT user_id FROM {$table} WHERE user_id > 0 AND revoked_at IS NULL )
				ORDER BY k.user_id ASC LIMIT %d",
				'happyaccess_blog_id',
				'happyaccess_temp_user',
				'happyaccess_token_id',
				(string) get_current_blog_id(),
				50
			)
		);
		// phpcs:enable

		$count = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( false === $user ) {
				continue;
			}
			$blog_id = (int) get_user_meta( $user_id, 'happyaccess_blog_id', true );
			// Grant ids repeat across a network, so there the account must name this site.
			if ( $blog_id > 0 ? get_current_blog_id() !== $blog_id : is_multisite() ) {
				continue;
			}
			$token_id = (int) get_user_meta( $user_id, 'happyaccess_token_id', true );
			$grant    = $token_id > 0 ? Grants::get( $token_id ) : null;
			$revoked  = null !== $grant && $grant['revoked_at'] > 0;
			if ( ! $revoked ) {
				if ( null !== $grant && (int) $grant['user_id'] === $user_id ) {
					continue;
				}
				if ( Clock::from_mysql( $user->user_registered ) >= (int) $registered_before ) {
					continue;
				}
			}
			if ( self::retire( $user_id, $revoked ? $grant : null ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Strips a leftover account, then deletes it with its posts and links
	 * handed to reassign_target(). With nobody to hand them to, or when the
	 * delete fails, the account stays stripped, keeps its meta and its posts.
	 *
	 * @param int        $user_id User id.
	 * @param array|null $grant   The revoked grant it belonged to, if known.
	 * @return bool True when the account was deleted.
	 */
	public static function retire( $user_id, $grant = null ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( false === $user ) {
			return false;
		}
		$login = $user->user_login;
		self::strip( $user_id );

		$target = self::reassign_target( $user_id );
		if ( null === $target || ! self::remove_user( $user_id, $target ) ) {
			return false;
		}

		$token_id = is_array( $grant ) ? (int) $grant['id'] : 0;
		if ( is_array( $grant ) && (int) $grant['user_id'] === $user_id ) {
			self::set_grant_user( $token_id, 0 );
		}
		AuditLog::add(
			'temp_user_deleted',
			array(
				'feature'  => 'support',
				'token_id' => $token_id,
				'user_id'  => 0,
				'meta'     => array(
					'user_login' => $login,
					'reason'     => 'cleanup',
				),
			)
		);
		return true;
	}

	/**
	 * Who gets a temp user's posts and links: the owner of its pass, the
	 * first administrator who is not a temp user, the current user when that
	 * is a real user, or the first real user with manage_options. Shared by
	 * the cron cleanup and the uninstaller.
	 *
	 * @param int $user_id The user being deleted.
	 * @return int|null User id, or null when the site has no other administrator.
	 */
	public static function reassign_target( $user_id ) {
		$token_id = (int) get_user_meta( $user_id, 'happyaccess_token_id', true );
		$grant    = ( $token_id > 0 && Installer::table_exists( 'tokens' ) ) ? Grants::get( $token_id ) : null;
		if ( null !== $grant ) {
			$owner = Grants::owner_id( $grant );
			if ( $owner > 0 && $owner !== $user_id ) {
				return $owner;
			}
		}

		$admin = self::first_real_user( $user_id, array( 'role' => 'administrator' ) );
		if ( null !== $admin ) {
			return $admin;
		}

		$current = get_current_user_id();
		if ( $current > 0 && $current !== $user_id && false !== get_userdata( $current ) && ! Capabilities::is_temp_user( $current ) && ! self::has_pass_link( $current ) ) {
			return $current;
		}

		return self::first_real_user( $user_id, array( 'capability' => 'manage_options' ) );
	}

	/**
	 * Whether a user carries a pass link.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private static function has_pass_link( $user_id ) {
		return (int) get_user_meta( $user_id, 'happyaccess_token_id', true ) > 0;
	}

	/**
	 * The lowest-ID user of this site that matches the query, is not the
	 * excluded one, and has neither the temp user marker nor a pass link.
	 *
	 * @param int   $exclude User id to skip.
	 * @param array $query   get_users arguments, a role or a capability.
	 * @return int|null
	 */
	private static function first_real_user( $exclude, array $query ) {
		$users = get_users(
			array_merge(
				$query,
				array(
					'fields'     => 'ID',
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'number'     => 1,
					'exclude'    => array( $exclude ),
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One row per account being removed, at cleanup or uninstall.
					'meta_query' => array(
						'relation' => 'AND',
						array(
							'key'     => 'happyaccess_temp_user',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => 'happyaccess_token_id',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			)
		);
		return empty( $users ) ? null : (int) $users[0];
	}

	/**
	 * Ends every login session of a user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function destroy_sessions( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id > 0 ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
	}

	/**
	 * Deletes the WooCommerce REST API keys of a user. Does nothing when
	 * WooCommerce is not installed.
	 *
	 * @param int $user_id User id.
	 * @return int How many keys were deleted.
	 */
	public static function delete_wc_api_keys( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$table   = $wpdb->prefix . 'woocommerce_api_keys';
		if ( $user_id < 1 ) {
			return 0;
		}

		// SHOW TABLES does not list temporary tables, so probe the table with a query that reads no rows.
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table probe, name from the prefix.
		$probe = $wpdb->query( "SELECT 1 FROM {$table} LIMIT 0" );
		$wpdb->suppress_errors( $suppress );
		if ( false === $probe ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce table.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) );
		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Whether any temp user is left on this install.
	 *
	 * @return bool
	 */
	public static function any_exist() {
		global $wpdb;
		if ( null === self::$any ) {
			self::watch();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed lookup, one row, once per request.
			$found     = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1", 'happyaccess_temp_user' ) );
			self::$any = null !== $found;
		}
		return self::$any;
	}

	/**
	 * Forgets whether any temp user exists.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$any = null;
	}

	/**
	 * Forgets the any_exist() answer when the temp user marker is added,
	 * changed or deleted on any user, deletes included.
	 *
	 * @param mixed  $meta_id   Meta id, or ids on delete.
	 * @param int    $object_id User id.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public static function marker_changed( $meta_id, $object_id = 0, $meta_key = '' ) {
		unset( $meta_id, $object_id );
		if ( 'happyaccess_temp_user' === $meta_key ) {
			self::flush_cache();
		}
	}

	/**
	 * Hooks the memo resets: marker changes and site switches. Adding the
	 * same hooks again is harmless.
	 *
	 * @return void
	 */
	private static function watch() {
		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'marker_changed' ), 10, 3 );
		}
		add_action( 'switch_blog', array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * Whether a user carries the grant's id as its temp user link, whether or
	 * not the temp marker is still there.
	 *
	 * @param int   $user_id User id.
	 * @param array $grant   Grant with id.
	 * @return bool
	 */
	public static function owned_by_grant( $user_id, array $grant ) {
		return (int) get_user_meta( (int) $user_id, 'happyaccess_token_id', true ) === (int) $grant['id']
			&& false !== get_userdata( (int) $user_id );
	}

	/**
	 * Whether a user is a temp user created for this exact grant.
	 *
	 * @param int   $user_id User id.
	 * @param array $grant   Grant with id.
	 * @return bool
	 */
	private static function belongs_to_grant( $user_id, array $grant ) {
		return Capabilities::is_temp_user( $user_id )
			&& (int) get_user_meta( $user_id, 'happyaccess_token_id', true ) === (int) $grant['id'];
	}

	/**
	 * Writes the user id on the grant row.
	 *
	 * @param int $grant_id Grant id.
	 * @param int $user_id  User id, 0 to clear.
	 * @return void
	 */
	private static function set_grant_user( $grant_id, $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update( Installer::table( 'tokens' ), array( 'user_id' => (int) $user_id ), array( 'id' => (int) $grant_id ), array( '%d' ), array( '%d' ) );
	}
}
