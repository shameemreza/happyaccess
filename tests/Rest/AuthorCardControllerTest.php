<?php
/**
 * Author card route tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Admin\AuthorCard;

require_once __DIR__ . '/RestTestCase.php';

class AuthorCardControllerTest extends RestTestCase {

	public function tear_down() {
		unset( $GLOBALS['wp_rest_auth_cookie'], $_SERVER['HTTP_X_WP_NONCE'] );
		parent::tear_down();
	}

	public function test_a_manager_can_say_not_now() {
		$res = $this->request( 'POST', '/author-card', array( 'choice' => 'later' ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame(
			array(
				'choice' => 'later',
				'state'  => 'tips',
			),
			$res->get_data()
		);
		$this->assertSame( 'no-store, max-age=0', $res->get_headers()['Cache-Control'] );
		$this->assertSame( 'later', get_user_meta( $this->owner, AuthorCard::META, true ) );
	}

	public function test_a_manager_can_mark_it_rated() {
		$res = $this->request( 'POST', '/author-card', array( 'choice' => 'rated' ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'tips', $res->get_data()['state'] );
		$this->assertSame( 'rated', get_user_meta( $this->owner, AuthorCard::META, true ) );
	}

	public function test_a_manager_can_hide_the_tips() {
		$res = $this->request( 'POST', '/author-card', array( 'choice' => 'hidden' ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame(
			array(
				'choice' => 'hidden',
				'state'  => 'none',
			),
			$res->get_data()
		);
		$this->assertSame( 'hidden', get_user_meta( $this->owner, AuthorCard::META, true ) );
	}

	public function test_dismissed_from_an_open_beta_page_is_saved_as_later() {
		$res = $this->request( 'POST', '/author-card', array( 'choice' => 'dismissed' ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'later', $res->get_data()['choice'] );
		$this->assertSame( 'later', get_user_meta( $this->owner, AuthorCard::META, true ) );
	}

	public function test_the_choice_is_saved_for_the_current_user_only() {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->request(
			'POST',
			'/author-card',
			array(
				'choice'  => 'later',
				'user_id' => $other,
			)
		);

		$this->assertSame( '', get_user_meta( $other, AuthorCard::META, true ) );
		$this->assertSame( 'later', get_user_meta( $this->owner, AuthorCard::META, true ) );
	}

	public function test_an_unknown_choice_is_a_400() {
		$res = $this->request( 'POST', '/author-card', array( 'choice' => 'credit' ) );

		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( '', get_user_meta( $this->owner, AuthorCard::META, true ) );
	}

	public function test_a_user_without_the_capability_gets_403() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$res = $this->request( 'POST', '/author-card', array( 'choice' => 'dismissed' ) );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( '', get_user_meta( $editor, AuthorCard::META, true ) );
	}

	public function test_a_temp_user_gets_403() {
		$temp = $this->as_temp_user();

		$res = $this->request( 'POST', '/author-card', array( 'choice' => 'dismissed' ) );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( '', get_user_meta( $temp, AuthorCard::META, true ) );
	}

	public function test_a_logged_out_request_gets_401() {
		wp_set_current_user( 0 );

		$res = $this->request( 'POST', '/author-card', array( 'choice' => 'dismissed' ) );

		$this->assertSame( 401, $res->get_status() );
	}

	/**
	 * Core's cookie check, as serve_request() runs it, for a request that came
	 * in with the login cookie.
	 *
	 * @return void
	 */
	private function cookie_request_check() {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		rest_cookie_check_errors( null );
	}

	public function test_a_cookie_request_without_a_nonce_gets_401() {
		$this->cookie_request_check();

		$res = $this->request( 'POST', '/author-card', array( 'choice' => 'dismissed' ) );

		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( '', get_user_meta( $this->owner, AuthorCard::META, true ) );
	}

	public function test_a_cookie_request_with_the_rest_nonce_is_saved() {
		// A valid nonce makes core send a fresh one as a header, which a test can't.
		global $wp_rest_server;
		$wp_rest_server = new class() extends WP_REST_Server {
			public function send_header( $key, $value ) {}
		};
		do_action( 'rest_api_init', $wp_rest_server );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$this->cookie_request_check();

		$res = $this->request( 'POST', '/author-card', array( 'choice' => 'dismissed' ) );

		$this->assertSame( 200, $res->get_status() );
	}

	public function test_get_is_not_a_route() {
		$res = $this->request( 'GET', '/author-card' );

		$this->assertSame( 404, $res->get_status() );
	}
}
