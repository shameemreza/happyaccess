<?php
/**
 * Router tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Login\Router;

class RouterTest extends WP_UnitTestCase {

	public function tear_down() {
		Router::reset();
		unset( $_REQUEST['step'] );
		parent::tear_down();
	}

	public function test_url_targets_wp_login_with_action_and_step() {
		$url = Router::url( 'code' );
		$this->assertStringContainsString( 'wp-login.php', $url );
		$this->assertStringContainsString( 'action=happyaccess', $url );
		$this->assertStringContainsString( 'step=code', $url );
	}

	public function test_url_encodes_extra_args() {
		$url = Router::url( 'link', array( 'k' => 'a+b/c' ) );
		$this->assertStringContainsString( 'k=a%2Bb%2Fc', $url );
	}

	public function test_url_follows_login_url_filter() {
		add_filter(
			'login_url',
			function () {
				return 'https://example.org/secret-login/';
			}
		);
		$this->assertStringStartsWith( 'https://example.org/secret-login/?', Router::url( 'code' ) );
	}

	public function test_steps_register_and_resolve() {
		$handler = function () {};
		Router::add_step( 'code', $handler );
		$this->assertSame( $handler, Router::resolve( 'code' ) );
		$this->assertNull( Router::resolve( 'missing' ) );
	}

	public function test_current_step_is_sanitized() {
		$_REQUEST['step'] = 'Code<script>';
		$this->assertSame( 'codescript', Router::current_step() );
	}

	public function test_register_hooks_login_form_action() {
		Router::register();
		$this->assertNotFalse( has_action( 'login_form_happyaccess', array( Router::class, 'dispatch' ) ) );
	}
}
