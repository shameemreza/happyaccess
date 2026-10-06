<?php
/**
 * Feature wiring tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\SupportAccess\Feature;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Login\Router;

class FeatureTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		delete_option( Settings::OPTION );
		Router::reset();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_enabled_registers_guards_and_login_steps() {
		Feature::register();
		$this->assertNotFalse( has_filter( 'wp_is_application_passwords_available_for_user' ) );
		$this->assertNotNull( Router::resolve( 'code' ) );
	}

	public function test_disabled_with_live_grant_keeps_guards_but_not_login() {
		Grants::create( array( 'label' => 'Acme' ) );
		Features::set( 'support_access', false );
		Feature::register();
		$this->assertNotFalse( has_filter( 'wp_is_application_passwords_available_for_user' ) );
		$this->assertNull( Router::resolve( 'code' ) );
	}

	public function test_disabled_without_grants_registers_nothing() {
		Features::set( 'support_access', false );
		remove_all_filters( 'wp_is_application_passwords_available_for_user' );
		Feature::register();
		$this->assertFalse( has_filter( 'wp_is_application_passwords_available_for_user' ) );
		$this->assertNull( Router::resolve( 'code' ) );
	}

	public function test_on_disable_revokes_all() {
		Grants::create( array( 'label' => 'a' ) );
		Feature::on_disable();
		$this->assertFalse( Grants::has_current() );
	}
}
