<?php
/**
 * Cron tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Cron;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\ActivityTracker;
use HappyAccess\Features\SupportAccess\CapabilityGuard;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class CronTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		delete_transient( 'happyaccess_cleanup_ran' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		ActivityTracker::reset();
		Clock::freeze( null );
		wp_clear_scheduled_hook( Cron::HOOK );
		parent::tear_down();
	}

	public function test_register_schedules_hourly() {
		Cron::register();
		$this->assertNotFalse( wp_next_scheduled( Cron::HOOK ) );
	}

	public function test_run_ends_expired_grants_and_purges_old_logs() {
		Clock::freeze( 1790000000 );
		Grants::create(
			array(
				'label'    => 'old',
				'duration' => 3600,
			)
		);
		AuditLog::add( 'ancient' );
		Clock::freeze( 1790000000 + 40 * DAY_IN_SECONDS );
		$counts = Cron::run();
		$this->assertSame( 1, $counts['grants'] );
		$this->assertGreaterThanOrEqual( 1, $counts['logs'] );
	}

	public function test_fallback_runs_once_per_ten_minutes() {
		$runs = 0;
		add_filter(
			'happyaccess_cleanup_counts',
			function ( $counts ) use ( &$runs ) {
				++$runs;
				return $counts;
			}
		);
		Cron::maybe_run_fallback();
		Cron::maybe_run_fallback();
		$this->assertSame( 1, $runs );
	}

	public function test_run_deletes_challenges_expired_over_a_day() {
		global $wpdb;
		Clock::freeze( 1790000000 );
		$now = Clock::now();
		foreach ( array( $now - 2 * DAY_IN_SECONDS, $now + HOUR_IN_SECONDS ) as $expires ) {
			$wpdb->insert(
				Installer::table( 'challenges' ),
				array(
					'user_id'    => 1,
					'purpose'    => 'login',
					'created_at' => Clock::mysql( $now - 3 * DAY_IN_SECONDS ),
					'expires_at' => Clock::mysql( $expires ),
				)
			);
		}
		$counts = Cron::run();
		$this->assertSame( 1, $counts['challenges'] );
	}

	public function test_run_deletes_grants_ended_before_retention_and_keeps_active_ones() {
		Clock::freeze( 1790000000 );
		$ended  = Grants::create( array( 'label' => 'ended' ) );
		$active = Grants::create(
			array(
				'label'    => 'active',
				'duration' => 30 * DAY_IN_SECONDS,
			)
		);
		Grants::revoke( $ended['id'] );

		Clock::freeze( 1790000000 + 31 * DAY_IN_SECONDS );
		$counts = Cron::run();

		$this->assertSame( 1, $counts['grants_purged'] );
		$this->assertNull( Grants::get( $ended['id'] ) );
		$this->assertNotNull( Grants::get( $active['id'] ) );
	}

	public function test_run_leaves_an_active_grant_untouched() {
		$active = Grants::create(
			array(
				'label'    => 'still going',
				'duration' => DAY_IN_SECONDS,
			)
		);
		Cron::run();
		$grant = Grants::get( $active['id'] );
		$this->assertSame( 0, $grant['revoked_at'] );
		$this->assertSame( 'active', $grant['status'] );
	}

	/**
	 * Agent A is browsing while grant B has run out with its temp user still there.
	 *
	 * @return array Grant id and user id of A, then grant id and user id of B.
	 */
	private function agent_browsing_while_another_grant_expired() {
		Clock::freeze( 1790000000 );
		$a      = Grants::create(
			array(
				'label'    => 'A',
				'duration' => DAY_IN_SECONDS,
			)
		);
		$b      = Grants::create(
			array(
				'label'    => 'B',
				'duration' => 3600,
			)
		);
		$a_user = TempUsers::get_or_create( Grants::get( $a['id'] ) );
		$b_user = TempUsers::get_or_create( Grants::get( $b['id'] ) );
		Clock::freeze( 1790000000 + 7200 );
		ActivityTracker::register();
		wp_set_current_user( $a_user );
		return array( $a['id'], $a_user, $b['id'], $b_user );
	}

	/**
	 * Role and settings rows logged under a grant.
	 *
	 * @param int $grant_id Grant id.
	 * @return array
	 */
	private function role_and_settings_rows( $grant_id ) {
		ActivityTracker::flush();
		$rows = array();
		foreach ( array( 'user_role_changed', 'user_role_added', 'settings_saved' ) as $event ) {
			$found = AuditLog::query(
				array(
					'event'    => $event,
					'token_id' => $grant_id,
				)
			)['items'];
			$rows  = array_merge( $rows, wp_list_pluck( $found, 'summary' ) );
		}
		return $rows;
	}

	public function test_fallback_does_not_run_inside_a_temp_users_request() {
		list( $a_id, , $b_id, $b_user ) = $this->agent_browsing_while_another_grant_expired();

		Cron::maybe_run_fallback();

		$this->assertSame( 0, Grants::get( $b_id )['revoked_at'] );
		$this->assertNotFalse( get_userdata( $b_user ) );
		$this->assertFalse( get_transient( Cron::FALLBACK_TRANSIENT ) );
		$this->assertSame( array(), $this->role_and_settings_rows( $a_id ) );
	}

	public function test_cleanup_in_a_temp_users_request_logs_nothing_under_that_agent() {
		list( $a_id, , $b_id, $b_user ) = $this->agent_browsing_while_another_grant_expired();

		$counts = Cron::run();

		$this->assertSame( 1, $counts['grants'] );
		$this->assertGreaterThan( 0, Grants::get( $b_id )['revoked_at'] );
		$this->assertFalse( get_userdata( $b_user ) );
		$this->assertSame( array(), $this->role_and_settings_rows( $a_id ) );
	}

	public function test_option_guards_stay_on_during_a_revoke_in_a_temp_users_request() {
		list( , , $b_id, $b_user ) = $this->agent_browsing_while_another_grant_expired();
		CapabilityGuard::register();
		$before = get_option( 'admin_email' );
		add_action(
			'delete_user',
			function () {
				update_option( 'admin_email', 'x@example.com' );
			}
		);

		$this->assertTrue( Grants::revoke( $b_id ) );

		$this->assertFalse( get_userdata( $b_user ) );
		$this->assertSame( $before, get_option( 'admin_email' ) );
	}

	public function test_retry_orphans_in_a_temp_users_request_logs_nothing_under_that_agent() {
		list( $a_id, , $b_id, $b_user ) = $this->agent_browsing_while_another_grant_expired();
		// Without the marker the delete fails, so the revoked grant keeps its user.
		delete_user_meta( $b_user, 'happyaccess_temp_user' );
		$this->assertTrue( Grants::revoke( $b_id ) );
		$this->assertNotFalse( get_userdata( $b_user ) );
		update_user_meta( $b_user, 'happyaccess_temp_user', 1 );

		$this->assertSame( 1, Grants::retry_orphans() );

		$this->assertFalse( get_userdata( $b_user ) );
		$this->assertSame( array(), $this->role_and_settings_rows( $a_id ) );
	}
}
