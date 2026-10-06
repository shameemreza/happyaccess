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

	/**
	 * Hooks the cleanup and schedules it when it isn't scheduled yet.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'admin_init', array( self::class, 'maybe_run_fallback' ) );

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
	 * @return array Counts for grants, logs, attempts and challenges.
	 */
	public static function run() {
		$counts = array(
			'grants'     => class_exists( Grants::class ) ? (int) Grants::cleanup_expired() : 0,
			'logs'       => (int) AuditLog::purge( (int) Settings::get( 'privacy.retention_days', 30 ) ),
			'attempts'   => (int) RateLimiter::purge( DAY_IN_SECONDS ),
			'challenges' => self::purge_challenges(),
		);

		return apply_filters( 'happyaccess_cleanup_counts', $counts );
	}

	/**
	 * Runs the cleanup from an admin request, at most once every ten minutes.
	 *
	 * @return void
	 */
	public static function maybe_run_fallback() {
		if ( false !== get_transient( self::FALLBACK_TRANSIENT ) ) {
			return;
		}
		// Set the flag first so a slow run doesn't let parallel requests start their own.
		set_transient( self::FALLBACK_TRANSIENT, 1, self::FALLBACK_INTERVAL );
		self::run();
	}

	/**
	 * Deletes login challenges that expired more than a day ago.
	 *
	 * @return int Rows deleted.
	 */
	private static function purge_challenges() {
		global $wpdb;
		$table = Installer::table( 'challenges' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", Clock::mysql( Clock::now() - DAY_IN_SECONDS ) ) );
	}
}
