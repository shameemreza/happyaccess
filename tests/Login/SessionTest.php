<?php
/**
 * Session tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Clock;
use HappyAccess\Login\Session;

class SessionTest extends WP_UnitTestCase {

	private $temp;

	public function set_up() {
		parent::set_up();
		Clock::freeze( 1790000000 );
		Session::reset();
		$this->temp = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $this->temp, 'happyaccess_temp_user', true );
	}

	public function tear_down() {
		Clock::freeze( null );
		Session::reset();
		parent::tear_down();
	}

	private function resolve_to( $state, $expires_at ) {
		Session::set_resolver(
			function () use ( $state, $expires_at ) {
				return array(
					'state'      => $state,
					'expires_at' => $expires_at,
				);
			}
		);
	}

	public function test_normal_users_are_ignored() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertNull( Session::end_reason( $admin ) );
		$this->assertSame( 1209600, Session::cap_cookie( 1209600, $admin, true ) );
	}

	public function test_active_grant_keeps_session_and_caps_cookie() {
		$this->resolve_to( 'active', 1790000000 + 3600 );
		$this->assertNull( Session::end_reason( $this->temp ) );
		$this->assertSame( 3600, Session::cap_cookie( 1209600, $this->temp, true ) );
		$this->assertSame( 600, Session::cap_cookie( 600, $this->temp, false ) );
	}

	public function test_expired_revoked_suspended_end_the_session() {
		$this->resolve_to( 'active', 1790000000 - 1 );
		$this->assertSame( 'expired', Session::end_reason( $this->temp ) );

		$this->resolve_to( 'revoked', 1790000000 + 3600 );
		$this->assertSame( 'revoked', Session::end_reason( $this->temp ) );

		$this->resolve_to( 'suspended', 1790000000 + 3600 );
		$this->assertSame( 'suspended', Session::end_reason( $this->temp ) );
	}

	public function test_temp_user_without_resolver_or_grant_is_ended() {
		$this->assertSame( 'revoked', Session::end_reason( $this->temp ) );

		Session::set_resolver(
			function () {
				return null;
			}
		);
		$this->assertSame( 'revoked', Session::end_reason( $this->temp ) );
		$this->assertSame( 1, Session::cap_cookie( 1209600, $this->temp, true ) );
	}

	public function test_enforce_logs_out_an_ended_temp_user_in_ajax() {
		$this->resolve_to( 'revoked', 1790000000 + 3600 );
		wp_set_current_user( $this->temp );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'send_auth_cookies', '__return_false' );

		$ended = array();
		add_action(
			'happyaccess_session_ended',
			function ( $user_id, $reason ) use ( &$ended ) {
				$ended = array( $user_id, $reason );
			},
			10,
			2
		);

		Session::enforce();

		$this->assertSame( array( $this->temp, 'revoked' ), $ended );
		$this->assertSame( 0, get_current_user_id() );
	}
}
