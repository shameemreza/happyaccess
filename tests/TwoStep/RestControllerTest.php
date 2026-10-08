<?php
/**
 * Two-step REST routes for the user's own setup.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\BackupCodes;
use HappyAccess\Features\TwoStep\Feature;
use HappyAccess\Features\TwoStep\RestController;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;
use HappyAccess\Login\Router;

class TwoStepRestControllerTest extends WP_UnitTestCase {

	const PASSWORD = 'correct horse battery';

	/**
	 * Settings globals as they were before rest_api_init ran.
	 *
	 * @var array
	 */
	private $settings_globals = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
		Clock::freeze( 1790000000 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		foreach ( array( 'new_allowed_options', 'wp_registered_settings' ) as $name ) {
			$this->settings_globals[ $name ] = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;
		}

		Features::set( 'two_step', true );
		$this->set_policy( array( 'editor' => 'optional' ) );
		Feature::register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
		add_filter( 'pre_wp_mail', '__return_true' );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			$GLOBALS['wp_rest_server'] = null;
			foreach ( $this->settings_globals as $name => $value ) {
				$GLOBALS[ $name ] = $value;
			}
			Clock::freeze( null );
			Router::reset();
		} finally {
			parent::tear_down();
		}
	}

	/**
	 * Saves the role policy, replacing the old one.
	 *
	 * @param array $policy Role slug to choice.
	 * @return void
	 */
	private function set_policy( array $policy ) {
		Settings::update( array( 'two_step' => array( 'role_policy' => array_fill_keys( array_keys( wp_roles()->roles ), 'off' ) ) ) );
		Settings::update( array( 'two_step' => array( 'role_policy' => $policy ) ) );
	}

	/**
	 * Logs in an editor with a known password.
	 *
	 * @return WP_User
	 */
	private function editor() {
		$user = self::factory()->user->create_and_get(
			array(
				'role'      => 'editor',
				'user_pass' => self::PASSWORD,
			)
		);
		wp_set_current_user( $user->ID );
		return $user;
	}

	/**
	 * Posts to a two-step route, through the filters the REST server runs on output.
	 *
	 * @param string $path   Route after twostep/.
	 * @param array  $params Body params.
	 * @return WP_REST_Response
	 */
	private function call( $path, array $params = array() ) {
		$request = new WP_REST_Request( 'POST', '/happyaccess/v1/twostep/' . $path );
		$request->set_body_params( $params );
		$response = rest_do_request( $request );
		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );
	}

	/**
	 * Error code of a response.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return string
	 */
	private function code_of( WP_REST_Response $response ) {
		$data = $response->get_data();
		return is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '';
	}

	/**
	 * Passes the re-check with the password.
	 *
	 * @return void
	 */
	private function recheck() {
		$response = $this->call( 'recheck', array( 'password' => self::PASSWORD ) );
		$this->assertSame( 200, $response->get_status(), 'The re-check passes.' );
	}

	/**
	 * The app code for the current time step.
	 *
	 * @param string $secret Secret.
	 * @param int    $offset Steps from now.
	 * @return string
	 */
	private function app_code( $secret, $offset = 0 ) {
		return Totp::code( $secret, Totp::step_for( Clock::now() ) + $offset );
	}

	/**
	 * Routes that change something, so they need a fresh re-check.
	 *
	 * @return array
	 */
	private function change_routes() {
		return array( 'app/begin', 'app/confirm', 'app/disable', 'email/enable', 'email/disable', 'backup/regenerate' );
	}

	public function test_every_route_refuses_a_logged_out_request() {
		foreach ( array_merge( array( 'recheck' ), $this->change_routes() ) as $route ) {
			$response = $this->call( $route, array( 'password' => self::PASSWORD ) );
			$this->assertSame( 401, $response->get_status(), $route );
		}
	}

	public function test_every_change_route_refuses_without_a_fresh_recheck() {
		$user = $this->editor();
		UserState::enable_email( $user->ID );

		foreach ( $this->change_routes() as $route ) {
			$response = $this->call( $route, array( 'code' => '123456' ) );
			$this->assertSame( 403, $response->get_status(), $route );
			$this->assertSame( 'happyaccess_recheck_required', $this->code_of( $response ), $route );
		}

		update_user_meta( $user->ID, UserState::META_RECHECK, Clock::now() - 16 * MINUTE_IN_SECONDS );
		foreach ( $this->change_routes() as $route ) {
			$this->assertSame( 'happyaccess_recheck_required', $this->code_of( $this->call( $route ) ), $route . ' with a stale re-check' );
		}
		$this->assertTrue( UserState::email_enabled( $user->ID ), 'Nothing changed.' );
	}

	public function test_a_support_temp_user_is_refused() {
		$user = $this->editor();
		update_user_meta( $user->ID, 'happyaccess_temp_user', 1 );

		foreach ( array_merge( array( 'recheck' ), $this->change_routes() ) as $route ) {
			$this->assertSame( 403, $this->call( $route, array( 'password' => self::PASSWORD ) )->get_status(), $route );
		}
		$this->assertSame( '', get_user_meta( $user->ID, UserState::META_RECHECK, true ) );
	}

	public function test_the_password_recheck_sets_the_timestamp_and_every_response_is_no_store() {
		$user = $this->editor();

		$response = $this->call( 'recheck', array( 'password' => self::PASSWORD ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Clock::now(), (int) get_user_meta( $user->ID, UserState::META_RECHECK, true ) );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );

		wp_set_current_user( 0 );
		$refused = $this->call( 'app/begin' );
		$this->assertSame( 401, $refused->get_status() );
		$this->assertStringContainsString( 'no-store', $refused->get_headers()['Cache-Control'], 'Refusals are no-store too.' );
	}

	public function test_a_wrong_password_counts_against_the_ip_limit() {
		$user = $this->editor();
		$max  = (int) Settings::get( 'security.max_attempts' );

		for ( $i = 0; $i < $max; $i++ ) {
			$response = $this->call( 'recheck', array( 'password' => 'wrong' ) );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'happyaccess_recheck_failed', $this->code_of( $response ) );
		}
		$locked = $this->call( 'recheck', array( 'password' => self::PASSWORD ) );
		$this->assertSame( 429, $locked->get_status(), 'Past the limit even the right password waits.' );
		$this->assertSame( '', get_user_meta( $user->ID, UserState::META_RECHECK, true ) );
	}

	public function test_an_app_or_backup_code_passes_the_recheck_once() {
		$user   = $this->editor();
		$secret = Totp::new_secret();
		UserState::enable_app( $user->ID, $secret );
		$codes = BackupCodes::generate( $user->ID );

		$this->assertSame( 200, $this->call( 'recheck', array( 'code' => $this->app_code( $secret ) ) )->get_status() );
		$this->assertSame( 403, $this->call( 'recheck', array( 'code' => $this->app_code( $secret ) ) )->get_status(), 'The same app code is used up.' );

		$this->assertSame( 200, $this->call( 'recheck', array( 'code' => $codes[0] ) )->get_status() );
		$this->assertSame( 9, BackupCodes::remaining( $user->ID ), 'The backup code is used up.' );
	}

	public function test_app_begin_confirm_and_disable_round_trip() {
		$user = $this->editor();
		$this->recheck();

		$begin = $this->call( 'app/begin' );
		$this->assertSame( 200, $begin->get_status() );
		$this->assertStringContainsString( 'no-store', $begin->get_headers()['Cache-Control'] );
		$data   = $begin->get_data();
		$secret = $data['secret'];
		$this->assertSame( 32, strlen( $secret ) );
		$this->assertStringStartsWith( 'otpauth://totp/', $data['uri'] );
		$this->assertStringContainsString( 'secret=' . $secret, $data['uri'] );
		$this->assertFalse( UserState::app_enabled( $user->ID ), 'Nothing is on before the first code.' );

		global $wpdb;
		$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . RestController::TRANSIENT . $user->ID ) );
		$this->assertNotSame( '', $held, 'The secret waits in a transient.' );
		$this->assertStringNotContainsString( $secret, $held, 'It waits encrypted.' );
		$timeout = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_timeout_' . RestController::TRANSIENT . $user->ID ) );
		$this->assertLessThanOrEqual( time() + 600, $timeout );

		$wrong = $this->call( 'app/confirm', array( 'code' => '000000' === $this->app_code( $secret ) ? '111111' : '000000' ) );
		$this->assertSame( 400, $wrong->get_status() );
		$this->assertFalse( UserState::app_enabled( $user->ID ) );

		$confirm = $this->call( 'app/confirm', array( 'code' => $this->app_code( $secret ) ) );
		$this->assertSame( 200, $confirm->get_status() );
		$this->assertStringContainsString( 'no-store', $confirm->get_headers()['Cache-Control'] );
		$this->assertTrue( UserState::app_enabled( $user->ID ) );
		$this->assertSame( $secret, UserState::totp_secret( $user->ID ) );
		$this->assertCount( 10, $confirm->get_data()['codes'], 'The first method makes the backup codes.' );
		$this->assertSame( 10, BackupCodes::remaining( $user->ID ) );
		$this->assertSame( '', (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . RestController::TRANSIENT . $user->ID ) ), 'The held secret is gone.' );

		$again = $this->call( 'app/confirm', array( 'code' => $this->app_code( $secret, 1 ) ) );
		$this->assertSame( 400, $again->get_status(), 'Confirm works once per begin.' );

		$off = $this->call( 'app/disable' );
		$this->assertSame( 200, $off->get_status() );
		$this->assertFalse( UserState::app_enabled( $user->ID ) );
	}

	public function test_a_second_method_keeps_the_saved_backup_codes() {
		$user = $this->editor();
		UserState::enable_email( $user->ID );
		$old = BackupCodes::generate( $user->ID );
		$this->recheck();

		$secret  = $this->call( 'app/begin' )->get_data()['secret'];
		$confirm = $this->call( 'app/confirm', array( 'code' => $this->app_code( $secret ) ) );
		$this->assertSame( array(), $confirm->get_data()['codes'] );
		$this->assertNotSame( '', BackupCodes::match_code( $user->ID, $old[0] ), 'The old codes still work.' );
	}

	public function test_email_enable_and_disable() {
		$user = $this->editor();
		$this->recheck();

		$on = $this->call( 'email/enable' );
		$this->assertSame( 200, $on->get_status() );
		$this->assertTrue( UserState::email_enabled( $user->ID ) );
		$this->assertCount( 10, $on->get_data()['codes'] );

		$this->assertSame( 200, $this->call( 'email/disable' )->get_status() );
		$this->assertFalse( UserState::email_enabled( $user->ID ) );
		$this->assertSame( 0, BackupCodes::remaining( $user->ID ), 'With no method left the backup codes go too.' );
	}

	public function test_backup_regenerate_replaces_the_old_codes() {
		$user = $this->editor();
		UserState::enable_email( $user->ID );
		$old = BackupCodes::generate( $user->ID );
		$this->recheck();

		$response = $this->call( 'backup/regenerate' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
		$new = $response->get_data()['codes'];
		$this->assertCount( 10, $new );
		$this->assertSame( '', BackupCodes::match_code( $user->ID, $old[0] ), 'An old code stops working.' );
		$this->assertNotSame( '', BackupCodes::match_code( $user->ID, $new[0] ) );
		$this->assertCount( 2, AuditLog::query( array( 'event' => 'twostep_backup_regenerated' ) )['items'], 'The first set and the new one are each logged.' );
	}

	public function test_backup_codes_need_a_method_first() {
		$this->editor();
		$this->recheck();
		$this->assertSame( 409, $this->call( 'backup/regenerate' )->get_status() );
	}

	public function test_a_required_user_cannot_turn_off_the_last_method() {
		$this->set_policy( array( 'editor' => 'required' ) );
		$user = $this->editor();
		UserState::enable_app( $user->ID, Totp::new_secret() );
		$this->recheck();

		$refused = $this->call( 'app/disable' );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'Your role needs two-step login.', $refused->get_data()['message'] );
		$this->assertTrue( UserState::app_enabled( $user->ID ) );

		UserState::enable_email( $user->ID );
		$this->assertSame( 200, $this->call( 'app/disable' )->get_status(), 'Another method is on, so this one can go.' );
		$this->assertSame( 403, $this->call( 'email/disable' )->get_status(), 'Now email is the last one.' );
		$this->assertTrue( UserState::email_enabled( $user->ID ) );
	}

	public function test_a_role_with_two_step_off_cannot_turn_a_method_on() {
		$this->set_policy( array( 'editor' => 'off' ) );
		$user = $this->editor();
		$this->recheck();

		$this->assertSame( 403, $this->call( 'app/begin' )->get_status() );
		$this->assertSame( 403, $this->call( 'email/enable' )->get_status() );
		$this->assertFalse( UserState::is_enabled( $user->ID ) );

		UserState::enable_email( $user->ID );
		$this->assertSame( 200, $this->call( 'email/disable' )->get_status(), 'A method that is on can still be turned off.' );
	}
}
