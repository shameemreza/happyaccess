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

	const LEGACY_TABLES = array( 'magic_links', 'otp_shares' );

	const LEGACY_OPTIONS = array(
		'happyaccess_max_attempts',
		'happyaccess_lockout_duration',
		'happyaccess_token_expiry',
		'happyaccess_cleanup_days',
		'happyaccess_enable_logging',
		'happyaccess_delete_on_uninstall',
		'happyaccess_recaptcha_enabled',
		'happyaccess_recaptcha_site_key',
		'happyaccess_recaptcha_threshold',
		'happyaccess_magic_link_expiry',
		'happyaccess_share_link_expiry',
		'happyaccess_otp_shares_db',
		'happyaccess_enable_email',
		'happyaccess_gdpr_consent_text',
		'happyaccess_version',
	);

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
	 * Runs the migration when the stored DB version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( version_compare( (string) get_option( 'happyaccess_db_version', '0.0.0' ), self::DB_VERSION, '<' ) ) {
			self::migrate();
		}
	}

	/**
	 * Brings the current site to DB_VERSION. Safe to run more than once.
	 *
	 * @return void
	 */
	public static function migrate() {
		$previous   = (string) get_option( 'happyaccess_db_version', '0.0.0' );
		$is_upgrade = ( '0.0.0' !== $previous && version_compare( $previous, self::DB_VERSION, '<' ) ) || false !== get_option( 'happyaccess_version', false );

		self::install();
		$result = self::hash_legacy_codes();
		self::migrate_options( $is_upgrade );

		// Leave the version and the legacy tables alone when anything failed, so the next maybe_upgrade() retries.
		if ( $result['failed'] ) {
			return;
		}
		foreach ( array( 'tokens', 'logs', 'attempts', 'challenges' ) as $name ) {
			if ( ! self::table_exists( $name ) ) {
				return;
			}
		}

		self::drop_legacy_tables();
		update_option( 'happyaccess_db_version', self::DB_VERSION );

		if ( $is_upgrade ) {
			AuditLog::add(
				'plugin_upgraded',
				array(
					'feature' => 'core',
					'user_id' => 0,
					'summary' => sprintf( 'Upgraded from %1$s to %2$s', $previous, self::DB_VERSION ),
					'meta'    => array( 'codes_hashed' => $result['hashed'] ),
				)
			);
		}
	}

	/**
	 * Activation entry point, including network activation.
	 *
	 * @param bool $network_wide Whether the plugin is network activated.
	 * @return void
	 */
	public static function activate( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					self::migrate();
				} finally {
					restore_current_blog();
				}
			}
			return;
		}
		self::migrate();
	}

	/**
	 * Sets up tables on a new site when the plugin is network active.
	 *
	 * @param \WP_Site $site New site.
	 * @return void
	 */
	public static function on_new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( HAPPYACCESS_PLUGIN_BASENAME ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::migrate();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Hashes 1.0.x plain codes that are still usable and removes every plain code.
	 *
	 * @return array{hashed:int,failed:bool} Codes hashed, and whether any write failed.
	 */
	private static function hash_legacy_codes() {
		global $wpdb;
		$table = self::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, no input.
		$rows   = $wpdb->get_results( "SELECT id, otp_code, expires_at, revoked_at, max_uses, use_count FROM {$table} WHERE otp_code IS NOT NULL AND otp_code <> ''", ARRAY_A );
		$hashed = 0;
		$failed = false;

		foreach ( (array) $rows as $row ) {
			$data      = array( 'otp_code' => null );
			$max_uses  = (int) $row['max_uses'];
			$exhausted = $max_uses > 0 && (int) $row['use_count'] >= $max_uses;
			$active    = empty( $row['revoked_at'] ) && ! $exhausted && Clock::from_mysql( $row['expires_at'] ) > Clock::now();
			if ( $active ) {
				$data['code_hash'] = Codes::hash_code( $row['otp_code'] );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			if ( false === $wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) ) ) {
				$failed = true;
			} elseif ( $active ) {
				++$hashed;
			}
		}

		return array(
			'hashed' => $hashed,
			'failed' => $failed,
		);
	}

	/**
	 * Moves 1.0.x options into happyaccess_settings.
	 *
	 * @param bool $is_upgrade Whether this site ran 1.0.x before.
	 * @return void
	 */
	private static function migrate_options( $is_upgrade ) {
		$map = array(
			'happyaccess_max_attempts'        => array( 'security', 'max_attempts' ),
			'happyaccess_lockout_duration'    => array( 'security', 'lockout_duration' ),
			'happyaccess_cleanup_days'        => array( 'privacy', 'retention_days' ),
			'happyaccess_enable_logging'      => array( 'privacy', 'logging' ),
			'happyaccess_delete_on_uninstall' => array( 'privacy', 'delete_on_uninstall' ),
			'happyaccess_recaptcha_enabled'   => array( 'security', 'recaptcha_enabled' ),
			'happyaccess_recaptcha_site_key'  => array( 'security', 'recaptcha_site_key' ),
			'happyaccess_recaptcha_threshold' => array( 'security', 'recaptcha_threshold' ),
		);

		$changes = array();
		foreach ( $map as $option => $path ) {
			$value = get_option( $option, null );
			if ( null !== $value ) {
				$changes[ $path[0] ][ $path[1] ] = $value;
			}
		}

		// 604800 was the untouched 1.0.x default; keep a custom choice only.
		$expiry = get_option( 'happyaccess_token_expiry', null );
		if ( null !== $expiry && 604800 !== (int) $expiry ) {
			$changes['support']['default_duration'] = (int) $expiry;
		}

		if ( $is_upgrade && '' === Settings::get( 'support.consent_given_at', '' ) ) {
			$changes['support']['consent_given_at'] = Clock::mysql();
			$changes['support']['consent_user_id']  = 0;
		}

		if ( ! empty( $changes ) ) {
			Settings::update( $changes );
		}

		$secret = get_option( 'happyaccess_recaptcha_secret_key', null );
		if ( null !== $secret ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Autoload flag is not exposed by the Options API.
			$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'happyaccess_recaptcha_secret_key' ) );
			if ( ! in_array( $autoload, array( 'no', 'off' ), true ) ) {
				delete_option( 'happyaccess_recaptcha_secret_key' );
				add_option( 'happyaccess_recaptcha_secret_key', $secret, '', false );
			}
		}

		foreach ( self::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Drops tables that 1.1.0 doesn't use.
	 *
	 * @return void
	 */
	private static function drop_legacy_tables() {
		global $wpdb;
		foreach ( self::LEGACY_TABLES as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table names.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
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
