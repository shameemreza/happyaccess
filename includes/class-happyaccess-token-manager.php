<?php
/**
 * Token manager for HappyAccess plugin.
 *
 * @package HappyAccess
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token manager class.
 *
 * @since 1.0.0
 */
class HappyAccess_Token_Manager {

	/**
	 * Generate a new access token.
	 *
	 * @since 1.0.0
	 * @since 1.0.2 Added $single_use parameter for one-time use tokens.
	 *
	 * @param int    $duration        Duration in seconds.
	 * @param string $role            WordPress user role.
	 * @param array  $metadata        Optional metadata.
	 * @param string $ip_restrictions Optional comma-separated IP addresses.
	 * @param bool   $single_use      Optional. If true, token auto-revokes after first use.
	 * @return array|WP_Error Token data on success, WP_Error on failure.
	 */
	public static function generate_token( $duration, $role, $metadata = array(), $ip_restrictions = '', $single_use = false ) {
		global $wpdb;
		
		// Validate duration.
		$duration = absint( $duration );
		if ( $duration < 3600 || $duration > 2592000 ) { // 1 hour to 30 days.
			return new WP_Error( 'invalid_duration', __( 'Invalid duration. Must be between 1 hour and 30 days.', 'happyaccess' ) );
		}
		
		// Validate role.
		$valid_roles = wp_roles()->get_names();
		if ( ! array_key_exists( $role, $valid_roles ) ) {
			return new WP_Error( 'invalid_role', __( 'Invalid user role.', 'happyaccess' ) );
		}
		
		// Generate secure token.
		$token = wp_generate_password( 64, false, false );
		$token_data = $token . '|' . time() . '|' . wp_salt( 'auth' );
		$token_hash = hash_hmac( 'sha256', $token_data, wp_salt( 'secure_auth' ) );
		
		// Generate OTP.
		$otp = HappyAccess_OTP_Handler::generate_otp();
		
		// Generate unique username.
		$temp_username = self::generate_temp_username();
		
		// Calculate expiry.
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $duration );
		
		// Prepare metadata.
		// Add single_use flag to metadata for display purposes.
		if ( $single_use ) {
			$metadata['single_use'] = true;
		}
		$metadata_json = ! empty( $metadata ) ? wp_json_encode( $metadata ) : null;
		
		// Prepare IP restrictions (sanitize and validate).
		$ip_restrictions = ! empty( $ip_restrictions ) ? sanitize_text_field( $ip_restrictions ) : null;
		
		// Determine max_uses: 1 for single-use, 0 for unlimited.
		$max_uses = $single_use ? 1 : 0;
		
		// Insert token into database.
		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
		$result = $wpdb->insert(
			$table,
			array(
				'token_hash'      => $token_hash,
				'otp_code'        => $otp,
				'temp_username'   => $temp_username,
				'role'            => $role,
				'created_by'      => get_current_user_id(),
				'created_at'      => current_time( 'mysql' ),
				'expires_at'      => $expires_at,
				'metadata'        => $metadata_json,
				'ip_restrictions' => $ip_restrictions,
				'max_uses'        => $max_uses,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		
		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to create token.', 'happyaccess' ) );
		}
		
		$token_id = $wpdb->insert_id;
		
		// Log token creation with masked OTP.
		$masked_otp = substr( $otp, 0, 2 ) . '****';
		$log_data = array(
			'token_id'   => $token_id,
			'otp'        => $masked_otp,
			'role'       => $role,
			'duration'   => self::format_duration( $duration ),
			'created_by' => get_current_user_id(),
		);
		if ( $single_use ) {
			$log_data['single_use'] = true;
		}
		HappyAccess_Logger::log( 'token_created', $log_data );
		
		return array(
			'id'            => $token_id,
			'token'         => $token,
			'hash'          => $token_hash,
			'otp'           => $otp,
			'temp_username' => $temp_username,
			'role'          => $role,
			'expires_at'    => $expires_at,
			'metadata'      => $metadata,
			'single_use'    => $single_use,
		);
	}

	/**
	 * Generate a unique temporary username.
	 *
	 * @since 1.0.0
	 * @return string The generated username.
	 */
	private static function generate_temp_username() {
		$prefix = 'happyaccess_';
		$suffix = wp_generate_password( 8, false, false );
		$username = strtolower( $prefix . $suffix );
		
		// Ensure uniqueness.
		if ( username_exists( $username ) ) {
			return self::generate_temp_username(); // Recursive call.
		}
		
		return $username;
	}

