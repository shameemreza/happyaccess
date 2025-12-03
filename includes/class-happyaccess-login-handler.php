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
	 * @param WP_User|WP_Error|null $user     User object or error.
	 * @param string                $username Username.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error User object on success, error on failure.
	 */
	public static function authenticate_otp( $user, $username, $password ) {
		// Check if OTP is provided.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress login form doesn't use nonces.
		if ( empty( $_POST['happyaccess_otp'] ) ) {
			// No OTP provided, continue with normal authentication.
			return $user;
		}
		
		// If we already have a WP_User, skip (another authentication method succeeded).
		if ( $user instanceof WP_User ) {
			return $user;
		}
		
		// Get and sanitize OTP.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress login form doesn't use nonces.
		$otp = isset( $_POST['happyaccess_otp'] ) ? 
			sanitize_text_field( wp_unslash( $_POST['happyaccess_otp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		
		// Remove non-numeric characters.
		$otp = preg_replace( '/[^0-9]/', '', $otp );
		
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
		
		// Store session start time for tracking.
		update_user_meta( $temp_user->ID, 'happyaccess_session_start', time() );
		
		// Log successful login with masked OTP.
		$masked_otp = substr( $otp, 0, 2 ) . '****';
		HappyAccess_Logger::log( 'login_success', array(
			'token_id' => $token_data['id'],
			'otp'      => $masked_otp,
			'user_id'  => $temp_user->ID,
			'username' => $temp_user->user_login,
			'role'     => $token_data['role'],
		) );
		
		// Clear any rate limiting for this IP.
		$rate_limiter = new HappyAccess_Rate_Limiter();
		$rate_limiter->clear_attempts( 'otp_' . $otp, HappyAccess_OTP_Handler::get_client_ip() );
		
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
		if ( isset( $_GET['happyaccess'] ) && $_GET['happyaccess'] === '1' ) {
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
				
				// Add inline script for live countdown.
				add_action( 'admin_footer', array( __CLASS__, 'countdown_script' ) );
				add_action( 'wp_footer', array( __CLASS__, 'countdown_script' ) );
			}
		}
	}

	/**
	 * Handle temp user logout.
	 *
	 * @since 1.0.1
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
	 * Output countdown timer script.
	 *
	 * @since 1.0.1
	 */
	public static function countdown_script() {
		static $output = false;
		if ( $output ) {
			return;
		}
		$output = true;
		?>
		<script type="text/javascript">
		(function() {
			var timer = document.getElementById('happyaccess-timer');
			if (!timer) return;
			
			var expires = parseInt(timer.getAttribute('data-expires'), 10) * 1000;
			var sessionStart = parseInt(timer.getAttribute('data-session-start'), 10) * 1000;
			var logoutUrl = timer.getAttribute('data-logout');
			
			function formatTime(ms) {
				var seconds = Math.floor(ms / 1000);
				if (seconds < 0) seconds = 0;
				var days = Math.floor(seconds / 86400);
				var hours = Math.floor((seconds % 86400) / 3600);
				var mins = Math.floor((seconds % 3600) / 60);
				var secs = seconds % 60;
				
				if (days > 0) {
					return days + 'd ' + hours + 'h ' + mins + 'm';
				} else if (hours > 0) {
					return hours + 'h ' + mins + 'm ' + secs + 's';
				} else {
					return mins + 'm ' + secs + 's';
				}
			}
			
			function updateTimer() {
				var now = Date.now();
				var remaining = expires - now;
				var active = now - sessionStart;
				
				if (remaining <= 0) {
					timer.innerHTML = '⏱️ <?php echo esc_js( __( 'Access expired, logging out...', 'happyaccess' ) ); ?>';
					// Auto logout after a brief delay.
					setTimeout(function() {
						window.location.href = logoutUrl;
					}, 1500);
					return;
				}
				
				timer.innerHTML = '⏱️ <?php echo esc_js( __( 'Access expires in', 'happyaccess' ) ); ?> <strong>' + formatTime(remaining) + '</strong> · <?php echo esc_js( __( 'Current session', 'happyaccess' ) ); ?> <strong>' + formatTime(active) + '</strong>';
			}
			
			updateTimer();
			setInterval(updateTimer, 1000);
		})();
		</script>
		<?php
	}
}

// Hook into login system.
add_filter( 'login_message', array( 'HappyAccess_Login_Handler', 'login_message' ) );
add_filter( 'login_redirect', array( 'HappyAccess_Login_Handler', 'login_redirect' ), 10, 3 );
add_action( 'admin_bar_menu', array( 'HappyAccess_Login_Handler', 'admin_bar_menu' ), 100 );
add_action( 'wp_logout', array( 'HappyAccess_Login_Handler', 'handle_logout' ) );
