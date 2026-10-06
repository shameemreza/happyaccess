<?php
/**
 * Temporary support users.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and removes the WordPress user behind a support grant.
 */
final class TempUsers {

	/**
	 * Creates the temp user for a grant and links it on the grant row.
	 *
	 * @param array $grant Grant with id, role, label and created_by.
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

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $username . '@happyaccess.invalid',
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $name,
				'nickname'     => $name,
				'role'         => sanitize_key( $grant['role'] ),
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

		return self::link_or_yield( $grant, $user_id );
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
	 * Deletes the grant's temp user. Never touches a normal user.
	 *
	 * @param array $grant Grant with id, created_by and user_id.
	 * @return bool True when a temp user was removed.
	 */
	public static function delete( array $grant ) {
		$user_id = isset( $grant['user_id'] ) ? (int) $grant['user_id'] : 0;
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

		$owner_id = Grants::owner_id( $grant );
		$reassign = $owner_id > 0 ? $owner_id : null;

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
	 * Removes a user from this site, and from the network when no other site
	 * uses it.
	 *
	 * @param int      $user_id  User id.
	 * @param int|null $reassign Who gets the user's posts and links, or null.
	 * @return bool
	 */
	private static function remove_user( $user_id, $reassign ) {
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed lookup, one row.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1", 'happyaccess_temp_user' ) );
		return null !== $found;
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
