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

class MigrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
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
}
