<?php
/**
 * Activator class for HappyAccess plugin.
 *
 * @package HappyAccess
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activation handler.
 *
 * @since 1.0.0
 */
class HappyAccess_Activator {

	/**
	 * Activate the plugin.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		self::create_tables();
		self::create_options();
		self::schedule_cron_events();
		
		// Set activation notice.
		set_transient( 'happyaccess_activation_notice', true, 5 );
		
		// Clear rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Create database tables.
	 *
	 * @since 1.0.0
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Tokens table.
		$table_tokens = $wpdb->prefix . 'happyaccess_tokens';
		$sql_tokens   = "CREATE TABLE $table_tokens (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			token_hash VARCHAR(64) NOT NULL,
			otp_code VARCHAR(10) NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			temp_username VARCHAR(60) NULL,
			role VARCHAR(50) DEFAULT 'administrator',
			created_by BIGINT(20) UNSIGNED NOT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME NOT NULL,
			used_at DATETIME NULL,
			revoked_at DATETIME NULL,
			max_uses INT DEFAULT 1,
			use_count INT DEFAULT 0,
			ip_restrictions TEXT NULL,
			metadata LONGTEXT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id),
			KEY expires_at (expires_at),
			KEY otp_code (otp_code)
		) $charset_collate;";

		// Audit logs table.
		$table_logs = $wpdb->prefix . 'happyaccess_logs';
		$sql_logs   = "CREATE TABLE $table_logs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			token_id BIGINT(20) UNSIGNED NOT NULL,
			event_type VARCHAR(50) NOT NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			ip_address VARCHAR(45) NULL,
			user_agent TEXT NULL,
			metadata LONGTEXT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY token_id (token_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) $charset_collate;";

		// Failed attempts table.
		$table_attempts = $wpdb->prefix . 'happyaccess_attempts';
		$sql_attempts   = "CREATE TABLE $table_attempts (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			identifier VARCHAR(100) NOT NULL,
			attempt_type VARCHAR(20) NOT NULL,
			ip_address VARCHAR(45) NOT NULL,
			attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY identifier_ip (identifier, ip_address),
			KEY attempted_at (attempted_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_tokens );
		dbDelta( $sql_logs );
		dbDelta( $sql_attempts );

		// Store database version.
		update_option( 'happyaccess_db_version', '1.0.0' );
	}

	/**
	 * Create default options.
	 *
	 * @since 1.0.0
	 */
	private static function create_options() {
		$default_options = array(
			'max_attempts'      => 5,
			'lockout_duration'  => 900, // 15 minutes.
			'token_expiry'      => 86400, // 24 hours.
			'cleanup_days'      => 30,
			'enable_logging'    => true,
			'enable_email'      => false,
			'gdpr_consent_text' => __( 'I understand that I am granting admin access to a third party and this must be disclosed in my Terms & Conditions as per GDPR requirements.', 'happyaccess' ),
		);

		foreach ( $default_options as $key => $value ) {
			add_option( 'happyaccess_' . $key, $value );
		}

		// Store activation time.
		add_option( 'happyaccess_activated', time() );
	}

	/**
	 * Schedule cron events.
	 *
	 * @since 1.0.0
	 */
	private static function schedule_cron_events() {
		if ( ! wp_next_scheduled( 'happyaccess_cleanup_expired' ) ) {
			wp_schedule_event( time(), 'hourly', 'happyaccess_cleanup_expired' );
		}

		if ( ! wp_next_scheduled( 'happyaccess_cleanup_attempts' ) ) {
			wp_schedule_event( time(), 'daily', 'happyaccess_cleanup_attempts' );
		}
	}
}
