<?php
/**
 * Settings and Features tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Features;
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
}
