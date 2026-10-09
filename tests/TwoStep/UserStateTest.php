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

	/**
	 * A stored key that is not the one in use, as when saving the key failed.
	 *
	 * @return string
	 */
	public function other_site_key() {
		return base64_encode( str_repeat( 'x', 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A stored key value.
	}

	/**
	 * Makes a parallel request write the state meta just before this
	 * request's first write of it lands, the way two logins at once would.
	 *
	 * @param array $parallel State the parallel request saves.
	 * @param int   $times    How many writes to get in front of.
	 * @return callable The filter, to remove later.
	 */
	private function race_state_writes( array $parallel, $times = 1 ) {
		global $wpdb;
		$user  = $this->user;
		$left  = $times;
		$race  = static function ( $query ) use ( $user, $parallel, &$left, &$race, $wpdb ) {
			if ( $left < 1 || 0 !== stripos( ltrim( $query ), 'UPDATE' ) || false === strpos( $query, $wpdb->usermeta ) || false === strpos( $query, 'grace_' ) ) {
				return $query;
			}
			--$left;
			remove_filter( 'query', $race );
			$parallel['tick'] = $left;
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s", maybe_serialize( $parallel ), $user, UserState::META_STATE ) );
			if ( $left > 0 ) {
				add_filter( 'query', $race );
			}
			return $query;
		};
		add_filter( 'query', $race );
		return $race;
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
		$this->assertSame( $secret, Secrets::decrypt_network( $stored['secret'] ) );
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
			$this->assertSame(
				array(
					'method'      => $method,
					'summary_key' => $event,
				),
				json_decode( $rows[0]['metadata'], true )
			);
		}
	}

	public function test_enable_app_refuses_when_the_site_key_is_not_saved() {
		Secrets::key();
		add_filter( 'pre_option_' . Secrets::OPTION, array( $this, 'other_site_key' ) );
		$result = UserState::enable_app( $this->user, Totp::new_secret() );
		remove_filter( 'pre_option_' . Secrets::OPTION, array( $this, 'other_site_key' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_no_site_key', $result->get_error_code() );
		$this->assertFalse( metadata_exists( 'user', $this->user, '_happyaccess_totp' ) );
		$this->assertFalse( UserState::app_enabled( $this->user ) );
		$this->assertCount( 0, $this->logged( 'twostep_enabled' ) );
	}

	public function test_enable_app_refuses_a_bad_user_or_secret() {
		$this->assertWPError( UserState::enable_app( 0, Totp::new_secret() ) );
		$this->assertWPError( UserState::enable_app( $this->user, '' ) );
		$this->assertTrue( UserState::enable_app( $this->user, Totp::new_secret() ) );
	}

	public function test_a_grace_login_counted_by_a_parallel_request_is_not_lost() {
		UserState::start_grace( $this->user, 1790000000 );
		$race = $this->race_state_writes(
			array(
				'grace_started_at'  => 1790000000,
				'grace_logins_used' => 1,
			)
		);

		$count = UserState::count_grace_login( $this->user );
		remove_filter( 'query', $race );

		$this->assertSame( 2, $count );
		$this->assertSame( 2, UserState::grace_logins_used( $this->user ) );
	}

	public function test_a_state_change_keeps_what_a_parallel_request_saved() {
		UserState::start_grace( $this->user, 1790000000 );
		$race = $this->race_state_writes(
			array(
				'grace_started_at'  => 1790000000,
				'grace_logins_used' => 1,
			)
		);

		UserState::enable_email( $this->user );
		remove_filter( 'query', $race );

		$this->assertTrue( UserState::email_enabled( $this->user ) );
		$this->assertSame( 1, UserState::grace_logins_used( $this->user ) );
		$this->assertSame( 1790000000, UserState::grace_started_at( $this->user ) );
	}

	public function test_the_state_write_gives_up_after_three_tries() {
		global $wpdb;
		UserState::start_grace( $this->user, 1790000000 );
		$race = $this->race_state_writes(
			array(
				'grace_started_at'  => 1790000000,
				'grace_logins_used' => 5,
			),
			10
		);

		$tries = 0;
		$count = static function ( $query ) use ( &$tries, $wpdb ) {
			if ( 0 === stripos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, $wpdb->usermeta ) && false !== strpos( $query, 'BINARY' ) ) {
				++$tries;
			}
			return $query;
		};
		add_filter( 'query', $count, 20 );
		UserState::count_grace_login( $this->user );
		remove_filter( 'query', $count, 20 );
		remove_filter( 'query', $race );

		$this->assertSame( 3, $tries );
	}

	public function test_a_step_used_by_a_parallel_request_is_refused() {
		global $wpdb;
		$secret = Totp::new_secret();
		UserState::enable_app( $this->user, $secret );
		$user = $this->user;

		// While this request checks the code, a parallel request uses the same step and saves it first.
		$race = static function ( $query ) use ( $user, $wpdb, &$race ) {
			if ( 0 !== stripos( ltrim( $query ), 'UPDATE' ) || false === strpos( $query, $wpdb->usermeta ) || false === strpos( $query, 'last_step' ) ) {
				return $query;
			}
			remove_filter( 'query', $race );
			$stored              = get_user_meta( $user, UserState::META_TOTP, true );
			$stored['last_step'] = 500;
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s", maybe_serialize( $stored ), $user, UserState::META_TOTP ) );
			return $query;
		};
		add_filter( 'query', $race );

		$this->assertFalse( UserState::consume_step( $this->user, 500 ) );
		remove_filter( 'query', $race );

		$this->assertSame( 500, UserState::last_step( $this->user ) );
		$this->assertFalse( UserState::consume_step( $this->user, 500 ) );
		$this->assertSame( $secret, UserState::totp_secret( $this->user ) );
	}
}