	/**
	 * Revoke a token.
	 *
	 * @since 1.0.0
	 * @param int $token_id Token ID.
	 * @return bool True on success, false on failure.
	 */
	public static function revoke_token( $token_id ) {
		global $wpdb;
		
		$token_id = absint( $token_id );
		if ( ! $token_id ) {
			return false;
		}
		
		// Get token details first.
		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$token = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
			$wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $token_id ),
			ARRAY_A
		);
		
		if ( ! $token ) {
			return false;
		}
		
		// Mark as revoked.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, safe table name.
		$result = $wpdb->update(
			$table,
			array( 'revoked_at' => current_time( 'mysql' ) ),
			array( 'id' => $token_id ),
			array( '%s' ),
			array( '%d' )
		);
		
		if ( false === $result ) {
			return false;
		}
		
		// Delete associated temporary user if exists.
		if ( ! empty( $token['user_id'] ) ) {
			$temp_user = new HappyAccess_Temp_User();
			$temp_user->delete_temp_user( $token['user_id'] );
		}
		
		// Log revocation.
		HappyAccess_Logger::log( 'token_revoked', array(
			'token_id' => $token_id,
			'revoked_by' => get_current_user_id(),
		) );
		
		return true;
	}

	/**
	 * Get all active tokens.
	 *
	 * Returns only truly active tokens (not expired, not revoked).
	 * Revoked tokens (including used single-use) are visible in Audit Logs.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of active tokens.
	 */
	public static function get_active_tokens() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$tokens = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
				"SELECT * FROM `$table` 
				WHERE expires_at > %s 
				AND revoked_at IS NULL 
				ORDER BY created_at DESC",
				current_time( 'mysql' )
			),
			ARRAY_A
		);
		
		return $tokens ? $tokens : array();
	}

	/**
	 * Clean up expired tokens.
	 *
	 * @since 1.0.0
	 * @return int Number of tokens cleaned up.
	 */
	public static function cleanup_expired_tokens() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );
		
		// Get expired tokens with associated users.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$expired_tokens = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
				"SELECT * FROM `$table` 
				WHERE expires_at < %s 
				AND user_id IS NOT NULL",
				current_time( 'mysql' )
			),
			ARRAY_A
		);
		
		$count = 0;
		$temp_user = new HappyAccess_Temp_User();
		
		foreach ( $expired_tokens as $token ) {
			// Delete temporary user.
			if ( $temp_user->delete_temp_user( $token['user_id'] ) ) {
				// Clear user_id from token.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, safe table name.
				$wpdb->update(
					$table,
					array( 'user_id' => null ),
					array( 'id' => $token['id'] ),
					array( '%d' ),
					array( '%d' )
				);
				
				$count++;
				
				// Log cleanup.
				HappyAccess_Logger::log( 'token_expired_cleanup', array(
					'token_id' => $token['id'],
					'user_id' => $token['user_id'],
				) );
			}
		}
		
		// Delete very old tokens (30 days).
		$retention_days = get_option( 'happyaccess_cleanup_days', 30 );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
				"DELETE FROM `$table` 
				WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)",
				current_time( 'mysql' ),
				$retention_days
			)
		);
		
		return $count;
	}

	/**
	 * Format duration in seconds to human-readable string.
	 *
	 * @since 1.0.1
	 * @param int  $seconds      Duration in seconds.
	 * @param bool $include_secs Whether to include seconds for short durations.
	 * @return string Human-readable duration.
	 */
	public static function format_duration( $seconds, $include_secs = true ) {
		$seconds = absint( $seconds );
		
		if ( $seconds >= 86400 ) {
			$days = floor( $seconds / 86400 );
			$hours = floor( ( $seconds % 86400 ) / 3600 );
			return sprintf(
				/* translators: 1: number of days, 2: number of hours */
				__( '%1$d days %2$d hours', 'happyaccess' ),
				$days,
				$hours
			);
		} elseif ( $seconds >= 3600 ) {
			$hours = floor( $seconds / 3600 );
			$mins = floor( ( $seconds % 3600 ) / 60 );
			return sprintf(
				/* translators: 1: number of hours, 2: number of minutes */
				__( '%1$d hours %2$d minutes', 'happyaccess' ),
				$hours,
				$mins
			);
		} elseif ( $seconds >= 60 ) {
			$minutes = floor( $seconds / 60 );
			$secs = $seconds % 60;
			if ( $include_secs ) {
				return sprintf(
					/* translators: 1: number of minutes, 2: number of seconds */
					__( '%1$d minutes %2$d seconds', 'happyaccess' ),
					$minutes,
					$secs
				);
			}
			return sprintf(
				/* translators: %d: number of minutes */
				_n( '%d minute', '%d minutes', $minutes, 'happyaccess' ),
				$minutes
			);
		} else {
			return sprintf(
				/* translators: %d: number of seconds */
				_n( '%d second', '%d seconds', $seconds, 'happyaccess' ),
				$seconds
			);
		}
	}
}
