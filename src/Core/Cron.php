<?php
/**
 * Scheduled cleanup.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

use HappyAccess\Features\SupportAccess\Grants;

defined( 'ABSPATH' ) || exit;

/**
 * Ends expired grants and deletes old rows once an hour. When WP-Cron is off
 * or stalled, a throttled run on admin load covers for it.
 */
final class Cron {

	const HOOK = 'happyaccess_hourly';

	const FALLBACK_TRANSIENT = 'happyaccess_cleanup_ran';

	const FALLBACK_INTERVAL = 600;

	const BATCH_SIZE = 500;

	const MAX_BATCHES = 10;

	/**
	 * Hooks the cleanup, and its scheduling to init.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'admin_init', array( self::class, 'maybe_run_fallback' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the cleanup when it isn't scheduled yet. It waits for init
	 * because wp_schedule_event() runs the cron_schedules filter, and other
	 * plugins translate their schedule names there, which loads their
	 * translations too early when it runs on plugins_loaded.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		}
	}

	/**
	 * Removes the scheduled event.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Runs every cleanup step.
	 *
	 * @return array Counts for grants, grants_purged, logs, attempts and challenges.
	 */
	public static function run() {
		$retention = (int) Settings::get( 'privacy.retention_days', 30 );
		$counts    = array(
			'grants'        => class_exists( Grants::class ) ? (int) Grants::cleanup_expired() : 0,
			'grants_purged' => self::purge_ended_grants( $retention ),
			'logs'          => (int) AuditLog::purge( $retention ),
			'attempts'      => (int) RateLimiter::purge( DAY_IN_SECONDS ),
			'challenges'    => self::purge_challenges(),
		);

		return (array) apply_filters( 'happyaccess_cleanup_counts', $counts );
	}

	/**
	 * Runs the cleanup from an admin request, at most once every ten minutes.
	 *
	 * @return void
	 */
	public static function maybe_run_fallback() {
		// A temp user's request would end other grants, and the activity log would pin those writes on that agent.
		if ( Capabilities::is_temp_user( get_current_user_id() ) ) {
			return;
		}
		if ( false !== get_transient( self::FALLBACK_TRANSIENT ) ) {
			return;
		}
		// Set the flag first so a slow run doesn't let parallel requests start their own.
		set_transient( self::FALLBACK_TRANSIENT, 1, self::FALLBACK_INTERVAL );
		self::run();
	}

	/**
	 * Deletes grants that ended more than the retention period ago. A grant
	 * whose temp user still exists is kept, so the orphan retry can finish it.
	 *
	 * @param int $days Retention days.
	 * @return int Rows deleted.
	 */
	private static function purge_ended_grants( $days ) {
		global $wpdb;
		$table  = Installer::table( 'tokens' );
		$cutoff = Clock::mysql( Clock::now() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		return self::delete_in_batches(
			static function () use ( $wpdb, $table, $cutoff ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
				return $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE revoked_at IS NOT NULL AND revoked_at < %s AND ( user_id IS NULL OR user_id = 0 ) LIMIT %d', $table, $cutoff, self::BATCH_SIZE ) );
			}
		);
	}

	/**
	 * Deletes login challenges that expired more than a day ago.
	 *
	 * @return int Rows deleted.
	 */
	private static function purge_challenges() {
		global $wpdb;
		$table  = Installer::table( 'challenges' );
		$cutoff = Clock::mysql( Clock::now() - DAY_IN_SECONDS );
		return self::delete_in_batches(
			static function () use ( $wpdb, $table, $cutoff ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
				return $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s LIMIT %d', $table, $cutoff, self::BATCH_SIZE ) );
			}
		);
	}

	/**
	 * Runs a delete of up to BATCH_SIZE rows again and again, so one run
	 * never holds a long lock. What a capped run leaves behind goes in the
	 * next one.
	 *
	 * @param callable $delete_batch Deletes one batch and returns the row count.
	 * @return int Rows deleted.
	 */
	private static function delete_in_batches( $delete_batch ) {
		$total = 0;
		for ( $i = 0; $i < self::MAX_BATCHES; $i++ ) {
			$deleted = (int) call_user_func( $delete_batch );
			$total  += $deleted;
			if ( $deleted < self::BATCH_SIZE ) {
				break;
			}
		}
		return $total;
	}
}
