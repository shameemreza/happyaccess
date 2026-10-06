<?php
/**
 * Failed attempt counting and lockouts.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Counts failures per action, scope and subject. Subjects are stored as a
 * keyed hash, so the table never holds raw emails or codes.
 */
final class RateLimiter {

	/**
	 * Records one failure.
	 *
	 * @param string $action  Action key, for example "support_code".
	 * @param string $scope   ip, account or site.
	 * @param string $subject IP bucket, user id or "site".
	 * @return void
	 */
	public static function hit( $action, $scope, $subject ) {
		global $wpdb;
		$ip = ClientIp::get();
		if ( Settings::get( 'privacy.anonymize_ip', false ) ) {
			$ip = ClientIp::anonymize( $ip );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$wpdb->insert(
			Installer::table( 'attempts' ),
			array(
				'identifier'   => self::identifier( $action, $subject ),
				'attempt_type' => substr( sanitize_key( $action ), 0, 20 ),
				'scope'        => sanitize_key( $scope ),
				'ip_address'   => $ip,
				'attempted_at' => Clock::mysql(),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Failures inside a window.
	 *
	 * @param string $action  Action key.
	 * @param string $scope   Scope.
	 * @param string $subject Subject.
	 * @param int    $window  Seconds.
	 * @return int
	 */
	public static function count( $action, $scope, $subject, $window ) {
		global $wpdb;
		$table = Installer::table( 'attempts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE identifier = %s AND scope = %s AND attempted_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
				self::identifier( $action, $subject ),
				sanitize_key( $scope ),
				Clock::mysql( Clock::now() - (int) $window )
			)
		);
	}

	/**
	 * Seconds until the subject may try again. 0 means allowed.
	 *
	 * Locked when $limit failures happened inside one $window; the lock lasts
	 * $lockout seconds from the newest of those failures.
	 *
	 * @param string $action  Action key.
	 * @param string $scope   Scope.
	 * @param string $subject Subject.
	 * @param int    $limit   Failures allowed.
	 * @param int    $window  Seconds the failures must fall inside.
	 * @param int    $lockout Lock length in seconds.
	 * @return int
	 */
	public static function retry_after( $action, $scope, $subject, $limit, $window, $lockout ) {
		global $wpdb;
		$limit = max( 1, (int) $limit );
		$table = Installer::table( 'attempts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$times = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT attempted_at FROM {$table} WHERE identifier = %s AND scope = %s AND attempted_at >= %s ORDER BY attempted_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
				self::identifier( $action, $subject ),
				sanitize_key( $scope ),
				Clock::mysql( Clock::now() - max( (int) $window, (int) $lockout ) ),
				$limit
			)
		);

		if ( count( $times ) < $limit ) {
			return 0;
		}

		$newest = Clock::from_mysql( $times[0] );
		$oldest = Clock::from_mysql( $times[ count( $times ) - 1 ] );
		if ( $newest - $oldest > (int) $window ) {
			return 0;
		}

		return max( 0, (int) $lockout - ( Clock::now() - $newest ) );
	}

	/**
	 * Removes failures for one subject, for example after a success.
	 *
	 * @param string $action  Action key.
	 * @param string $scope   Scope.
	 * @param string $subject Subject.
	 * @return void
	 */
	public static function clear( $action, $scope, $subject ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->delete(
			Installer::table( 'attempts' ),
			array(
				'identifier' => self::identifier( $action, $subject ),
				'scope'      => sanitize_key( $scope ),
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Deletes rows older than a number of seconds.
	 *
	 * @param int $older_than Seconds.
	 * @return int Rows deleted.
	 */
	public static function purge( $older_than ) {
		global $wpdb;
		$table = Installer::table( 'attempts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE attempted_at < %s", Clock::mysql( Clock::now() - (int) $older_than ) ) );
	}

	/**
	 * Rate limit subject for the current client IP.
	 *
	 * @return string
	 */
	public static function ip_subject() {
		return ClientIp::bucket( ClientIp::get() );
	}

	/**
	 * Stored identifier: action plus a keyed hash of the subject.
	 *
	 * @param string $action  Action key.
	 * @param string $subject Subject.
	 * @return string
	 */
	private static function identifier( $action, $subject ) {
		return substr( sanitize_key( $action ), 0, 40 ) . ':' . substr( Secrets::hmac( 'rl:' . (string) $subject ), 0, 40 );
	}
}
