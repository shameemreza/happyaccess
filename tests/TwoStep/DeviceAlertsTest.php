<?php
/**
 * New device login alert tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\Challenge;
use HappyAccess\Features\TwoStep\DeviceAlerts;
use HappyAccess\Features\TwoStep\Feature;
use HappyAccess\Features\TwoStep\Profile;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;
use HappyAccess\Login\Router;

class DeviceAlertsTest extends WP_UnitTestCase {

	const PASSWORD = 'correct horse battery';

	const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

	/**
	 * Cookies the alerts tried to send: name, value and options.
	 *
	 * @var array
	 */
	private $cookies = array();

	/**
	 * Mails caught before they are sent.
	 *
	 * @var array
	 */
	private $mails = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
		Challenge::reset();
		Clock::freeze( 1790000000 );

		$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
		$_SERVER['HTTP_USER_AGENT'] = self::CHROME_MAC;
		$_COOKIE                    = array();
		$_REQUEST                   = array();

		$this->cookies = array();
		$this->mails   = array();

		add_filter( 'send_auth_cookies', '__return_false' );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		add_filter( 'happyaccess_twostep_send_cookie', array( $this, 'catch_cookie' ), 10, 4 );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			$_COOKIE  = array();
			$_REQUEST = array();
			unset( $_SERVER['HTTP_USER_AGENT'] );
			if ( class_exists( DeviceAlerts::class, false ) ) {
				DeviceAlerts::reset();
			}
			Clock::freeze( null );
			Router::reset();
			Challenge::reset();
		} finally {
			parent::tear_down();
		}
	}

	public function catch_mail( $null, $atts ) {
		unset( $null );
		$this->mails[] = $atts;
		return true;
	}

	public function catch_cookie( $handled, $name, $value, $options ) {
		unset( $handled );
		if ( DeviceAlerts::COOKIE === $name ) {
			$this->cookies[] = array(
				'name'    => $name,
				'value'   => $value,
				'options' => $options,
			);
		}
		return true;
	}

	/**
	 * Turns the feature on and adds its hooks.
	 *
	 * @return void
	 */
	private function turn_on() {
		Features::set( 'two_step', true );
		Feature::register();
	}

	private function admin() {
		return self::factory()->user->create_and_get(
			array(
				'role'      => 'administrator',
				'user_pass' => self::PASSWORD,
			)
		);
	}

	/**
	 * Fires wp_login as every login path does, then sends the queued mail.
	 *
	 * @param WP_User $user The user.
	 * @return void
	 */
	private function log_in( WP_User $user ) {
		do_action( 'wp_login', $user->user_login, $user );
		DeviceAlerts::flush_queue();
	}

	/**
	 * Logs in once with no cookie, so the user has a known device.
	 *
	 * @param WP_User $user The user.
	 * @return string The device id in the cookie.
	 */
	private function first_login( WP_User $user ) {
		$this->log_in( $user );
		$this->assertCount( 1, $this->cookies );
		$id            = $this->cookies[0]['value'];
		$this->cookies = array();
		return $id;
	}

	private function devices( $user_id ) {
		return get_user_meta( $user_id, DeviceAlerts::META, true );
	}

	private function alert_rows( $user_id ) {
		global $wpdb;
		$table = Installer::table( 'logs' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s AND user_id = %d", 'new_device_login', $user_id ), ARRAY_A );
	}

	/**
	 * Seeds a known device list, so the next login without a cookie is a new device.
	 *
	 * @param int $user_id User id.
	 * @param int $count   Devices.
	 * @return void
	 */
	private function seed_devices( $user_id, $count = 1 ) {
		$devices = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$devices[] = array(
				'hash' => Codes::hash_key( 'seed-' . $i ),
				'seen' => Clock::now() - 1000 + $i,
			);
		}
		update_user_meta( $user_id, DeviceAlerts::META, $devices );
	}

	public function test_the_first_login_only_remembers_the_device() {
		$this->turn_on();
		$user = $this->admin();

		$this->log_in( $user );

		$this->assertSame( array(), $this->mails );
		$this->assertCount( 1, $this->cookies );
		$cookie = $this->cookies[0];
		$this->assertSame( 43, strlen( $cookie['value'] ) );
		$this->assertTrue( $cookie['options']['httponly'] );
		$this->assertSame( 'Lax', $cookie['options']['samesite'] );
		$this->assertSame( COOKIEPATH, $cookie['options']['path'] );
		$this->assertSame( is_ssl(), $cookie['options']['secure'] );
		$this->assertSame( Clock::now() + YEAR_IN_SECONDS, $cookie['options']['expires'] );

		$devices = $this->devices( $user->ID );
		$this->assertCount( 1, $devices );
		$this->assertSame( Codes::hash_key( $cookie['value'] ), $devices[0]['hash'] );
		$this->assertSame( Clock::now(), $devices[0]['seen'] );
		$this->assertNotContains( $cookie['value'], array_column( $devices, 'hash' ), 'Only the hash is stored.' );
		$this->assertSame( array(), $this->alert_rows( $user->ID ) );
	}

	public function test_a_known_browser_updates_its_last_seen_time_and_the_cookie_expiry() {
		$this->turn_on();
		$user = $this->admin();
		$id   = $this->first_login( $user );

		Clock::freeze( Clock::now() + 600 );
		$_COOKIE[ DeviceAlerts::COOKIE ] = $id;
		$this->log_in( $user );

		$this->assertSame( array(), $this->mails );
		$this->assertCount( 1, $this->cookies, 'The same id is sent again, so a browser used every day never ages out.' );
		$this->assertSame( $id, $this->cookies[0]['value'] );
		$this->assertSame( Clock::now() + YEAR_IN_SECONDS, $this->cookies[0]['options']['expires'] );
		$this->assertTrue( $this->cookies[0]['options']['httponly'] );
		$devices = $this->devices( $user->ID );
		$this->assertCount( 1, $devices );
		$this->assertSame( Clock::now(), $devices[0]['seen'] );
		$this->assertSame( array(), $this->alert_rows( $user->ID ) );
	}

	public function test_a_new_browser_gets_a_cookie_an_email_and_a_log_row() {
		$this->turn_on();
		$user = $this->admin();
		$this->first_login( $user );

		$this->log_in( $user );

		$this->assertCount( 1, $this->cookies );
		$this->assertCount( 2, $this->devices( $user->ID ) );
		$this->assertCount( 1, $this->mails );
		$mail = $this->mails[0];
		$this->assertSame( $user->user_email, $mail['to'] );
		$this->assertStringEndsWith( '] New login to your account', $mail['subject'] );
		$this->assertStringContainsString( 'Chrome on macOS', $mail['message'] );
		$this->assertStringContainsString( '203.0.113.9', $mail['message'] );
		$this->assertStringContainsString( esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), Clock::now() ) ), $mail['message'] );
		$this->assertStringContainsString( esc_html( "If this wasn't you, change your password now." ), $mail['message'] );
		$this->assertStringContainsString( esc_url( wp_lostpassword_url() ), $mail['message'] );

		$rows = $this->alert_rows( $user->ID );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'two_step', $rows[0]['feature'] );
		$this->assertSame( '203.0.113.9', $rows[0]['ip_address'] );
		$this->assertSame( 'Chrome on macOS', json_decode( $rows[0]['metadata'], true )['browser'] );
	}

	public function test_an_unknown_well_formed_id_is_a_new_device_and_keeps_its_id() {
		$this->turn_on();
		$user = $this->admin();
		$this->first_login( $user );

		$shared                          = Codes::link_key();
		$_COOKIE[ DeviceAlerts::COOKIE ] = $shared;
		$this->log_in( $user );

		$this->assertCount( 1, $this->mails );
		$this->assertCount( 1, $this->alert_rows( $user->ID ) );
		$this->assertSame( array(), $this->cookies, 'The id another account set stays, so that account still knows the browser.' );
		$this->assertContains( Codes::hash_key( $shared ), array_column( $this->devices( $user->ID ), 'hash' ) );
	}

	public function test_a_malformed_cookie_gets_a_fresh_id() {
		$this->turn_on();
		$user = $this->admin();
		$this->first_login( $user );

		$_COOKIE[ DeviceAlerts::COOKIE ] = 'not-a-device-id';
		$this->log_in( $user );

		$this->assertCount( 1, $this->mails );
		$this->assertCount( 1, $this->cookies );
		$this->assertSame( 43, strlen( $this->cookies[0]['value'] ) );
		$this->assertNotSame( 'not-a-device-id', $this->cookies[0]['value'] );
	}

	public function test_the_first_login_with_an_unknown_id_remembers_that_id() {
		$this->turn_on();
		$user                            = $this->admin();
		$shared                          = Codes::link_key();
		$_COOKIE[ DeviceAlerts::COOKIE ] = $shared;

		$this->log_in( $user );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->cookies );
		$this->assertSame( array( Codes::hash_key( $shared ) ), array_column( $this->devices( $user->ID ), 'hash' ) );
	}

	public function test_two_accounts_taking_turns_on_one_browser_get_one_alert_each() {
		$this->turn_on();
		$first  = $this->admin();
		$second = $this->admin();
		$this->seed_devices( $first->ID );
		$this->seed_devices( $second->ID );

		$values = array();
		for ( $round = 0; $round < 4; $round++ ) {
			foreach ( array( $first, $second ) as $user ) {
				Clock::freeze( Clock::now() + 60 );
				$this->cookies = array();
				$this->log_in( $user );
				// The browser keeps whatever cookie the site sent, like a cookie jar.
				foreach ( $this->cookies as $cookie ) {
					$_COOKIE[ DeviceAlerts::COOKIE ] = $cookie['value'];
					$values[]                        = $cookie['value'];
				}
			}
		}

		$this->assertCount( 1, $this->alert_rows( $first->ID ) );
		$this->assertCount( 1, $this->alert_rows( $second->ID ) );
		$this->assertCount( 2, $this->mails );
		$this->assertNotEmpty( $values );
		$this->assertCount( 1, array_unique( $values ), 'The cookie value never changes.' );
		$this->assertSame( $_COOKIE[ DeviceAlerts::COOKIE ], $values[0] );
	}

	public function test_a_password_login_through_wp_signon_is_watched() {
		$this->turn_on();
		$user = $this->admin();
		$this->seed_devices( $user->ID );

		$result = wp_signon(
			array(
				'user_login'    => $user->user_login,
				'user_password' => self::PASSWORD,
			)
		);
		DeviceAlerts::flush_queue();

		$this->assertInstanceOf( WP_User::class, $result );
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_user_without_two_step_gets_the_tip_with_a_profile_link() {
		$this->turn_on();
		$user = $this->admin();
		$this->seed_devices( $user->ID );

		$this->log_in( $user );

		$this->assertStringContainsString( 'Turn on two-step login to keep your account safe.', $this->mails[0]['message'] );
		$this->assertStringContainsString( esc_url( Profile::url_for( $user ) ), $this->mails[0]['message'] );
	}

	public function test_the_plain_text_part_has_the_same_details() {
		$site_name = 'Test site';
		$time      = 'October 9, 2026 10:00 am';
		$device    = 'Firefox on Linux';
		$ip        = '203.0.113.0';
		$reset_url = wp_lostpassword_url();
		$setup_url = Profile::url();
		ob_start();
		include HAPPYACCESS_PLUGIN_DIR . 'templates/emails/new-device-text.php';
		$text = (string) ob_get_clean();

		foreach ( array( $time, $device, $ip, $reset_url, $setup_url, "If this wasn't you, change your password now.", 'Turn on two-step login to keep your account safe.' ) as $part ) {
			$this->assertStringContainsString( $part, $text );
		}

		$setup_url = '';
		ob_start();
		include HAPPYACCESS_PLUGIN_DIR . 'templates/emails/new-device-text.php';
		$this->assertStringNotContainsString( 'Turn on two-step login', (string) ob_get_clean() );
	}

	public function test_a_user_with_two_step_gets_no_tip() {
		$this->turn_on();
		$user = $this->admin();
		UserState::enable_app( $user->ID, Totp::new_secret() );
		$this->seed_devices( $user->ID );

		$this->log_in( $user );

		$this->assertCount( 1, $this->mails );
		$this->assertStringNotContainsString( 'Turn on two-step login', $this->mails[0]['message'] );
	}

	public function test_a_role_not_in_the_list_never_gets_an_email() {
		$this->turn_on();
		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$this->seed_devices( $editor->ID );

		$this->log_in( $editor );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->cookies );
		$this->assertCount( 1, $this->devices( $editor->ID ), 'Nothing is remembered either.' );
	}

	public function test_the_saved_role_list_decides() {
		$this->turn_on();
		Settings::update( array( 'two_step' => array( 'device_alert_roles' => array( 'editor' ) ) ) );
		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$admin  = $this->admin();
		$this->seed_devices( $editor->ID );
		$this->seed_devices( $admin->ID );

		$this->log_in( $editor );
		$this->log_in( $admin );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( $editor->user_email, $this->mails[0]['to'] );
	}

	public function test_an_empty_saved_list_sends_nothing() {
		$this->turn_on();
		Settings::update( array( 'two_step' => array( 'device_alert_roles' => array() ) ) );
		$user = $this->admin();
		$this->seed_devices( $user->ID );

		$this->log_in( $user );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->cookies );
	}

	public function test_a_support_temp_user_is_skipped() {
		$this->turn_on();
		$user = $this->admin();
		update_user_meta( $user->ID, 'happyaccess_temp_user', 1 );
		$this->seed_devices( $user->ID );

		$this->log_in( $user );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->cookies );
	}

	public function test_an_application_password_login_is_skipped() {
		$this->turn_on();
		$user = $this->admin();
		$this->seed_devices( $user->ID );

		Challenge::note_application_password( $user );
		$this->log_in( $user );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->cookies );
	}

	public function test_an_xmlrpc_login_is_skipped() {
		$this->turn_on();
		$user = $this->admin();
		$this->seed_devices( $user->ID );

		Challenge::set_context( 'xmlrpc' );
		$this->log_in( $user );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->cookies );
	}

	public function test_an_interim_login_is_skipped() {
		$this->turn_on();
		$user = $this->admin();
		$this->seed_devices( $user->ID );

		$_REQUEST['interim-login'] = '1';
		$this->log_in( $user );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->cookies );
	}

	public function test_it_keeps_twenty_devices_at_most_and_drops_the_oldest() {
		$this->turn_on();
		$user = $this->admin();
		$this->seed_devices( $user->ID, 20 );

		$this->log_in( $user );

		$devices = $this->devices( $user->ID );
		$this->assertCount( 20, $devices );
		$hashes = array_column( $devices, 'hash' );
		$this->assertNotContains( Codes::hash_key( 'seed-0' ), $hashes, 'The oldest went.' );
		$this->assertContains( Codes::hash_key( 'seed-1' ), $hashes );
		$this->assertContains( Codes::hash_key( $this->cookies[0]['value'] ), $hashes );
	}

	public function test_it_sends_three_emails_an_hour_at_most() {
		$this->turn_on();
		$user = $this->admin();
		$this->seed_devices( $user->ID );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->log_in( $user );
		}
		$this->assertCount( 3, $this->mails );
		$this->assertCount( 5, $this->alert_rows( $user->ID ), 'Every new device is still logged.' );

		Clock::freeze( Clock::now() + HOUR_IN_SECONDS + 1 );
		$this->log_in( $user );
		$this->assertCount( 4, $this->mails );
	}

	public function test_the_ip_is_anonymized_when_the_setting_is_on() {
		$this->turn_on();
		Settings::update( array( 'privacy' => array( 'anonymize_ip' => true ) ) );
		$user = $this->admin();
		$this->seed_devices( $user->ID );

		$this->log_in( $user );

		$this->assertStringContainsString( '203.0.113.0', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( '203.0.113.9', $this->mails[0]['message'] );
	}

	public function test_nothing_is_added_while_two_step_is_off() {
		Features::set( 'two_step', false );
		Feature::register();
		$user = $this->admin();

		do_action( 'wp_login', $user->user_login, $user );

		$this->assertFalse( has_action( 'wp_login', array( DeviceAlerts::class, 'on_login' ) ) );
		$this->assertFalse( metadata_exists( 'user', $user->ID, DeviceAlerts::META ) );
		$this->assertSame( array(), $this->cookies );
	}

	public function test_the_hook_runs_late_and_register_can_run_twice() {
		$this->turn_on();
		Feature::register();
		$this->assertSame( PHP_INT_MAX, has_action( 'wp_login', array( DeviceAlerts::class, 'on_login' ) ) );
	}

	public function provide_agents() {
		return array(
			'chrome on mac'     => array( self::CHROME_MAC, 'Chrome on macOS' ),
			'edge on windows'   => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0', 'Edge on Windows' ),
			'firefox on linux'  => array( 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox on Linux' ),
			'safari on iphone'  => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'Safari on iOS' ),
			'chrome on android' => array( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36', 'Chrome on Android' ),
			'opera on windows'  => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/113.0.0.0', 'Opera on Windows' ),
			'a script'          => array( 'curl/8.7.1', 'Unknown browser' ),
			'nothing'           => array( '', 'Unknown browser' ),
		);
	}

	/**
	 * @dataProvider provide_agents
	 */
	public function test_the_browser_name( $agent, $expected ) {
		$this->assertSame( $expected, DeviceAlerts::browser_name( $agent ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_a_super_admin_counts_as_an_administrator() {
		$this->turn_on();
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		grant_super_admin( $user->ID );
		$this->seed_devices( $user->ID );

		$this->log_in( $user );

		$this->assertCount( 1, $this->mails );
	}
}
