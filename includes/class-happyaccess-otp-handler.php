<?php
/**
 * OTP handler for HappyAccess plugin.
 *
 * @package HappyAccess
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OTP handler class.
 *
 * @since 1.0.0
 */
class HappyAccess_OTP_Handler {

	/**
	 * Generate a 6-digit OTP.
	 *
	 * @since 1.0.0
	 * @return string The generated OTP.
	 */
	public static function generate_otp() {
		// Generate cryptographically secure random 6-digit code.
		$otp = str_pad( (string) random_int( 100000, 999999 ), 6, '0', STR_PAD_LEFT );
		
		// Ensure it's not already in use (very unlikely but safe).
		if ( self::otp_exists( $otp ) ) {
			return self::generate_otp(); // Recursive call to generate new one.
		}
		
		return $otp;
	}

	/**
	 * Check if an OTP already exists.
	 *
	 * @since 1.0.0
	 * @param string $otp The OTP to check.
	 * @return bool True if exists, false otherwise.
	 */
	private static function otp_exists( $otp ) {
		global $wpdb;
		$table = $wpdb->prefix . 'happyaccess_tokens';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
				"SELECT COUNT(*) FROM $table 
				WHERE otp_code = %s 
				AND expires_at > %s 
				AND revoked_at IS NULL",
				$otp,
				current_time( 'mysql' )
			)
		);
		
		return $exists > 0;
	}

	/**
	 * Verify an OTP.
	 *
	 * @since 1.0.0
	 * @param string $otp The OTP to verify.
	 * @return array|WP_Error Token data on success, WP_Error on failure.
	 */
	public static function verify_otp( $otp ) {
		global $wpdb;
		
		// Sanitize the OTP.
		$otp = preg_replace( '/[^0-9]/', '', $otp );
		
		// Check length.
		if ( strlen( $otp ) !== 6 ) {
			return new WP_Error( 'invalid_otp_format', __( 'Access code must be exactly 6 digits. Please check your code and try again.', 'happyaccess' ) );
		}
		
		// Check rate limiting.
		$rate_limiter = new HappyAccess_Rate_Limiter();
		$rate_check = $rate_limiter->check_rate_limit( 'otp_' . $otp, self::get_client_ip() );
		
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}
		
		// Get token from database.
		$table = $wpdb->prefix . 'happyaccess_tokens';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$token = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
				"SELECT * FROM $table 
				WHERE otp_code = %s 
				AND expires_at > %s 
				AND revoked_at IS NULL 
				AND (max_uses = 0 OR use_count < max_uses)",
				$otp,
				current_time( 'mysql' )
			),
			ARRAY_A
		);
		
		if ( ! $token ) {
			// Log failed attempt.
			$rate_limiter->log_attempt( 'otp_' . $otp, self::get_client_ip() );
			return new WP_Error( 'invalid_otp', __( 'The access code is invalid or has expired. Please verify the code or request a new one.', 'happyaccess' ) );
		}
		
		// Check IP restrictions if set.
		if ( ! empty( $token['ip_restrictions'] ) ) {
			$allowed_ips = explode( ',', $token['ip_restrictions'] );
			$client_ip = self::get_client_ip();
			
			if ( ! in_array( $client_ip, $allowed_ips, true ) ) {
				return new WP_Error( 'ip_restricted', __( 'Access denied: This access code is restricted to specific IP addresses. Please connect from an authorized network or contact the site administrator.', 'happyaccess' ) );
			}
		}
		
		// Update use count and last used time.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, safe table name.
		$wpdb->update(
			$table,
			array(
				'use_count' => $token['use_count'] + 1,
				'used_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $token['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		
		// Log successful verification.
		HappyAccess_Logger::log( 'otp_verified', array(
			'token_id' => $token['id'],
			'ip'       => self::get_client_ip(),
		) );
		
		return $token;
	}

	/**
	 * Get client IP address.
	 *
	 * @since 1.0.0
	 * @return string The client IP address.
	 */
	public static function get_client_ip() {
		$ip = '';
		
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		// phpcs:enable
		
		// Sanitize and validate the IP.
		$ip = sanitize_text_field( $ip );
		
		// Handle multiple IPs (from proxy).
		if ( strpos( $ip, ',' ) !== false ) {
			$ips = explode( ',', $ip );
			$ip = trim( $ips[0] );
		}
		
		// Validate IP format.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '0.0.0.0';
		}
		
		return $ip;
	}
}
