<?php
/**
 * Magic Link handler for HappyAccess plugin.
 *
 * Provides secure, time-limited, one-click authentication links.
 *
 * @package HappyAccess
 * @since   1.0.3
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Magic Link handler class.
 *
 * @since 1.0.3
 */
class HappyAccess_Magic_Link {

	/**
	 * Query parameter name for magic link token.
	 *
	 * @var string
	 */
	const QUERY_PARAM = 'ha_m'; // Short query param for cleaner URLs.

	/**
	 * Available expiration options in seconds.
	 *
	 * @var array
	 */
	const EXPIRATION_OPTIONS = array(
		60   => '1 minute',
		120  => '2 minutes',
		300  => '5 minutes',
		600  => '10 minutes',
	);

	/**
	 * Initialize magic link hooks.
	 *
	 * @since 1.0.3
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_magic_link' ), 1 );
	}

	/**
	 * Generate a magic link for a token.
	 *
	 * @since 1.0.3
	 *
	 * @param int $token_id         The parent token ID.
	 * @param int $expiration_seconds Magic link expiration in seconds.
	 * @return array|WP_Error Array with 'url' and 'expires_at' on success, WP_Error on failure.
	 */
	public static function generate( $token_id, $expiration_seconds = 300 ) {
		global $wpdb;

		$token_id = absint( $token_id );
		if ( ! $token_id ) {
			return new WP_Error( 'invalid_token_id', __( 'Invalid token ID.', 'happyaccess' ) );
		}

		// Validate expiration.
		$expiration_seconds = absint( $expiration_seconds );
		if ( $expiration_seconds < 60 || $expiration_seconds > 600 ) {
			$expiration_seconds = 300; // Default to 5 minutes.
		}

		// Get the parent token to verify it exists and is valid.
		$tokens_table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$parent_token = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped.
				"SELECT * FROM `$tokens_table` WHERE id = %d AND expires_at > %s AND revoked_at IS NULL",
				$token_id,
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		if ( ! $parent_token ) {
			return new WP_Error( 'invalid_parent_token', __( 'Parent token not found or has expired.', 'happyaccess' ) );
		}

		// SECURITY: Invalidate any existing magic links for this token.
		// Only one magic link should be active per token at any time.
		self::invalidate_previous_links( $token_id );

		// Generate a cryptographically secure magic token (shorter but still secure).
		$magic_token = bin2hex( random_bytes( 16 ) ); // 32 character hex string - 128 bits of entropy.
		$magic_hash  = hash_hmac( 'sha256', $magic_token . '|' . time(), wp_salt( 'secure_auth' ) );

		// Calculate expiration.
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $expiration_seconds );

