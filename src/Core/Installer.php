<?php
/**
 * Tables, upgrades and multisite setup.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the HappyAccess tables.
 */
final class Installer {

	const DB_VERSION = '1.1.0';

	/**
	 * Full table name.
	 *
	 * @param string $name One of tokens, logs, attempts, challenges.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'happyaccess_' . $name;
	}

	/**
	 * Whether a HappyAccess table exists. Uses SHOW COLUMNS so it also sees
	 * temporary tables in tests.
	 *
	 * @param string $name Short table name.
	 * @return bool
	 */
	public static function table_exists( $name ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$columns  = $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix.
		$wpdb->suppress_errors( $suppress );
		return ! empty( $columns );
	}

	/**
	 * Creates or updates all tables and the site key.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::schema( $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
		Secrets::key();
	}

	/**
	 * CREATE TABLE statements in dbDelta format. Columns that existed in
	 * 1.0.x keep their old definitions so dbDelta never changes them.
	 *
	 * @param string $collate Charset and collation clause.
	 * @return array
	 */
	private static function schema( $collate ) {
		$tokens     = self::table( 'tokens' );
		$logs       = self::table( 'logs' );
		$attempts   = self::table( 'attempts' );
		$challenges = self::table( 'challenges' );

		return array(
			"CREATE TABLE {$tokens} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash varchar(64) NOT NULL,
				otp_code varchar(10) NULL,
				code_hash varchar(64) NULL,
				link_hash varchar(64) NULL,
				label varchar(190) NOT NULL DEFAULT '',
				recipient_email varchar(190) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NULL,
				temp_username varchar(60) NULL,
				role varchar(50) DEFAULT 'administrator',
				protection varchar(30) NOT NULL DEFAULT 'protected',
				restrictions longtext NULL,
				redirect_to varchar(255) NOT NULL DEFAULT '',
				notify varchar(10) NOT NULL DEFAULT 'first',
				created_by bigint(20) unsigned NOT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				expires_at datetime NOT NULL,
				used_at datetime NULL,
				revoked_at datetime NULL,
				suspended_at datetime NULL,
				last_login_at datetime NULL,
				login_count int(11) NOT NULL DEFAULT 0,
				max_uses int(11) DEFAULT 0,
				use_count int(11) DEFAULT 0,
				ip_restrictions text NULL,
				metadata longtext NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY user_id (user_id),
				KEY expires_at (expires_at),
				KEY code_hash (code_hash),
				KEY link_hash (link_hash)
			) {$collate};",
			"CREATE TABLE {$logs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_id bigint(20) unsigned NOT NULL DEFAULT 0,
				feature varchar(20) NOT NULL DEFAULT 'support',
				event_type varchar(50) NOT NULL,
				user_id bigint(20) unsigned NULL,
				ip_address varchar(45) NULL,
				user_agent text NULL,
				summary text NULL,
				metadata longtext NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY token_id (token_id),
				KEY event_type (event_type),
				KEY created_at (created_at),
				KEY feature (feature)
			) {$collate};",
			"CREATE TABLE {$attempts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				identifier varchar(100) NOT NULL,
				attempt_type varchar(20) NOT NULL,
				scope varchar(20) NOT NULL DEFAULT 'ip',
				ip_address varchar(45) NOT NULL,
				attempted_at datetime DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY identifier_ip (identifier,ip_address),
				KEY attempted_at (attempted_at),
				KEY scope_identifier (scope,identifier)
			) {$collate};",
			"CREATE TABLE {$challenges} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				purpose varchar(20) NOT NULL,
				code_hash varchar(64) NOT NULL DEFAULT '',
				link_hash varchar(64) NOT NULL DEFAULT '',
				request_key_hash varchar(64) NOT NULL DEFAULT '',
				attempts int(11) NOT NULL DEFAULT 0,
				ip varchar(45) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				used_at datetime NULL,
				PRIMARY KEY  (id),
				KEY user_purpose (user_id,purpose),
				KEY link_hash (link_hash),
				KEY expires_at (expires_at)
			) {$collate};",
		);
	}
}
