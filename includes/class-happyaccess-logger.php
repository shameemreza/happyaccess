<?php
/**
 * Logger for HappyAccess plugin.
 *
 * @package HappyAccess
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger class.
 *
 * @since 1.0.0
 */
class HappyAccess_Logger {

	/**
	 * Log an event.
	 *
	 * @since 1.0.0
	 * @param string $event_type Event type.
	 * @param array  $data       Event data.
	 * @return bool True on success, false on failure.
	 */
	public static function log( $event_type, $data = array() ) {
		global $wpdb;
		
		// Check if logging is enabled.
		if ( ! get_option( 'happyaccess_enable_logging', true ) ) {
			return false;
		}
		
		$table = $wpdb->prefix . 'happyaccess_logs';
		
		// Get current user if not in data.
		if ( ! isset( $data['user_id'] ) ) {
			$data['user_id'] = get_current_user_id();
		}
		
		// Get IP address.
		$ip_address = HappyAccess_OTP_Handler::get_client_ip();
		
		// Get user agent.
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? 
			sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		
		// Get token ID from data or default.
		$token_id = isset( $data['token_id'] ) ? absint( $data['token_id'] ) : 0;
		
		// Prepare metadata.
		$metadata = ! empty( $data ) ? wp_json_encode( $data ) : null;
		
		// Insert log entry.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
		$result = $wpdb->insert(
			$table,
			array(
				'token_id'    => $token_id,
				'event_type'  => $event_type,
				'user_id'     => $data['user_id'],
				'ip_address'  => $ip_address,
				'user_agent'  => $user_agent,
				'metadata'    => $metadata,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		
		return false !== $result;
	}

	/**
	 * Get logs.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Array of log entries.
	 */
	public static function get_logs( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'happyaccess_logs';
		
		$defaults = array(
			'limit'      => 50,
			'offset'     => 0,
			'event_type' => '',
			'token_id'   => 0,
			'user_id'    => 0,
			'order'      => 'DESC',
		);
		
		$args = wp_parse_args( $args, $defaults );
		
		$where = array();
		$where_values = array();
		
		if ( ! empty( $args['event_type'] ) ) {
			$where[] = 'event_type = %s';
			$where_values[] = $args['event_type'];
		}
		
		if ( ! empty( $args['token_id'] ) ) {
			$where[] = 'token_id = %d';
			$where_values[] = $args['token_id'];
		}
		
		if ( ! empty( $args['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$where_values[] = $args['user_id'];
		}
		
		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
		
		$query = "SELECT * FROM $table $where_clause ORDER BY created_at {$args['order']} LIMIT %d OFFSET %d";
		$where_values[] = $args['limit'];
		$where_values[] = $args['offset'];
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$logs = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is dynamically built but safe.
			$wpdb->prepare( $query, ...$where_values ),
			ARRAY_A
		);
		
		return $logs ? $logs : array();
	}

	/**
	 * Clear old logs.
	 *
	 * @since 1.0.0
	 * @param int $days Number of days to keep.
	 * @return int Number of logs deleted.
	 */
	public static function clear_old_logs( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'happyaccess_logs';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
				"DELETE FROM $table WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)",
				current_time( 'mysql' ),
				$days
			)
		);
		
		return $deleted;
	}
}