		// Store the magic link in database.
		$magic_table = esc_sql( $wpdb->prefix . 'happyaccess_magic_links' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$result = $wpdb->insert(
			$magic_table,
			array(
				'token_id'     => $token_id,
				'magic_hash'   => $magic_hash,
				'expires_at'   => $expires_at,
				'created_at'   => current_time( 'mysql' ),
				'created_by'   => get_current_user_id(),
				'ip_address'   => self::get_client_ip(),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to create magic link.', 'happyaccess' ) );
		}

		$magic_id = $wpdb->insert_id;

		// Build the magic link URL.
		// Format: magic_token:magic_id (allows lookup without exposing token directly).
		$magic_param = base64_encode( $magic_token . ':' . $magic_id );
		$magic_url   = add_query_arg(
			self::QUERY_PARAM,
			rawurlencode( $magic_param ),
			wp_login_url()
		);

		// Log magic link creation.
		HappyAccess_Logger::log( 'magic_link_created', array(
			'token_id'      => $token_id,
			'magic_id'      => $magic_id,
			'expires_in'    => HappyAccess_Token_Manager::format_duration( $expiration_seconds, false ),
			'otp'           => substr( $parent_token['otp_code'], 0, 2 ) . '****',
			'role'          => $parent_token['role'],
		) );

		return array(
			'url'        => $magic_url,
			'expires_at' => $expires_at,
			'magic_id'   => $magic_id,
		);
	}

	/**
	 * Handle incoming magic link authentication.
	 *
	 * @since 1.0.3
	 */
	public static function handle_magic_link() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Magic link authentication.
		if ( ! isset( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Magic link authentication.
		$magic_param = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for magic link token encoding.
		$decoded     = base64_decode( $magic_param, true );

		if ( false === $decoded || strpos( $decoded, ':' ) === false ) {
			self::redirect_with_error( 'invalid_magic_link' );
			return;
		}

		// Parse magic token and ID.
		$parts       = explode( ':', $decoded, 2 );
		$magic_token = $parts[0];
		$magic_id    = absint( $parts[1] ?? 0 );

		if ( empty( $magic_token ) || ! $magic_id ) {
			self::redirect_with_error( 'invalid_magic_link' );
			return;
		}

		// Verify the magic link.
		$result = self::verify( $magic_token, $magic_id );

		if ( is_wp_error( $result ) ) {
			HappyAccess_Logger::log( 'magic_link_failed', array(
				'magic_id' => $magic_id,
				'error'    => $result->get_error_message(),
				'ip'       => self::get_client_ip(),
			) );
			self::redirect_with_error( $result->get_error_code() );
			return;
		}

		// Magic link is valid - authenticate the user.
		$token_data = $result['token_data'];

		// Create or get temporary user.
		$temp_user_handler = new HappyAccess_Temp_User();
		$temp_user         = $temp_user_handler->create_or_get( $token_data['id'] );

		if ( is_wp_error( $temp_user ) ) {
			HappyAccess_Logger::log( 'magic_link_user_error', array(
				'magic_id' => $magic_id,
				'error'    => $temp_user->get_error_message(),
			) );
			self::redirect_with_error( 'user_creation_failed' );
			return;
		}

		// Update use count on parent token.
		self::increment_token_use_count( $token_data['id'] );

		// Log successful magic link login.
		HappyAccess_Logger::log( 'magic_link_success', array(
			'magic_id'      => $magic_id,
			'token_id'      => $token_data['id'],
			'user_id'       => $temp_user->ID,
			'username'      => $temp_user->user_login,
			'role'          => $token_data['role'],
			'otp'           => substr( $token_data['otp_code'], 0, 2 ) . '****',
			'ip'            => self::get_client_ip(),
		) );

		// Store session start time.
		update_user_meta( $temp_user->ID, 'happyaccess_session_start', time() );

		// Check if parent token is single-use.
		if ( 1 === (int) $token_data['max_uses'] ) {
			update_user_meta( $temp_user->ID, 'happyaccess_single_use_revoked', true );
			// Pass true to keep_user so the temp user remains active for this session.
			HappyAccess_Token_Manager::revoke_token( $token_data['id'], true );
			HappyAccess_Logger::log( 'token_auto_revoked_single_use', array(
				'token_id' => $token_data['id'],
				'via'      => 'magic_link',
			) );
		}

		// Log the user in.
		wp_set_current_user( $temp_user->ID );
		wp_set_auth_cookie( $temp_user->ID, false );

		// Redirect to admin.
		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Verify a magic link token.
	 *
	 * @since 1.0.3
	 *
	 * @param string $magic_token The magic token from URL.
	 * @param int    $magic_id    The magic link ID.
	 * @return array|WP_Error Token data on success, WP_Error on failure.
	 */
	private static function verify( $magic_token, $magic_id ) {
		global $wpdb;

		// Rate limiting check.
		$rate_limiter = new HappyAccess_Rate_Limiter();
		$ip           = self::get_client_ip();

		$rate_check = $rate_limiter->check_rate_limit( 'magic_link', $ip );
		if ( is_wp_error( $rate_check ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many attempts. Please try again later.', 'happyaccess' ) );
		}

		// Get magic link from database.
		$magic_table = esc_sql( $wpdb->prefix . 'happyaccess_magic_links' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$magic_link = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped.
				"SELECT * FROM `$magic_table` WHERE id = %d",
				$magic_id
			),
			ARRAY_A
		);

		if ( ! $magic_link ) {
			$rate_limiter->log_attempt( 'magic_link', $ip, 'magic_link_not_found' );
			return new WP_Error( 'not_found', __( 'Magic link not found.', 'happyaccess' ) );
		}

		// Check if already used.
		if ( ! empty( $magic_link['used_at'] ) ) {
			return new WP_Error( 'already_used', __( 'This magic link has already been used.', 'happyaccess' ) );
		}

		// Check if expired.
		if ( strtotime( $magic_link['expires_at'] ) < time() ) {
			return new WP_Error( 'expired', __( 'This magic link has expired.', 'happyaccess' ) );
		}

		// Verify token hash.
		$expected_hash = hash_hmac( 'sha256', $magic_token . '|' . strtotime( $magic_link['created_at'] ), wp_salt( 'secure_auth' ) );

		if ( ! hash_equals( $magic_link['magic_hash'], $expected_hash ) ) {
			$rate_limiter->log_attempt( 'magic_link', $ip, 'magic_link_invalid' );
			return new WP_Error( 'invalid_token', __( 'Invalid magic link token.', 'happyaccess' ) );
		}

		// Get parent token.
		$tokens_table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$parent_token = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped.
				"SELECT * FROM `$tokens_table` WHERE id = %d AND expires_at > %s AND revoked_at IS NULL",
				$magic_link['token_id'],
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		if ( ! $parent_token ) {
			return new WP_Error( 'parent_expired', __( 'The associated access token has expired or been revoked.', 'happyaccess' ) );
		}

		// Check IP restrictions on parent token.
		if ( ! empty( $parent_token['ip_restrictions'] ) ) {
			$allowed_ips = array_map( 'trim', explode( ',', $parent_token['ip_restrictions'] ) );
			if ( ! in_array( $ip, $allowed_ips, true ) ) {
				return new WP_Error( 'ip_restricted', __( 'Access denied from your IP address.', 'happyaccess' ) );
			}
		}

		// Mark magic link as used.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			$magic_table,
			array(
				'used_at'    => current_time( 'mysql' ),
				'used_ip'    => $ip,
			),
			array( 'id' => $magic_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Clear rate limiting.
		$rate_limiter->clear_attempts( 'magic_link', $ip );

		return array(
			'magic_link' => $magic_link,
			'token_data' => $parent_token,
		);
	}

	/**
	 * Increment token use count.
	 *
	 * @since 1.0.3
	 *
	 * @param int $token_id Token ID.
	 */
	private static function increment_token_use_count( $token_id ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped.
				"UPDATE `$table` SET use_count = use_count + 1 WHERE id = %d",
				$token_id
			)
		);
	}

	/**
	 * Redirect to login with error message.
	 *
	 * @since 1.0.3
	 *
	 * @param string $error_code Error code.
	 */
	private static function redirect_with_error( $error_code ) {
		$error_messages = array(
			'invalid_magic_link'    => __( 'Invalid magic link. Please request a new one.', 'happyaccess' ),
			'not_found'             => __( 'Magic link not found. It may have been deleted.', 'happyaccess' ),
			'already_used'          => __( 'This magic link has already been used. Magic links can only be used once.', 'happyaccess' ),
			'expired'               => __( 'This magic link has expired. Please request a new one.', 'happyaccess' ),
			'invalid_token'         => __( 'Invalid magic link token.', 'happyaccess' ),
			'parent_expired'        => __( 'The associated access token has expired or been revoked.', 'happyaccess' ),
			'ip_restricted'         => __( 'Access denied. Your IP address is not allowed.', 'happyaccess' ),
			'rate_limited'          => __( 'Too many attempts. Please try again later.', 'happyaccess' ),
			'user_creation_failed'  => __( 'Failed to create temporary user. Please try again.', 'happyaccess' ),
		);

		$message = isset( $error_messages[ $error_code ] ) ? $error_messages[ $error_code ] : $error_messages['invalid_magic_link'];

		// Store error in transient for display.
		set_transient( 'happyaccess_magic_link_error_' . self::get_client_ip(), $message, 60 );

		wp_safe_redirect( add_query_arg( 'happyaccess_error', '1', wp_login_url() ) );
		exit;
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
			// Take the first IP if multiple.
			$ip = explode( ',', $ip )[0];
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return filter_var( trim( $ip ), FILTER_VALIDATE_IP ) ? trim( $ip ) : '0.0.0.0';
	}

	/**
	 * Create magic links database table.
	 *
	 * @since 1.0.3
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'happyaccess_magic_links';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			token_id bigint(20) UNSIGNED NOT NULL,
			magic_hash varchar(64) NOT NULL,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			created_by bigint(20) UNSIGNED DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			used_at datetime DEFAULT NULL,
			used_ip varchar(45) DEFAULT NULL,
			PRIMARY KEY (id),
			KEY token_id (token_id),
			KEY expires_at (expires_at),
			KEY magic_hash (magic_hash)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Invalidate all previous magic links for a token.
	 *
	 * SECURITY: Only one magic link should be active per token.
	 * When a new one is generated, previous ones are marked as used.
	 *
	 * @since 1.0.3
	 *
	 * @param int $token_id The parent token ID.
	 * @return int Number of links invalidated.
	 */
	private static function invalidate_previous_links( $token_id ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'happyaccess_magic_links' );

		// Mark all unused magic links for this token as invalidated.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$invalidated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped.
				"UPDATE `$table` 
				SET used_at = %s, used_ip = %s 
				WHERE token_id = %d AND used_at IS NULL",
				current_time( 'mysql' ),
				'invalidated_by_new_link',
				$token_id
			)
		);

