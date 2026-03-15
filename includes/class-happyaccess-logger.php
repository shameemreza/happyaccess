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
		
		$table = esc_sql( $wpdb->prefix . 'happyaccess_logs' );
		
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
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		
		return false !== $result;
	}

	/**
	 * Get logs.
	 *
	 * @since 1.0.0
	 * @since 1.0.4 Added date_from/date_to filter support; reduced cache TTL.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of log entries.
	 */
	public static function get_logs( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'      => 50,
			'offset'     => 0,
			'event_type' => '',
			'token_id'   => 0,
			'user_id'    => 0,
			'date_from'  => '',
			'date_to'    => '',
			'order'      => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$limit  = absint( $args['limit'] );
		$offset = absint( $args['offset'] );
		$order  = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$table = esc_sql( $wpdb->prefix . 'happyaccess_logs' );

		// Build WHERE conditions and prepared values.
		$where_clauses = array();
		$prepare_args  = array();

		if ( ! empty( $args['event_type'] ) ) {
			$where_clauses[] = 'event_type = %s';
			$prepare_args[]  = sanitize_text_field( $args['event_type'] );
		}

		if ( ! empty( $args['token_id'] ) && absint( $args['token_id'] ) > 0 ) {
			$where_clauses[] = 'token_id = %d';
			$prepare_args[]  = absint( $args['token_id'] );
		}

		if ( ! empty( $args['user_id'] ) && absint( $args['user_id'] ) > 0 ) {
			$where_clauses[] = 'user_id = %d';
			$prepare_args[]  = absint( $args['user_id'] );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where_clauses[] = 'created_at >= %s';
			$prepare_args[]  = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where_clauses[] = 'created_at <= %s';
			$prepare_args[]  = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
		}

		// Build the query.
		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		// Order is whitelisted above (ASC or DESC only), not user input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped, $where_sql uses placeholders, $order is whitelisted.
		$sql = "SELECT * FROM `$table` $where_sql ORDER BY created_at $order LIMIT %d OFFSET %d";

		$prepare_args[] = $limit;
		$prepare_args[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; $where_clauses contains only placeholder strings (%s, %d), actual values are in $prepare_args and escaped by prepare().
		$logs = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared below with dynamic args.
			$wpdb->prepare( $sql, $prepare_args ),
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
		$table = esc_sql( $wpdb->prefix . 'happyaccess_logs' );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
				"DELETE FROM `$table` WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)",
				gmdate( 'Y-m-d H:i:s' ),
				$days
			)
		);
		
		return $deleted;
	}
}
