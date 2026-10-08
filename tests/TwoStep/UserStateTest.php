<?php
/**
 * Per-user two-step state tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\BackupCodes;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;

class UserStateTest extends WP_UnitTestCase {

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

	/**
	 * Log rows of one event for the user.
	 *
	 * @param string $event Event key.
	 * @return array
	 */
	private function logged( $event ) {
		global $wpdb;
		$table = Installer::table( 'logs' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s AND user_id = %d", $event, $this->user ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_a_new_user_has_nothing_enabled() {
		$this->assertFalse( UserState::is_enabled( $this->user ) );
		$this->assertSame( array(), UserState::methods( $this->user ) );
		$this->assertFalse( UserState::app_enabled( $this->user ) );
		$this->assertFalse( UserState::email_enabled( $this->user ) );
	}

	public function test_the_secret_is_stored_encrypted_and_reads_back_the_same() {
		$secret = Totp::new_secret();
		UserState::enable_app( $this->user, $secret );

		$stored = get_user_meta( $this->user, '_happyaccess_totp', true );
		$this->assertStringNotContainsString( $secret, wp_json_encode( $stored ) );
		$this->assertStringNotContainsString( $secret, maybe_serialize( $stored ) );
		$this->assertSame( $secret, Secrets::decrypt( $stored['secret'] ) );
		$this->assertSame( 0, $stored['last_step'] );

		$this->assertSame( $secret, UserState::totp_secret( $this->user ) );
		$this->assertSame( 0, UserState::last_step( $this->user ) );
	}

	public function test_enabling_the_app_makes_it_the_default_method() {
		UserState::enable_email( $this->user );
		$this->assertSame( 'email', UserState::default_method( $this->user ) );

		UserState::enable_app( $this->user, Totp::new_secret() );
		$this->assertSame( 'app', UserState::default_method( $this->user ) );
		$this->assertSame( array( 'app', 'email' ), UserState::methods( $this->user ) );
		$this->assertTrue( UserState::is_enabled( $this->user ) );
	}

	public function test_disabling_the_app_removes_the_secret_and_falls_back_to_email() {
		UserState::enable_email( $this->user );
		UserState::enable_app( $this->user, Totp::new_secret() );
		UserState::disable_app( $this->user );

		$this->assertSame( '', get_user_meta( $this->user, '_happyaccess_totp', true ) );
		$this->assertNull( UserState::totp_secret( $this->user ) );
		$this->assertSame( 'email', UserState::default_method( $this->user ) );
		$this->assertSame( array( 'email' ), UserState::methods( $this->user ) );
	}

	public function test_disabling_email_leaves_the_app() {
		UserState::enable_email( $this->user );
		UserState::enable_app( $this->user, Totp::new_secret() );
		UserState::disable_email( $this->user );
		$this->assertSame( array( 'app' ), UserState::methods( $this->user ) );
		$this->assertTrue( UserState::is_enabled( $this->user ) );
	}

	public function test_backup_codes_alone_do_not_count() {
		BackupCodes::generate( $this->user );
		$this->assertFalse( UserState::is_enabled( $this->user ) );
		$this->assertSame( array(), UserState::methods( $this->user ) );

		UserState::enable_email( $this->user );
		$this->assertTrue( UserState::is_enabled( $this->user ) );
		$this->assertSame( array( 'email', 'backup' ), UserState::methods( $this->user ) );
	}

	public function test_backup_leaves_the_methods_when_the_last_code_is_used() {
		UserState::enable_email( $this->user );
		$codes = BackupCodes::generate( $this->user );
		foreach ( $codes as $code ) {
			$this->assertTrue( BackupCodes::use_code( $this->user, $code ) );
		}
		$this->assertSame( array( 'email' ), UserState::methods( $this->user ) );
	}

	public function test_reset_clears_every_two_step_meta_key() {
		UserState::enable_email( $this->user );
		UserState::enable_app( $this->user, Totp::new_secret() );
		BackupCodes::generate( $this->user );
		UserState::start_grace( $this->user );

		UserState::reset( $this->user );

		foreach ( array( '_happyaccess_twostep', '_happyaccess_totp', '_happyaccess_backup_codes' ) as $key ) {
			$this->assertFalse( metadata_exists( 'user', $this->user, $key ), $key );
		}
		$this->assertFalse( UserState::is_enabled( $this->user ) );
		$this->assertSame( 0, UserState::grace_started_at( $this->user ) );
	}

	public function test_reset_leaves_other_users_alone() {
		$other = self::factory()->user->create();
		UserState::enable_email( $other );
		UserState::enable_email( $this->user );
		UserState::reset( $this->user );
		$this->assertTrue( UserState::email_enabled( $other ) );
	}

	public function test_grace_starts_once_and_counts_logins() {
		$this->assertSame( 0, UserState::grace_started_at( $this->user ) );
		$this->assertSame( 0, UserState::grace_logins_used( $this->user ) );

		UserState::start_grace( $this->user, 1790000000 );
		UserState::start_grace( $this->user, 1790009999 );
		$this->assertSame( 1790000000, UserState::grace_started_at( $this->user ) );

		$this->assertSame( 1, UserState::count_grace_login( $this->user ) );
		$this->assertSame( 2, UserState::count_grace_login( $this->user ) );
		$this->assertSame( 2, UserState::grace_logins_used( $this->user ) );
	}

	public function test_grace_does_not_turn_on_a_method() {
		UserState::start_grace( $this->user );
		$this->assertFalse( UserState::is_enabled( $this->user ) );
	}

	public function test_consume_step_moves_forward_once_and_refuses_the_same_or_an_older_step() {
		UserState::enable_app( $this->user, Totp::new_secret() );

		$this->assertTrue( UserState::consume_step( $this->user, 100 ) );
		$this->assertSame( 100, UserState::last_step( $this->user ) );
		$this->assertFalse( UserState::consume_step( $this->user, 100 ) );
		$this->assertFalse( UserState::consume_step( $this->user, 99 ) );
		$this->assertTrue( UserState::consume_step( $this->user, 101 ) );
		$this->assertSame( 101, UserState::last_step( $this->user ) );
	}

	public function test_consume_step_keeps_the_secret() {
		$secret = Totp::new_secret();
		UserState::enable_app( $this->user, $secret );
		UserState::consume_step( $this->user, 7 );
		$this->assertSame( $secret, UserState::totp_secret( $this->user ) );
	}

	public function test_consume_step_without_an_app_is_refused() {
		$this->assertFalse( UserState::consume_step( $this->user, 5 ) );
	}

	public function test_enable_and_disable_are_logged_without_any_secret() {
		$secret = Totp::new_secret();
		UserState::enable_app( $this->user, $secret );
		UserState::enable_email( $this->user );
		UserState::disable_app( $this->user );
		UserState::reset( $this->user );

		$enabled = $this->logged( 'twostep_enabled' );
		$this->assertCount( 2, $enabled );
		$this->assertSame( 'two_step', $enabled[0]['feature'] );
		$this->assertCount( 1, $this->logged( 'twostep_disabled' ) );
		$this->assertCount( 1, $this->logged( 'twostep_reset' ) );

		global $wpdb;
		$table = Installer::table( 'logs' );
		$dump  = wp_json_encode( $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertStringNotContainsString( $secret, $dump );
	}

	public function test_a_change_that_changes_nothing_is_not_logged() {
		UserState::disable_app( $this->user );
		UserState::disable_email( $this->user );
		UserState::enable_email( $this->user );
		UserState::enable_email( $this->user );
		$this->assertCount( 0, $this->logged( 'twostep_disabled' ) );
		$this->assertCount( 1, $this->logged( 'twostep_enabled' ) );
	}

	public function test_the_step_outcomes_are_logged_for_the_user_with_the_method_only() {
		UserState::log_passed( $this->user, 'app' );
		UserState::log_failed( $this->user, 'email' );
		UserState::log_locked( $this->user, 'backup' );

		foreach ( array( 'twostep_passed' => 'app', 'twostep_failed' => 'email', 'twostep_locked' => 'backup' ) as $event => $method ) {
			$rows = $this->logged( $event );
			$this->assertCount( 1, $rows, $event );
			$this->assertSame( 'two_step', $rows[0]['feature'] );
			$this->assertSame( array( 'method' => $method ), json_decode( $rows[0]['metadata'], true ) );
		}
	}
}
