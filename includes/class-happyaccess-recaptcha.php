<?php
/**
 * reCAPTCHA v3 handler for HappyAccess plugin.
 *
 * Provides invisible bot protection for OTP login.
 *
 * @package HappyAccess
 * @since   1.0.3
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * reCAPTCHA handler class.
 *
 * @since 1.0.3
 */
class HappyAccess_ReCaptcha {

	/**
	 * reCAPTCHA v3 verify endpoint.
	 *
	 * @var string
	 */
	const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	/**
	 * Default score threshold (0.0 - 1.0).
	 * Scores below this are considered bots.
	 *
	 * @var float
	 */
	const DEFAULT_THRESHOLD = 0.5;

	/**
	 * Check if reCAPTCHA is enabled and configured.
	 *
	 * @since 1.0.3
	 *
	 * @return bool True if enabled and configured.
	 */
	public static function is_enabled() {
		$enabled    = get_option( 'happyaccess_recaptcha_enabled', false );
		$site_key   = get_option( 'happyaccess_recaptcha_site_key', '' );
		$secret_key = get_option( 'happyaccess_recaptcha_secret_key', '' );

		return $enabled && ! empty( $site_key ) && ! empty( $secret_key );
	}

	/**
	 * Get reCAPTCHA site key.
	 *
	 * @since 1.0.3
	 *
	 * @return string Site key.
	 */
	public static function get_site_key() {
		return get_option( 'happyaccess_recaptcha_site_key', '' );
	}

	/**
	 * Get reCAPTCHA secret key.
	 *
	 * @since 1.0.3
	 *
	 * @return string Secret key.
	 */
	public static function get_secret_key() {
		return get_option( 'happyaccess_recaptcha_secret_key', '' );
	}

	/**
	 * Get score threshold.
	 *
	 * @since 1.0.3
	 *
	 * @return float Score threshold (0.0 - 1.0).
	 */
	public static function get_threshold() {
		$threshold = get_option( 'happyaccess_recaptcha_threshold', self::DEFAULT_THRESHOLD );
		return max( 0.0, min( 1.0, (float) $threshold ) );
	}

