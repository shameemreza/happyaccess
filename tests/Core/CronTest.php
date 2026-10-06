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
use HappyAccess\Features\SupportAccess\Grants;

class CronTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		delete_transient( 'happyaccess_cleanup_ran' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
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
}
