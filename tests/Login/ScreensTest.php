<?php
/**
 * Login screen preparation tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Core\Recaptcha;
use HappyAccess\Core\Settings;
use HappyAccess\Features\Passwordless\Forms;
use HappyAccess\Login\Screens;

class ScreensTest extends WP_UnitTestCase {

	use HappyAccess_Test_Recaptcha;

	public function set_up() {
		parent::set_up();
		Installer::install();
		delete_option( Settings::OPTION );
		$GLOBALS['wp_scripts'] = null;
	}

	public function tear_down() {
		$GLOBALS['wp_scripts'] = null;
		parent::tear_down();
	}

	private function form_screen( $captcha ) {
		return array(
			'type'    => 'render',
			'title'   => 'Temporary access',
			'body'    => '<form name="happyaccess-code"></form>',
			'errors'  => null,
			'message' => '',
			'captcha' => $captcha,
		);
	}

	public function test_a_form_screen_loads_the_script_while_recaptcha_is_on() {
		$this->turn_on_recaptcha();
		Screens::prepare( $this->form_screen( 'code' ) );

		$this->assertTrue( wp_script_is( Recaptcha::HANDLE, 'enqueued' ) );
		$inline = implode( "\n", array_filter( (array) wp_scripts()->get_data( Recaptcha::HANDLE, 'after' ), 'is_string' ) );
		$this->assertStringContainsString( '"code"', $inline );
	}

	public function test_nothing_loads_while_recaptcha_is_off() {
		Settings::update( array( 'security' => array( 'recaptcha_enabled' => true ) ) );
		Screens::prepare( $this->form_screen( 'code' ) );
		$this->assertFalse( wp_script_is( Recaptcha::HANDLE, 'registered' ) );
	}

	public function test_a_screen_without_a_form_or_a_redirect_loads_nothing() {
		$this->turn_on_recaptcha();
		$screen = $this->form_screen( 'code' );
		unset( $screen['captcha'] );
		Screens::prepare( $screen );
		Screens::prepare(
			array(
				'type'    => 'redirect',
				'url'     => 'http://example.org/',
				'captcha' => 'code',
			)
		);
		$this->assertFalse( wp_script_is( Recaptcha::HANDLE, 'registered' ) );
	}

	public function test_a_screen_that_names_a_two_step_action_loads_nothing() {
		$this->turn_on_recaptcha();
		Screens::prepare( $this->form_screen( 'twostep' ) );
		Screens::prepare( $this->form_screen( 'twostep_setup' ) );
		$this->assertFalse( wp_script_is( Recaptcha::HANDLE, 'registered' ) );
	}

	public function test_a_login_screen_after_the_inline_forms_still_gets_the_submit_script() {
		$this->turn_on_recaptcha();
		Forms::enqueue();
		$this->assertTrue( wp_script_is( Recaptcha::HANDLE, 'enqueued' ) );

		Screens::prepare( $this->form_screen( 'pl_request' ) );

		$inline = implode( "\n", array_filter( (array) wp_scripts()->get_data( Recaptcha::HANDLE, 'after' ), 'is_string' ) );
		$this->assertStringContainsString( '"pl_request"', $inline );
	}
}
