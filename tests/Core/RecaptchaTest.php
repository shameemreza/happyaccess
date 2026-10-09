<?php
/**
 * reCAPTCHA v3 tests. Google is never called: pre_http_request answers.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Privacy;
use HappyAccess\Core\Recaptcha;
use HappyAccess\Core\Settings;

class RecaptchaTest extends WP_UnitTestCase {

	const SITE_KEY = 'test-site-key';

	/**
	 * Requests caught on their way out: url and args.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * What the stub answers: an array of Google's fields, or a WP_Error.
	 *
	 * @var array|WP_Error
	 */
	private $answer = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		delete_option( Settings::OPTION );
		delete_option( Recaptcha::SECRET_OPTION );
		delete_transient( Recaptcha::UNAVAILABLE_TRANSIENT );
		delete_transient( Recaptcha::FAILED_TRANSIENT );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->requests         = array();
		$this->answer           = array(
			'success' => true,
			'action'  => 'code',
			'score'   => 0.9,
		);
		add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
		wp_dequeue_script( Recaptcha::HANDLE );
		wp_deregister_script( Recaptcha::HANDLE );
	}

	public function tear_down() {
		try {
			remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
			remove_all_filters( 'happyaccess_verify_captcha' );
			wp_dequeue_script( Recaptcha::HANDLE );
			wp_deregister_script( Recaptcha::HANDLE );
		} finally {
			parent::tear_down();
		}
	}

	public function stub_http( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( false !== $preempt ) {
			return $preempt;
		}
		if ( is_wp_error( $this->answer ) ) {
			return $this->answer;
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $this->answer ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function turn_on( $threshold = 0.5 ) {
		Settings::update(
			array(
				'security' => array(
					'recaptcha_enabled'   => true,
					'recaptcha_site_key'  => self::SITE_KEY,
					'recaptcha_threshold' => $threshold,
				),
			)
		);
		update_option( Recaptcha::SECRET_OPTION, 'test-secret-value', false );
	}

	private function log_rows( $event ) {
		return AuditLog::query( array( 'event' => $event ) )['items'];
	}

	public function test_it_is_on_only_with_the_switch_a_site_key_and_a_secret() {
		$this->assertFalse( Recaptcha::enabled() );

		Settings::update( array( 'security' => array( 'recaptcha_enabled' => true ) ) );
		$this->assertFalse( Recaptcha::enabled(), 'No site key and no secret.' );

		Settings::update( array( 'security' => array( 'recaptcha_site_key' => self::SITE_KEY ) ) );
		$this->assertFalse( Recaptcha::enabled(), 'No secret.' );

		update_option( Recaptcha::SECRET_OPTION, 'test-secret-value', false );
		$this->assertTrue( Recaptcha::enabled() );

		Settings::update( array( 'security' => array( 'recaptcha_enabled' => false ) ) );
		$this->assertFalse( Recaptcha::enabled(), 'Switched off.' );
	}

	public function test_a_passing_token_is_checked_with_the_secret_the_token_and_the_ip() {
		$this->turn_on();

		$this->assertTrue( Recaptcha::verify( 'good-token', 'code' ) );

		$this->assertCount( 1, $this->requests );
		$sent = $this->requests[0];
		$this->assertSame( 'https://www.google.com/recaptcha/api/siteverify', $sent['url'] );
		$this->assertSame( 'POST', $sent['args']['method'] );
		$this->assertSame( 5, $sent['args']['timeout'] );
		$this->assertSame( 'test-secret-value', $sent['args']['body']['secret'] );
		$this->assertSame( 'good-token', $sent['args']['body']['response'] );
		$this->assertSame( '203.0.113.9', $sent['args']['body']['remoteip'] );
	}

	public function test_a_score_under_the_threshold_is_refused() {
		$this->turn_on( 0.7 );
		$this->answer['score'] = 0.6;

		$result = Recaptcha::verify( 'good-token', 'code' );

		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_captcha', $result->get_error_code() );
		$this->assertSame( "The security check didn't pass. Reload the page and try again.", $result->get_error_message() );

		$this->answer['score'] = 0.7;
		$this->assertTrue( Recaptcha::verify( 'good-token', 'code' ), 'A score equal to the threshold passes.' );
	}

	public function test_a_missing_score_is_refused() {
		$this->turn_on();
		unset( $this->answer['score'] );
		$this->assertWPError( Recaptcha::verify( 'good-token', 'code' ) );
	}

	public function test_a_token_made_for_another_step_is_refused() {
		$this->turn_on();
		$this->answer['action'] = 'pl_request';
		$this->assertWPError( Recaptcha::verify( 'good-token', 'code' ) );

		unset( $this->answer['action'] );
		$this->assertWPError( Recaptcha::verify( 'good-token', 'code' ), 'No action at all.' );
	}

	public function test_a_token_google_rejects_is_refused() {
		$this->turn_on();
		$this->answer = array(
			'success'     => false,
			'error-codes' => array( 'invalid-input-response' ),
		);
		$this->assertWPError( Recaptcha::verify( 'bad-token', 'code' ) );
	}

	public function test_a_missing_token_is_refused_without_asking_google() {
		$this->turn_on();

		$this->assertWPError( Recaptcha::verify( '', 'code' ) );
		$this->assertWPError( Recaptcha::verify( null, 'code' ) );
		$this->assertWPError( Recaptcha::verify( array( 'x' ), 'code' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_when_google_cannot_be_reached_the_step_is_refused_and_logged_once_an_hour() {
		$this->turn_on();
		$this->answer = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );

		$first  = Recaptcha::verify( 'good-token', 'code' );
		$second = Recaptcha::verify( 'good-token', 'pl_request' );

		$this->assertWPError( $first, 'Like 1.0.6, a network error fails closed.' );
		$this->assertWPError( $second );
		$this->assertSame( "The security check didn't pass. Reload the page and try again.", $first->get_error_message() );

		$rows = $this->log_rows( 'captcha_unavailable' );
		$this->assertCount( 1, $rows, 'One row an hour, however many logins fail.' );
		$this->assertSame( 'core', $rows[0]['feature'] );
		$this->assertSame( 'code', $rows[0]['meta']['step'] );
		$this->assertStringNotContainsString( 'test-secret-value', wp_json_encode( $rows ) );
		$this->assertStringNotContainsString( 'good-token', wp_json_encode( $rows ) );

		delete_transient( Recaptcha::UNAVAILABLE_TRANSIENT );
		Recaptcha::verify( 'good-token', 'code' );
		$this->assertCount( 2, $this->log_rows( 'captcha_unavailable' ), 'The next hour logs again.' );
	}

	public function test_the_two_step_steps_go_on_when_google_cannot_be_reached_and_log_it() {
		$this->turn_on();
		$this->answer = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );

		$this->assertTrue( Recaptcha::verify( 'good-token', 'twostep' ) );
		$this->assertTrue( Recaptcha::verify( 'good-token', 'twostep_setup' ) );

		$rows = $this->log_rows( 'captcha_unavailable' );
		$this->assertCount( 1, $rows, 'Still one row an hour.' );
		$this->assertSame( 'twostep', $rows[0]['meta']['step'] );
		$this->assertSame( 'allowed', $rows[0]['meta']['outcome'] );
	}

	public function test_the_public_steps_log_that_they_were_refused() {
		$this->turn_on();
		$this->answer = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );

		foreach ( array( 'code', 'link', 'pl_request', 'pl_verify' ) as $action ) {
			$this->assertWPError( Recaptcha::verify( 'good-token', $action ), $action );
		}
		$this->assertSame( 'refused', $this->log_rows( 'captcha_unavailable' )[0]['meta']['outcome'] );
	}

	public function test_the_two_step_steps_still_refuse_what_a_reachable_google_refuses() {
		$this->turn_on();

		$this->assertWPError( Recaptcha::verify( '', 'twostep' ), 'A missing token.' );
		$this->assertWPError( Recaptcha::verify( '', 'twostep_setup' ) );
		$this->answer = array(
			'success' => true,
			'action'  => 'code',
			'score'   => 0.9,
		);
		$this->assertWPError( Recaptcha::verify( 'good-token', 'twostep' ), 'A token made for another step.' );
		$this->answer = array(
			'success' => true,
			'action'  => 'twostep',
			'score'   => 0.1,
		);
		$this->assertWPError( Recaptcha::verify( 'good-token', 'twostep' ), 'A low score.' );
		$this->answer = array( 'success' => false );
		$this->assertWPError( Recaptcha::verify( 'good-token', 'twostep_setup' ), 'A token Google rejects.' );
		$this->assertSame( array(), $this->log_rows( 'captcha_unavailable' ) );
	}

	public function test_the_filter_can_still_refuse_a_two_step_step_while_google_is_down() {
		$this->turn_on();
		$this->answer = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		add_filter( 'happyaccess_verify_captcha', '__return_false' );

		$this->assertWPError( Recaptcha::verify( 'good-token', 'twostep' ) );
	}

	public function test_an_answer_that_is_not_json_counts_as_unavailable() {
		$this->turn_on();
		$this->answer = array();
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => '<html>Bad gateway</html>',
					'response' => array(
						'code'    => 502,
						'message' => 'Bad Gateway',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			5
		);

		$this->assertWPError( Recaptcha::verify( 'good-token', 'code' ) );
		$this->assertCount( 1, $this->log_rows( 'captcha_unavailable' ) );
	}

	public function test_a_failed_check_is_logged_once_an_hour_without_the_token() {
		$this->turn_on();
		$this->answer['score']  = 0.1;
		$this->answer['action'] = 'twostep';

		Recaptcha::verify( 'good-token', 'twostep' );
		Recaptcha::verify( '', 'code' );

		$rows = $this->log_rows( 'captcha_failed' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'core', $rows[0]['feature'] );
		$this->assertSame( 'twostep', $rows[0]['meta']['step'] );
		$this->assertSame( 'score', $rows[0]['meta']['reason'] );
		$this->assertStringNotContainsString( 'good-token', wp_json_encode( $rows ) );
	}

	public function test_the_filter_has_the_final_word_and_gets_the_step() {
		$this->turn_on();
		$seen = array();
		add_filter(
			'happyaccess_verify_captcha',
			static function ( $passed, $action ) use ( &$seen ) {
				$seen[] = array( $passed, $action );
				return ! $passed;
			},
			10,
			2
		);

		$this->answer['action'] = 'link';
		$this->assertWPError( Recaptcha::verify( 'good-token', 'link' ), 'A pass the filter turns down.' );
		$this->assertTrue( Recaptcha::verify( '', 'link' ), 'A failure the filter lets through.' );

		$this->assertSame(
			array(
				array( true, 'link' ),
				array( false, 'link' ),
			),
			$seen
		);
	}

	public function test_check_skips_everything_while_it_is_off() {
		add_filter( 'happyaccess_verify_captcha', '__return_false' );

		$this->assertTrue( Recaptcha::check( '', 'code' ) );
		$this->assertSame( array(), $this->requests );

		Settings::update( array( 'security' => array( 'recaptcha_enabled' => true ) ) );
		$this->assertTrue( Recaptcha::check( '', 'code' ), 'Switched on but with no keys, as 1.1.0 saved it.' );
		$this->assertSame( array(), $this->requests );
	}

	public function test_check_verifies_while_it_is_on() {
		$this->turn_on();
		$this->assertWPError( Recaptcha::check( '', 'code' ) );
		$this->assertTrue( Recaptcha::check( 'good-token', 'code' ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_the_script_loads_only_while_it_is_on() {
		Recaptcha::enqueue( 'code' );
		$this->assertFalse( wp_script_is( Recaptcha::HANDLE, 'enqueued' ) );

		$this->turn_on();
		Recaptcha::enqueue( 'code' );
		$this->assertTrue( wp_script_is( Recaptcha::HANDLE, 'enqueued' ) );

		$script = wp_scripts()->registered[ Recaptcha::HANDLE ];
		$this->assertStringStartsWith( 'https://www.google.com/recaptcha/api.js?', $script->src );
		$this->assertStringContainsString( 'render=' . self::SITE_KEY, $script->src );
		$inline = implode( "\n", (array) wp_scripts()->get_data( Recaptcha::HANDLE, 'after' ) );
		$this->assertStringContainsString( '"code"', $inline );
		$this->assertStringContainsString( '"' . self::SITE_KEY . '"', $inline );
		$this->assertStringContainsString( '"happyaccess_recaptcha"', $inline );
		$this->assertStringNotContainsString( 'test-secret-value', $inline . $script->src );
	}

	public function test_an_unknown_step_loads_nothing() {
		$this->turn_on();
		Recaptcha::enqueue( 'something_else' );
		$this->assertFalse( wp_script_is( Recaptcha::HANDLE, 'enqueued' ) );
	}

	public function test_every_step_has_its_own_action() {
		$this->assertSame( array( 'code', 'link', 'pl_request', 'pl_verify', 'twostep', 'twostep_setup' ), Recaptcha::ACTIONS );
	}

	public function test_the_privacy_text_names_google_and_says_it_is_only_while_on() {
		$text = Privacy::policy_text();
		$this->assertStringContainsString( 'If reCAPTCHA is turned on', $text );
		$this->assertStringContainsString( 'every HappyAccess login screen', $text );
		$this->assertStringContainsString( 'While it is off, nothing is sent to Google.', $text );
	}

	public function test_its_events_are_core_events() {
		$this->assertContains( 'captcha_unavailable', Privacy::CORE_EVENTS );
		$this->assertContains( 'captcha_failed', Privacy::CORE_EVENTS );
	}
}
