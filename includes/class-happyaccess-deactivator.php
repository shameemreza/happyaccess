<?php
/**
 * Deactivator class for HappyAccess plugin.
 *
 * @package HappyAccess
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin deactivation handler.
 *
 * @since 1.0.0
 */
class HappyAccess_Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * SECURITY: All active tokens are revoked on deactivation to prevent
	 * security risks. This is intentional - admin access tools must not
	 * leave active access credentials when disabled.
	 *
	 * @since 1.0.0
	 * @since 1.0.2 Revoke all active tokens on deactivation for security.
	 */
	public static function deactivate() {
		// Clear scheduled cron events.
		wp_clear_scheduled_hook( 'happyaccess_cleanup_expired' );
		wp_clear_scheduled_hook( 'happyaccess_cleanup_attempts' );
		
		// SECURITY: Revoke all active tokens and cleanup users.
		self::revoke_all_tokens_and_cleanup();
		
		// Clear rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Revoke all tokens and clean up ALL temporary users.
	 *
	 * SECURITY: This ensures no access credentials remain active when
	 * the plugin is deactivated. This is critical for an admin access tool.
	 *
	 * @since 1.0.2
	 */
	private static function revoke_all_tokens_and_cleanup() {
		global $wpdb;
		
		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );
		
		// STEP 1: Get ALL tokens with user_ids (regardless of status).
		// This ensures we clean up ALL temp users, not just from active tokens.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$tokens_with_users = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
			"SELECT id, user_id FROM `$table` WHERE user_id IS NOT NULL",
			ARRAY_A
		);
		
		$deleted_users = 0;
		
		// Delete ALL temporary users.
		if ( $tokens_with_users ) {
			foreach ( $tokens_with_users as $token ) {
				if ( ! empty( $token['user_id'] ) ) {
					$user = get_user_by( 'ID', $token['user_id'] );
					if ( $user && get_user_meta( $token['user_id'], 'happyaccess_temp_user', true ) ) {
						wp_delete_user( $token['user_id'] );
						$deleted_users++;
					}
				}
			}
		}
		
		// STEP 2: Clear user_ids from ALL tokens.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
			"UPDATE `$table` SET user_id = NULL WHERE user_id IS NOT NULL"
		);
		
		// STEP 3: Revoke ALL non-expired, non-revoked tokens.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$revoked_count = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
				"UPDATE `$table` 
				SET revoked_at = %s 
				WHERE expires_at > %s 
				AND revoked_at IS NULL",
				current_time( 'mysql' ),
				current_time( 'mysql' )
			)
		);
		
		// Log the deactivation action if logger is available.
		if ( class_exists( 'HappyAccess_Logger' ) ) {
			HappyAccess_Logger::log( 'plugin_deactivated', array(
				'revoked_tokens' => $revoked_count,
				'deleted_users'  => $deleted_users,
				'reason'         => 'plugin_deactivation',
			) );
		}
	}
}
