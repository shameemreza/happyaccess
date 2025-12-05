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
		$share_hash  = hash_hmac( 'sha256', $share_token . '|' . time(), wp_salt( 'secure_auth' ) );

		// Calculate expiration.
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $expiration_seconds );

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
				'created_at'  => current_time( 'mysql' ),
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

		if ( false === $decoded || strpos( $decoded, ':' ) === false ) {
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
			return new WP_Error( 'not_found', __( 'Share link not found or has been removed.', 'happyaccess' ) );
		}

		// Check if already viewed (for single-view links).
		if ( $share['single_view'] && ! empty( $share['viewed_at'] ) ) {
			return new WP_Error( 'already_viewed', __( 'This link has already been viewed. For security, share links can only be viewed once.', 'happyaccess' ) );
		}

		// Check if expired.
		if ( strtotime( $share['expires_at'] ) < time() ) {
			return new WP_Error( 'expired', __( 'This share link has expired. Please request a new one.', 'happyaccess' ) );
		}

		// Verify token hash.
		$expected_hash = hash_hmac( 'sha256', $share_token . '|' . strtotime( $share['created_at'] ), wp_salt( 'secure_auth' ) );

		if ( ! hash_equals( $share['share_hash'], $expected_hash ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid share link token.', 'happyaccess' ) );
		}

		// Mark as viewed if single-view.
		if ( $share['single_view'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->update(
				$share_table,
				array(
					'viewed_at' => current_time( 'mysql' ),
					'viewed_ip' => self::get_client_ip(),
				),
				array( 'id' => $share_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

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
				current_time( 'mysql' ),
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
			<style>
				/* WordPress Admin Native Colors */
				:root {
					--wp-admin-bg: #f0f0f1;
					--wp-admin-dark: #1d2327;
					--wp-admin-blue: #2271b1;
					--wp-admin-blue-hover: #135e96;
					--wp-admin-green: #00a32a;
					--wp-admin-red: #d63638;
					--wp-admin-border: #c3c4c7;
					--wp-admin-text: #3c434a;
					--wp-admin-text-light: #646970;
				}
				* { box-sizing: border-box; margin: 0; padding: 0; }
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					background: var(--wp-admin-bg);
					min-height: 100vh;
					display: flex;
					align-items: center;
					justify-content: center;
					padding: 20px;
				}
				.container {
					background: #fff;
					border: 1px solid var(--wp-admin-border);
					border-radius: 4px;
					box-shadow: 0 1px 1px rgba(0,0,0,.04);
					max-width: 480px;
					width: 100%;
					overflow: hidden;
				}
				.header {
					background: var(--wp-admin-dark);
					color: #fff;
					padding: 20px 24px;
					text-align: center;
				}
				.header h1 {
					font-size: 18px;
					font-weight: 600;
					margin-bottom: 4px;
				}
				.header .site-name {
					font-size: 13px;
					opacity: 0.85;
				}
				.content {
					padding: 24px;
					text-align: center;
				}
				.otp-label {
					font-size: 11px;
					color: var(--wp-admin-text-light);
					margin-bottom: 12px;
					text-transform: uppercase;
					letter-spacing: 0.5px;
					font-weight: 500;
				}
				.otp-code {
					font-size: 42px;
					font-weight: 600;
					font-family: Consolas, Monaco, monospace;
					letter-spacing: 8px;
					color: var(--wp-admin-dark);
					background: var(--wp-admin-bg);
					padding: 20px 28px;
					border: 1px solid var(--wp-admin-border);
					border-radius: 4px;
					margin-bottom: 20px;
					display: inline-block;
				}
				.button {
					display: inline-block;
					background: var(--wp-admin-blue);
					color: #fff;
					border: 1px solid var(--wp-admin-blue);
					padding: 0 14px;
					height: 32px;
					line-height: 30px;
					border-radius: 3px;
					font-size: 13px;
					font-weight: 400;
					cursor: pointer;
					text-decoration: none;
					white-space: nowrap;
				}
				.button:hover, .button:focus {
					background: var(--wp-admin-blue-hover);
					border-color: var(--wp-admin-blue-hover);
					color: #fff;
				}
				.button.copied {
					background: var(--wp-admin-green);
					border-color: var(--wp-admin-green);
				}
				.info {
					margin-top: 20px;
					padding-top: 20px;
					border-top: 1px solid var(--wp-admin-border);
					text-align: left;
				}
				.info p {
					font-size: 13px;
					color: var(--wp-admin-text-light);
					margin-bottom: 6px;
					line-height: 1.5;
				}
				.notice-warning {
					background: #fcf9e8;
					border-left: 4px solid #dba617;
					padding: 12px;
					margin-top: 16px;
					text-align: left;
				}
				.notice-warning p {
					color: var(--wp-admin-text);
					font-size: 13px;
					margin: 0;
				}
				.footer {
					background: var(--wp-admin-bg);
					border-top: 1px solid var(--wp-admin-border);
					padding: 12px 24px;
					text-align: center;
				}
				.footer a {
					color: var(--wp-admin-blue);
					text-decoration: none;
					font-size: 13px;
				}
				.footer a:hover {
					color: var(--wp-admin-blue-hover);
					text-decoration: underline;
				}
			</style>
		</head>
		<body>
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
			
			<script>
			function copyCode() {
				var code = document.getElementById('otp-code').innerText.trim();
				var btn = document.getElementById('copy-btn');
				var originalText = btn.innerText;
				
				function showCopied() {
					btn.classList.add('copied');
					btn.innerText = '<?php echo esc_js( __( 'Copied!', 'happyaccess' ) ); ?>';
					setTimeout(function() {
						btn.classList.remove('copied');
						btn.innerText = originalText;
					}, 2000);
				}
				
				if (navigator.clipboard && window.isSecureContext) {
					navigator.clipboard.writeText(code).then(showCopied);
				} else {
					var temp = document.createElement('textarea');
					temp.value = code;
					document.body.appendChild(temp);
					temp.select();
					document.execCommand('copy');
					document.body.removeChild(temp);
					showCopied();
				}
			}
			</script>
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
			<style>
				:root {
					--wp-admin-bg: #f0f0f1;
					--wp-admin-dark: #1d2327;
					--wp-admin-blue: #2271b1;
					--wp-admin-blue-hover: #135e96;
					--wp-admin-border: #c3c4c7;
					--wp-admin-text: #3c434a;
					--wp-admin-text-light: #646970;
				}
				* { box-sizing: border-box; margin: 0; padding: 0; }
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					background: var(--wp-admin-bg);
					min-height: 100vh;
					display: flex;
					align-items: center;
					justify-content: center;
					padding: 20px;
				}
				.container {
					background: #fff;
					border: 1px solid var(--wp-admin-border);
					border-radius: 4px;
					box-shadow: 0 1px 1px rgba(0,0,0,.04);
					max-width: 420px;
					width: 100%;
					padding: 32px 24px;
					text-align: center;
				}
				.dashicons {
					font-size: 48px;
					width: 48px;
					height: 48px;
					color: #dba617;
					margin-bottom: 16px;
				}
				h1 {
					font-size: 20px;
					font-weight: 600;
					color: var(--wp-admin-dark);
					margin-bottom: 12px;
				}
				p {
					color: var(--wp-admin-text-light);
					line-height: 1.5;
					margin-bottom: 20px;
					font-size: 14px;
				}
				.button {
					display: inline-block;
					background: var(--wp-admin-blue);
					color: #fff;
					border: 1px solid var(--wp-admin-blue);
					padding: 0 14px;
					height: 32px;
					line-height: 30px;
					border-radius: 3px;
					font-size: 13px;
					text-decoration: none;
				}
				.button:hover {
					background: var(--wp-admin-blue-hover);
					border-color: var(--wp-admin-blue-hover);
					color: #fff;
				}
			</style>
			<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone error page outside normal WP template loading. ?>
			<link rel="stylesheet" href="<?php echo esc_url( includes_url( 'css/dashicons.min.css' ) ); ?>" />
		</head>
		<body>
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
	 * @since 1.0.3
	 */
	public static function maybe_create_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'happyaccess_otp_shares';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking table existence.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ( $table_exists ) {
			return;
		}

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
				current_time( 'mysql' )
			)
		);

		return $deleted ? $deleted : 0;
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

// Initialize share link handling.
HappyAccess_OTP_Share::init();

