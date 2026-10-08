<?php
/**
 * Settings, setup, catalog and lock route tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Settings;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Rest\SettingsController;

require_once __DIR__ . '/RestTestCase.php';

class SettingsControllerTest extends RestTestCase {

	const SECRET = 'happyaccess_recaptcha_secret_key';

	/**
	 * Every route, with params that pass validation so only the permission
	 * check decides.
	 *
	 * @return array
	 */
	public function routes_provider() {
		return array(
			'get settings'  => array( 'GET', '/settings', array() ),
			'save settings' => array( 'POST', '/settings', array( 'security' => array( 'max_attempts' => 9 ) ) ),
			'setup'         => array(
				'POST',
				'/setup',
				array(
					'features' => array( 'support_access' => true ),
					'consent'  => true,
				),
			),
			'catalog'       => array( 'GET', '/catalog', array() ),
			'lock'          => array( 'POST', '/lock', array() ),
		);
	}

	/**
	 * @dataProvider routes_provider
	 */
	public function test_logged_out_gets_401( $method, $path, $params ) {
		Grants::create( array( 'label' => 'Target' ) );
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( $method, $path, $params )->get_status() );
		$this->assertSame( 1, count( Grants::list_current() ) );
		$this->assertSame( 5, Settings::get( 'security.max_attempts' ) );
		$this->assertSame( '', Settings::get( 'support.consent_given_at' ) );
	}

	/**
	 * @dataProvider routes_provider
	 */
	public function test_protected_temp_user_gets_403( $method, $path, $params ) {
		$this->as_temp_user();

		$this->assertSame( 403, $this->request( $method, $path, $params )->get_status() );
		$this->assertSame( 5, Settings::get( 'security.max_attempts' ) );
		$this->assertSame( '', Settings::get( 'support.consent_given_at' ) );
		$this->assertSame( 1, count( Grants::list_current() ) );
	}

	/**
	 * @dataProvider routes_provider
	 */
	public function test_full_temp_user_gets_403( $method, $path, $params ) {
		$this->as_temp_user(
			array(
				'level'        => 'full',
				'confirm_full' => true,
			)
		);

		$this->assertSame( 403, $this->request( $method, $path, $params )->get_status() );
		$this->assertSame( 5, Settings::get( 'security.max_attempts' ) );
		$this->assertSame( 1, count( Grants::list_current() ) );
	}

	public function test_get_hides_the_secret_and_reports_it_is_set() {
		$data = $this->request( 'GET', '/settings' )->get_data();
		$this->assertFalse( $data['recaptcha_secret_set'] );

		update_option( self::SECRET, 'sk_live_abc123', false );
		$response = $this->request( 'GET', '/settings' );
		$data     = $response->get_data();

		$this->assertTrue( $data['recaptcha_secret_set'] );
		$this->assertSame( 5, $data['security']['max_attempts'] );
		$this->assertArrayNotHasKey( 'recaptcha_secret_key', $data['security'] );
		$this->assertStringNotContainsString( 'sk_live_abc123', wp_json_encode( $data ) );
	}

	public function test_save_changes_only_what_was_sent() {
		$response = $this->request(
			'POST',
			'/settings',
			array(
				'privacy' => array( 'retention_days' => 90 ),
				'bogus'   => array( 'x' => 1 ),
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 90, $data['privacy']['retention_days'] );
		$this->assertSame( 5, $data['security']['max_attempts'] );
		$this->assertArrayNotHasKey( 'bogus', $data );
		$this->assertSame( 90, Settings::get( 'privacy.retention_days' ) );
	}

	public function test_save_clamps_out_of_range_values() {
		$data = $this->request( 'POST', '/settings', array( 'security' => array( 'max_attempts' => 999 ) ) )->get_data();

		$this->assertSame( 20, $data['security']['max_attempts'] );
		$this->assertSame( 20, Settings::get( 'security.max_attempts' ) );
	}

	public function test_save_rejects_a_non_object_group() {
		$response = $this->request( 'POST', '/settings', array( 'security' => 'nope' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 5, Settings::get( 'security.max_attempts' ) );
	}

	public function test_save_cannot_fake_consent() {
		$this->request(
			'POST',
			'/settings',
			array(
				'support' => array(
					'default_duration' => 7200,
					'consent_given_at' => '2026-01-01 00:00:00',
					'consent_user_id'  => 99,
				),
			)
		);

		$this->assertSame( 7200, Settings::get( 'support.default_duration' ) );
		$this->assertSame( '', Settings::get( 'support.consent_given_at' ) );
		$this->assertSame( 0, Settings::get( 'support.consent_user_id' ) );
	}

	public function test_turning_support_access_off_revokes_current_passes() {
		Grants::create( array( 'label' => 'One' ) );
		Grants::create( array( 'label' => 'Two' ) );

		$response = $this->request( 'POST', '/settings', array( 'features' => array( 'support_access' => false ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $data['revoked'] );
		$this->assertFalse( $data['features']['support_access'] );
		$this->assertSame( array(), Grants::list_current() );
	}

	public function test_saving_other_settings_does_not_revoke_or_report_revoked() {
		Grants::create( array( 'label' => 'One' ) );

		$data = $this->request( 'POST', '/settings', array( 'features' => array( 'two_step' => true ) ) )->get_data();

		$this->assertArrayNotHasKey( 'revoked', $data );
		$this->assertCount( 1, Grants::list_current() );
	}

	public function test_secret_is_written_with_autoload_off_and_never_returned() {
		global $wpdb;

		$data = $this->request( 'POST', '/settings', array( 'recaptcha_secret_key' => 'sk_live_abc123' ) )->get_data();

		$this->assertTrue( $data['recaptcha_secret_set'] );
		$this->assertStringNotContainsString( 'sk_live_abc123', wp_json_encode( $data ) );
		$this->assertSame( 'sk_live_abc123', get_option( self::SECRET ) );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", self::SECRET ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_secret_inside_the_security_group_is_stored_and_not_kept_in_settings() {
		$data = $this->request( 'POST', '/settings', array( 'security' => array( 'recaptcha_secret_key' => 'sk_nested' ) ) )->get_data();

		$this->assertTrue( $data['recaptcha_secret_set'] );
		$this->assertSame( 'sk_nested', get_option( self::SECRET ) );
		$this->assertStringNotContainsString( 'sk_nested', wp_json_encode( get_option( Settings::OPTION ) ) );
	}

	public function test_empty_secret_clears_it() {
		update_option( self::SECRET, 'sk_live_abc123', false );

		$data = $this->request( 'POST', '/settings', array( 'recaptcha_secret_key' => '' ) )->get_data();

		$this->assertFalse( $data['recaptcha_secret_set'] );
		$this->assertFalse( get_option( self::SECRET, false ) );
	}

	public function test_bad_secret_type_is_refused_without_echoing_it() {
		$response = $this->request( 'POST', '/settings', array( 'recaptcha_secret_key' => array( 'leak-me-123' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringNotContainsString( 'leak-me-123', wp_json_encode( $response->get_data() ) );
		$this->assertFalse( get_option( self::SECRET, false ) );
	}

	public function test_needs_setup_until_consent_is_recorded() {
		$this->assertTrue( $this->request( 'GET', '/settings' )->get_data()['needs_setup'] );

		Settings::update( array( 'support' => array( 'consent_given_at' => '2026-01-01 00:00:00' ) ) );
		$this->assertFalse( $this->request( 'GET', '/settings' )->get_data()['needs_setup'] );
	}

	public function test_setup_without_consent_is_refused() {
		$response = $this->request( 'POST', '/setup', array( 'features' => array( 'support_access' => true ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'happyaccess_consent_required', $response->get_data()['code'] );
		$this->assertSame( 'Please confirm before giving anyone access.', $response->get_data()['message'] );
		$this->assertSame( '', Settings::get( 'support.consent_given_at' ) );
		$this->assertFalse( Settings::get( 'features.passwordless' ) );
	}

	public function test_setup_with_consent_records_time_and_user() {
		$response = $this->request(
			'POST',
			'/setup',
			array(
				'features' => array(
					'support_access' => true,
					'passwordless'   => true,
					'two_step'       => false,
				),
				'consent'  => true,
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['features']['passwordless'] );
		$this->assertFalse( $data['needs_setup'] );
		$this->assertSame( Clock::mysql(), Settings::get( 'support.consent_given_at' ) );
		$this->assertSame( $this->owner, Settings::get( 'support.consent_user_id' ) );
	}

	public function test_setup_with_support_access_off_needs_no_consent() {
		$response = $this->request( 'POST', '/setup', array( 'features' => array( 'support_access' => false ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['features']['support_access'] );
		$this->assertFalse( $data['needs_setup'] );
	}

	public function test_setup_rejects_unknown_feature_names() {
		$response = $this->request(
			'POST',
			'/setup',
			array(
				'features' => array( 'everything' => true ),
				'consent'  => true,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '', Settings::get( 'support.consent_given_at' ) );
	}

	public function test_setup_requires_features() {
		$this->assertSame( 400, $this->request( 'POST', '/setup', array( 'consent' => true ) )->get_status() );
	}

	public function test_catalog_returns_groups_and_presets() {
		$response = $this->request( 'GET', '/catalog' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $data['groups'] );
		$this->assertArrayHasKey( 'id', $data['groups'][0] );
		$this->assertArrayHasKey( 'administrator', $data['presets'] );
		$this->assertArrayHasKey( 'editor', $data['presets'] );
	}

	public function test_lock_revokes_every_grant_and_logs_it() {
		$first  = Grants::create( array( 'label' => 'One' ) )['id'];
		$second = Grants::create( array( 'label' => 'Two' ) )['id'];

		$response = $this->request( 'POST', '/lock' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'revoked' => 2 ), $response->get_data() );
		$this->assertSame( 'revoked', Grants::get( $first )['status'] );
		$this->assertSame( 'revoked', Grants::get( $second )['status'] );
		$this->assertSame( array(), Grants::list_current() );
		$this->assertSame( 1, AuditLog::query( array( 'event' => 'emergency_lock' ) )['total'] );
	}

	public function test_lock_with_no_passes_returns_zero() {
		$this->assertSame( array( 'revoked' => 0 ), $this->request( 'POST', '/lock' )->get_data() );
	}

	public function test_save_cannot_turn_support_access_on_before_setup() {
		Settings::update( array( 'features' => array( 'support_access' => false ) ) );

		$response = $this->request(
			'POST',
			'/settings',
			array(
				'features' => array( 'support_access' => true ),
				'security' => array( 'max_attempts' => 9 ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'happyaccess_consent_required', $response->get_data()['code'] );
		$this->assertSame( 'Please confirm before giving anyone access.', $response->get_data()['message'] );
		$this->assertFalse( Settings::get( 'features.support_access' ) );
		$this->assertSame( 5, Settings::get( 'security.max_attempts' ) );
	}

	public function test_save_turns_support_access_on_after_setup() {
		Settings::update(
			array(
				'features' => array( 'support_access' => false ),
				'support'  => array( 'consent_given_at' => '2026-01-01 00:00:00' ),
			)
		);

		$response = $this->request( 'POST', '/settings', array( 'features' => array( 'support_access' => true ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( Settings::get( 'features.support_access' ) );
	}

	public function test_save_that_leaves_support_access_on_needs_no_consent() {
		$response = $this->request(
			'POST',
			'/settings',
			array(
				'features' => array( 'support_access' => true ),
				'security' => array( 'max_attempts' => 9 ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 9, Settings::get( 'security.max_attempts' ) );
	}

	public function test_non_string_nested_secret_keeps_the_stored_secret() {
		update_option( self::SECRET, 'sk_live_abc123', false );

		// A list or null is refused outright; a number or boolean passes validation and is ignored as not text.
		$expected = array(
			array( array( 'x' ), 400 ),
			array( 123, 200 ),
			array( null, 400 ),
			array( true, 200 ),
		);
		foreach ( $expected as $case ) {
			list( $bad, $status ) = $case;
			$response             = $this->request( 'POST', '/settings', array( 'security' => array( 'recaptcha_secret_key' => $bad ) ) );
			$this->assertSame( $status, $response->get_status(), wp_json_encode( $bad ) );
			$this->assertSame( 'sk_live_abc123', get_option( self::SECRET ), wp_json_encode( $bad ) );
		}
	}

	/**
	 * One pass that is still current and one that ran out but was never revoked.
	 *
	 * @return void
	 */
	private function one_current_and_one_expired() {
		Grants::create( array( 'label' => 'Old', 'duration' => 3600 ) );
		Clock::freeze( 1790000000 + 7200 );
		Grants::create( array( 'label' => 'Now' ) );
	}

	public function test_lock_counts_only_current_passes() {
		$this->one_current_and_one_expired();

		$this->assertSame( array( 'revoked' => 1 ), $this->request( 'POST', '/lock' )->get_data() );
	}

	public function test_turning_support_access_off_counts_only_current_passes() {
		$this->one_current_and_one_expired();

		$data = $this->request( 'POST', '/settings', array( 'features' => array( 'support_access' => false ) ) )->get_data();

		$this->assertSame( 1, $data['revoked'] );
	}

	/**
	 * settings_changed rows, newest first.
	 *
	 * @return array
	 */
	private function settings_rows() {
		return AuditLog::query( array( 'event' => 'settings_changed' ) )['items'];
	}

	public function test_a_save_logs_the_changed_key_names_without_values() {
		$this->request(
			'POST',
			'/settings',
			array(
				'security'             => array(
					'max_attempts' => 9,
					'proxy_header' => '',
				),
				'privacy'              => array( 'retention_days' => 45 ),
				'recaptcha_secret_key' => 'sk_live_value',
			)
		);

		$rows = $this->settings_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'core', $rows[0]['feature'] );
		$this->assertSame( $this->owner, (int) $rows[0]['user_id'] );
		$this->assertSame( array( 'privacy.retention_days', 'security.max_attempts', 'security.recaptcha_secret_key' ), $rows[0]['meta']['keys'] );
		$encoded = wp_json_encode( $rows[0] );
		$this->assertStringNotContainsString( 'sk_live_value', $encoded );
		$this->assertStringNotContainsString( '45', $rows[0]['summary'] );
		$this->assertContains( 'settings_changed', \HappyAccess\Core\Privacy::ADMIN_EVENTS );
	}

	public function test_a_save_that_changes_nothing_logs_nothing() {
		$this->request( 'POST', '/settings', array( 'security' => array( 'max_attempts' => Settings::get( 'security.max_attempts' ) ) ) );
		update_option( SettingsController::SECRET_OPTION, 'same', false );
		$this->request( 'POST', '/settings', array( 'recaptcha_secret_key' => 'same' ) );

		$this->assertSame( array(), $this->settings_rows() );
	}

	public function test_turning_logging_off_is_the_last_row_logged() {
		$this->request( 'POST', '/settings', array( 'privacy' => array( 'logging' => false ) ) );
		$this->request( 'POST', '/settings', array( 'privacy' => array( 'retention_days' => 60 ) ) );

		$rows = $this->settings_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'privacy.logging' ), $rows[0]['meta']['keys'] );
		$this->assertFalse( Settings::get( 'privacy.logging' ) );

		$this->request( 'POST', '/settings', array( 'privacy' => array( 'logging' => true ) ) );
		$rows = $this->settings_rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'privacy.logging' ), $rows[0]['meta']['keys'] );
	}

	public function test_clearing_the_secret_logs_its_key_name() {
		update_option( SettingsController::SECRET_OPTION, 'old', false );
		$this->request( 'POST', '/settings', array( 'recaptcha_secret_key' => '' ) );

		$rows = $this->settings_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'security.recaptcha_secret_key' ), $rows[0]['meta']['keys'] );
	}

	public function provide_bad_groups() {
		return array(
			'a list'               => array( array( 'security' => array( 1, 2 ) ) ),
			'a string'             => array( array( 'privacy' => 'nope' ) ),
			'a number'             => array( array( 'support' => 5 ) ),
			'a list in a value'    => array( array( 'security' => array( 'max_attempts' => array( 7 ) ) ) ),
			'an object in a value' => array( array( 'privacy' => array( 'retention_days' => array( 'x' => 1 ) ) ) ),
			'null in a value'      => array( array( 'security' => array( 'max_attempts' => null ) ) ),
			'nested group value'   => array( array( 'features' => array( 'support_access' => array( 'on' => true ) ) ) ),
		);
	}

	/**
	 * @dataProvider provide_bad_groups
	 */
	public function test_save_refuses_groups_that_are_not_objects_of_plain_values( $params ) {
		$response = $this->request( 'POST', '/settings', $params );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 5, Settings::get( 'security.max_attempts' ) );
		$this->assertSame( 30, Settings::get( 'privacy.retention_days' ) );
		$this->assertSame( array(), $this->settings_rows() );
	}

	public function test_save_takes_the_passwordless_group_with_its_two_maps() {
		$response = $this->request(
			'POST',
			'/settings',
			array(
				'passwordless' => array(
					'code_lifetime' => 120,
					'show_on'       => array( 'woo_checkout' => false ),
					'role_policy'   => array(
						'administrator' => 'email_only',
						'not_a_role'    => 'email_only',
					),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 300, Settings::get( 'passwordless.code_lifetime' ) );
		$this->assertFalse( Settings::get( 'passwordless.show_on.woo_checkout' ) );
		$this->assertTrue( Settings::get( 'passwordless.show_on.wp_login' ) );
		$this->assertSame( array( 'administrator' => 'email_only' ), Settings::get( 'passwordless.role_policy' ) );
		$data = $response->get_data();
		$this->assertSame( array( 'administrator' => 'email_only' ), $data['passwordless']['role_policy'] );
		$this->assertSame( 300, $data['passwordless']['code_lifetime'] );
	}

	public function test_save_and_read_expose_the_toggle_style() {
		$read = $this->request( 'GET', '/settings' );
		$this->assertSame( 'link', $read->get_data()['passwordless']['toggle_style'] );

		$response = $this->request( 'POST', '/settings', array( 'passwordless' => array( 'toggle_style' => 'button' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'button', Settings::get( 'passwordless.toggle_style' ) );
		$this->assertSame( 'button', $response->get_data()['passwordless']['toggle_style'] );

		$response = $this->request( 'POST', '/settings', array( 'passwordless' => array( 'toggle_style' => 'huge' ) ) );
		$this->assertSame( 'link', $response->get_data()['passwordless']['toggle_style'] );
	}

	public function test_save_takes_the_two_step_group_with_its_role_map() {
		$response = $this->request(
			'POST',
			'/settings',
			array(
				'two_step' => array(
					'grace_type'   => 'days',
					'grace_days'   => 99,
					'block_xmlrpc' => false,
					'role_policy'  => array(
						'administrator' => 'required',
						'not_a_role'    => 'required',
					),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'days', Settings::get( 'two_step.grace_type' ) );
		$this->assertSame( 30, Settings::get( 'two_step.grace_days' ) );
		$this->assertFalse( Settings::get( 'two_step.block_xmlrpc' ) );
		$this->assertSame( array( 'administrator' => 'required' ), Settings::get( 'two_step.role_policy' ) );
		$data = $response->get_data();
		$this->assertSame( array( 'administrator' => 'required' ), $data['two_step']['role_policy'] );
		$this->assertSame( 3, $data['two_step']['grace_logins'] );
	}

	public function test_save_refuses_a_nested_value_in_two_step_outside_the_role_map() {
		$response = $this->request( 'POST', '/settings', array( 'two_step' => array( 'grace_days' => array( 5 ) ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 7, Settings::get( 'two_step.grace_days' ) );
	}

	public function provide_bad_passwordless_groups() {
		return array(
			'a list for role policy'     => array( array( 'passwordless' => array( 'role_policy' => array( 'email_only' ) ) ) ),
			'a deep role policy'         => array( array( 'passwordless' => array( 'role_policy' => array( 'editor' => array( 'x' => 'email_only' ) ) ) ) ),
			'a deep show on'             => array( array( 'passwordless' => array( 'show_on' => array( 'wp_login' => array( 'x' => true ) ) ) ) ),
			'a map for the lifetime'     => array( array( 'passwordless' => array( 'code_lifetime' => array( 'x' => 600 ) ) ) ),
			'a string for show on'       => array( array( 'passwordless' => array( 'show_on' => 'yes' ) ) ),
			'a map in another group key' => array( array( 'security' => array( 'show_on' => array( 'wp_login' => true ) ) ) ),
		);
	}

	/**
	 * @dataProvider provide_bad_passwordless_groups
	 */
	public function test_save_refuses_malformed_passwordless_values( $params ) {
		$response = $this->request( 'POST', '/settings', $params );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 600, Settings::get( 'passwordless.code_lifetime' ) );
		$this->assertSame( array(), Settings::get( 'passwordless.role_policy' ) );
		$this->assertSame( array(), $this->settings_rows() );
	}

	public function test_save_still_takes_plain_values_of_each_type() {
		$response = $this->request(
			'POST',
			'/settings',
			array(
				'security' => array(
					'max_attempts'        => '7',
					'recaptcha_enabled'   => true,
					'recaptcha_threshold' => 0.7,
					'proxy_header'        => '',
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 7, Settings::get( 'security.max_attempts' ) );
		$this->assertTrue( Settings::get( 'security.recaptcha_enabled' ) );
	}

	/**
	 * Makes writes to one option fail, as a full disk or a locked table would.
	 *
	 * @param string $option Option name.
	 * @return callable The filter, to remove later.
	 */
	private function break_option_writes( $option ) {
		$filter = static function ( $query ) use ( $option ) {
			$query = (string) $query;
			$write = 0 === stripos( ltrim( $query ), 'UPDATE' ) || 0 === stripos( ltrim( $query ), 'INSERT' );
			return $write && false !== strpos( $query, "'" . $option . "'" ) ? 'this is not valid sql' : $query;
		};
		add_filter( 'query', $filter );
		return $filter;
	}

	public function test_a_failed_settings_write_is_a_500_with_a_message() {
		global $wpdb;
		$filter   = $this->break_option_writes( Settings::OPTION );
		$suppress = $wpdb->suppress_errors( true );

		$response = $this->request( 'POST', '/settings', array( 'privacy' => array( 'retention_days' => 90 ) ) );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $filter );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'happyaccess_settings_not_saved', $response->get_data()['code'] );
		$this->assertSame( 'Settings could not be saved. Please try again.', $response->get_data()['message'] );
		$this->assertSame( 30, Settings::get( 'privacy.retention_days' ) );
		$this->assertSame( array(), $this->settings_rows(), 'A change that was not saved is not logged.' );
	}

	public function test_a_failed_setup_write_is_a_500_and_records_no_consent() {
		global $wpdb;
		$filter   = $this->break_option_writes( Settings::OPTION );
		$suppress = $wpdb->suppress_errors( true );

		$response = $this->request( 'POST', '/setup', array( 'features' => array( 'support_access' => false ) ) );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $filter );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'happyaccess_settings_not_saved', $response->get_data()['code'] );
		$this->assertSame( '', Settings::get( 'support.consent_given_at' ) );
	}

	public function test_a_failed_secret_write_is_a_500_and_is_not_logged() {
		global $wpdb;
		$filter   = $this->break_option_writes( self::SECRET );
		$suppress = $wpdb->suppress_errors( true );

		$response = $this->request( 'POST', '/settings', array( 'recaptcha_secret_key' => 'sk_live_value' ) );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $filter );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'happyaccess_settings_not_saved', $response->get_data()['code'] );
		$this->assertStringNotContainsString( 'sk_live_value', wp_json_encode( $response->get_data() ) );
		$this->assertFalse( get_option( self::SECRET, false ) );
		$this->assertSame( array(), $this->settings_rows() );
	}

	public function test_a_failed_secret_write_still_logs_the_settings_that_were_saved() {
		global $wpdb;
		$filter   = $this->break_option_writes( self::SECRET );
		$suppress = $wpdb->suppress_errors( true );

		$response = $this->request(
			'POST',
			'/settings',
			array(
				'privacy'              => array( 'retention_days' => 90 ),
				'recaptcha_secret_key' => 'sk_live_value',
			)
		);

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $filter );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 90, Settings::get( 'privacy.retention_days' ) );
		$rows = $this->settings_rows();
		$this->assertCount( 1, $rows, 'The saved part of the change is logged once.' );
		$this->assertStringContainsString( 'privacy.retention_days', wp_json_encode( $rows ) );
		$this->assertStringNotContainsString( 'recaptcha_secret_key', wp_json_encode( $rows ) );
		$this->assertStringNotContainsString( 'sk_live_value', wp_json_encode( $rows ) );
	}

	public function test_saving_the_same_values_again_is_not_an_error() {
		$this->request( 'POST', '/settings', array( 'privacy' => array( 'retention_days' => 90 ) ) );
		$response = $this->request( 'POST', '/settings', array( 'privacy' => array( 'retention_days' => 90 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->settings_rows() );
	}
}
