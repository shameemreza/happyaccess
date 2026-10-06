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

		self::set_grant_user( (int) $grant['id'], $user_id );

		return $user_id;
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
		if ( $user_id > 0 && false !== get_userdata( $user_id ) && self::belongs_to_grant( $user_id, $grant ) ) {
			$blog_id = (int) get_user_meta( $user_id, 'happyaccess_blog_id', true );
			if ( 0 === $blog_id || get_current_blog_id() === $blog_id ) {
				return $user_id;
			}
		}
		return self::create( $grant );
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

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( is_multisite() && ! function_exists( 'wpmu_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}

		$reassign = null;
		if ( ! empty( $grant['created_by'] ) && false !== get_userdata( (int) $grant['created_by'] ) ) {
			$reassign = (int) $grant['created_by'];
		}

		$login = $user->user_login;
		if ( is_multisite() ) {
			$other_blogs = array_diff( array_keys( (array) get_blogs_of_user( $user_id ) ), array( get_current_blog_id() ) );
			// Removing from the blog first reassigns posts and links; wpmu_delete_user alone would delete them.
			$removed = remove_user_from_blog( $user_id, get_current_blog_id(), $reassign );
			$deleted = ! is_wp_error( $removed );
			if ( $deleted && empty( $other_blogs ) ) {
				$deleted = wpmu_delete_user( $user_id );
			}
		} else {
			$deleted = wp_delete_user( $user_id, $reassign );
		}
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
