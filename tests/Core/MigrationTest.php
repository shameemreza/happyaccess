<?php
/**
 * Upgrade from 1.0.6 tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\SupportAccess\Grants;

class MigrationTest extends WP_UnitTestCase {

	/**
	 * dbDelta's ALTER TABLE commits the test transaction, so rows written
	 * before it, such as the migration lock, the seeded 1.0.6 options and temp
	 * user meta, survive the rollback. Clear every happyaccess option, transient
	 * and usermeta row, and commit the cleanup.
	 */
	private function clear_migration_state() {
		global $wpdb;
		$like = $wpdb->esc_like( 'happyaccess_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like, '_transient_' . $like, '_transient_timeout_' . $like ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $like ) );
		$wpdb->query( 'COMMIT' );
		wp_cache_flush();
	}

	public function set_up() {
		parent::set_up();
		$this->clear_migration_state();
		Clock::freeze( 1790000000 );
		delete_option( Settings::OPTION );
		delete_option( 'happyaccess_db_version' );
		HappyAccess_Test_Legacy_Schema::drop_all();
		HappyAccess_Test_Legacy_Schema::create();
		$this->seed_legacy_data();
	}

	public function tear_down() {
		Clock::freeze( null );
		HappyAccess_Test_Legacy_Schema::drop_all();
		parent::tear_down();
		$this->clear_migration_state();
	}

	private function seed_legacy_data() {
		global $wpdb;
		$table = $wpdb->prefix . 'happyaccess_tokens';
		$wpdb->insert( $table, array( 'token_hash' => 'a', 'otp_code' => '123456', 'created_by' => 1, 'expires_at' => Clock::mysql( 1790000000 + DAY_IN_SECONDS ) ) );
		$wpdb->insert( $table, array( 'token_hash' => 'b', 'otp_code' => '654321', 'created_by' => 1, 'expires_at' => Clock::mysql( 1790000000 - DAY_IN_SECONDS ) ) );
		$wpdb->insert( $table, array( 'token_hash' => 'c', 'otp_code' => '111111', 'created_by' => 1, 'expires_at' => Clock::mysql( 1790000000 + DAY_IN_SECONDS ), 'revoked_at' => Clock::mysql() ) );

		update_option( 'happyaccess_version', '1.0.6' );
		update_option( 'happyaccess_db_version', '1.0.4' );
		update_option( 'happyaccess_max_attempts', 8 );
		update_option( 'happyaccess_lockout_duration', 900 );
		update_option( 'happyaccess_token_expiry', 604800 );
		update_option( 'happyaccess_cleanup_days', 14 );
		update_option( 'happyaccess_enable_logging', true );
		update_option( 'happyaccess_recaptcha_enabled', true );
		update_option( 'happyaccess_recaptcha_site_key', 'site-key' );
		update_option( 'happyaccess_recaptcha_secret_key', 'secret-key' );
		update_option( 'happyaccess_magic_link_expiry', 300 );
	}

	private function token( $hash ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}happyaccess_tokens WHERE token_hash = %s", $hash ), ARRAY_A );
	}

	public function test_active_codes_are_hashed_and_plain_codes_removed() {
		Installer::migrate();

		$active = $this->token( 'a' );
		$this->assertNull( $active['otp_code'] );
		$this->assertTrue( Codes::verify_code( '123456', $active['code_hash'] ) );

		$expired = $this->token( 'b' );
		$this->assertNull( $expired['otp_code'] );
		$this->assertEmpty( $expired['code_hash'] );

		$revoked = $this->token( 'c' );
		$this->assertNull( $revoked['otp_code'] );
		$this->assertEmpty( $revoked['code_hash'] );
	}

	public function test_options_are_mapped_and_legacy_removed() {
		global $wpdb;
		Installer::migrate();

		$this->assertSame( 8, Settings::get( 'security.max_attempts' ) );
		$this->assertSame( 900, Settings::get( 'security.lockout_duration' ) );
		$this->assertSame( 259200, Settings::get( 'support.default_duration' ) );
		$this->assertSame( 14, Settings::get( 'privacy.retention_days' ) );
		$this->assertTrue( Settings::get( 'security.recaptcha_enabled' ) );
		$this->assertSame( 'site-key', Settings::get( 'security.recaptcha_site_key' ) );
		$this->assertNotSame( '', Settings::get( 'support.consent_given_at' ) );
		$this->assertTrue( Settings::get( 'features.support_access' ) );
		$this->assertFalse( Settings::get( 'features.passwordless' ) );

		$this->assertFalse( get_option( 'happyaccess_max_attempts' ) );
		$this->assertFalse( get_option( 'happyaccess_magic_link_expiry' ) );
		$this->assertFalse( get_option( 'happyaccess_version' ) );

		$this->assertSame( 'secret-key', get_option( 'happyaccess_recaptcha_secret_key' ) );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'happyaccess_recaptcha_secret_key' ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );

		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
	}

	public function test_legacy_tables_dropped_and_new_ones_exist() {
		Installer::migrate();
		$this->assertFalse( Installer::table_exists( 'magic_links' ) );
		$this->assertFalse( Installer::table_exists( 'otp_shares' ) );
		$this->assertTrue( Installer::table_exists( 'challenges' ) );
	}

	public function test_upgrade_is_logged_once_and_running_twice_is_safe() {
		Installer::migrate();
		Installer::migrate();

		$this->assertSame( 1, AuditLog::query( array( 'event' => 'plugin_upgraded' ) )['total'] );
		$this->assertTrue( Codes::verify_code( '123456', $this->token( 'a' )['code_hash'] ) );
	}

	public function test_maybe_upgrade_only_runs_when_behind() {
		Installer::maybe_upgrade();
		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );

		update_option( 'happyaccess_max_attempts', 3 );
		Installer::maybe_upgrade();
		$this->assertSame( 3, (int) get_option( 'happyaccess_max_attempts' ) );
	}

	public function test_fresh_install_records_no_consent() {
		HappyAccess_Test_Legacy_Schema::drop_all();
		foreach ( Installer::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_option( 'happyaccess_db_version' );

		Installer::migrate();

		$this->assertSame( '', Settings::get( 'support.consent_given_at' ) );
		$this->assertSame( 0, AuditLog::query( array( 'event' => 'plugin_upgraded' ) )['total'] );
	}

	public function test_fresh_install_reactivated_still_records_no_consent() {
		HappyAccess_Test_Legacy_Schema::drop_all();
		foreach ( Installer::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_option( 'happyaccess_db_version' );

		Installer::migrate();
		Installer::migrate();

		$this->assertSame( '', Settings::get( 'support.consent_given_at' ) );
		$this->assertSame( 0, AuditLog::query( array( 'event' => 'plugin_upgraded' ) )['total'] );
	}

	public function test_exhausted_code_is_not_hashed() {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'happyaccess_tokens',
			array(
				'token_hash' => 'd',
				'otp_code'   => '222222',
				'created_by' => 1,
				'expires_at' => Clock::mysql( 1790000000 + DAY_IN_SECONDS ),
				'max_uses'   => 1,
				'use_count'  => 1,
			)
		);

		Installer::migrate();

		$exhausted = $this->token( 'd' );
		$this->assertNull( $exhausted['otp_code'] );
		$this->assertEmpty( $exhausted['code_hash'] );
	}

	public function test_write_failure_keeps_version_and_legacy_tables_then_retry_completes() {
		global $wpdb;
		$tokens  = $wpdb->prefix . 'happyaccess_tokens';
		$already = false;
		$break   = static function ( $query ) use ( &$already, $tokens ) {
			if ( ! $already && 0 === strpos( $query, "UPDATE `{$tokens}`" ) ) {
				$already = true;
				return 'UPDATE this is not valid sql';
			}
			return $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		Installer::migrate();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertTrue( $already, 'The UPDATE was not intercepted.' );
		$this->assertSame( '1.0.4', get_option( 'happyaccess_db_version' ) );
		$this->assertTrue( Installer::table_exists( 'magic_links' ) );
		$this->assertNotFalse( get_transient( Installer::FAILED_TRANSIENT ), 'A failed run must leave the back-off marker.' );

		// The marker lasts 15 minutes; clear it to retry now.
		delete_transient( Installer::FAILED_TRANSIENT );
		Installer::maybe_upgrade();

		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		$this->assertFalse( Installer::table_exists( 'magic_links' ) );
		$this->assertNull( $this->token( 'a' )['otp_code'] );
		$this->assertTrue( Codes::verify_code( '123456', $this->token( 'a' )['code_hash'] ) );
		$this->assertSame( 1, AuditLog::query( array( 'event' => 'plugin_upgraded' ) )['total'] );
	}
	private function insert_legacy_token( $hash, array $extra = array() ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'happyaccess_tokens',
			array_merge(
				array(
					'token_hash' => $hash,
					'created_by' => 1,
					'expires_at' => Clock::mysql( 1790000000 + DAY_IN_SECONDS ),
				),
				$extra
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function test_one_time_flag_is_kept() {
		$id = $this->insert_legacy_token(
			'once',
			array(
				'otp_code' => '333333',
				'max_uses' => 1,
			)
		);

		Installer::migrate();

		$row = $this->token( 'once' );
		$this->assertSame( $id, (int) $row['id'] );
		$this->assertSame( 1, (int) $row['max_uses'] );
		$this->assertSame( 0, (int) $row['use_count'] );
		$this->assertTrue( Codes::verify_code( '333333', $row['code_hash'] ) );
	}

	public function test_suspension_is_carried_by_the_token_user_id_and_the_flag_is_kept() {
		global $wpdb;
		$suspended = $this->insert_legacy_token( 'sus-col' );
		$other     = $this->insert_legacy_token( 'sus-none' );

		$deactivated = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $deactivated, 'happyaccess_deactivated', true );
		$wpdb->update( $wpdb->prefix . 'happyaccess_tokens', array( 'user_id' => $deactivated ), array( 'id' => $suspended ) );

		$active_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$wpdb->update( $wpdb->prefix . 'happyaccess_tokens', array( 'user_id' => $active_user ), array( 'id' => $other ) );

		Installer::migrate();

		$this->assertSame( Clock::mysql(), $this->token( 'sus-col' )['suspended_at'] );
		$this->assertNull( $this->token( 'sus-none' )['suspended_at'] );
		$this->assertTrue( (bool) get_user_meta( $deactivated, 'happyaccess_deactivated', true ), 'The migration leaves the old flag alone.' );
	}

	public function test_migration_backfills_the_blog_id_of_legacy_temp_users() {
		global $wpdb;
		$legacy = $this->insert_legacy_token( 'blog-legacy' );
		$set    = $this->insert_legacy_token( 'blog-set' );
		$plain  = $this->insert_legacy_token( 'blog-plain' );
		$gone   = $this->insert_legacy_token( 'blog-gone' );

		$legacy_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $legacy_user, 'happyaccess_temp_user', 1 );
		$set_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $set_user, 'happyaccess_temp_user', 1 );
		update_user_meta( $set_user, 'happyaccess_blog_id', 99 );
		$plain_user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$table = $wpdb->prefix . 'happyaccess_tokens';
		$wpdb->update( $table, array( 'user_id' => $legacy_user ), array( 'id' => $legacy ) );
		$wpdb->update( $table, array( 'user_id' => $set_user ), array( 'id' => $set ) );
		$wpdb->update( $table, array( 'user_id' => $plain_user ), array( 'id' => $plain ) );
		$wpdb->update( $table, array( 'user_id' => 987654 ), array( 'id' => $gone ) );

		Installer::migrate();

		$this->assertSame( (string) get_current_blog_id(), get_user_meta( $legacy_user, 'happyaccess_blog_id', true ) );
		$this->assertSame( '99', get_user_meta( $set_user, 'happyaccess_blog_id', true ), 'An existing blog id is kept.' );
		$this->assertSame( '', get_user_meta( $plain_user, 'happyaccess_blog_id', true ), 'Only temp users get a blog id.' );
		$this->assertFalse( get_userdata( 987654 ) );

		$before = get_user_meta( $legacy_user, 'happyaccess_blog_id' );
		Installer::backfill_blog_ids();
		$this->assertSame( $before, get_user_meta( $legacy_user, 'happyaccess_blog_id' ), 'Running it again adds no second row.' );
	}

	public function test_a_deactivated_user_that_is_not_in_the_token_user_id_column_does_not_suspend_it() {
		$id = $this->insert_legacy_token( 'sus-foreign' );

		// Another site's temp user: usermeta is network-wide and token ids are per site, so the meta can point at this site's token id.
		$foreign = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $foreign, 'happyaccess_temp_user', true );
		update_user_meta( $foreign, 'happyaccess_token_id', $id );
		update_user_meta( $foreign, 'happyaccess_deactivated', true );

		Installer::migrate();

		$this->assertNull( $this->token( 'sus-foreign' )['suspended_at'] );
		$this->assertTrue( (bool) get_user_meta( $foreign, 'happyaccess_deactivated', true ), 'Another site\'s suspension flag must survive.' );
	}

	public function test_existing_suspended_at_is_not_overwritten() {
		global $wpdb;
		$id   = $this->insert_legacy_token( 'sus-old' );
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user, 'happyaccess_deactivated', true );
		Installer::install();
		$wpdb->update(
			$wpdb->prefix . 'happyaccess_tokens',
			array(
				'suspended_at' => '2026-01-02 03:04:05',
				'user_id'      => $user,
			),
			array( 'id' => $id )
		);

		Installer::migrate();

		$this->assertSame( '2026-01-02 03:04:05', $this->token( 'sus-old' )['suspended_at'] );
		$this->assertTrue( (bool) get_user_meta( $user, 'happyaccess_deactivated', true ) );
	}

	public function test_restrictions_are_built_from_ip_column_and_metadata() {
		$this->insert_legacy_token(
			'restr',
			array(
				'ip_restrictions' => ' 203.0.113.5 , not-an-ip,2001:db8::1,,198.51.100.7 ',
				'metadata'        => wp_json_encode(
					array(
						'restricted_menus' => array( 'plugins.php', ' <b>tools.php</b> ', '' ),
						'hide_admin_bar'   => true,
					)
				),
			)
		);
		$this->insert_legacy_token( 'plain' );

		Installer::migrate();

		$row = $this->token( 'restr' );
		$this->assertSame(
			array(
				'ips'            => array( '203.0.113.5', '2001:db8::1', '198.51.100.7' ),
				'menus'          => array( 'plugins.php', 'tools.php' ),
				'hide_admin_bar' => true,
			),
			json_decode( $row['restrictions'], true )
		);
		$this->assertSame( ' 203.0.113.5 , not-an-ip,2001:db8::1,,198.51.100.7 ', $row['ip_restrictions'] );
		$this->assertEmpty( $this->token( 'plain' )['restrictions'] );
	}

	public function test_existing_restrictions_are_not_replaced() {
		global $wpdb;
		$id = $this->insert_legacy_token( 'restr-keep', array( 'ip_restrictions' => '203.0.113.5' ) );
		Installer::install();
		$wpdb->update( $wpdb->prefix . 'happyaccess_tokens', array( 'restrictions' => '{"ips":["192.0.2.1"],"menus":[],"hide_admin_bar":false}' ), array( 'id' => $id ) );

		Installer::migrate();

		$this->assertSame( '{"ips":["192.0.2.1"],"menus":[],"hide_admin_bar":false}', $this->token( 'restr-keep' )['restrictions'] );
	}

	public function test_note_becomes_label_only_when_label_is_empty() {
		global $wpdb;
		$this->insert_legacy_token( 'note-a', array( 'metadata' => wp_json_encode( array( 'note' => '  Ticket <i>1234</i>  ' ) ) ) );
		$this->insert_legacy_token( 'note-long', array( 'metadata' => wp_json_encode( array( 'note' => str_repeat( 'é', 250 ) ) ) ) );
		$kept = $this->insert_legacy_token( 'note-kept', array( 'metadata' => wp_json_encode( array( 'note' => 'Old note' ) ) ) );
		Installer::install();
		$wpdb->update( $wpdb->prefix . 'happyaccess_tokens', array( 'label' => 'Chosen label' ), array( 'id' => $kept ) );

		Installer::migrate();

		$this->assertSame( 'Ticket 1234', $this->token( 'note-a' )['label'] );
		$this->assertSame( str_repeat( 'é', 190 ), $this->token( 'note-long' )['label'] );
		$this->assertSame( 'Chosen label', $this->token( 'note-kept' )['label'] );
	}

	public function test_legacy_use_becomes_the_login_history() {
		$used  = $this->insert_legacy_token(
			'used-once',
			array(
				'used_at'   => '2026-09-01 10:00:00',
				'use_count' => 1,
			)
		);
		$fresh = $this->insert_legacy_token( 'never-used' );
		$this->insert_legacy_token(
			'new-history',
			array(
				'used_at'   => '2026-09-02 10:00:00',
				'use_count' => 3,
			)
		);

		Installer::migrate();
		// A 1.1.0 login has since filled the new columns, so a later run must not put the legacy values back.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'happyaccess_tokens',
			array(
				'last_login_at' => '2026-09-05 08:00:00',
				'login_count'   => 5,
			),
			array( 'token_hash' => 'new-history' )
		);
		$first = $this->token( 'used-once' );
		Installer::migrate();

		$this->assertSame( $used, (int) $first['id'] );
		$this->assertSame( '2026-09-01 10:00:00', $first['last_login_at'] );
		$this->assertSame( 1, (int) $first['login_count'] );
		$this->assertSame( $first, $this->token( 'used-once' ) );

		$never = $this->token( 'never-used' );
		$this->assertSame( $fresh, (int) $never['id'] );
		$this->assertNull( $never['last_login_at'] );
		$this->assertSame( 0, (int) $never['login_count'] );

		$this->assertSame( '2026-09-05 08:00:00', $this->token( 'new-history' )['last_login_at'] );
		$this->assertSame( 5, (int) $this->token( 'new-history' )['login_count'] );
	}

	public function test_carried_state_is_stable_when_the_migration_runs_twice() {
		$id = $this->insert_legacy_token(
			'twice',
			array(
				'ip_restrictions' => '203.0.113.5',
				'metadata'        => wp_json_encode(
					array(
						'note'             => 'Twice',
						'restricted_menus' => array( 'tools.php' ),
					)
				),
			)
		);
		global $wpdb;
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user, 'happyaccess_deactivated', true );
		$wpdb->update( $wpdb->prefix . 'happyaccess_tokens', array( 'user_id' => $user ), array( 'id' => $id ) );

		Installer::migrate();
		$first = $this->token( 'twice' );
		Clock::freeze( 1790000000 + 3600 );
		Installer::migrate();

		$this->assertSame( $first, $this->token( 'twice' ) );
		$this->assertSame( 'Twice', $first['label'] );
		$this->assertNotEmpty( $first['suspended_at'] );
		$this->assertNotEmpty( $first['restrictions'] );
	}
	private function lock_value() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Installer::LOCK_OPTION ) );
	}

	private function set_lock( $timestamp ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", Installer::LOCK_OPTION, (string) $timestamp ) );
	}

	public function test_a_fresh_lock_makes_migrate_a_no_op() {
		$this->set_lock( 1790000000 - 60 );

		Installer::migrate();

		$this->assertSame( '1.0.4', get_option( 'happyaccess_db_version' ) );
		$this->assertTrue( Installer::table_exists( 'magic_links' ) );
		$this->assertSame( '123456', $this->token( 'a' )['otp_code'] );
		$this->assertSame( (string) ( 1790000000 - 60 ), $this->lock_value(), 'The holder keeps its lock.' );
	}

	public function test_a_lock_a_little_in_the_future_still_counts_as_held() {
		$this->set_lock( 1790000000 + 30 );

		Installer::migrate();

		$this->assertSame( '1.0.4', get_option( 'happyaccess_db_version' ) );
		$this->assertSame( (string) ( 1790000000 + 30 ), $this->lock_value(), 'The holder keeps its lock.' );
	}

	public function test_a_lock_far_in_the_future_is_taken_over() {
		$this->set_lock( 1790000000 + 61 );

		Installer::migrate();

		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		$this->assertNull( $this->lock_value() );
	}

	public function test_release_leaves_a_lock_another_request_took_over() {
		global $wpdb;
		$other = '1790000000:another-request';
		// Runs after the migration steps and before the lock is released.
		add_action(
			'delete_transient_' . Installer::FAILED_TRANSIENT,
			static function () use ( $wpdb, $other ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => $other ), array( 'option_name' => Installer::LOCK_OPTION ) );
				wp_cache_delete( Installer::LOCK_OPTION, 'options' );
			}
		);

		Installer::migrate();

		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		$this->assertSame( $other, $this->lock_value(), 'A request releases only the lock it took.' );
	}

	public function test_the_lock_value_carries_an_owner_mark() {
		Installer::migrate();
		$this->assertNull( $this->lock_value() );
		$first = null;
		add_action(
			'delete_transient_' . Installer::FAILED_TRANSIENT,
			function () use ( &$first ) {
				$first = $this->lock_value();
			}
		);
		HappyAccess_Test_Legacy_Schema::drop_all();
		HappyAccess_Test_Legacy_Schema::create();
		delete_option( 'happyaccess_db_version' );
		Installer::migrate();
		$this->assertMatchesRegularExpression( '/^1790000000:[A-Za-z0-9]{8,}$/', (string) $first );
	}

	public function test_a_stale_lock_is_taken_over() {
		$this->set_lock( 1790000000 - 301 );

		Installer::migrate();

		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		$this->assertNull( $this->lock_value() );
	}

	public function test_the_lock_is_released_after_success_and_after_failure() {
		Installer::migrate();
		$this->assertNull( $this->lock_value() );

		global $wpdb;
		HappyAccess_Test_Legacy_Schema::drop_all();
		HappyAccess_Test_Legacy_Schema::create();
		delete_option( 'happyaccess_db_version' );
		$this->seed_legacy_data();
		$tokens = $wpdb->prefix . 'happyaccess_tokens';
		$break  = static function ( $query ) use ( $tokens ) {
			return 0 === strpos( $query, "UPDATE `{$tokens}`" ) ? 'UPDATE this is not valid sql' : $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		Installer::migrate();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );
		$this->assertNull( $this->lock_value() );
		$this->assertSame( '1.0.4', get_option( 'happyaccess_db_version' ) );
	}

	public function test_maybe_upgrade_is_skipped_while_the_failure_marker_exists() {
		set_transient( Installer::FAILED_TRANSIENT, 'write_failed', 15 * MINUTE_IN_SECONDS );

		Installer::maybe_upgrade();

		$this->assertSame( '1.0.4', get_option( 'happyaccess_db_version' ) );
		$this->assertTrue( Installer::table_exists( 'magic_links' ) );

		delete_transient( Installer::FAILED_TRANSIENT );
		Installer::maybe_upgrade();
		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
	}
	public function test_migration_stops_when_the_new_columns_did_not_get_created() {
		global $wpdb;
		$skip_alter = static function ( $query ) {
			return 0 === stripos( ltrim( $query ), 'ALTER TABLE' ) ? 'SELECT 1' : $query;
		};
		add_filter( 'query', $skip_alter );

		Installer::migrate();

		remove_filter( 'query', $skip_alter );
		$this->assertSame( '1.0.4', get_option( 'happyaccess_db_version' ) );
		$this->assertTrue( Installer::table_exists( 'magic_links' ), 'Legacy tables stay until the schema is complete.' );
		$this->assertSame( '123456', $this->token( 'a' )['otp_code'], 'No data step runs on an incomplete schema.' );
		$this->assertStringStartsWith( 'missing_column:', (string) get_transient( Installer::FAILED_TRANSIENT ) );
		$this->assertNull( $this->lock_value() );

		delete_transient( Installer::FAILED_TRANSIENT );
		Installer::migrate();
		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		$this->assertFalse( Installer::table_exists( 'magic_links' ) );
		$columns = wp_list_pluck( $wpdb->get_results( 'SHOW COLUMNS FROM ' . Installer::table( 'attempts' ) ), 'Field' );
		$this->assertContains( 'scope', $columns );
	}
	public function test_migrated_six_digit_grants_expire_within_seven_days() {
		$this->insert_legacy_token( 'long', array( 'otp_code' => '444444', 'expires_at' => Clock::mysql( 1790000000 + 20 * DAY_IN_SECONDS ) ) );
		$this->insert_legacy_token( 'short', array( 'otp_code' => '555555', 'expires_at' => Clock::mysql( 1790000000 + 2 * DAY_IN_SECONDS ) ) );
		$this->insert_legacy_token( 'link-only', array( 'expires_at' => Clock::mysql( 1790000000 + 20 * DAY_IN_SECONDS ) ) );

		Installer::migrate();

		$this->assertSame( Clock::mysql( 1790000000 + 7 * DAY_IN_SECONDS ), $this->token( 'long' )['expires_at'] );
		$this->assertSame( Clock::mysql( 1790000000 + 2 * DAY_IN_SECONDS ), $this->token( 'short' )['expires_at'] );
		$this->assertSame( Clock::mysql( 1790000000 + 20 * DAY_IN_SECONDS ), $this->token( 'link-only' )['expires_at'], 'Rows without a code keep their expiry.' );
		$this->assertTrue( Codes::verify_code( '444444', $this->token( 'long' )['code_hash'] ) );
	}

	/**
	 * Makes statements that touch the given table fail.
	 *
	 * @param string $verb  First word of the statement.
	 * @param string $table Table name fragment.
	 * @return callable The filter, to remove later.
	 */
	private function break_statements( $verb, $table ) {
		$filter = static function ( $query ) use ( $verb, $table ) {
			$query = (string) $query;
			return 0 === stripos( ltrim( $query ), $verb ) && false !== strpos( $query, $table ) ? 'this is not valid sql' : $query;
		};
		add_filter( 'query', $filter );
		return $filter;
	}

	private function seed_share() {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'happyaccess_otp_shares',
			array(
				'token_id'   => 1,
				'otp_code'   => '246810',
				'share_hash' => 'abc',
				'expires_at' => Clock::mysql( 1790000000 + DAY_IN_SECONDS ),
				'created_at' => Clock::mysql(),
			)
		);
	}

	private function share_codes() {
		global $wpdb;
		// get_col() turns an empty string into null, so read whole rows.
		return wp_list_pluck( $wpdb->get_results( 'SELECT otp_code FROM ' . $wpdb->prefix . 'happyaccess_otp_shares' ), 'otp_code' );
	}

	public function test_a_failed_drop_blanks_the_plain_share_codes() {
		global $wpdb;
		$this->seed_share();
		$filter   = $this->break_statements( 'DROP', 'otp_shares' );
		$suppress = $wpdb->suppress_errors( true );

		Installer::migrate();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $filter );
		$this->assertTrue( Installer::table_exists( 'otp_shares' ), 'The drop was blocked.' );
		$this->assertSame( array( '' ), $this->share_codes(), 'No plain code is left behind.' );
		$this->assertFalse( Installer::table_exists( 'magic_links' ), 'The other legacy table still goes.' );
		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		$this->assertFalse( get_transient( Installer::FAILED_TRANSIENT ) );
	}

	public function test_a_failed_magic_links_drop_with_the_share_table_gone_still_finishes() {
		global $wpdb;
		$this->seed_share();
		$filter   = $this->break_statements( 'DROP', 'magic_links' );
		$suppress = $wpdb->suppress_errors( true );

		Installer::migrate();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $filter );
		$this->assertFalse( Installer::table_exists( 'otp_shares' ), 'The share table went, so no plain code is left.' );
		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		$this->assertFalse( get_transient( Installer::FAILED_TRANSIENT ) );
	}

	public function test_a_failed_drop_and_failed_blanking_is_a_failed_migration() {
		global $wpdb;
		$this->seed_share();
		$drop     = $this->break_statements( 'DROP', 'otp_shares' );
		$blank    = $this->break_statements( 'UPDATE', 'otp_shares' );
		$suppress = $wpdb->suppress_errors( true );

		Installer::migrate();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $drop );
		remove_filter( 'query', $blank );
		$this->assertSame( '1.0.4', get_option( 'happyaccess_db_version' ), 'The version stays so a later request retries.' );
		$this->assertSame( 'legacy_codes_remain', get_transient( Installer::FAILED_TRANSIENT ) );
		$this->assertNull( $this->lock_value() );
	}

	public function test_a_clean_drop_removes_the_share_table() {
		$this->seed_share();

		Installer::migrate();

		$this->assertFalse( Installer::table_exists( 'otp_shares' ) );
		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
	}

	/**
	 * A 1.0.6 single-use pass after its login: 1.0.6 set revoked_at right
	 * away, kept the session, and marked the account.
	 *
	 * @param string $hash       Token hash.
	 * @param int    $expires_at Expiry timestamp.
	 * @param bool   $marked     Whether the account has the 1.0.6 single-use mark.
	 * @return array Grant id and temp user id.
	 */
	private function used_single_use_pass( $hash, $expires_at, $marked = true ) {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$id   = $this->insert_legacy_token(
			$hash,
			array(
				'otp_code'   => '777777',
				'user_id'    => $user,
				'max_uses'   => 1,
				'use_count'  => 1,
				'used_at'    => Clock::mysql( 1790000000 - 600 ),
				'expires_at' => Clock::mysql( $expires_at ),
				'revoked_at' => Clock::mysql( 1790000000 - 600 ),
			)
		);
		update_user_meta( $user, 'happyaccess_temp_user', true );
		update_user_meta( $user, 'happyaccess_token_id', $id );
		if ( $marked ) {
			update_user_meta( $user, 'happyaccess_single_use_revoked', true );
		}
		return array( $id, $user );
	}

	public function test_a_used_single_use_pass_in_time_keeps_its_session_and_account() {
		list( $id, $user ) = $this->used_single_use_pass( 'once-in-use', 1790000000 + DAY_IN_SECONDS );
		$post              = self::factory()->post->create(
			array(
				'post_author' => $user,
				'post_status' => 'draft',
			)
		);

		Installer::migrate();
		Grants::flush_cache();

		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		$this->assertNull( $this->token( 'once-in-use' )['revoked_at'] );
		$this->assertSame( 'used', Grants::get( $id )['status'] );
		$this->assertSame( 'active', Grants::resolve_user( $user )['state'] );

		Grants::retry_orphans();
		$this->assertNotFalse( get_userdata( $user ), 'The account stays until the pass expires.' );
		$this->assertSame( $user, (int) get_post( $post )->post_author );
	}

	public function test_a_carried_single_use_pass_cannot_log_in_again() {
		list( $id ) = $this->used_single_use_pass( 'once-no-reuse', 1790000000 + DAY_IN_SECONDS );

		Installer::migrate();
		Grants::flush_cache();

		$this->assertEmpty( $this->token( 'once-no-reuse' )['code_hash'] );
		$this->assertNull( Grants::find_by_code( '777777' ) );
		$this->assertFalse( Grants::record_login( $id ) );
		$this->assertSame( 1, (int) $this->token( 'once-no-reuse' )['use_count'] );
	}

	public function test_single_use_passes_outside_the_carry_stay_revoked() {
		$this->used_single_use_pass( 'once-expired', 1790000000 - HOUR_IN_SECONDS );
		$this->used_single_use_pass( 'once-unmarked', 1790000000 + DAY_IN_SECONDS, false );
		$this->insert_legacy_token(
			'once-gone',
			array(
				'user_id'    => 0,
				'max_uses'   => 1,
				'use_count'  => 1,
				'revoked_at' => Clock::mysql( 1790000000 - 600 ),
			)
		);

		Installer::migrate();

		$this->assertNotNull( $this->token( 'once-expired' )['revoked_at'] );
		$this->assertNotNull( $this->token( 'once-unmarked' )['revoked_at'], 'Without the 1.0.6 mark, the revoke may have been the merchant\'s.' );
		$this->assertNotNull( $this->token( 'once-gone' )['revoked_at'] );
	}
}
