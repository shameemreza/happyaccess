<?php
/**
 * Emailed two-step code tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\EmailMethod;

class EmailMethodTest extends WP_UnitTestCase {

	/**
	 * The user under test.
	 *
	 * @var WP_User
	 */
	private $user;

	public function set_up() {
		parent::set_up();
		Installer::install();
		delete_option( Settings::OPTION );
		Clock::freeze( 1790000000 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		reset_phpmailer_instance();
		$this->user = self::factory()->user->create_and_get(
			array(
				'role'       => 'editor',
				'user_email' => 'sam@example.org',
			)
		);
		EmailMethod::flush_queue();
		reset_phpmailer_instance();
	}

	public function tear_down() {
		Clock::freeze( null );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/**
	 * Sends a code, flushes the queue and returns the code from the mail.
	 *
	 * @param WP_User|null $user User, default the test user.
	 * @return string
	 */
	private function send_and_read( $user = null ) {
		reset_phpmailer_instance();
		$this->assertTrue( EmailMethod::send( null === $user ? $this->user : $user, 77 ) );
		EmailMethod::flush_queue();
		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertNotFalse( $sent );
		$this->assertSame( 1, preg_match( '/(\d{3}) (\d{3})/', $sent->body, $m ) );
		return $m[1] . $m[2];
	}

	/**
	 * All open email challenge rows.
	 *
	 * @return array
	 */
	private function rows() {
		global $wpdb;
		$table = Installer::table( 'challenges' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_send_stores_a_hashed_six_digit_code_for_ten_minutes() {
		$code = $this->send_and_read();
		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'twostep_email', $rows[0]['purpose'] );
		$this->assertSame( (int) $this->user->ID, (int) $rows[0]['user_id'] );
		$this->assertSame( Codes::hash_code( $code, Codes::PURPOSE_TWOSTEP_EMAIL ), $rows[0]['code_hash'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', 1790000600 ), $rows[0]['expires_at'] );
		$this->assertSame( 0, (int) $rows[0]['attempts'] );
		$this->assertNull( $rows[0]['used_at'] );
		$this->assertStringNotContainsString( $code, wp_json_encode( $rows ) );
	}

	public function test_an_open_code_hashed_before_purposes_still_passes() {
		global $wpdb;
		$code = $this->send_and_read();
		$rows = $this->rows();
		$wpdb->update( Installer::table( 'challenges' ), array( 'code_hash' => Codes::legacy_hash_code( $code ) ), array( 'id' => $rows[0]['id'] ) );

		update_option( Codes::PURPOSE_SINCE_OPTION, Clock::mysql( Clock::now() + 1 ), false );
		$result = EmailMethod::verify( $this->user->ID, $code );
		delete_option( Codes::PURPOSE_SINCE_OPTION );
		$this->assertTrue( $result );
	}

	public function test_an_old_style_hash_on_a_code_sent_after_purposes_is_refused() {
		global $wpdb;
		$code = $this->send_and_read();
		$rows = $this->rows();
		$wpdb->update( Installer::table( 'challenges' ), array( 'code_hash' => Codes::legacy_hash_code( $code ) ), array( 'id' => $rows[0]['id'] ) );

		update_option( Codes::PURPOSE_SINCE_OPTION, Clock::mysql( Clock::now() - 1 ), false );
		$result = EmailMethod::verify( $this->user->ID, $code );
		delete_option( Codes::PURPOSE_SINCE_OPTION );
		$this->assertWPError( $result );
	}

	public function test_the_email_has_the_code_the_expiry_the_ip_and_a_text_part() {
		$this->send_and_read();
		$sent = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertSame( array( 'sam@example.org' ), wp_list_pluck( $sent->to, 0 ) );
		$this->assertStringContainsString( 'Your two-step login code', $sent->subject );
		$this->assertStringContainsString( 'It expires in 10 minutes.', $sent->body );
		$this->assertStringContainsString( '203.0.113.9', $sent->body );
		$this->assertStringContainsString( "If you didn't try to log in, change your password.", $sent->body );
		$this->assertStringNotContainsString( 'step=verify', $sent->body );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertMatchesRegularExpression( '/Your two-step login code is \d{3} \d{3}/', $mailer->AltBody ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertStringContainsString( "If you didn't try to log in, change your password.", $mailer->AltBody ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public function test_send_queues_the_mail_for_shutdown_and_sends_nothing_before() {
		reset_phpmailer_instance();
		EmailMethod::send( $this->user, 1 );
		$this->assertNotFalse( has_action( 'shutdown', array( EmailMethod::class, 'flush_queue' ) ) );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
		EmailMethod::flush_queue();
		$this->assertNotFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_the_right_code_works_once() {
		$code = $this->send_and_read();
		$this->assertTrue( EmailMethod::verify( $this->user->ID, $code ) );
		$again = EmailMethod::verify( $this->user->ID, $code );
		$this->assertWPError( $again );
		$this->assertSame( 'happyaccess_invalid_code', $again->get_error_code() );
	}

	public function test_a_code_with_a_space_or_dash_works() {
		$code = $this->send_and_read();
		$this->assertTrue( EmailMethod::verify( $this->user->ID, substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ) ) );
	}

	public function test_a_wrong_code_five_times_cancels_the_code() {
		$code  = $this->send_and_read();
		$wrong = '000000' === $code ? '111111' : '000000';

		for ( $i = 1; $i <= 4; $i++ ) {
			$result = EmailMethod::verify( $this->user->ID, $wrong );
			$this->assertSame( 'happyaccess_invalid_code', $result->get_error_code(), "try $i" );
		}
		$fifth = EmailMethod::verify( $this->user->ID, $wrong );
		$this->assertSame( 'happyaccess_code_locked', $fifth->get_error_code() );

		$this->assertWPError( EmailMethod::verify( $this->user->ID, $code ) );
		$rows = $this->rows();
		$this->assertNotNull( $rows[0]['used_at'] );
		$this->assertSame( 5, (int) $rows[0]['attempts'] );
	}

	public function test_four_wrong_codes_leave_the_right_one_working() {
		$code  = $this->send_and_read();
		$wrong = '000000' === $code ? '111111' : '000000';
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertWPError( EmailMethod::verify( $this->user->ID, $wrong ) );
		}
		$this->assertTrue( EmailMethod::verify( $this->user->ID, $code ) );
	}

	public function test_an_expired_code_fails() {
		$code = $this->send_and_read();
		Clock::freeze( 1790000000 + 600 );
		$this->assertWPError( EmailMethod::verify( $this->user->ID, $code ) );
	}

	public function test_a_code_still_works_the_second_before_it_expires() {
		$code = $this->send_and_read();
		Clock::freeze( 1790000000 + 599 );
		$this->assertTrue( EmailMethod::verify( $this->user->ID, $code ) );
	}

	public function test_a_new_code_cancels_the_earlier_open_one_of_the_same_user_only() {
		$other = self::factory()->user->create_and_get( array( 'user_email' => 'other@example.org' ) );
		$first = $this->send_and_read();
		$mine  = $this->send_and_read( $other );
		$again = $this->send_and_read();

		$rows = $this->rows();
		$this->assertCount( 3, $rows );
		$this->assertNotNull( $rows[0]['used_at'] );
		$this->assertNull( $rows[1]['used_at'] );
		$this->assertNull( $rows[2]['used_at'] );

		if ( $first !== $again ) {
			$this->assertWPError( EmailMethod::verify( $this->user->ID, $first ) );
		}
		$this->assertTrue( EmailMethod::verify( $other->ID, $mine ) );
		$this->assertTrue( EmailMethod::verify( $this->user->ID, $again ) );
	}

	public function test_a_code_of_one_user_does_not_work_for_another() {
		$other = self::factory()->user->create_and_get();
		$code  = $this->send_and_read();
		$this->assertWPError( EmailMethod::verify( $other->ID, $code ) );
		$this->assertTrue( EmailMethod::verify( $this->user->ID, $code ) );
	}

	public function test_other_purposes_are_left_alone() {
		global $wpdb;
		$table = Installer::table( 'challenges' );
		$wpdb->insert(
			$table,
			array(
				'user_id'    => $this->user->ID,
				'purpose'    => 'passwordless',
				'code_hash'  => str_repeat( 'a', 64 ),
				'created_at' => Clock::mysql(),
				'expires_at' => Clock::mysql( Clock::now() + 600 ),
			)
		);
		$this->send_and_read();
		$rows = $this->rows();
		$this->assertSame( 'passwordless', $rows[0]['purpose'] );
		$this->assertNull( $rows[0]['used_at'] );
	}

	public function test_verify_with_no_code_sent_or_a_non_string_fails() {
		$this->assertWPError( EmailMethod::verify( $this->user->ID, '123456' ) );
		$this->send_and_read();
		$this->assertWPError( EmailMethod::verify( $this->user->ID, array( '123456' ) ) );
		$this->assertWPError( EmailMethod::verify( 0, '123456' ) );
	}
}
