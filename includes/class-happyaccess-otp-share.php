<?php
/**
 * OTP Share handler for HappyAccess plugin.
 *
 * Provides secure, time-limited links to view OTP codes.
 *
 * @package HappyAccess
 * @since   1.0.3
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OTP Share handler class.
 *
 * @since 1.0.3
 */
class HappyAccess_OTP_Share {

	/**
	 * Query parameter name for share link.
	 *
	 * @var string
	 */
	const QUERY_PARAM = 'ha_v'; // Short query param for cleaner URLs.

	/**
	 * Initialize share link hooks.
	 *
	 * @since 1.0.3
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_share_link' ), 1 );
	}

	/**
	 * Generate a share link for an OTP.
	 *
	 * @since 1.0.3
	 *
	 * @param int    $token_id           The token ID.
	 * @param string $otp_code           The OTP code to share.
	 * @param int    $expiration_seconds Link expiration in seconds.
	 * @param bool   $single_view        If true, link expires after first view.
	 * @return array|WP_Error Array with 'url' and 'expires_at' on success, WP_Error on failure.
	 */
	public static function generate( $token_id, $otp_code, $expiration_seconds = 300, $single_view = true ) {
		global $wpdb;

		$token_id = absint( $token_id );
		if ( ! $token_id || empty( $otp_code ) ) {
			return new WP_Error( 'invalid_params', __( 'Invalid token ID or OTP code.', 'happyaccess' ) );
		}

		// Validate expiration (1-10 minutes).
		$expiration_seconds = absint( $expiration_seconds );
		if ( $expiration_seconds < 60 || $expiration_seconds > 600 ) {
			$expiration_seconds = 300; // Default to 5 minutes.
		}

		// Ensure table exists.
		self::maybe_create_table();

		// SECURITY: Invalidate any previous share links for this token.
		self::invalidate_previous_shares( $token_id );

		// Generate a cryptographically secure share token (shorter but still secure).
		$share_token = bin2hex( random_bytes( 12 ) ); // 24 character hex string - 96 bits of entropy.
		$now_utc     = time();
		$share_hash  = hash_hmac( 'sha256', $share_token . '|' . $now_utc, wp_salt( 'secure_auth' ) );

		// Calculate expiration — store in UTC for consistent comparison.
		$expires_at = gmdate( 'Y-m-d H:i:s', $now_utc + $expiration_seconds );
		$created_at = gmdate( 'Y-m-d H:i:s', $now_utc );

		// Store the share link in database.
		$share_table = esc_sql( $wpdb->prefix . 'happyaccess_otp_shares' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$result = $wpdb->insert(
			$share_table,
			array(
				'token_id'    => $token_id,
				'otp_code'    => $otp_code,
				'share_hash'  => $share_hash,
				'expires_at'  => $expires_at,
				'single_view' => $single_view ? 1 : 0,
				'created_at'  => $created_at,
				'created_by'  => get_current_user_id(),
				'ip_address'  => self::get_client_ip(),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to create share link.', 'happyaccess' ) );
		}

		$share_id = $wpdb->insert_id;

		// Build the share link URL.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for URL encoding.
		$share_param = base64_encode( $share_token . ':' . $share_id );
		$share_url   = add_query_arg(
			self::QUERY_PARAM,
			rawurlencode( $share_param ),
			home_url( '/' )
		);

		// Log share link creation.
		HappyAccess_Logger::log( 'otp_share_created', array(
			'token_id'    => $token_id,
			'share_id'    => $share_id,
			'otp'         => substr( $otp_code, 0, 2 ) . '****',
			'expires_in'  => HappyAccess_Token_Manager::format_duration( $expiration_seconds, false ),
			'single_view' => $single_view,
		) );

		return array(
			'url'         => $share_url,
			'expires_at'  => $expires_at,
			'share_id'    => $share_id,
			'single_view' => $single_view,
		);
	}

	/**
	 * Handle incoming share link requests.
	 *
	 * @since 1.0.3
	 */
	public static function handle_share_link() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Share link authentication.
		if ( ! isset( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Share link authentication.
		$share_param = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for share link token.
		$decoded     = base64_decode( $share_param, true );

		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			self::render_error_page( __( 'Invalid share link.', 'happyaccess' ) );
			return;
		}

		// Parse share token and ID.
		$parts       = explode( ':', $decoded, 2 );
		$share_token = $parts[0];
		$share_id    = absint( $parts[1] ?? 0 );

		if ( empty( $share_token ) || ! $share_id ) {
			self::render_error_page( __( 'Invalid share link format.', 'happyaccess' ) );
			return;
		}

		// Verify the share link.
		$result = self::verify( $share_token, $share_id );

		if ( is_wp_error( $result ) ) {
			HappyAccess_Logger::log( 'otp_share_failed', array(
				'share_id' => $share_id,
				'error'    => $result->get_error_message(),
				'ip'       => self::get_client_ip(),
			) );
			self::render_error_page( $result->get_error_message() );
			return;
		}

		// Log successful view.
		HappyAccess_Logger::log( 'otp_share_viewed', array(
			'share_id' => $share_id,
			'token_id' => $result['token_id'],
			'otp'      => substr( $result['otp_code'], 0, 2 ) . '****',
			'ip'       => self::get_client_ip(),
		) );

		// Render the OTP display page.
		self::render_otp_page( $result['otp_code'], $result['expires_at'], $result['single_view'] );
	}

	/**
	 * Verify a share link token.
	 *
	 * @since 1.0.3
	 *
	 * @param string $share_token The share token from URL.
	 * @param int    $share_id    The share link ID.
	 * @return array|WP_Error Share data on success, WP_Error on failure.
	 */
	private static function verify( $share_token, $share_id ) {
		global $wpdb;

		// Rate limiting check.
		$rate_limiter = new HappyAccess_Rate_Limiter();
		$ip           = self::get_client_ip();

		$rate_check = $rate_limiter->check_rate_limit( 'otp_share', $ip );
		if ( is_wp_error( $rate_check ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many attempts. Please try again later.', 'happyaccess' ) );
		}

		// Ensure table exists.
		self::maybe_create_table();

		$share_table = esc_sql( $wpdb->prefix . 'happyaccess_otp_shares' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$share = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped.
				"SELECT * FROM `$share_table` WHERE id = %d",
				$share_id
			),
			ARRAY_A
		);

		if ( ! $share ) {
			$rate_limiter->log_attempt( 'otp_share', $ip, 'share_not_found' );
			return new WP_Error( 'not_found', __( 'Share link not found or has been removed.', 'happyaccess' ) );
		}

		// Check if already viewed (for single-view links).
		if ( $share['single_view'] && ! empty( $share['viewed_at'] ) ) {
			return new WP_Error( 'already_viewed', __( 'This link has already been viewed. For security, share links can only be viewed once.', 'happyaccess' ) );
		}

		// Check if expired (timestamps stored in UTC).
		if ( strtotime( $share['expires_at'] . ' UTC' ) < time() ) {
			return new WP_Error( 'expired', __( 'This share link has expired. Please request a new one.', 'happyaccess' ) );
		}

		// Verify token hash (created_at was stored in UTC via gmdate).
		$expected_hash = hash_hmac( 'sha256', $share_token . '|' . strtotime( $share['created_at'] . ' UTC' ), wp_salt( 'secure_auth' ) );

		if ( ! hash_equals( $share['share_hash'], $expected_hash ) ) {
			$rate_limiter->log_attempt( 'otp_share', $ip, 'share_invalid' );
			return new WP_Error( 'invalid_token', __( 'Invalid share link token.', 'happyaccess' ) );
		}

		// Mark as viewed if single-view (atomic to prevent race condition).
		if ( $share['single_view'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$marked = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
					"UPDATE `$share_table` SET viewed_at = %s, viewed_ip = %s WHERE id = %d AND viewed_at IS NULL",
					gmdate( 'Y-m-d H:i:s' ),
					$ip,
					$share_id
				)
			);

			if ( 0 === (int) $marked ) {
				return new WP_Error( 'already_viewed', __( 'This link has already been viewed. For security, share links can only be viewed once.', 'happyaccess' ) );
			}
		}

		// Clear rate limiting on success.
		$rate_limiter->clear_attempts( 'otp_share', $ip );

		return array(
			'otp_code'    => $share['otp_code'],
			'token_id'    => $share['token_id'],
			'expires_at'  => $share['expires_at'],
			'single_view' => (bool) $share['single_view'],
		);
	}

	/**
	 * Invalidate previous share links for a token.
	 *
	 * @since 1.0.3
	 *
	 * @param int $token_id Token ID.
	 * @return int Number of shares invalidated.
	 */
	private static function invalidate_previous_shares( $token_id ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'happyaccess_otp_shares' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$invalidated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped.
				"UPDATE `$table` 
				SET viewed_at = %s, viewed_ip = %s 
				WHERE token_id = %d AND viewed_at IS NULL",
				gmdate( 'Y-m-d H:i:s' ),
				'invalidated_by_new_share',
				$token_id
			)
		);

