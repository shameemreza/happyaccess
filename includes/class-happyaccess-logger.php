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
		
		$defaults = array(
			'limit'      => 50,
			'offset'     => 0,
			'event_type' => '',
			'token_id'   => 0,
			'user_id'    => 0,
			'order'      => 'DESC',
		);
		
		$args = wp_parse_args( $args, $defaults );
		
		// Sanitize inputs.
		$limit     = absint( $args['limit'] );
		$offset    = absint( $args['offset'] );
		$order_asc = ( 'ASC' === strtoupper( $args['order'] ) );
		
		// Cache key for this query.
		$cache_key = 'happyaccess_logs_' . md5( wp_json_encode( $args ) );
		$logs      = wp_cache_get( $cache_key, 'happyaccess' );
		
		if ( false === $logs ) {
			// Determine which filters are active.
			$has_event_type = ! empty( $args['event_type'] );
			$has_token_id   = ! empty( $args['token_id'] ) && absint( $args['token_id'] ) > 0;
			$has_user_id    = ! empty( $args['user_id'] ) && absint( $args['user_id'] ) > 0;
			
			// Execute query based on active filters (avoiding dynamic SQL building).
			// Note: Direct database queries are required for custom plugin tables.
			// Results are cached using wp_cache_set() below.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $has_event_type && $has_token_id && $has_user_id ) {
				// All three filters.
				if ( $order_asc ) {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE event_type = %s AND token_id = %d AND user_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
							$args['event_type'],
							absint( $args['token_id'] ),
							absint( $args['user_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				} else {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE event_type = %s AND token_id = %d AND user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
							$args['event_type'],
							absint( $args['token_id'] ),
							absint( $args['user_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				}
			} elseif ( $has_event_type && $has_token_id ) {
				if ( $order_asc ) {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE event_type = %s AND token_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
							$args['event_type'],
							absint( $args['token_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				} else {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE event_type = %s AND token_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
							$args['event_type'],
							absint( $args['token_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				}
			} elseif ( $has_event_type && $has_user_id ) {
				if ( $order_asc ) {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE event_type = %s AND user_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
							$args['event_type'],
							absint( $args['user_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				} else {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE event_type = %s AND user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
							$args['event_type'],
							absint( $args['user_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				}
			} elseif ( $has_token_id && $has_user_id ) {
				if ( $order_asc ) {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE token_id = %d AND user_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
							absint( $args['token_id'] ),
							absint( $args['user_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				} else {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE token_id = %d AND user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
							absint( $args['token_id'] ),
							absint( $args['user_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				}
			} elseif ( $has_event_type ) {
				if ( $order_asc ) {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE event_type = %s ORDER BY created_at ASC LIMIT %d OFFSET %d",
							$args['event_type'],
							$limit,
							$offset
						),
						ARRAY_A
					);
				} else {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE event_type = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
							$args['event_type'],
							$limit,
							$offset
						),
						ARRAY_A
					);
				}
			} elseif ( $has_token_id ) {
				if ( $order_asc ) {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE token_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
							absint( $args['token_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				} else {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE token_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
							absint( $args['token_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				}
			} elseif ( $has_user_id ) {
				if ( $order_asc ) {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE user_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
							absint( $args['user_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				} else {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
							absint( $args['user_id'] ),
							$limit,
							$offset
						),
						ARRAY_A
					);
				}
			} else {
				// No filters.
				if ( $order_asc ) {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs ORDER BY created_at ASC LIMIT %d OFFSET %d",
							$limit,
							$offset
						),
						ARRAY_A
					);
				} else {
					$logs = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}happyaccess_logs ORDER BY created_at DESC LIMIT %d OFFSET %d",
							$limit,
							$offset
						),
						ARRAY_A
					);
				}
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			
			// Cache results.
			wp_cache_set( $cache_key, $logs, 'happyaccess', HOUR_IN_SECONDS );
		}
		
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
				current_time( 'mysql' ),
				$days
			)
		);
		
		return $deleted;
	}
}
