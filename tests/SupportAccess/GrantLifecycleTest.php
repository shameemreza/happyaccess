<?php
/**
 * Grant lifecycle tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;
use HappyAccess\Login\Session;

class GrantLifecycleTest extends WP_UnitTestCase {

	private $owner;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Clock::freeze( 1790000000 );
		$this->owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner );
		Grants::flush_cache();
	}

	public function tear_down() {
		Session::reset();
		Clock::freeze( null );
		parent::tear_down();
	}

	private function grant_with_user( array $args = array() ) {
		$made    = Grants::create(
			array_merge(
				array(
					'label'    => 'Acme',
					'duration' => DAY_IN_SECONDS,
				),
				$args
			)
		);
		$user_id = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		WP_Session_Tokens::get_instance( $user_id )->create( time() + 3600 );
		return array( $made['id'], $user_id );
	}

	public function test_extend_is_capped_at_thirty_days_from_now() {
		list( $id ) = $this->grant_with_user();
		$this->assertTrue( Grants::extend( $id, 3 * DAY_IN_SECONDS ) );
		$this->assertSame( 1790000000 + 4 * DAY_IN_SECONDS, Grants::get( $id )['expires_at'] );
		Grants::extend( $id, 90 * DAY_IN_SECONDS );
		$this->assertSame( 1790000000 + Grants::MAX_DURATION, Grants::get( $id )['expires_at'] );
	}

	public function test_suspend_destroys_sessions_and_resume_restores() {
		list( $id, $user_id ) = $this->grant_with_user();
		$this->assertTrue( Grants::suspend( $id ) );
		$this->assertSame( 'suspended', Grants::get( $id )['status'] );
		$this->assertSame( array(), WP_Session_Tokens::get_instance( $user_id )->get_all() );
		$this->assertTrue( Grants::resume( $id ) );
		$this->assertSame( 'active', Grants::get( $id )['status'] );
	}

	public function test_regenerate_replaces_both_secrets() {
		list( $id ) = $this->grant_with_user();
		$old        = Grants::get( $id );
		$this->assertNotNull( $old );
		// The original secrets are only known from create(), so make a second grant to capture them.
		$made = Grants::create( array( 'label' => 'Second', 'duration' => DAY_IN_SECONDS ) );
		$this->assertTrue( Grants::record_login( $made['id'] ) );
		$new = Grants::regenerate( $made['id'] );
		$this->assertNull( Grants::find_by_code( $made['code'] ) );
		$this->assertNull( Grants::find_by_link( $made['link_key'] ) );
		$this->assertSame( $made['id'], Grants::find_by_code( $new['code'] )['id'] );
		$this->assertSame( $made['id'], Grants::find_by_link( $new['link_key'] )['id'] );
		$this->assertSame( 0, Grants::get( $made['id'] )['use_count'] );
	}

	public function test_regenerate_reopens_a_used_one_time_grant_and_destroys_sessions() {
		list( $id, $user_id ) = $this->grant_with_user( array( 'one_time' => true ) );
		$this->assertTrue( Grants::record_login( $id ) );
		$this->assertSame( 'used', Grants::get( $id )['status'] );
		$this->assertFalse( Grants::record_login( $id ) );
		$this->assertCount( 1, WP_Session_Tokens::get_instance( $user_id )->get_all() );

		$new = Grants::regenerate( $id );
		$this->assertNotNull( $new );
		$this->assertSame( 0, Grants::get( $id )['use_count'] );
		$this->assertSame( 'active', Grants::get( $id )['status'] );
		$this->assertSame( array(), WP_Session_Tokens::get_instance( $user_id )->get_all() );
		$this->assertSame( $id, Grants::find_by_code( $new['code'] )['id'] );
		$this->assertTrue( Grants::record_login( $id ) );
	}

	public function test_regenerate_works_for_a_grant_without_a_temp_user() {
		$made = Grants::create( array( 'label' => 'Fresh', 'one_time' => true ) );
		$this->assertTrue( Grants::record_login( $made['id'] ) );
		$this->assertNotNull( Grants::regenerate( $made['id'] ) );
		$this->assertTrue( Grants::record_login( $made['id'] ) );
	}

	public function test_extend_and_resume_keep_working_on_a_used_grant() {
		list( $id ) = $this->grant_with_user( array( 'one_time' => true ) );
		$this->assertTrue( Grants::record_login( $id ) );
		$this->assertSame( 'used', Grants::get( $id )['status'] );
		$this->assertTrue( Grants::extend( $id, HOUR_IN_SECONDS ) );
		$this->assertSame( 'used', Grants::get( $id )['status'] );
		Session::set_resolver( array( Grants::class, 'resolve_user' ) );
		$this->assertNull( Session::end_reason( Grants::get( $id )['user_id'] ) );
		$this->assertTrue( Grants::suspend( $id ) );
		$this->assertTrue( Grants::resume( $id ) );
		$this->assertSame( 'used', Grants::get( $id )['status'] );
	}

	public function test_regenerate_returns_null_for_a_revoked_grant() {
		list( $id ) = $this->grant_with_user();
		Grants::revoke( $id );
		$this->assertNull( Grants::regenerate( $id ) );
	}

	public function test_extend_returns_false_for_revoked_and_unknown_grants() {
		list( $id ) = $this->grant_with_user();
		Grants::revoke( $id );
		$this->assertFalse( Grants::extend( $id, 3600 ) );
		$this->assertFalse( Grants::extend( 999999, 3600 ) );
	}

	public function test_revoke_destroys_sessions() {
		list( $id, $user_id ) = $this->grant_with_user();
		$this->assertCount( 1, WP_Session_Tokens::get_instance( $user_id )->get_all() );
		Grants::revoke( $id );
		$this->assertSame( array(), WP_Session_Tokens::get_instance( $user_id )->get_all() );
	}

	public function test_revoke_all_returns_its_count() {
		$this->grant_with_user();
		$this->grant_with_user();
		list( $done ) = $this->grant_with_user();
		Grants::revoke( $done );
		$this->assertSame( 2, Grants::revoke_all( 'lockdown' ) );
		$this->assertSame( 0, Grants::revoke_all( 'lockdown' ) );
		$this->assertSame( 'revoked', Grants::get( $done )['end_reason'] );
	}

	public function test_failed_delete_is_logged_and_retried_by_cleanup() {
		list( $id, $user_id ) = $this->grant_with_user();
		delete_user_meta( $user_id, 'happyaccess_temp_user' );

		$this->assertTrue( Grants::revoke( $id ) );
		$this->assertNotFalse( get_userdata( $user_id ) );
		$this->assertSame( 1, AuditLog::query( array( 'event' => 'temp_user_delete_failed', 'token_id' => $id ) )['total'] );

		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );
		Grants::cleanup_expired();
		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertSame( 0, Grants::retry_orphans() );
	}

	public function test_retry_orphans_counts_deleted_users() {
		list( $id, $user_id ) = $this->grant_with_user();
		delete_user_meta( $user_id, 'happyaccess_temp_user' );
		Grants::revoke( $id );
		$this->assertSame( 0, Grants::retry_orphans() );
		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );
		$this->assertSame( 1, Grants::retry_orphans() );
	}

	public function test_resolver_reports_revoked_for_a_grant_revoked_early_as_expired() {
		list( $id, $user_id ) = $this->grant_with_user();
		// Keep the user alive so the resolver can still read its meta.
		delete_user_meta( $user_id, 'happyaccess_temp_user' );
		$this->assertTrue( Grants::revoke( $id, 'expired' ) );
		$grant = Grants::get( $id );
		$this->assertSame( 'expired', $grant['status'] );
		$this->assertGreaterThan( Clock::now(), $grant['expires_at'] );
		$this->assertSame( 'revoked', Grants::resolve_user( $user_id )['state'] );
	}

	public function test_session_sees_a_suspension_in_the_same_request() {
		list( $id, $user_id ) = $this->grant_with_user();
		Session::set_resolver( array( Grants::class, 'resolve_user' ) );
		$this->assertNull( Session::end_reason( $user_id ) );
		Grants::suspend( $id );
		$this->assertSame( 'suspended', Session::end_reason( $user_id ) );
	}

	public function test_revoke_deletes_user_logs_and_fires_action() {
		list( $id, $user_id ) = $this->grant_with_user();
		$fired                = array();
		add_action(
			'happyaccess_grant_ended',
			function ( $grant, $reason ) use ( &$fired ) {
				$fired = array( $grant['id'], $reason, $grant['status'] );
			},
			10,
			2
		);

		$this->assertTrue( Grants::revoke( $id ) );
		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertSame( array( $id, 'revoked', 'revoked' ), $fired );
		$this->assertSame(
			1,
			AuditLog::query(
				array(
					'event'    => 'grant_ended',
					'token_id' => $id,
				)
			)['total']
		);
		$this->assertFalse( Grants::revoke( $id ) );
	}

	public function test_record_login_is_atomic_and_respects_one_time() {
		list( $id ) = $this->grant_with_user( array( 'one_time' => true ) );
		$this->assertTrue( Grants::record_login( $id ) );
		$this->assertFalse( Grants::record_login( $id ) );
		$grant = Grants::get( $id );
		$this->assertSame( 'used', $grant['status'] );
		$this->assertSame( 1, $grant['login_count'] );
		$this->assertSame( 1790000000, $grant['last_login_at'] );
	}

	public function test_last_login_count_follows_the_updating_statement() {
		list( $id ) = $this->grant_with_user();
		$this->assertTrue( Grants::record_login( $id ) );
		$this->assertSame( 1, Grants::last_login_count() );
		$this->assertTrue( Grants::record_login( $id ) );
		$this->assertSame( 2, Grants::last_login_count() );
		Grants::suspend( $id );
		$this->assertFalse( Grants::record_login( $id ) );
		$this->assertSame( 0, Grants::last_login_count() );
	}

	public function test_record_login_fails_for_suspended_and_expired() {
		list( $id ) = $this->grant_with_user();
		Grants::suspend( $id );
		$this->assertFalse( Grants::record_login( $id ) );
		Grants::resume( $id );
		Clock::freeze( 1790000000 + 2 * DAY_IN_SECONDS );
		$this->assertFalse( Grants::record_login( $id ) );
	}

	public function test_cleanup_expired_ends_only_expired_grants() {
		list( $old, $old_user ) = $this->grant_with_user( array( 'duration' => 3600 ) );
		list( $new )            = $this->grant_with_user();
		Clock::freeze( 1790000000 + 7200 );
		$this->assertSame( 1, Grants::cleanup_expired() );
		$this->assertSame( 'expired', Grants::get( $old )['status'] );
		$this->assertSame( 'expired', Grants::get( $old )['end_reason'] );
		$this->assertFalse( get_userdata( $old_user ) );
		$this->assertSame( 'active', Grants::get( $new )['status'] );
	}

	public function test_resolver_maps_states() {
		list( $id, $user_id ) = $this->grant_with_user( array( 'one_time' => true ) );
		$this->assertSame(
			array(
				'state'      => 'active',
				'expires_at' => 1790000000 + DAY_IN_SECONDS,
			),
			Grants::resolve_user( $user_id )
		);

		Grants::record_login( $id );
		Grants::flush_cache();
		$this->assertSame( 'active', Grants::resolve_user( $user_id )['state'] );

		Grants::suspend( $id );
		Grants::flush_cache();
		$this->assertSame( 'suspended', Grants::resolve_user( $user_id )['state'] );

		update_user_meta( $user_id, 'happyaccess_blog_id', 999 );
		Grants::flush_cache();
		$this->assertSame(
			array(
				'state'      => 'active',
				'expires_at' => PHP_INT_MAX,
			),
			Grants::resolve_user( $user_id )
		);

		$this->assertNull( Grants::resolve_user( $this->owner ) );
	}

	public function test_resolver_never_touches_the_current_user() {
		list( , $user_id ) = $this->grant_with_user();
		wp_set_current_user( 0 );
		add_filter(
			'determine_current_user',
			function () {
				throw new Exception( 'resolver resolved the current user' );
			}
		);
		Grants::flush_cache();
		$this->assertSame( 'active', Grants::resolve_user( $user_id )['state'] );
	}
}
