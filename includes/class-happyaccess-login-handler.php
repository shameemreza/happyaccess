<?php
/**
 * Login handler for HappyAccess plugin.
 *
 * @package HappyAccess
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Login handler class.
 *
 * @since 1.0.0
 */
class HappyAccess_Login_Handler {

	/**
	 * Enqueue login scripts.
	 *
	 * @since 1.0.0
	 */
	public static function enqueue_login_scripts() {
		wp_enqueue_script(
			'happyaccess-login',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/login.js',
			array(),
			HAPPYACCESS_VERSION,
			true
		);
	}

	/**
	 * Add OTP field to login form.
	 *
	 * @since 1.0.0
	 */
	public static function add_otp_field() {
		?>
		<p>
			<label for="happyaccess_otp"><?php esc_html_e( 'Temporary Support Access', 'happyaccess' ); ?></label>
			<input type="text" 
				   name="happyaccess_otp" 
				   id="happyaccess_otp" 
				   class="input" 
				   value="" 
				   size="20" 
				   maxlength="6" 
				   pattern="[0-9]{6}" 
				   inputmode="numeric"
				   autocomplete="one-time-code"
				   aria-describedby="happyaccess_otp_description"
				   placeholder="<?php esc_attr_e( '000000', 'happyaccess' ); ?>" />
		</p>
		<p id="happyaccess_otp_description" class="description">
			<?php esc_html_e( 'If you have a temporary access code from the site owner, enter it here. Regular users should use the standard login fields above.', 'happyaccess' ); ?>
		</p>
		<br />
		<?php
	}

