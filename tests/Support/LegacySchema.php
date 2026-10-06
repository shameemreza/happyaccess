<?php
/**
 * 1.0.6 tables, used to test the upgrade.
 *
 * @package HappyAccess
 */

class HappyAccess_Test_Legacy_Schema {

	public static function drop_all() {
		global $wpdb;
		foreach ( array( 'tokens', 'logs', 'attempts', 'challenges', 'magic_links', 'otp_shares' ) as $name ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}happyaccess_{$name}" );
		}
	}

	public static function create() {
		global $wpdb;
		$p = $wpdb->prefix;
		$c = $wpdb->get_charset_collate();

		$wpdb->query(
			"CREATE TABLE {$p}happyaccess_tokens (
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
				max_uses INT DEFAULT 0,
				use_count INT DEFAULT 0,
				ip_restrictions TEXT NULL,
				metadata LONGTEXT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY token_hash (token_hash),
				KEY user_id (user_id),
				KEY expires_at (expires_at),
				KEY otp_code (otp_code)
			) {$c}"
		);
		$wpdb->query(
			"CREATE TABLE {$p}happyaccess_logs (
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
			) {$c}"
		);
		$wpdb->query(
			"CREATE TABLE {$p}happyaccess_attempts (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				identifier VARCHAR(100) NOT NULL,
				attempt_type VARCHAR(20) NOT NULL,
				ip_address VARCHAR(45) NOT NULL,
				attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY identifier_ip (identifier, ip_address),
				KEY attempted_at (attempted_at)
			) {$c}"
		);
		$wpdb->query(
			"CREATE TABLE {$p}happyaccess_magic_links (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				token_id BIGINT(20) UNSIGNED NOT NULL,
				magic_hash VARCHAR(64) NOT NULL,
				expires_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id)
			) {$c}"
		);
		$wpdb->query(
			"CREATE TABLE {$p}happyaccess_otp_shares (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				token_id BIGINT(20) UNSIGNED NOT NULL,
				otp_code VARCHAR(10) NOT NULL,
				share_hash VARCHAR(64) NOT NULL,
				expires_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id)
			) {$c}"
		);
	}
}
