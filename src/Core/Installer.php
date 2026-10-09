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

	const LOCK_OPTION = 'happyaccess_migration_lock';

	const FAILED_TRANSIENT = 'happyaccess_migration_failed';

	const LOCK_TTL = 300;

	/**
	 * Seconds a lock may sit in the future, from clock drift between servers,
	 * and still count as held.
	 */
	const LOCK_SKEW = 60;

	const LEGACY_CODE_MAX_AGE = 7 * DAY_IN_SECONDS;

	/**
	 * A site whose stored DB version is below this one still has 1.0.x data
	 * to carry. Later DB_VERSION bumps must not carry it again.
	 */
	const LEGACY_BELOW = '1.1.0';

	const LEGACY_CRON_HOOKS = array( 'happyaccess_cleanup_expired', 'happyaccess_cleanup_attempts' );

	const NETWORK_HOOK = 'happyaccess_network_upgrade';

	/**
	 * Network option set to DB_VERSION once every site of the network is there.
	 */
	const NETWORK_OPTION = 'happyaccess_network_db_version';

	const NETWORK_BATCH = 20;

	/**
	 * Columns 1.1.0 needs that 1.0.6 didn't have, by short table name. A table
	 * with an empty list only has to exist.
	 */
	const REQUIRED_COLUMNS = array(
		'tokens'     => array( 'code_hash', 'link_hash', 'label', 'recipient_email', 'protection', 'restrictions', 'redirect_to', 'notify', 'suspended_at', 'last_login_at', 'login_count' ),
		'logs'       => array( 'feature', 'summary' ),
		'attempts'   => array( 'scope' ),
		'challenges' => array(),
	);

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
	 * The plugin version this site last ran, autoloaded. 1.0.x used the same
	 * name, and the migration reads and deletes that value, so this one is
	 * written only once the migration is done.
	 */
	const VERSION_OPTION = 'happyaccess_version';

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
		// Scheduled first: a site backing off after a failed run must not stop the network loop from starting.
		self::maybe_schedule_network_upgrade();
		if ( self::migration_failed() ) {
			return;
		}
		if ( version_compare( (string) get_option( 'happyaccess_db_version', '0.0.0' ), self::DB_VERSION, '<' ) ) {
			self::migrate();
		}
	}

	/**
	 * Notes a new plugin version and asks for a rewrite flush, since a new
	 * version may change the rules HappyAccess adds even without a database
	 * change. Waits until the migration has run, because the migration
	 * tells a 1.0.x site by the old value of this option.
	 *
	 * @return void
	 */
	public static function note_version() {
		if ( self::DB_VERSION !== get_option( 'happyaccess_db_version' ) ) {
			return;
		}
		if ( HAPPYACCESS_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}
		Internal::run(
			static function () {
				update_option( self::VERSION_OPTION, HAPPYACCESS_VERSION, true );
			}
		);
		Features::request_rewrite_flush();
	}

	/**
	 * Whether the last migration of this site failed less than 15 minutes ago.
	 * A failed run backs off so every request doesn't retry it.
	 *
	 * @return bool
	 */
	private static function migration_failed() {
		// Transient calls can add or delete an option, so they run inside the bypass.
		$failed = Internal::run(
			static function () {
				return get_transient( self::FAILED_TRANSIENT );
			}
		);
		return false !== $failed;
	}

	/**
	 * On the main site of a network where the plugin is network active,
	 * schedules the loop that brings every other site to DB_VERSION. Sites
	 * without traffic would otherwise never run their own migration.
	 *
	 * @return void
	 */
	private static function maybe_schedule_network_upgrade() {
		if ( ! is_multisite() || ! is_main_site() || doing_action( self::NETWORK_HOOK ) ) {
			return;
		}
		if ( self::DB_VERSION === get_site_option( self::NETWORK_OPTION ) || ! self::network_active() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::NETWORK_HOOK ) ) {
			wp_schedule_single_event( time(), self::NETWORK_HOOK );
		}
	}

	/**
	 * The network upgrade event. Migrates up to NETWORK_BATCH sites that are
	 * behind, then schedules itself again while any site is still behind. A
	 * run that moved no site forward waits out the failure backoff first.
	 *
	 * @return void
	 */
	public static function network_upgrade() {
		if ( ! is_multisite() || ! self::network_active() ) {
			return;
		}

		$migrated = 0;
		$moved    = 0;
		$behind   = 0;
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				if ( self::DB_VERSION !== get_option( 'happyaccess_db_version' ) ) {
					if ( self::migration_failed() ) {
						// Backing off. It stays behind, and it doesn't take a slot from a site that can move.
						++$behind;
						continue;
					}
					if ( $migrated < self::NETWORK_BATCH ) {
						++$migrated;
						self::maybe_upgrade();
					}
					if ( self::DB_VERSION === get_option( 'happyaccess_db_version' ) ) {
						++$moved;
					} else {
						++$behind;
					}
				}
			} finally {
				restore_current_blog();
			}
		}

		if ( $behind > 0 ) {
			wp_schedule_single_event( time() + ( $moved > 0 ? 0 : 15 * MINUTE_IN_SECONDS ), self::NETWORK_HOOK );
			return;
		}
		update_site_option( self::NETWORK_OPTION, self::DB_VERSION );
	}

	/**
	 * Whether the plugin is network active.
	 *
	 * @return bool
	 */
	private static function network_active() {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active_for_network( HAPPYACCESS_PLUGIN_BASENAME );
	}

	/**
	 * What this request wrote into the lock row, or an empty string when it
	 * holds no lock. Release removes a row only when it still has this value.
	 *
	 * @var string
	 */
	private static $lock_value = '';

	/**
	 * Brings the current site to DB_VERSION. Safe to run more than once. Only
	 * one request runs it at a time; the others return without doing anything.
	 *
	 * @return void
	 */
	public static function migrate() {
		if ( ! self::acquire_lock() ) {
			return;
		}
		try {
			$failure = Internal::run(
				static function () {
					return self::run_migration();
				}
			);
			Internal::run(
				static function () use ( $failure ) {
					if ( '' === $failure ) {
						delete_transient( self::FAILED_TRANSIENT );
					} else {
						set_transient( self::FAILED_TRANSIENT, $failure, 15 * MINUTE_IN_SECONDS );
					}
				}
			);
		} finally {
			self::release_lock();
		}
	}

	/**
	 * The migration steps. Leaves the version and the legacy tables alone when
	 * anything failed, so a later maybe_upgrade() retries.
	 *
	 * @return string Empty on success, otherwise why it stopped.
	 */
	private static function run_migration() {
		$previous   = (string) get_option( 'happyaccess_db_version', '0.0.0' );
		$is_upgrade = ( '0.0.0' !== $previous && version_compare( $previous, self::DB_VERSION, '<' ) ) || false !== get_option( 'happyaccess_version', false );

		self::install();

		// The 1.0.6 cleanup events have no callback anymore.
		foreach ( self::LEGACY_CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// dbDelta reports nothing when an ALTER fails, so look at the tables before any data step relies on the new columns.
		$missing = self::missing_schema();
		if ( '' !== $missing ) {
			return $missing;
		}

		$result  = array(
			'hashed' => 0,
			'failed' => false,
		);
		$options = array(
			'failed'  => false,
			'changed' => array(),
		);
		// The 1.0.x flags and options stay behind, so carrying them on a later bump would undo what the merchant changed since.
		if ( version_compare( $previous, self::LEGACY_BELOW, '<' ) ) {
			$result = self::hash_legacy_codes();
			if ( self::carry_legacy_grant_state() ) {
				$result['failed'] = true;
			}
			// After the carry, so a single-use pass it just put back in time is not closed here.
			if ( self::close_legacy_expired() ) {
				$result['failed'] = true;
			}
			$options = self::migrate_options( $is_upgrade );
			if ( $options['failed'] ) {
				$result['failed'] = true;
			}
		}
		self::backfill_blog_ids();

		if ( $result['failed'] ) {
			return 'write_failed';
		}

		// A table that can't be dropped is harmless once its plain codes are gone, so only a failed blanking stops the upgrade.
		if ( ! self::drop_legacy_tables() && ! self::blank_legacy_codes() ) {
			return 'legacy_codes_remain';
		}
		update_option( 'happyaccess_db_version', self::DB_VERSION );
		// A new version may change the rewrite rules HappyAccess adds.
		Features::request_rewrite_flush();

		if ( $is_upgrade ) {
			AuditLog::add(
				'plugin_upgraded',
				array(
					'feature' => 'core',
					'user_id' => 0,
					'summary' => sprintf( 'Upgraded from %1$s to %2$s', $previous, self::DB_VERSION ),
					'meta'    => array_merge( array( 'codes_hashed' => $result['hashed'] ), $options['changed'] ),
				)
			);
		}

		return '';
	}

	/**
	 * The first table or column from REQUIRED_COLUMNS that doesn't exist.
	 *
	 * @return string Empty when the schema is complete, otherwise "missing_table:x" or "missing_column:x.y".
	 */
	private static function missing_schema() {
		global $wpdb;
		foreach ( self::REQUIRED_COLUMNS as $name => $columns ) {
			$suppress = $wpdb->suppress_errors( true );
			$found    = $wpdb->get_col( 'SHOW COLUMNS FROM ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix.
			$wpdb->suppress_errors( $suppress );
			if ( empty( $found ) ) {
				return 'missing_table:' . $name;
			}
			foreach ( $columns as $column ) {
				if ( ! in_array( $column, $found, true ) ) {
					return 'missing_column:' . $name . '.' . $column;
				}
			}
		}
		return '';
	}

	/**
	 * Takes the migration lock: an options row holding the time it was taken
	 * and a random owner mark. A lock younger than LOCK_TTL seconds belongs to
	 * a running request, and so does one up to LOCK_SKEW seconds in the future.
	 * An older one, or one further ahead, is left over from a crash and is
	 * taken over.
	 *
	 * @return bool Whether this request now holds the lock.
	 */
	private static function acquire_lock() {
		global $wpdb;
		$now   = Clock::now();
		$mine  = $now . ':' . wp_generate_password( 12, false );
		$added = self::insert_option_once( self::LOCK_OPTION, $mine );
		if ( 1 === $added ) {
			self::$lock_value = $mine;
			return true;
		}
		if ( false === $added ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read past the options cache.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
		if ( null !== $held ) {
			// The leading number is the time; older locks hold only that.
			$age = $now - (int) $held;
			if ( $age >= -self::LOCK_SKEW && $age < self::LOCK_TTL ) {
				return false;
			}
			// Remove only the stale row we read, never a lock another request just took over.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock row.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $held ) );
			self::forget_option( self::LOCK_OPTION );
		}

		if ( 1 === self::insert_option_once( self::LOCK_OPTION, $mine ) ) {
			self::$lock_value = $mine;
			return true;
		}
		return false;
	}

	/**
	 * Releases the migration lock, only when the row is still the one this
	 * request wrote. A request that ran past LOCK_TTL may have lost its lock
	 * to another, and must not remove that one.
	 *
	 * @return void
	 */
	private static function release_lock() {
		global $wpdb;
		if ( '' === self::$lock_value ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock row.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, self::$lock_value ) );
		self::$lock_value = '';
		self::forget_option( self::LOCK_OPTION );
	}

	/**
	 * Adds an option row only when it doesn't exist yet, in one statement, so
	 * two requests can't both create it. The row is not autoloaded.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 * @return int|false 1 when created, 0 when it already existed, false on a database error.
	 */
	public static function insert_option_once( $name, $value ) {
		global $wpdb;
		$autoload = function_exists( 'wp_autoload_values_to_autoload' ) ? 'off' : 'no';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE has no Options API equivalent.
		$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $value, $autoload ) );
		self::forget_option( $name );
		return false === $rows ? false : (int) $rows;
	}

	/**
	 * Drops cached copies of an option after a raw write.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	public static function forget_option( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
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
		if ( ! self::network_active() ) {
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
				// A 6-digit code is the weakest secret here, so it can't outlive a week.
				$data['expires_at'] = Clock::mysql( min( Clock::from_mysql( $row['expires_at'] ), Clock::now() + self::LEGACY_CODE_MAX_AGE ) );
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
	 * Carries 1.0.6 grant state into the 1.1.0 columns: the deactivated flag
	 * on the token's temp user becomes suspended_at (the flag itself stays), the IP allowlist and the menu and
	 * admin bar settings become restrictions, the note becomes the label, and
	 * used_at and use_count become the login history when it is still empty.
	 * A single-use pass that 1.0.6 revoked at its login, while the session it
	 * started still runs, loses that revoke so 1.1.0 reads it as used.
	 * The old columns stay as they are. Safe to run more than once.
	 *
	 * @return bool Whether any write failed.
	 */
	private static function carry_legacy_grant_state() {
		global $wpdb;
		$table = self::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, no input.
		$rows   = $wpdb->get_results( "SELECT id, user_id, label, restrictions, suspended_at, revoked_at, expires_at, ip_restrictions, metadata, used_at, max_uses, use_count, last_login_at, login_count FROM {$table}", ARRAY_A );
		$failed = false;

		foreach ( (array) $rows as $row ) {
			$data     = array();
			$metadata = empty( $row['metadata'] ) ? array() : json_decode( $row['metadata'], true );
			$metadata = is_array( $metadata ) ? $metadata : array();

			// Only the token's own user_id column is site-local. The happyaccess_token_id meta is network-wide while token ids are per site, so on multisite it can point at another site's token.
			if ( ! empty( $row['user_id'] ) && empty( $row['suspended_at'] ) && get_user_meta( (int) $row['user_id'], 'happyaccess_deactivated', true ) ) {
				$data['suspended_at'] = Clock::mysql();
			}

			if ( self::in_use_single_use( $row ) ) {
				// use_count already equals max_uses and the plain code is gone, so the pass can't start another login.
				$data['revoked_at'] = null;
			}

			if ( empty( $row['restrictions'] ) ) {
				$restrictions = self::legacy_restrictions( $row, $metadata );
				if ( null !== $restrictions ) {
					$data['restrictions'] = wp_json_encode( $restrictions );
				}
			}

			if ( '' === (string) $row['label'] && isset( $metadata['note'] ) && is_string( $metadata['note'] ) ) {
				$note = mb_substr( sanitize_text_field( $metadata['note'] ), 0, 190, 'UTF-8' );
				if ( '' !== $note ) {
					$data['label'] = $note;
				}
			}

			// 1.0.6 kept the first use in used_at and never wrote the 1.1.0 columns, so without this a used pass reads as never logged in.
			if ( empty( $row['last_login_at'] ) && ! empty( $row['used_at'] ) ) {
				$data['last_login_at'] = $row['used_at'];
			}
			if ( 0 === (int) $row['login_count'] && (int) $row['use_count'] > 0 ) {
				$data['login_count'] = (int) $row['use_count'];
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			if ( ! empty( $data ) && false === $wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) ) ) {
				$failed = true;
			}
		}

		return $failed;
	}

	/**
	 * Closes passes that expired under 1.0.6. 1.0.6 never set revoked_at on
	 * expiry, so without this the first 1.1.0 cleanup would end each one again
	 * and mail the owner about passes that ended weeks ago. Each row gets
	 * revoked_at = expires_at and end_reason "expired", with no hook and no log
	 * row. A temp user still linked to one stays for Grants::retry_orphans(),
	 * which hands its posts over and sends nothing. Safe to run more than once.
	 *
	 * @return bool Whether any write failed.
	 */
	private static function close_legacy_expired() {
		global $wpdb;
		$table = self::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, expires_at, metadata FROM {$table} WHERE revoked_at IS NULL AND expires_at <= %s", Clock::mysql() ), ARRAY_A );
		$failed = false;

		foreach ( (array) $rows as $row ) {
			$metadata               = empty( $row['metadata'] ) ? array() : json_decode( $row['metadata'], true );
			$metadata               = is_array( $metadata ) ? $metadata : array();
			$metadata['end_reason'] = 'expired';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET revoked_at = expires_at, metadata = %s WHERE id = %d AND revoked_at IS NULL", wp_json_encode( $metadata ), (int) $row['id'] ) );
			if ( false === $changed ) {
				$failed = true;
				continue;
			}
			$user_id = (int) $row['user_id'];
			if ( $user_id > 0 && false !== get_userdata( $user_id ) ) {
				\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			}
		}

		return $failed;
	}

	/**
	 * Whether a token row is a 1.0.6 single-use pass whose one login is still
	 * running. 1.0.6 set revoked_at right after that login and marked the
	 * account with happyaccess_single_use_revoked; the support person kept
	 * working until expires_at.
	 *
	 * @param array $row Token row.
	 * @return bool
	 */
	private static function in_use_single_use( array $row ) {
		if ( 1 !== (int) $row['max_uses'] || (int) $row['use_count'] < 1 || empty( $row['revoked_at'] ) || empty( $row['user_id'] ) ) {
			return false;
		}
		if ( Clock::from_mysql( $row['expires_at'] ) <= Clock::now() ) {
			return false;
		}
		// Only this mark tells the 1.0.6 auto revoke apart from a merchant's revoke, which deleted the account.
		return (bool) get_user_meta( (int) $row['user_id'], 'happyaccess_single_use_revoked', true );
	}

	/**
	 * Gives 1.0.6 temp users of this site the happyaccess_blog_id meta that
	 * 1.1.0 reads. Skips users that no longer exist and users that have it.
	 * Safe to run more than once.
	 *
	 * @return void
	 */
	public static function backfill_blog_ids() {
		global $wpdb;
		$table = self::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$user_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$table} WHERE user_id > %d", 0 ) );
		$blog_id  = get_current_blog_id();

		foreach ( (array) $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			if ( ! get_userdata( $user_id ) || ! get_user_meta( $user_id, 'happyaccess_temp_user', true ) || '' !== (string) get_user_meta( $user_id, 'happyaccess_blog_id', true ) ) {
				continue;
			}
			update_user_meta( $user_id, 'happyaccess_blog_id', $blog_id );
		}
	}

	/**
	 * Restrictions in the 1.1.0 shape, or null when the 1.0.6 grant had none.
	 *
	 * @param array $row      Token row.
	 * @param array $metadata Decoded 1.0.6 metadata.
	 * @return array|null
	 */
	private static function legacy_restrictions( array $row, array $metadata ) {
		$ips = array();
		foreach ( explode( ',', (string) $row['ip_restrictions'] ) as $ip ) {
			$ip = trim( $ip );
			if ( ClientIp::valid( $ip ) ) {
				$ips[] = $ip;
			}
		}

		$menus = array();
		if ( isset( $metadata['restricted_menus'] ) && is_array( $metadata['restricted_menus'] ) ) {
			foreach ( $metadata['restricted_menus'] as $slug ) {
				$slug = is_string( $slug ) ? sanitize_text_field( $slug ) : '';
				if ( '' !== $slug ) {
					$menus[] = $slug;
				}
			}
		}

		$hide_admin_bar = ! empty( $metadata['hide_admin_bar'] );

		if ( empty( $ips ) && empty( $menus ) && ! $hide_admin_bar ) {
			return null;
		}

		return array(
			'ips'            => $ips,
			'menus'          => $menus,
			'hide_admin_bar' => $hide_admin_bar,
		);
	}

	/**
	 * Moves 1.0.x options into happyaccess_settings. The legacy options are
	 * deleted only once the new settings are stored.
	 *
	 * @param bool $is_upgrade Whether this site ran 1.0.x before.
	 * @return array{failed:bool,changed:array} Whether the settings write failed, and the 1.0.x values that had to change, by setting.
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
		$changed = array();
		foreach ( $map as $option => $path ) {
			$value = get_option( $option, null );
			if ( null !== $value ) {
				$changes[ $path[0] ][ $path[1] ] = $value;
			}
		}

		// 1.0.x read 0 as "never purge" and stored any number of days. 1.1.0 has no "never", so both get the longest it allows instead of a purge.
		if ( isset( $changes['privacy']['retention_days'] ) ) {
			$days    = (int) $changes['privacy']['retention_days'];
			$longest = Settings::RANGES['privacy.retention_days'][1];
			if ( $days < 1 || $days > $longest ) {
				$changes['privacy']['retention_days'] = $longest;
				$changed['retention_days']            = array(
					'from' => $days,
					'to'   => $longest,
				);
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
			$expected = Settings::merge( $changes );
			Settings::update( $changes );
			Settings::flush_cache();
			// update_option() also returns false for an unchanged value, so compare what is stored with what was meant to be.
			if ( Settings::all() !== $expected ) {
				return array(
					'failed'  => true,
					'changed' => $changed,
				);
			}
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
		return array(
			'failed'  => false,
			'changed' => $changed,
		);
	}

	/**
	 * Drops tables that 1.1.0 doesn't use.
	 *
	 * @return bool Whether every table is gone.
	 */
	private static function drop_legacy_tables() {
		global $wpdb;
		$dropped = true;
		foreach ( self::LEGACY_TABLES as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table names.
			if ( false === $wpdb->query( "DROP TABLE IF EXISTS {$table}" ) ) {
				$dropped = false;
			}
		}
		return $dropped;
	}

	/**
	 * Empties the plain codes in the 1.0.x share table, for the case where the
	 * table itself can't be dropped.
	 *
	 * @return bool Whether the codes are gone.
	 */
	private static function blank_legacy_codes() {
		global $wpdb;
		// The share table is the only one with plain codes; if it is already gone, nothing is left to blank.
		if ( ! self::table_exists( 'otp_shares' ) ) {
			return true;
		}
		$table    = self::table( 'otp_shares' );
		$previous = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name; the column is NOT NULL.
		$result = $wpdb->query( "UPDATE {$table} SET otp_code = ''" );
		$wpdb->suppress_errors( $previous );
		return false !== $result;
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