	/**
	 * Authenticate with OTP.
	 *
	 * @since 1.0.0
	 * @since 1.0.2 Added auto-revoke for single-use tokens.
	 * @since 1.0.3 Added reCAPTCHA v3 verification.
	 * @since 1.0.6 Added fallback for mod_security hosts that strip custom POST params.
	 *
	 * @param WP_User|WP_Error|null $user     User object or error.
	 * @param string                $username Username.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error User object on success, error on failure.
	 */
	public static function authenticate_otp( $user, $username, $password ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress login form doesn't use nonces.
		$otp_from_post = isset( $_POST['happyaccess_otp'] ) ?
			sanitize_text_field( wp_unslash( $_POST['happyaccess_otp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Fallback: server-level WAFs (e.g. mod_security on Newfold/Bluehost
		// hosting) strip non-standard POST parameters from wp-login.php. The
		// login JS embeds a sentinel username and the OTP value in the
		// password field so the code still reaches the server.
		$is_fallback = false;
		if ( empty( $otp_from_post ) && 'happyaccess_otp' === $username ) {
			$otp_from_post = $password;
			$is_fallback   = true;

			// Prevent core auth handlers from trying to authenticate the
			// sentinel username and overriding our OTP error messages.
			remove_filter( 'authenticate', 'wp_authenticate_username_password', 20 );
			remove_filter( 'authenticate', 'wp_authenticate_email_password', 20 );
		}

		if ( empty( $otp_from_post ) ) {
			return $user;
		}

		// If we already have a WP_User, skip (another authentication method succeeded).
		if ( $user instanceof WP_User ) {
			return $user;
		}
		
		// Verify reCAPTCHA first (if enabled).
		$recaptcha_result = HappyAccess_ReCaptcha::validate_login();
		if ( is_wp_error( $recaptcha_result ) ) {
			return $recaptcha_result;
		}
		
		// Sanitize OTP from whichever source provided it.
		$otp = sanitize_text_field( $otp_from_post );
		
		// Remove non-numeric characters.
		$otp = preg_replace( '/[^0-9]/', '', $otp );

		if ( $is_fallback ) {
			HappyAccess_Logger::log( 'otp_fallback_used', array(
				'ip' => HappyAccess_OTP_Handler::get_client_ip(),
			) );
		}
		
		// Check OTP length.
		if ( strlen( $otp ) !== 6 ) {
			return new WP_Error( 
				'invalid_otp_format', 
				__( '<strong>Invalid format:</strong> Access codes must be exactly 6 digits. Please enter the complete code provided by your site administrator.', 'happyaccess' )
			);
		}
		
		// Verify OTP.
		$token_data = HappyAccess_OTP_Handler::verify_otp( $otp );
		
		if ( is_wp_error( $token_data ) ) {
			// Log failed attempt with the attempted OTP (masked for security).
			$masked_otp = substr( $otp, 0, 2 ) . '****';
			HappyAccess_Logger::log( 'login_failed', array(
				'attempted_code' => $masked_otp,
				'error'          => $token_data->get_error_message(),
				'ip'             => HappyAccess_OTP_Handler::get_client_ip(),
			) );
			
			return new WP_Error( 
				'invalid_otp', 
				__( '<strong>Access code error:</strong> The code you entered is invalid or has expired. Please check the code and try again, or request a new access code from the site administrator.', 'happyaccess' )
			);
		}
		
		// Create or get temporary user.
		$temp_user_handler = new HappyAccess_Temp_User();
		$temp_user = $temp_user_handler->create_or_get( $token_data['id'] );
		
		if ( is_wp_error( $temp_user ) ) {
			return $temp_user;
		}

		// Block deactivated temp users from logging in.
		if ( HappyAccess_Access_Guard::is_deactivated( $temp_user->ID ) ) {
			return new WP_Error(
				'happyaccess_deactivated',
				__( '<strong>Access suspended:</strong> Your temporary access has been deactivated by the site administrator. Please contact them to reactivate.', 'happyaccess' )
			);
		}
		
		// Store session start time for tracking.
		update_user_meta( $temp_user->ID, 'happyaccess_session_start', time() );
		
		// Log successful login with masked OTP.
		$masked_otp = substr( $otp, 0, 2 ) . '****';
		$log_data = array(
			'token_id' => $token_data['id'],
			'otp'      => $masked_otp,
			'user_id'  => $temp_user->ID,
			'username' => $temp_user->user_login,
			'role'     => $token_data['role'],
		);
		
		// Check if this is a single-use token.
		$is_single_use = ! empty( $token_data['_single_use_pending_revoke'] );
		if ( $is_single_use ) {
			$log_data['single_use'] = true;
		}
		
		HappyAccess_Logger::log( 'login_success', $log_data );
		
		// Clear any rate limiting for this IP.
		$rate_limiter = new HappyAccess_Rate_Limiter();
		$rate_limiter->clear_attempts( 'otp_' . $otp, HappyAccess_OTP_Handler::get_client_ip() );
		
		// SECURITY: Auto-revoke single-use tokens after successful login.
		// The user is already authenticated and their session is established.
		// Revoking now prevents the code from being reused.
		if ( $is_single_use ) {
			// Store token ID in user meta for reference (the token is about to be revoked).
			update_user_meta( $temp_user->ID, 'happyaccess_single_use_revoked', true );
			
			// Auto-revoke the token.
			HappyAccess_OTP_Handler::auto_revoke_single_use_token( $token_data['id'] );
		}
		
		// Return the user object to complete login.
		return $temp_user;
	}

	/**
	 * Add custom login message.
	 *
	 * @since 1.0.0
	 * @param string $message Existing message.
	 * @return string Modified message.
	 */
	public static function login_message( $message ) {
		// Check if we're coming from a HappyAccess link.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking for display message, not processing data.
		if ( isset( $_GET['happyaccess'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['happyaccess'] ) ) ) {
			$message .= '<div class="message" style="border-left-color: #00a32a;">';
			$message .= '<strong>' . esc_html__( 'Temporary Access Login', 'happyaccess' ) . '</strong><br />';
			$message .= esc_html__( 'Enter your 6-digit access code below. No username or password needed.', 'happyaccess' );
			$message .= '</div>';
		}
		
		return $message;
	}

	/**
	 * Redirect after successful temporary login.
	 *
	 * @since 1.0.0
	 * @param string  $redirect_to Redirect URL.
	 * @param string  $request     Requested redirect URL.
	 * @param WP_User $user        User object.
	 * @return string Modified redirect URL.
	 */
	public static function login_redirect( $redirect_to, $request, $user ) {
		// Check if this is a temporary user.
		if ( $user && ! is_wp_error( $user ) && HappyAccess_Temp_User::is_temp_user( $user->ID ) ) {
			// Redirect to admin dashboard by default.
			if ( empty( $request ) ) {
				return admin_url();
			}
		}
		
		return $redirect_to;
	}

	/**
	 * Add admin bar notification for temporary users.
	 *
	 * @since 1.0.0
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar object.
	 */
	public static function admin_bar_menu( $wp_admin_bar ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		
		$user_id = get_current_user_id();
		
		// Check if this is a temporary user.
		if ( ! HappyAccess_Temp_User::is_temp_user( $user_id ) ) {
			return;
		}
		
		// Get expiry time.
		$expires = get_user_meta( $user_id, 'happyaccess_expires', true );
		
		// Get session start time.
		$session_start = get_user_meta( $user_id, 'happyaccess_session_start', true );
		if ( ! $session_start ) {
			$session_start = time();
			update_user_meta( $user_id, 'happyaccess_session_start', $session_start );
		}
		
		if ( $expires ) {
			$expiry_timestamp = strtotime( $expires );
			$time_left = $expiry_timestamp - time();
			
			if ( $time_left > 0 ) {
				// Format times.
				$expires_display = self::format_time_remaining( $time_left );
				$session_duration = time() - $session_start;
				$session_display = self::format_time_remaining( $session_duration );
				
				$text = sprintf(
					'<span id="happyaccess-timer" data-expires="%d" data-session-start="%d" data-logout="%s">⏱️ %s <strong>%s</strong> · %s <strong>%s</strong></span>',
					esc_attr( $expiry_timestamp ),
					esc_attr( $session_start ),
					esc_url( wp_logout_url( home_url() ) ),
					esc_html__( 'Access expires in', 'happyaccess' ),
					esc_html( $expires_display ),
					esc_html__( 'Current session', 'happyaccess' ),
					esc_html( $session_display )
				);
				
				// Add main node.
				$wp_admin_bar->add_node( array(
					'id'    => 'happyaccess-notice',
					'title' => $text,
					'meta'  => array(
						'class' => 'happyaccess-admin-bar-notice',
					),
				) );
				
				// Add logout link as submenu.
				$wp_admin_bar->add_node( array(
					'id'     => 'happyaccess-logout',
					'parent' => 'happyaccess-notice',
					'title'  => __( '🚪 End Session & Logout', 'happyaccess' ),
					'href'   => wp_logout_url( home_url() ),
					'meta'   => array(
						'class' => 'happyaccess-admin-bar-logout',
						'title' => __( 'Log out from this temporary session', 'happyaccess' ),
					),
				) );
				
				// Enqueue countdown script with localized strings.
				wp_enqueue_script(
					'happyaccess-countdown',
					plugin_dir_url( dirname( __FILE__ ) ) . 'assets/countdown.js',
					array(),
					HAPPYACCESS_VERSION,
					true
				);
				wp_localize_script( 'happyaccess-countdown', 'happyaccessCountdown', array(
					'expired'   => __( 'Access expired, logging out...', 'happyaccess' ),
					'expiresIn' => __( 'Access expires in', 'happyaccess' ),
					'session'   => __( 'Current session', 'happyaccess' ),
				) );
			}
		}
	}

	/**
	 * Handle temp user logout.
	 *
	 * @since 1.0.1
	 * @since 1.0.3 Added temp user deletion on logout.
	 *
	 * @param int $user_id User ID being logged out.
	 */
	public static function handle_logout( $user_id ) {
		// Check if this is a temporary user.
		if ( ! HappyAccess_Temp_User::is_temp_user( $user_id ) ) {
			return;
		}
		
		// Get session start time.
		$session_start = get_user_meta( $user_id, 'happyaccess_session_start', true );
		$session_duration = $session_start ? ( time() - $session_start ) : 0;
		
		// Get user info for logging.
		$user = get_user_by( 'ID', $user_id );
		$username = $user ? $user->user_login : 'unknown';
		
		// Log the logout with session duration.
		HappyAccess_Logger::log( 'temp_user_logout', array(
			'user_id'          => $user_id,
			'username'         => $username,
			'session_duration' => HappyAccess_Token_Manager::format_duration( $session_duration ),
		) );
		
		// Clear session start time.
		delete_user_meta( $user_id, 'happyaccess_session_start' );
		
		// Schedule temp user deletion after logout completes.
		// We use shutdown hook to ensure the logout process completes first.
		add_action( 'shutdown', function() use ( $user_id ) {
			$temp_user_handler = new HappyAccess_Temp_User();
			$temp_user_handler->delete_temp_user( $user_id );
		} );
	}

	/**
	 * Format time remaining in human-readable format.
	 *
	 * @since 1.0.1
	 * @param int $seconds Seconds remaining.
	 * @return string Formatted time string.
	 */
	public static function format_time_remaining( $seconds ) {
		$days = floor( $seconds / 86400 );
		$hours = floor( ( $seconds % 86400 ) / 3600 );
		$minutes = floor( ( $seconds % 3600 ) / 60 );
		$secs = $seconds % 60;
		
		if ( $days > 0 ) {
			// Show days, hours, minutes (no seconds - too much detail for days).
			return sprintf(
				/* translators: 1: days, 2: hours, 3: minutes */
				_n( '%1$dd %2$dh %3$dm', '%1$dd %2$dh %3$dm', $days, 'happyaccess' ),
				$days,
				$hours,
				$minutes
			);
		} elseif ( $hours > 0 ) {
			// Show hours, minutes, seconds.
			return sprintf(
				/* translators: 1: hours, 2: minutes, 3: seconds */
				__( '%1$dh %2$dm %3$ds', 'happyaccess' ),
				$hours,
				$minutes,
				$secs
			);
		} else {
			// Show minutes, seconds.
			return sprintf(
				/* translators: 1: minutes, 2: seconds */
				__( '%1$dm %2$ds', 'happyaccess' ),
				$minutes,
				$secs
			);
		}
	}

	/**
	 * Countdown timer is now loaded from assets/countdown.js via wp_enqueue_script()
	 * in admin_bar_menu(). This method is kept for backward compatibility but is no
	 * longer hooked — see admin_bar_menu().
	 *
	 * @since 1.0.1
	 * @since 1.0.5 Moved to external JS file with wp_enqueue_script().
	 */
	public static function countdown_script() {
		// No-op: countdown is now enqueued as an external script in admin_bar_menu().
	}
}

// Hook into login system.
add_filter( 'login_message', array( 'HappyAccess_Login_Handler', 'login_message' ) );
add_filter( 'login_redirect', array( 'HappyAccess_Login_Handler', 'login_redirect' ), 10, 3 );
add_action( 'admin_bar_menu', array( 'HappyAccess_Login_Handler', 'admin_bar_menu' ), 100 );
add_action( 'wp_logout', array( 'HappyAccess_Login_Handler', 'handle_logout' ) );
