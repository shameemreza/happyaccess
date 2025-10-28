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
			'1.0.0',
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
			<input type="text" name="happyaccess_otp" id="happyaccess_otp" 
				   class="input" value="" size="20" 
				   maxlength="6" 
				   pattern="[0-9]{6}" 
				   autocomplete="off" />
		</p>
		<p class="description">
			<?php esc_html_e( 'If you have a temporary access code from the site owner, enter it here. Regular users should use the standard login fields above.', 'happyaccess' ); ?>
		</p>
		<br>
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
			// Log failed attempt.
			HappyAccess_Logger::log( 'login_failed', array(
				'error' => $token_data->get_error_message(),
				'ip'    => HappyAccess_OTP_Handler::get_client_ip(),
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
		
		// Log successful login.
		HappyAccess_Logger::log( 'login_success', array(
			'token_id' => $token_data['id'],
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
		
		if ( $expires ) {
			$time_left = strtotime( $expires ) - time();
			
			if ( $time_left > 0 ) {
				$hours = floor( $time_left / 3600 );
				$minutes = floor( ( $time_left % 3600 ) / 60 );
				
				$text = sprintf(
					/* translators: 1: hours, 2: minutes */
					__( '⏱️ Temporary Access: %1$dh %2$dm remaining', 'happyaccess' ),
					$hours,
					$minutes
				);
				
				$wp_admin_bar->add_node( array(
					'id'    => 'happyaccess-notice',
					'title' => $text,
					'meta'  => array(
						'class' => 'happyaccess-admin-bar-notice',
					),
				) );
			}
		}
	}
}

// Hook into login system.
add_filter( 'login_message', array( 'HappyAccess_Login_Handler', 'login_message' ) );
add_filter( 'login_redirect', array( 'HappyAccess_Login_Handler', 'login_redirect' ), 10, 3 );
add_action( 'admin_bar_menu', array( 'HappyAccess_Login_Handler', 'admin_bar_menu' ), 100 );
