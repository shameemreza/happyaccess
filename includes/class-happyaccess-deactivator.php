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
	 * @since 1.0.0
	 */
	public static function deactivate() {
		// Clear scheduled cron events.
		wp_clear_scheduled_hook( 'happyaccess_cleanup_expired' );
		wp_clear_scheduled_hook( 'happyaccess_cleanup_attempts' );
		
		// Clean up any active temporary users.
		self::cleanup_temp_users();
		
		// Clear rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Clean up temporary users.
	 *
	 * @since 1.0.0
	 */
	private static function cleanup_temp_users() {
		global $wpdb;
		
		// Get all active tokens with users.
		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$tokens = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe, no user input.
			"SELECT user_id FROM `$table` WHERE user_id IS NOT NULL",
			ARRAY_A
		);
		
		if ( $tokens ) {
			foreach ( $tokens as $token ) {
				if ( ! empty( $token['user_id'] ) ) {
					// Delete the temporary user.
					wp_delete_user( $token['user_id'] );
				}
			}
			
			// Clear user_ids from tokens.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, safe table name.
			$wpdb->update(
				$table,
				array( 'user_id' => null ),
				array(),
				array( '%d' ),
				array()
			);
		}
	}
}
