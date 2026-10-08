<?php
/**
 * Backup code tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\BackupCodes;

class BackupCodesTest extends WP_UnitTestCase {

	/**
	 * The user under test.
	 *
	 * @var int
	 */
	private $user;

	public function set_up() {
		parent::set_up();
		Installer::install();
		delete_option( Settings::OPTION );
		$this->user = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	public function test_generate_returns_ten_distinct_codes_once() {
		$codes = BackupCodes::generate( $this->user );
		$this->assertCount( 10, $codes );
		$this->assertCount( 10, array_unique( $codes ) );
		foreach ( $codes as $code ) {
			$this->assertMatchesRegularExpression( '/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{10}$/', $code );
		}
		$this->assertSame( 10, BackupCodes::remaining( $this->user ) );
	}

	public function test_no_plain_code_is_in_user_meta() {
		$codes = BackupCodes::generate( $this->user );
		BackupCodes::use_code( $this->user, $codes[0] );

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $this->user ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dump = wp_json_encode( $rows );
		foreach ( $codes as $code ) {
			$this->assertStringNotContainsString( $code, $dump );
		}
		$stored = get_user_meta( $this->user, '_happyaccess_backup_codes', true );
		$this->assertCount( 9, $stored );
		foreach ( $stored as $hash ) {
			$this->assertNotContains( $hash, $codes );
			$this->assertGreaterThan( 20, strlen( $hash ) );
		}
	}

	public function test_each_code_works_once() {
		$codes = BackupCodes::generate( $this->user );
		$this->assertTrue( BackupCodes::use_code( $this->user, $codes[3] ) );
		$this->assertFalse( BackupCodes::use_code( $this->user, $codes[3] ) );
		$this->assertSame( 9, BackupCodes::remaining( $this->user ) );
		$this->assertTrue( BackupCodes::use_code( $this->user, $codes[4] ) );
		$this->assertSame( 8, BackupCodes::remaining( $this->user ) );
	}

	public function test_case_spaces_and_dashes_are_ignored() {
		$codes = BackupCodes::generate( $this->user );
		$code  = $codes[0];
		$typed = strtolower( substr( $code, 0, 5 ) ) . '-' . substr( $code, 5 );
		$this->assertTrue( BackupCodes::use_code( $this->user, $typed ) );

		$spaced = substr( $codes[1], 0, 4 ) . ' ' . substr( $codes[1], 4, 3 ) . ' ' . substr( $codes[1], 7 );
		$this->assertTrue( BackupCodes::use_code( $this->user, '  ' . $spaced . ' ' ) );
	}

	public function test_a_wrong_or_empty_code_is_refused_and_costs_nothing() {
		BackupCodes::generate( $this->user );
		$this->assertFalse( BackupCodes::use_code( $this->user, 'AAAAAAAAAA' ) );
		$this->assertFalse( BackupCodes::use_code( $this->user, '' ) );
		$this->assertFalse( BackupCodes::use_code( $this->user, '-- --' ) );
		$this->assertSame( 10, BackupCodes::remaining( $this->user ) );
	}

	public function test_regenerating_stops_the_old_codes() {
		$old = BackupCodes::generate( $this->user );
		$new = BackupCodes::generate( $this->user );

		$this->assertSame( 10, BackupCodes::remaining( $this->user ) );
		$this->assertFalse( BackupCodes::use_code( $this->user, $old[0] ) );
		$this->assertTrue( BackupCodes::use_code( $this->user, $new[0] ) );
	}

	public function test_a_code_of_one_user_does_not_work_for_another() {
		$other = self::factory()->user->create();
		$mine  = BackupCodes::generate( $this->user );
		BackupCodes::generate( $other );
		$this->assertFalse( BackupCodes::use_code( $other, $mine[0] ) );
		$this->assertSame( 10, BackupCodes::remaining( $other ) );
	}

	public function test_a_user_with_no_codes_has_none_remaining() {
		$this->assertSame( 0, BackupCodes::remaining( $this->user ) );
		$this->assertFalse( BackupCodes::use_code( $this->user, 'ABCDEFGHJK' ) );
	}

	public function test_a_code_taken_by_a_parallel_request_is_refused() {
		$codes = BackupCodes::generate( $this->user );
		$user  = $this->user;

		// While this request checks the hashes, a parallel request uses the same code and saves the shorter list first.
		$race = static function ( $check, $password, $hash ) use ( $user, &$race ) {
			if ( $check ) {
				remove_filter( 'check_password', $race, 10 );
				$stored = get_user_meta( $user, '_happyaccess_backup_codes', true );
				update_user_meta( $user, '_happyaccess_backup_codes', array_values( array_diff( $stored, array( $hash ) ) ) );
			}
			return $check;
		};
		add_filter( 'check_password', $race, 10, 3 );

		$this->assertFalse( BackupCodes::use_code( $this->user, $codes[0] ) );
		$this->assertSame( 9, BackupCodes::remaining( $this->user ) );
		$this->assertFalse( BackupCodes::use_code( $this->user, $codes[0] ) );
	}

	public function test_use_and_generate_are_logged_without_codes() {
		global $wpdb;
		$codes = BackupCodes::generate( $this->user );
		BackupCodes::use_code( $this->user, $codes[0] );
		BackupCodes::use_code( $this->user, 'AAAAAAAAAA' );
		BackupCodes::generate( $this->user );

		$table = Installer::table( 'logs' );
		$this->assertSame( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'twostep_backup_regenerated' AND feature = 'two_step'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'twostep_backup_used' AND feature = 'two_step'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dump = wp_json_encode( $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $codes as $code ) {
			$this->assertStringNotContainsString( $code, $dump );
		}
	}
}