		if ( $invalidated > 0 ) {
			HappyAccess_Logger::log( 'magic_links_invalidated', array(
				'token_id' => $token_id,
				'count'    => $invalidated,
				'reason'   => 'new_link_generated',
			) );
		}

		return $invalidated ? $invalidated : 0;
	}

	/**
	 * Clean up expired magic links.
	 *
	 * @since 1.0.3
	 *
	 * @return int Number of links cleaned up.
	 */
	public static function cleanup_expired() {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'happyaccess_magic_links' );

		// Delete magic links expired more than 1 hour ago.
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
	 * Display magic link error on login page.
	 *
	 * @since 1.0.3
	 *
	 * @param string $message Existing message.
	 * @return string Modified message.
	 */
	public static function display_login_error( $message ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking for display.
		if ( isset( $_GET['happyaccess_error'] ) ) {
			$error = get_transient( 'happyaccess_magic_link_error_' . self::get_client_ip() );
			if ( $error ) {
				delete_transient( 'happyaccess_magic_link_error_' . self::get_client_ip() );
				$message .= '<div id="login_error"><strong>' . esc_html__( 'Magic Link Error:', 'happyaccess' ) . '</strong> ' . esc_html( $error ) . '</div>';
			}
		}
		return $message;
	}
}

// Initialize magic link handling.
HappyAccess_Magic_Link::init();

// Add login error filter.
add_filter( 'login_message', array( 'HappyAccess_Magic_Link', 'display_login_error' ) );

