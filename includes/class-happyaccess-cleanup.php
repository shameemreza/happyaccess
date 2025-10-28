<?php
/**
 * Cleanup handler for HappyAccess plugin.
 *
 * @package HappyAccess
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cleanup class.
 *
 * @since 1.0.0
 */
class HappyAccess_Cleanup {

	/**
	 * Clean up expired tokens and users.
	 *
	 * @since 1.0.0
	 */
	public static function cleanup_expired_tokens() {
		$cleaned = HappyAccess_Token_Manager::cleanup_expired_tokens();
		
		if ( $cleaned > 0 ) {
			HappyAccess_Logger::log( 'cleanup_completed', array(
				'tokens_cleaned' => $cleaned,
			) );
		}
	}

	/**
	 * Clean up old failed attempts.
	 *
	 * @since 1.0.0
	 */
	public static function cleanup_old_attempts() {
		global $wpdb;
		$table = $wpdb->prefix . 'happyaccess_attempts';
		
		// Delete attempts older than 24 hours.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
				"DELETE FROM $table WHERE attempted_at < DATE_SUB(%s, INTERVAL 24 HOUR)",
				current_time( 'mysql' )
			)
		);
		
		if ( $deleted > 0 ) {
			HappyAccess_Logger::log( 'attempts_cleaned', array(
				'attempts_deleted' => $deleted,
			) );
		}
		
		return $deleted;
	}
}
