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

	public function test_url_args_cannot_override_action_or_step() {
		$url = Router::url(
			'code',
			array(
				'action' => 'evil',
				'Step'   => 'evil',
				'k'      => 'v',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'happyaccess', $query['action'] );
		$this->assertSame( 'code', $query['step'] );
		$this->assertSame( 'v', $query['k'] );
		$this->assertSame( 1, substr_count( $url, 'action=' ) );
		$this->assertSame( 1, substr_count( $url, 'step=' ) );
	}

	public function test_url_drops_args_that_are_not_scalar() {
		$url = Router::url(
			'link',
			array(
				'a' => array( 'x' ),
				'b' => new stdClass(),
				'c' => null,
				'd' => 7,
				''  => 'empty-key',
			)
		);
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( array( 'action', 'step', 'd' ), array_keys( $query ) );
		$this->assertSame( '7', $query['d'] );
	}

	public function test_add_step_ignores_a_handler_that_is_not_callable() {
		Router::add_step( 'bad', 'happyaccess_no_such_function' );
		Router::add_step( 'worse', array( 'NoSuchClass', 'nothing' ) );

		$this->assertNull( Router::resolve( 'bad' ) );
		$this->assertNull( Router::resolve( 'worse' ) );
	}

	public function test_add_step_replaces_an_earlier_handler_with_a_callable_one() {
		$first  = function () {};
		$second = function () {};
		Router::add_step( 'code', $first );
		Router::add_step( 'code', 'happyaccess_no_such_function' );
		$this->assertSame( $first, Router::resolve( 'code' ), 'A bad handler leaves the working one in place.' );
		Router::add_step( 'code', $second );
		$this->assertSame( $second, Router::resolve( 'code' ) );
	}

	public function test_run_calls_the_handler_of_the_current_step() {
		$calls = 0;
		Router::add_step(
			'code',
			function () use ( &$calls ) {
				++$calls;
			}
		);
		$_REQUEST['step'] = 'code';

		$this->assertTrue( Router::run() );
		$this->assertSame( 1, $calls );
	}

	public function test_run_sends_an_unknown_step_to_the_normal_login() {
		$sent = array();
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$sent ) {
				$sent[] = $location;
				return false;
			}
		);
		$_REQUEST['step'] = 'nope';

		$this->assertFalse( Router::run() );
		$this->assertSame( array( wp_login_url() ), $sent );
	}

	/**
	 * DONOTCACHEPAGE is a constant, so it is checked in a separate process
	 * and cannot leak into other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_run_marks_the_page_uncacheable_for_a_known_step() {
		Router::add_step( 'code', function () {} );
		$_REQUEST['step'] = 'code';
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );

		Router::run();

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		$this->assertTrue( DONOTCACHEPAGE );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_run_marks_the_page_uncacheable_for_an_unknown_step() {
		add_filter( 'wp_redirect', '__return_false' );
		$_REQUEST['step'] = 'nope';
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );

		Router::run();

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}
}