	/**
	 * Enqueue reCAPTCHA script on login page.
	 *
	 * @since 1.0.3
	 */
	public static function enqueue_scripts() {
		if ( ! self::is_enabled() ) {
			return;
		}

		$site_key = self::get_site_key();

		// Enqueue reCAPTCHA v3 script.
		wp_enqueue_script(
			'google-recaptcha',
			'https://www.google.com/recaptcha/api.js?render=' . esc_attr( $site_key ),
			array(),
			'3.0',
			true
		);

		// Add inline script to handle token generation.
		$inline_script = sprintf(
			'document.addEventListener("DOMContentLoaded", function() {
				var loginForm = document.getElementById("loginform");
				if (!loginForm) return;
				
				// Create hidden input for reCAPTCHA token.
				var tokenInput = document.createElement("input");
				tokenInput.type = "hidden";
				tokenInput.name = "happyaccess_recaptcha_token";
				tokenInput.id = "happyaccess_recaptcha_token";
				loginForm.appendChild(tokenInput);
				
				// Execute reCAPTCHA on form submit.
				loginForm.addEventListener("submit", function(e) {
					var otpField = document.getElementById("happyaccess_otp");
					if (!otpField || !otpField.value) {
						return true; // Only verify for HappyAccess OTP login.
					}
					
					if (tokenInput.value) {
						return true; // Already have token.
					}
					
					e.preventDefault();
					grecaptcha.ready(function() {
						grecaptcha.execute("%s", {action: "happyaccess_login"}).then(function(token) {
							tokenInput.value = token;
							loginForm.submit();
						});
					});
				});
			});',
			esc_js( $site_key )
		);

		wp_add_inline_script( 'google-recaptcha', $inline_script );
	}

	/**
	 * Verify reCAPTCHA token.
	 *
	 * @since 1.0.3
	 *
	 * @param string $token The reCAPTCHA response token.
	 * @return array|WP_Error Verification result or error.
	 */
	public static function verify( $token ) {
		if ( empty( $token ) ) {
			return new WP_Error( 'missing_token', __( 'reCAPTCHA verification failed. Please try again.', 'happyaccess' ) );
		}

		$secret_key = self::get_secret_key();
		if ( empty( $secret_key ) ) {
			// reCAPTCHA not properly configured - allow access but log warning.
			HappyAccess_Logger::log( 'recaptcha_misconfigured', array(
				'error' => 'Secret key not configured',
			) );
			return array(
				'success' => true,
				'score'   => 1.0,
				'action'  => 'happyaccess_login',
			);
		}

		// Make verification request to Google.
		$response = wp_remote_post( self::VERIFY_URL, array(
			'timeout' => 10,
			'body'    => array(
				'secret'   => $secret_key,
				'response' => $token,
				'remoteip' => self::get_client_ip(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			HappyAccess_Logger::log( 'recaptcha_error', array(
				'error' => $response->get_error_message(),
			) );
			// On network error, fail open (allow access) to not block legitimate users.
			return array(
				'success' => true,
				'score'   => 1.0,
				'action'  => 'happyaccess_login',
				'error'   => 'network_error',
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid reCAPTCHA response. Please try again.', 'happyaccess' ) );
		}

		// Check if verification succeeded.
		if ( empty( $data['success'] ) ) {
			$error_codes = isset( $data['error-codes'] ) ? implode( ', ', $data['error-codes'] ) : 'unknown';
			HappyAccess_Logger::log( 'recaptcha_failed', array(
				'error_codes' => $error_codes,
				'ip'          => self::get_client_ip(),
			) );
			return new WP_Error( 'verification_failed', __( 'reCAPTCHA verification failed. Please try again.', 'happyaccess' ) );
		}

		// Check action.
		if ( isset( $data['action'] ) && 'happyaccess_login' !== $data['action'] ) {
			return new WP_Error( 'action_mismatch', __( 'reCAPTCHA action mismatch. Please try again.', 'happyaccess' ) );
		}

		// Check score.
		$score     = isset( $data['score'] ) ? (float) $data['score'] : 0.0;
		$threshold = self::get_threshold();

		if ( $score < $threshold ) {
			HappyAccess_Logger::log( 'recaptcha_low_score', array(
				'score'     => $score,
				'threshold' => $threshold,
				'ip'        => self::get_client_ip(),
			) );
			return new WP_Error( 
				'low_score', 
				__( 'Access denied. Our security system detected suspicious activity.', 'happyaccess' )
			);
		}

		return array(
			'success' => true,
			'score'   => $score,
			'action'  => $data['action'] ?? '',
		);
	}

	/**
	 * Validate reCAPTCHA during OTP authentication.
	 *
	 * Called during the authenticate filter.
	 *
	 * @since 1.0.3
	 *
	 * @return true|WP_Error True if valid, WP_Error if failed.
	 */
	public static function validate_login() {
		if ( ! self::is_enabled() ) {
			return true;
		}

		// Check if OTP is being used.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Login form validation.
		if ( empty( $_POST['happyaccess_otp'] ) ) {
			return true; // Only validate for HappyAccess OTP login.
		}

		// Get reCAPTCHA token.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress login form doesn't use nonces.
		$token = isset( $_POST['happyaccess_recaptcha_token'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress login form doesn't use nonces.
			? sanitize_text_field( wp_unslash( $_POST['happyaccess_recaptcha_token'] ) )
			: '';

		// Verify the token.
		$result = self::verify( $token );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Log successful verification.
		HappyAccess_Logger::log( 'recaptcha_passed', array(
			'score' => $result['score'],
			'ip'    => self::get_client_ip(),
		) );

		return true;
	}

	/**
	 * Get client IP address.
	 *
	 * @since 1.0.3
	 *
	 * @return string Client IP address.
	 */
	private static function get_client_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ip = explode( ',', $ip )[0];
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return filter_var( trim( $ip ), FILTER_VALIDATE_IP ) ? trim( $ip ) : '0.0.0.0';
	}
}

