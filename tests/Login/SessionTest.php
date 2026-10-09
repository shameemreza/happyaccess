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
		$this->assertSame( 3600, Session::cap_cookie( 600, $this->temp, false ) );
	}

	public function test_cookie_lasts_as_long_as_the_grant_even_when_longer_than_the_default() {
		$this->resolve_to( 'active', 1790000000 + 7 * DAY_IN_SECONDS );
		$this->assertEqualsWithDelta( 7 * DAY_IN_SECONDS, Session::cap_cookie( 172800, $this->temp, true ), 2 );
	}

	public function test_cookie_length_is_left_alone_for_a_temp_user_from_another_site() {
		update_user_meta( $this->temp, 'happyaccess_blog_id', get_current_blog_id() + 1 );
		Session::set_resolver( array( \HappyAccess\Features\SupportAccess\Grants::class, 'resolve_user' ) );
		\HappyAccess\Features\SupportAccess\Grants::flush_cache();
		$this->assertSame( 172800, Session::cap_cookie( 172800, $this->temp, true ) );
	}

	public function test_cookie_length_is_never_below_one_second() {
		$this->resolve_to( 'active', 1790000000 );
		$this->assertSame( 1, Session::cap_cookie( 172800, $this->temp, true ) );
		$this->resolve_to( 'active', 1790000000 - 500 );
		$this->assertSame( 1, Session::cap_cookie( 172800, $this->temp, true ) );
		$this->resolve_to( 'suspended', 1790000000 + 3600 );
		$this->assertSame( 1, Session::cap_cookie( 172800, $this->temp, true ) );
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

	public function ended_grants() {
		return array(
			'revoked'   => array( 'revoked', 1790000000 + 3600, 'revoked' ),
			'expired'   => array( 'active', 1790000000 - 1, 'expired' ),
			'suspended' => array( 'suspended', 1790000000 + 3600, 'suspended' ),
		);
	}

	/**
	 * @dataProvider ended_grants
	 *
	 * @param string $state      Grant state the resolver returns.
	 * @param int    $expires_at When the grant ends.
	 * @param string $reason     The reason the session ends with.
	 */
	public function test_enforce_logs_out_an_ended_temp_user_in_ajax( $state, $expires_at, $reason ) {
		$this->resolve_to( $state, $expires_at );
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

		$this->assertSame( array( $this->temp, $reason ), $ended );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Replaces core authentication with a stand-in that resolves the temp user,
	 * like a valid cookie would, then hooks Session on top.
	 */
	private function simulate_cookie_auth() {
		remove_all_filters( 'determine_current_user' );
		$temp = $this->temp;
		add_filter(
			'determine_current_user',
			function () use ( $temp ) {
				return $temp;
			},
			10
		);
		Session::register();
	}

	private function with_init_done( $done, $callback ) {
		$had = array_key_exists( 'init', $GLOBALS['wp_actions'] );
		$old = $had ? $GLOBALS['wp_actions']['init'] : null;
		try {
			if ( $done ) {
				$GLOBALS['wp_actions']['init'] = max( 1, (int) $old );
			} else {
				unset( $GLOBALS['wp_actions']['init'] );
			}
			return call_user_func( $callback );
		} finally {
			if ( $had ) {
				$GLOBALS['wp_actions']['init'] = $old;
			} else {
				unset( $GLOBALS['wp_actions']['init'] );
			}
		}
	}

	public function test_filter_current_user_drops_ended_temp_user_after_init() {
		$this->resolve_to( 'revoked', 1790000000 + 3600 );
		$result = $this->with_init_done(
			true,
			function () {
				return Session::filter_current_user( $this->temp );
			}
		);
		$this->assertFalse( $result );
	}

	public function test_filter_current_user_leaves_ended_temp_user_alone_before_init() {
		$this->resolve_to( 'revoked', 1790000000 + 3600 );
		$result = $this->with_init_done(
			false,
			function () {
				return Session::filter_current_user( $this->temp );
			}
		);
		$this->assertSame( $this->temp, $result );
	}

	public function test_filter_current_user_keeps_active_temp_user() {
		$this->resolve_to( 'active', 1790000000 + 3600 );
		$result = $this->with_init_done(
			true,
			function () {
				return Session::filter_current_user( $this->temp );
			}
		);
		$this->assertSame( $this->temp, $result );
	}

	public function test_filter_current_user_passes_normal_users_and_empty_through() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->with_init_done(
			true,
			function () use ( $admin ) {
				$this->assertSame( $admin, Session::filter_current_user( $admin ) );
				$this->assertFalse( Session::filter_current_user( false ) );
				$this->assertSame( 0, Session::filter_current_user( 0 ) );
			}
		);
	}

	public function test_registered_filter_drops_revoked_temp_user_after_init() {
		$this->resolve_to( 'revoked', 1790000000 + 3600 );
		$this->simulate_cookie_auth();
		$result = $this->with_init_done(
			true,
			function () {
				return apply_filters( 'determine_current_user', false );
			}
		);
		$this->assertFalse( $result );
	}

	public function test_enforce_still_runs_the_logout_for_a_cookie_user_resolved_before_init() {
		$this->resolve_to( 'revoked', 1790000000 + 3600 );
		$this->simulate_cookie_auth();
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

		wp_set_current_user( 0 );
		$resolved = $this->with_init_done(
			false,
			function () {
				return apply_filters( 'determine_current_user', false );
			}
		);
		$this->assertSame( $this->temp, $resolved );

		wp_set_current_user( $this->temp );
		$this->with_init_done(
			true,
			function () {
				Session::enforce();
			}
		);

		$this->assertSame( array( $this->temp, 'revoked' ), $ended );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_resolver_with_missing_keys_fails_closed_without_notice() {
		Session::set_resolver(
			function () {
				return array( 'state' => 'active' );
			}
		);
		$this->assertSame( 'expired', Session::end_reason( $this->temp ) );
	}

	public function test_block_core_auth_rejects_a_temp_user() {
		$result = Session::block_core_auth( get_userdata( $this->temp ) );
		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_temp_user', $result->get_error_code() );
	}

	public function test_block_core_auth_passes_other_results_through() {
		$admin = get_userdata( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$error = new WP_Error( 'x', 'y' );

		$this->assertSame( $admin, Session::block_core_auth( $admin ) );
		$this->assertSame( $error, Session::block_core_auth( $error ) );
		$this->assertNull( Session::block_core_auth( null ) );
	}

	public function test_core_login_is_blocked_for_a_temp_user_with_a_valid_password() {
		Session::register();
		wp_set_password( 'a-known-password-123', $this->temp );
		$login = get_userdata( $this->temp )->user_login;

		$result = wp_authenticate( $login, 'a-known-password-123' );

		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_temp_user', $result->get_error_code() );
	}
	public function test_a_resolver_that_asks_for_the_same_grant_does_not_recurse() {
		$calls = 0;
		$inner = null;
		$user  = $this->temp;
		Session::set_resolver(
			static function () use ( &$calls, &$inner, $user ) {
				++$calls;
				$inner = Session::grant( $user );
				return array(
					'state'      => 'active',
					'expires_at' => 1790000000 + 3600,
				);
			}
		);

		$outer = Session::grant( $user );

		$this->assertSame( 1, $calls );
		$this->assertSame( 'revoked', $inner['state'], 'The re-entrant call sees a fail-closed grant.' );
		$this->assertSame( 'active', $outer['state'] );
		$this->assertSame( 1790000000 + 3600, $outer['expires_at'] );
		$this->assertSame( $outer, Session::grant( $user ), 'The resolver result is what stays cached.' );
		$this->assertSame( 1, $calls );
	}
}