		return $invalidated ? $invalidated : 0;
	}

	/**
	 * Render the OTP display page.
	 *
	 * @since 1.0.3
	 *
	 * @param string $otp_code    The OTP code.
	 * @param string $expires_at  Expiration datetime.
	 * @param bool   $single_view Whether this was a single-view link.
	 */
	private static function render_otp_page( $otp_code, $expires_at, $single_view ) {
		$site_name = get_bloginfo( 'name' );
		$expires_display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $expires_at ) );
		
		// Prevent caching.
		nocache_headers();
		
		// Enqueue styles and scripts via WP API.
		wp_enqueue_style( 'happyaccess-otp-share', HAPPYACCESS_PLUGIN_URL . 'assets/otp-share.css', array(), HAPPYACCESS_VERSION );
		wp_enqueue_script( 'happyaccess-otp-share-copy', HAPPYACCESS_PLUGIN_URL . 'assets/otp-share-copy.js', array(), HAPPYACCESS_VERSION, true );
		wp_localize_script( 'happyaccess-otp-share-copy', 'happyaccessOtpShare', array(
			'copied' => __( 'Copied!', 'happyaccess' ),
		) );
		
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex, nofollow">
			<title><?php
				/* translators: %s: site name */
				echo esc_html( sprintf( __( 'Access Code - %s', 'happyaccess' ), $site_name ) );
			?></title>
			<?php wp_print_styles( 'happyaccess-otp-share' ); ?>
		</head>
		<body class="happyaccess-otp-page">
			<div class="container">
				<div class="header">
					<h1><?php esc_html_e( 'Temporary Access Code', 'happyaccess' ); ?></h1>
					<div class="site-name"><?php echo esc_html( $site_name ); ?></div>
				</div>
				<div class="content">
					<div class="otp-label"><?php esc_html_e( 'Your Access Code', 'happyaccess' ); ?></div>
					<div class="otp-code" id="otp-code"><?php echo esc_html( $otp_code ); ?></div>
					<br>
					<button type="button" class="button" id="copy-btn" onclick="copyCode()">
						<?php esc_html_e( 'Copy Code', 'happyaccess' ); ?>
					</button>
					
					<div class="info">
						<p><strong><?php esc_html_e( 'Expires:', 'happyaccess' ); ?></strong> <?php echo esc_html( $expires_display ); ?></p>
						<p><?php esc_html_e( 'Enter this code at the login page in the "Temporary Support Access" field.', 'happyaccess' ); ?></p>
					</div>
					
					<?php if ( $single_view ) : ?>
					<div class="notice-warning">
						<p><strong><?php esc_html_e( 'Note:', 'happyaccess' ); ?></strong> <?php esc_html_e( 'This link is now expired. Save this code - you cannot view this page again.', 'happyaccess' ); ?></p>
					</div>
					<?php endif; ?>
				</div>
				<div class="footer">
					<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Go to Login Page', 'happyaccess' ); ?> &rarr;</a>
				</div>
			</div>
			<?php wp_print_scripts( 'happyaccess-otp-share-copy' ); ?>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Render error page.
	 *
	 * @since 1.0.3
	 *
	 * @param string $message Error message.
	 */
	private static function render_error_page( $message ) {
		$site_name = get_bloginfo( 'name' );
		
		nocache_headers();
		
		// Enqueue styles via WP API.
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'happyaccess-otp-share', HAPPYACCESS_PLUGIN_URL . 'assets/otp-share.css', array( 'dashicons' ), HAPPYACCESS_VERSION );
		
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex, nofollow">
			<title><?php
				/* translators: %s: site name */
				echo esc_html( sprintf( __( 'Link Error - %s', 'happyaccess' ), $site_name ) );
			?></title>
			<?php wp_print_styles( array( 'dashicons', 'happyaccess-otp-share' ) ); ?>
		</head>
		<body class="happyaccess-error-page">
			<div class="container">
				<span class="dashicons dashicons-warning"></span>
				<h1><?php esc_html_e( 'Link Unavailable', 'happyaccess' ); ?></h1>
				<p><?php echo esc_html( $message ); ?></p>
				<a href="<?php echo esc_url( home_url() ); ?>" class="button"><?php esc_html_e( 'Return to Site', 'happyaccess' ); ?></a>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Create the OTP shares table if it doesn't exist.
	 *
	 * Uses a static flag to avoid repeated SHOW TABLES queries within
	 * the same request, and checks the DB version option across requests.
	 *
	 * @since 1.0.3
	 * @since 1.0.4 Added static flag and version check for performance.
	 */
	public static function maybe_create_table() {
		static $checked = false;

		if ( $checked ) {
			return;
		}
		$checked = true;

		// Skip if already created in a previous request.
		$db_version = get_option( 'happyaccess_otp_shares_db', '0' );
		if ( '1' === $db_version ) {
			return;
		}

		global $wpdb;

		$table_name      = $wpdb->prefix . 'happyaccess_otp_shares';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			token_id bigint(20) UNSIGNED NOT NULL,
			otp_code varchar(10) NOT NULL,
			share_hash varchar(64) NOT NULL,
			expires_at datetime NOT NULL,
			single_view tinyint(1) DEFAULT 1,
			created_at datetime NOT NULL,
			created_by bigint(20) UNSIGNED DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			viewed_at datetime DEFAULT NULL,
			viewed_ip varchar(45) DEFAULT NULL,
			PRIMARY KEY (id),
			KEY token_id (token_id),
			KEY expires_at (expires_at),
			KEY share_hash (share_hash)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'happyaccess_otp_shares_db', '1' );
	}

	/**
	 * Clean up expired share links.
	 *
	 * @since 1.0.3
	 *
	 * @return int Number of links cleaned up.
	 */
	public static function cleanup_expired() {
		global $wpdb;

		// Ensure table exists.
		self::maybe_create_table();

		$table = esc_sql( $wpdb->prefix . 'happyaccess_otp_shares' );

		// Delete share links expired more than 1 hour ago.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table cleanup.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped.
				"DELETE FROM `$table` WHERE expires_at < DATE_SUB(%s, INTERVAL 1 HOUR)",
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return $deleted ? $deleted : 0;
	}

	/**
	 * Get client IP address.
	 *
	 * @since 1.0.3
	 * @since 1.0.4 Delegates to centralized HappyAccess_OTP_Handler::get_client_ip().
	 *
	 * @return string Client IP address.
	 */
	private static function get_client_ip() {
		return HappyAccess_OTP_Handler::get_client_ip();
	}
}

// Initialize share link handling.
HappyAccess_OTP_Share::init();

