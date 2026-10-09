<?php
/**
 * Settings and Features tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Features;
use HappyAccess\Core\Internal;
use HappyAccess\Core\Settings;

class SettingsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
	}

	public function test_defaults_when_option_missing() {
		$this->assertSame( 5, Settings::get( 'security.max_attempts' ) );
		$this->assertTrue( Settings::get( 'features.support_access' ) );
		$this->assertFalse( Settings::get( 'features.passwordless' ) );
		$this->assertSame( 'fallback', Settings::get( 'nope.missing', 'fallback' ) );
	}

	public function test_update_merges_casts_and_clamps() {
		Settings::update(
			array(
				'security' => array(
					'max_attempts'        => '7',
					'lockout_duration'    => 5,
					'recaptcha_threshold' => '3',
					'proxy_header'        => 'HTTP_EVIL',
					'unknown_key'         => 'x',
				),
				'privacy'  => array( 'logging' => 'false' ),
			)
		);

		$this->assertSame( 7, Settings::get( 'security.max_attempts' ) );
		$this->assertSame( 60, Settings::get( 'security.lockout_duration' ) );
		$this->assertSame( 1.0, Settings::get( 'security.recaptcha_threshold' ) );
		$this->assertSame( '', Settings::get( 'security.proxy_header' ) );
		$this->assertNull( Settings::get( 'security.unknown_key' ) );
		$this->assertFalse( Settings::get( 'privacy.logging' ) );
		$this->assertSame( 30, Settings::get( 'privacy.retention_days' ) );
	}

	public function test_site_code_cap_is_clamped_to_5_to_100() {
		$this->assertSame( 30, Settings::get( 'security.site_code_cap' ) );

		Settings::update( array( 'security' => array( 'site_code_cap' => 1000 ) ) );
		$this->assertSame( 100, Settings::get( 'security.site_code_cap' ) );

		Settings::update( array( 'security' => array( 'site_code_cap' => 1 ) ) );
		$this->assertSame( 5, Settings::get( 'security.site_code_cap' ) );
	}

	public function test_valid_choice_is_kept() {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_CF_CONNECTING_IP' ) ) );
		$this->assertSame( 'HTTP_CF_CONNECTING_IP', Settings::get( 'security.proxy_header' ) );
	}

	public function test_features_toggle() {
		$this->assertTrue( Features::is_enabled( 'support_access' ) );
		$this->assertFalse( Features::is_enabled( 'two_step' ) );

		$this->assertTrue( Features::set( 'two_step', true ) );
		$this->assertTrue( Features::is_enabled( 'two_step' ) );

		$this->assertFalse( Features::set( 'made_up', true ) );
		$this->assertFalse( Features::is_enabled( 'made_up' ) );
	}

	public function test_temp_user_cannot_update_settings() {
		$temp = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $temp, 'happyaccess_temp_user', 1 );
		wp_set_current_user( $temp );

		$saved = Settings::update( array( 'privacy' => array( 'logging' => false ) ) );

		$this->assertTrue( $saved['privacy']['logging'] );
		$this->assertTrue( Settings::get( 'privacy.logging' ) );
		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	public function test_internal_write_during_a_temp_user_request_still_saves() {
		$temp = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $temp, 'happyaccess_temp_user', 1 );
		wp_set_current_user( $temp );

		Internal::run(
			static function () {
				Settings::update( array( 'privacy' => array( 'logging' => false ) ) );
			}
		);

		$this->assertFalse( Settings::get( 'privacy.logging' ) );
	}

	public function test_all_reads_the_option_once_until_a_write_or_a_blog_switch() {
		Settings::update( array( 'privacy' => array( 'retention_days' => 9 ) ) );
		$reads = 0;
		$count = static function ( $value ) use ( &$reads ) {
			++$reads;
			return $value;
		};
		add_filter( 'option_' . Settings::OPTION, $count );
		Settings::flush_cache();

		Settings::all();
		Settings::get( 'privacy.logging' );
		Settings::all();
		$this->assertSame( 1, $reads );

		Settings::update( array( 'privacy' => array( 'retention_days' => 12 ) ) );
		$this->assertSame( 12, Settings::get( 'privacy.retention_days' ) );

		$stored                              = get_option( Settings::OPTION );
		$stored['privacy']['retention_days'] = 15;
		update_option( Settings::OPTION, $stored );
		$this->assertSame( 15, Settings::get( 'privacy.retention_days' ) );

		$before = $reads;
		Settings::all();
		$this->assertSame( $before, $reads );
		do_action( 'switch_blog', get_current_blog_id(), get_current_blog_id(), 'switch' );
		Settings::all();
		$this->assertSame( $before + 1, $reads );

		delete_option( Settings::OPTION );
		$this->assertSame( 30, Settings::get( 'privacy.retention_days' ) );
		remove_filter( 'option_' . Settings::OPTION, $count );
	}

	public function test_passwordless_defaults() {
		$this->assertSame( 600, Settings::get( 'passwordless.code_lifetime' ) );
		$this->assertTrue( Settings::get( 'passwordless.show_on.wp_login' ) );
		$this->assertTrue( Settings::get( 'passwordless.show_on.woo_account' ) );
		$this->assertTrue( Settings::get( 'passwordless.show_on.woo_checkout' ) );
		$this->assertSame( array(), Settings::get( 'passwordless.role_policy' ) );
		$this->assertSame( 'link', Settings::get( 'passwordless.toggle_style' ) );
	}

	public function test_two_step_defaults() {
		$this->assertSame( array(), Settings::get( 'two_step.role_policy' ) );
		$this->assertSame( 'logins', Settings::get( 'two_step.grace_type' ) );
		$this->assertSame( 3, Settings::get( 'two_step.grace_logins' ) );
		$this->assertSame( 7, Settings::get( 'two_step.grace_days' ) );
		$this->assertTrue( Settings::get( 'two_step.block_xmlrpc' ) );
	}

	public function test_two_step_grace_type_takes_logins_or_days_and_nothing_else() {
		Settings::update( array( 'two_step' => array( 'grace_type' => 'days' ) ) );
		$this->assertSame( 'days', Settings::get( 'two_step.grace_type' ) );

		Settings::update( array( 'two_step' => array( 'grace_type' => 'weeks' ) ) );
		$this->assertSame( 'logins', Settings::get( 'two_step.grace_type' ) );
	}

	public function test_two_step_grace_numbers_are_clamped() {
		Settings::update(
			array(
				'two_step' => array(
					'grace_logins' => 0,
					'grace_days'   => 0,
				),
			)
		);
		$this->assertSame( 1, Settings::get( 'two_step.grace_logins' ) );
		$this->assertSame( 1, Settings::get( 'two_step.grace_days' ) );

		Settings::update(
			array(
				'two_step' => array(
					'grace_logins' => '99',
					'grace_days'   => 500,
				),
			)
		);
		$this->assertSame( 10, Settings::get( 'two_step.grace_logins' ) );
		$this->assertSame( 30, Settings::get( 'two_step.grace_days' ) );

		Settings::update(
			array(
				'two_step' => array(
					'grace_logins' => 5,
					'grace_days'   => 14,
				),
			)
		);
		$this->assertSame( 5, Settings::get( 'two_step.grace_logins' ) );
		$this->assertSame( 14, Settings::get( 'two_step.grace_days' ) );
	}

	public function test_two_step_block_xmlrpc_is_cast_to_a_boolean() {
		Settings::update( array( 'two_step' => array( 'block_xmlrpc' => 'false' ) ) );
		$this->assertFalse( Settings::get( 'two_step.block_xmlrpc' ) );
		Settings::update( array( 'two_step' => array( 'block_xmlrpc' => '1' ) ) );
		$this->assertTrue( Settings::get( 'two_step.block_xmlrpc' ) );
	}

	public function test_two_step_role_policy_keeps_known_roles_and_choices_only() {
		Settings::update(
			array(
				'two_step' => array(
					'role_policy' => array(
						'administrator' => 'required',
						'editor'        => 'optional',
						'author'        => 'off',
						'subscriber'    => 'sometimes',
						'not_a_role'    => 'required',
						'customer'      => array( 'required' ),
					),
				),
			)
		);
		$this->assertSame(
			array(
				'administrator' => 'required',
				'editor'        => 'optional',
				'author'        => 'off',
			),
			Settings::get( 'two_step.role_policy' )
		);
	}

	public function test_two_step_role_policy_does_not_take_the_passwordless_choices() {
		Settings::update( array( 'two_step' => array( 'role_policy' => array( 'editor' => 'email_only' ) ) ) );
		$this->assertSame( array(), Settings::get( 'two_step.role_policy' ) );

		Settings::update( array( 'passwordless' => array( 'role_policy' => array( 'editor' => 'required' ) ) ) );
		$this->assertSame( array(), Settings::get( 'passwordless.role_policy' ) );
	}

	public function test_a_role_policy_that_is_not_a_map_becomes_empty() {
		Settings::update( array( 'two_step' => array( 'role_policy' => 'required' ) ) );
		$this->assertSame( array(), Settings::get( 'two_step.role_policy' ) );
	}

	public function test_device_alert_roles_default_to_administrator_until_saved() {
		$this->assertSame( array( 'administrator' ), Settings::get( 'two_step.device_alert_roles' ) );

		Settings::update( array( 'two_step' => array( 'grace_days' => 9 ) ) );
		$this->assertSame( array( 'administrator' ), Settings::get( 'two_step.device_alert_roles' ) );
	}

	public function test_device_alert_roles_keep_known_roles_once_each_and_an_empty_list_stays_empty() {
		Settings::update( array( 'two_step' => array( 'device_alert_roles' => array( 'editor', 'not_a_role', 'editor', 7, array( 'author' ), 'author' ) ) ) );
		$this->assertSame( array( 'editor', 'author' ), Settings::get( 'two_step.device_alert_roles' ) );

		Settings::update( array( 'two_step' => array( 'device_alert_roles' => array() ) ) );
		$this->assertSame( array(), Settings::get( 'two_step.device_alert_roles' ) );
		Settings::flush_cache();
		$this->assertSame( array(), Settings::get( 'two_step.device_alert_roles' ), 'A saved empty list means no alerts, not the default.' );

		Settings::update( array( 'two_step' => array( 'device_alert_roles' => 'administrator' ) ) );
		$this->assertSame( array(), Settings::get( 'two_step.device_alert_roles' ) );
	}

	public function test_a_saved_device_alert_list_replaces_the_old_one() {
		Settings::update( array( 'two_step' => array( 'device_alert_roles' => array( 'administrator', 'editor', 'author' ) ) ) );
		Settings::update( array( 'two_step' => array( 'device_alert_roles' => array( 'subscriber' ) ) ) );
		$this->assertSame( array( 'subscriber' ), Settings::get( 'two_step.device_alert_roles' ) );
	}

	public function test_toggle_style_takes_link_or_button_and_nothing_else() {
		Settings::update( array( 'passwordless' => array( 'toggle_style' => 'button' ) ) );
		$this->assertSame( 'button', Settings::get( 'passwordless.toggle_style' ) );

		Settings::update( array( 'passwordless' => array( 'toggle_style' => 'Rainbow' ) ) );
		$this->assertSame( 'link', Settings::get( 'passwordless.toggle_style' ) );

		Settings::update( array( 'passwordless' => array( 'toggle_style' => array( 'button' ) ) ) );
		$this->assertSame( 'link', Settings::get( 'passwordless.toggle_style' ) );
	}

	public function test_code_lifetime_is_clamped_to_300_to_1800() {
		Settings::update( array( 'passwordless' => array( 'code_lifetime' => 10 ) ) );
		$this->assertSame( 300, Settings::get( 'passwordless.code_lifetime' ) );

		Settings::update( array( 'passwordless' => array( 'code_lifetime' => '99999' ) ) );
		$this->assertSame( 1800, Settings::get( 'passwordless.code_lifetime' ) );

		Settings::update( array( 'passwordless' => array( 'code_lifetime' => 900 ) ) );
		$this->assertSame( 900, Settings::get( 'passwordless.code_lifetime' ) );
	}

	public function test_show_on_values_are_cast_to_booleans() {
		Settings::update( array( 'passwordless' => array( 'show_on' => array( 'woo_checkout' => 'false', 'wp_login' => '0', 'elsewhere' => true ) ) ) );
		$this->assertFalse( Settings::get( 'passwordless.show_on.woo_checkout' ) );
		$this->assertFalse( Settings::get( 'passwordless.show_on.wp_login' ) );
		$this->assertTrue( Settings::get( 'passwordless.show_on.woo_account' ) );
		$this->assertNull( Settings::get( 'passwordless.show_on.elsewhere' ) );
	}

	public function test_role_policy_keeps_email_only_and_either_for_known_roles() {
		Settings::update( array( 'passwordless' => array( 'role_policy' => array( 'administrator' => 'email_only', 'editor' => 'either' ) ) ) );
		$this->assertSame(
			array(
				'administrator' => 'email_only',
				'editor'        => 'either',
			),
			Settings::get( 'passwordless.role_policy' )
		);
	}

	public function test_role_policy_drops_unknown_roles_and_refuses_other_values() {
		Settings::update(
			array(
				'passwordless' => array(
					'role_policy' => array(
						'administrator' => 'email_only',
						'not_a_role'    => 'email_only',
						'editor'        => 'code_only',
						'author'        => array( 'email_only' ),
						'subscriber'    => true,
						'shop_manager'  => '',
					),
				),
			)
		);
		$policy = Settings::get( 'passwordless.role_policy' );
		$this->assertSame( array( 'administrator' => 'email_only' ), $policy );
	}

	public function test_role_policy_that_is_not_a_map_is_ignored() {
		update_option( Settings::OPTION, array( 'passwordless' => array( 'role_policy' => 'email_only' ) ) );
		Settings::flush_cache();
		$this->assertSame( array(), Settings::get( 'passwordless.role_policy' ) );

		update_option( Settings::OPTION, array( 'passwordless' => array( 'role_policy' => array( 'email_only', 'either' ) ) ) );
		Settings::flush_cache();
		$this->assertSame( array(), Settings::get( 'passwordless.role_policy' ) );
	}
}
