<?php
/**
 * Installer schema tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;

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
}
