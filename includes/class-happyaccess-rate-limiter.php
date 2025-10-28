<?php
/**
 * Rate limiter for HappyAccess plugin.
 *
 * @package HappyAccess
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate limiter class.
 *
 * @since 1.0.0
 */
class HappyAccess_Rate_Limiter {

	/**
	 * Maximum attempts allowed.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Lockout duration in seconds.
	 *
	 * @var int
	 */
	const LOCKOUT_DURATION = 900; // 15 minutes.

	/**
	 * Check rate limit.
	 *
	 * @since 1.0.0
	 * @param string $identifier Identifier (e.g., OTP code).
	 * @param string $ip         IP address.
	 * @return bool|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check_rate_limit( $identifier, $ip ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'happyaccess_attempts';
		$max_attempts = get_option( 'happyaccess_max_attempts', self::MAX_ATTEMPTS );
		$lockout_duration = get_option( 'happyaccess_lockout_duration', self::LOCKOUT_DURATION );
		
		// Count recent attempts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$recent_attempts = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
				"SELECT COUNT(*) FROM $table 
				WHERE identifier = %s 
				AND ip_address = %s 
				AND attempted_at > %s",
				$identifier,
				$ip,
				gmdate( 'Y-m-d H:i:s', time() - $lockout_duration )
			)
		);
		
		if ( $recent_attempts >= $max_attempts ) {
			return new WP_Error( 
				'rate_limit_exceeded', 
				sprintf(
					/* translators: %d: minutes */
					__( '<strong>Too many failed attempts:</strong> For security reasons, access has been temporarily blocked. Please wait %d minutes before trying again or contact the site administrator for assistance.', 'happyaccess' ),
					round( $lockout_duration / 60 )
				)
			);
		}
		
		return true;
	}

	/**
	 * Log an attempt.
	 *
	 * @since 1.0.0
	 * @param string $identifier   Identifier.
	 * @param string $ip           IP address.
	 * @param string $attempt_type Type of attempt.
	 * @return bool True on success, false on failure.
	 */
	public function log_attempt( $identifier, $ip, $attempt_type = 'otp' ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'happyaccess_attempts';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
		$result = $wpdb->insert(
			$table,
			array(
				'identifier'    => $identifier,
				'attempt_type'  => $attempt_type,
				'ip_address'    => $ip,
				'attempted_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
		
		return false !== $result;
	}

	/**
	 * Clear attempts for an identifier.
	 *
	 * @since 1.0.0
	 * @param string $identifier Identifier.
	 * @param string $ip         IP address.
	 * @return int Number of attempts cleared.
	 */
	public function clear_attempts( $identifier, $ip ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'happyaccess_attempts';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, safe table name.
		$deleted = $wpdb->delete(
			$table,
			array(
				'identifier' => $identifier,
				'ip_address' => $ip,
			),
			array( '%s', '%s' )
		);
		
		return $deleted;
	}

	/**
	 * Get remaining attempts.
	 *
	 * @since 1.0.0
	 * @param string $identifier Identifier.
	 * @param string $ip         IP address.
	 * @return int Number of remaining attempts.
	 */
	public function get_remaining_attempts( $identifier, $ip ) {
		global $wpdb;
		
		$table = $wpdb->prefix . 'happyaccess_attempts';
		$max_attempts = get_option( 'happyaccess_max_attempts', self::MAX_ATTEMPTS );
		$lockout_duration = get_option( 'happyaccess_lockout_duration', self::LOCKOUT_DURATION );
		
		// Count recent attempts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$recent_attempts = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
				"SELECT COUNT(*) FROM $table 
				WHERE identifier = %s 
				AND ip_address = %s 
				AND attempted_at > %s",
				$identifier,
				$ip,
				gmdate( 'Y-m-d H:i:s', time() - $lockout_duration )
			)
		);
		
		$remaining = $max_attempts - $recent_attempts;
		
		return max( 0, $remaining );
	}
}
