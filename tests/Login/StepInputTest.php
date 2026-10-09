<?php
/**
 * Tests for the step callbacks' request readers: each one keeps only the
 * fields its handler uses, cleaned the way the handler needs them.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\ClientIp;
use HappyAccess\Core\Recaptcha;
use HappyAccess\Core\Settings;
use HappyAccess\Features\Passwordless\LoginSteps as PasswordlessSteps;
use HappyAccess\Features\SupportAccess\LoginSteps as SupportSteps;
use HappyAccess\Features\TwoStep\Challenge;
use HappyAccess\Features\TwoStep\SetupSteps;

class StepInputTest extends WP_UnitTestCase {

	/**
	 * Superglobals as they were before the test.
	 *
	 * @var array
	 */
	private $saved = array();

	public function set_up() {
		parent::set_up();
		$this->saved = array(
			'get'    => $_GET,
			'post'   => $_POST,
			'cookie' => $_COOKIE,
			'server' => $_SERVER,
		);

		$_GET    = array();
		$_POST   = array();
		$_COOKIE = array();
	}

	public function tear_down() {
		$_GET    = $this->saved['get'];
		$_POST   = $this->saved['post'];
		$_COOKIE = $this->saved['cookie'];
		$_SERVER = $this->saved['server'];
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * Sets a superglobal the way WordPress leaves it, slashed.
	 *
	 * @param string $name   get, post or cookie.
	 * @param array  $values Values.
	 * @return void
	 */
	private function request( $name, array $values ) {
		$slashed = wp_slash( $values );
		if ( 'get' === $name ) {
			$_GET = $slashed;
		} elseif ( 'post' === $name ) {
			$_POST = $slashed;
		} else {
			$_COOKIE = $slashed;
		}
	}

	public function test_the_code_screen_keeps_only_its_fields_as_clean_text() {
		$this->request(
			'post',
			array(
				'_wpnonce'       => 'abc123',
				'pwd'            => " 1234 5678\n",
				Recaptcha::FIELD => 'token-1_2',
				'redirect_to'    => '/wp-admin/',
				'other'          => 'ignored',
			)
		);

		$this->assertSame(
			array(
				'_wpnonce'       => 'abc123',
				'pwd'            => '1234 5678',
				Recaptcha::FIELD => 'token-1_2',
			),
			SupportSteps::code_input()
		);
	}

	public function test_an_array_where_text_is_expected_becomes_empty() {
		$this->request( 'post', array( 'pwd' => array( '1', '2' ) ) );

		$this->assertSame( array( 'pwd' => '' ), SupportSteps::code_input() );
	}

	public function test_the_link_screen_reads_the_key_from_the_query_and_the_form() {
		$this->request( 'get', array( 'k' => 'Key-_09<b>' ) );
		$this->request(
			'post',
			array(
				'_wpnonce' => 'n1',
				'k'        => 'Key-_09',
				'pwd'      => 'not used here',
			)
		);

		list( $get, $post ) = SupportSteps::link_input();
		$this->assertSame( array( 'k' => 'Key-_09' ), $get );
		$this->assertSame(
			array(
				'_wpnonce' => 'n1',
				'k'        => 'Key-_09',
			),
			$post
		);
	}

	public function test_redirects_keep_percent_encoding_and_validate_to_the_same_url() {
		$raw = '  /wp-admin/edit.php?s=caf%C3%A9 bar&post_type=page ';
		$this->request( 'get', array( 'redirect_to' => $raw ) );
		$this->request(
			'post',
			array(
				'redirect_to' => 'https://example.org/shop/?q=%20x',
				'log'         => ' Someone@Example.org ',
			)
		);

		list( $get, $post ) = PasswordlessSteps::request_input();
		$this->assertSame( '/wp-admin/edit.php?s=caf%C3%A9%20bar&post_type=page', $get['redirect_to'] );
		$this->assertSame( PasswordlessSteps::valid_redirect( trim( $raw ) ), PasswordlessSteps::valid_redirect( $get['redirect_to'] ) );
		$this->assertSame( 'https://example.org/shop/?q=%20x', $post['redirect_to'] );
		$this->assertSame( 'Someone@Example.org', $post['log'] );
	}

	public function test_the_verify_screen_reads_remember_me_as_a_flag_and_only_its_cookies() {
		$this->request(
			'post',
			array(
				'_wpnonce'   => 'n2',
				'pwd'        => '123456',
				'c'          => 'abcdef0123',
				'rememberme' => 'forever',
			)
		);
		$this->request(
			'cookie',
			array(
				PasswordlessSteps::COOKIE         => 'req-KEY_1',
				PasswordlessSteps::CONFIRM_COOKIE => 'conf-KEY_2',
				'wordpress_logged_in_x'           => 'not ours',
			)
		);

		list( $get, $post, $cookies ) = PasswordlessSteps::verify_input();
		$this->assertSame( array(), $get );
		$this->assertSame(
			array(
				'_wpnonce'   => 'n2',
				'c'          => 'abcdef0123',
				'pwd'        => '123456',
				'rememberme' => '1',
			),
			$post
		);
		$this->assertSame(
			array(
				PasswordlessSteps::COOKIE         => 'req-KEY_1',
				PasswordlessSteps::CONFIRM_COOKIE => 'conf-KEY_2',
			),
			$cookies
		);
	}

	public function test_an_empty_remember_me_stays_off() {
		$this->request( 'post', array( 'rememberme' => '0' ) );

		list( , $post ) = PasswordlessSteps::verify_input();
		$this->assertArrayNotHasKey( 'rememberme', $post );
	}

	public function test_the_two_step_screens_read_the_carried_values_and_their_own_fields() {
		$this->request(
			'get',
			array(
				'redirect_to'   => '/wp-admin/',
				'rememberme'    => '1',
				'interim-login' => '1',
				'from'          => 'woo',
				'method'        => 'email',
				'sent'          => '1',
			)
		);
		$this->request(
			'post',
			array(
				'_wpnonce'        => 'n3',
				'method'          => 'app',
				'send'            => '1',
				'pwd'             => '123 456',
				SetupSteps::FIELD => 'confirm',
				'redirect_to'     => '/my-account/',
				'interim-login'   => '',
				'log'             => 'not used here',
			)
		);
		$this->request( 'cookie', array( Challenge::COOKIE => 'pending-KEY' ) );

		list( $get, $post, $cookies ) = Challenge::step_input();
		$this->assertSame(
			array(
				'from'          => 'woo',
				'method'        => 'email',
				'sent'          => '1',
				'redirect_to'   => '/wp-admin/',
				'rememberme'    => '1',
				'interim-login' => '1',
			),
			$get
		);
		$this->assertSame(
			array(
				'_wpnonce'        => 'n3',
				'method'          => 'app',
				'send'            => '1',
				'pwd'             => '123 456',
				SetupSteps::FIELD => 'confirm',
				'redirect_to'     => '/my-account/',
			),
			$post
		);
		$this->assertSame( array( Challenge::COOKIE => 'pending-KEY' ), $cookies );
	}

	public function test_the_client_ip_reads_only_remote_addr_and_the_chosen_header() {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_CF_CONNECTING_IP' ) ) );
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = ' 8.8.8.8 ';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '9.9.9.9';

		$this->assertSame( '8.8.8.8', ClientIp::get() );

		Settings::update( array( 'security' => array( 'proxy_header' => '' ) ) );
		$this->assertSame( '10.0.0.1', ClientIp::get() );
	}
}
