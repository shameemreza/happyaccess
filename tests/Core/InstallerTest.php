<?php
/**
 * Installer schema tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Features\SupportAccess\CapabilityGuard;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class InstallerTest extends WP_UnitTestCase {

	public function test_install_creates_all_tables_with_new_columns() {
		global $wpdb;
		Installer::install();

		foreach ( array( 'tokens', 'logs', 'attempts', 'challenges' ) as $name ) {
			$this->assertTrue( Installer::table_exists( $name ), $name . ' table missing' );
		}

		$token_columns = wp_list_pluck( $wpdb->get_results( 'SHOW COLUMNS FROM ' . Installer::table( 'tokens' ) ), 'Field' );
		foreach ( array( 'code_hash', 'link_hash', 'label', 'recipient_email', 'protection', 'restrictions', 'redirect_to', 'notify', 'suspended_at', 'last_login_at', 'login_count' ) as $column ) {
			$this->assertContains( $column, $token_columns );
		}

		$log_columns = wp_list_pluck( $wpdb->get_results( 'SHOW COLUMNS FROM ' . Installer::table( 'logs' ) ), 'Field' );
		$this->assertContains( 'feature', $log_columns );
		$this->assertContains( 'summary', $log_columns );

		$this->assertSame( 32, strlen( Secrets::key() ) );
	}

	public function test_install_twice_is_safe() {
		Installer::install();
		Installer::install();
		$this->assertTrue( Installer::table_exists( 'tokens' ) );
	}

	private function become_temp_user() {
		Installer::install();
		Capabilities::register();
		CapabilityGuard::register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Grants::flush_cache();
		$made = Grants::create( array( 'label' => 'Acme' ) );
		wp_set_current_user( TempUsers::get_or_create( Grants::get( $made['id'] ) ) );
	}

	public function test_an_expired_failure_flag_is_read_without_dying_for_a_temp_user() {
		update_option( '_transient_happyaccess_migration_failed', 'boom', false );
		update_option( '_transient_timeout_happyaccess_migration_failed', time() - 60, false );
		$this->become_temp_user();

		Installer::maybe_upgrade();

		$this->assertFalse( get_option( '_transient_happyaccess_migration_failed' ) );
	}

	public function test_a_migration_clears_and_sets_its_failure_flag_for_a_temp_user() {
		set_transient( Installer::FAILED_TRANSIENT, 'boom', 900 );
		update_option( 'happyaccess_db_version', '0.0.0' );
		$this->become_temp_user();

		Installer::migrate();

		$this->assertFalse( get_transient( Installer::FAILED_TRANSIENT ) );
	}
}
