<?php
/**
 * Temporary user management for HappyAccess plugin.
 *
 * @package HappyAccess
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Temporary user class.
 *
 * @since 1.0.0
 */
class HappyAccess_Temp_User {

	/**
	 * Create or get a temporary user for a token.
	 *
	 * @since 1.0.0
	 * @param int $token_id Token ID.
	 * @return WP_User|WP_Error User object on success, WP_Error on failure.
	 */
	public function create_or_get( $token_id ) {
		global $wpdb;
		
		// Get token details.
		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$token = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
			$wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $token_id ),
			ARRAY_A
		);
		
		if ( ! $token ) {
			return new WP_Error( 'invalid_token', __( 'Invalid token.', 'happyaccess' ) );
		}
		
		// Check if user already exists for this token.
		if ( ! empty( $token['user_id'] ) ) {
			$user = get_user_by( 'id', $token['user_id'] );
			if ( $user ) {
				return $user;
			}
		}
		
		// Create new temporary user.
		$user_data = array(
			'user_login'    => $token['temp_username'],
			'user_pass'     => wp_generate_password( 32, true, true ), // Strong random password.
			'user_email'    => $token['temp_username'] . '@happyaccess.local',
			'role'          => $token['role'],
			/* translators: %s: user role */
			'display_name'  => sprintf( __( 'Temp Access (%s)', 'happyaccess' ), $token['role'] ),
			'description'   => __( 'Temporary user created by HappyAccess', 'happyaccess' ),
		);
		
		// Insert user.
		$user_id = wp_insert_user( $user_data );
		
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		
		// Add user meta to identify as temporary.
		update_user_meta( $user_id, 'happyaccess_temp_user', true );
		update_user_meta( $user_id, 'happyaccess_token_id', $token_id );
		update_user_meta( $user_id, 'happyaccess_expires', $token['expires_at'] );
		
		// Update token with user ID.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
		$wpdb->update(
			$table,
			array( 'user_id' => $user_id ),
			array( 'id' => $token_id ),
			array( '%d' ),
			array( '%d' )
		);
		
		// Log user creation.
		HappyAccess_Logger::log( 'temp_user_created', array(
			'token_id' => $token_id,
			'user_id'  => $user_id,
			'username' => $token['temp_username'],
			'role'     => $token['role'],
		) );
		
		return get_user_by( 'id', $user_id );
	}

	/**
	 * Delete a temporary user.
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_temp_user( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		
		if ( ! $user ) {
			return false;
		}
		
		// Verify this is a temporary user.
		if ( ! get_user_meta( $user_id, 'happyaccess_temp_user', true ) ) {
			return false;
		}
		
		// Log before deletion.
		HappyAccess_Logger::log( 'temp_user_deleted', array(
			'user_id'  => $user_id,
			'username' => $user->user_login,
		) );
		
		// Ensure wp_delete_user() is available (not loaded during init hook).
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		
		// Delete the user.
		return wp_delete_user( $user_id );
	}

	/**
	 * Check if a user is a temporary user.
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return bool True if temporary, false otherwise.
	 */
	public static function is_temp_user( $user_id ) {
		return (bool) get_user_meta( $user_id, 'happyaccess_temp_user', true );
	}
}
