<?php
/**
 * Failed attempt counting and lockouts.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Counts tries per action, scope and subject. The subject is stored as a
 * keyed hash of its trimmed, lowercased form, so the table never holds raw
 * emails or codes. The ip_address column is a separate field: it holds the
 * client IP as is, unless the anonymize_ip privacy setting is on.
 *
 * Callers use attempt() before every verification and only verify when it
 * returns 0. On a success they call clear() for the "ip" and "account"
 * scopes, never for "site", so a valid guess can't wipe the site-wide count.
 */
final class RateLimiter {

	const SCOPES = array( 'ip', 'account', 'site' );

	/**
	 * Counts one try and says whether the caller may verify now. The check
	 * runs before the insert, and the count runs again after it, so parallel
	 * requests can't all slip through on the same stale count.
	 *
	 * @param string $action  Action key.
	 * @param string $scope   ip, account or site.
	 * @param string $subject Subject.
	 * @param int    $limit   Tries allowed inside the window.
	 * @param int    $window  Seconds.
	 * @param int    $lockout Lock length in seconds.
	 * @return int Seconds to wait. 0 means the caller may verify now.
	 */
	public static function attempt( $action, $scope, $subject, $limit, $window, $lockout ) {
		$wait = self::retry_after( $action, $scope, $subject, $limit, $window, $lockout );
		if ( $wait > 0 ) {
			return $wait;
		}

		if ( ! self::hit( $action, $scope, $subject ) ) {
			return max( 1, (int) $lockout );
		}

		if ( self::count( $action, $scope, $subject, $window ) > max( 1, (int) $limit ) ) {
			$wait = self::retry_after( $action, $scope, $subject, $limit, $window, $lockout );
			return $wait > 0 ? $wait : max( 1, (int) $lockout );
		}

		return 0;
	}

	/**
	 * Records one try.
	 *
	 * @param string $action  Action key, for example "support_code".
	 * @param string $scope   ip, account or site.
	 * @param string $subject IP bucket, user id or "site".
	 * @return bool Whether the row was stored.
	 * @throws \InvalidArgumentException For an unknown scope.
	 */
	public static function hit( $action, $scope, $subject ) {
		global $wpdb;
		self::check_scope( $scope );
		$ip = ClientIp::get();
		if ( Settings::get( 'privacy.anonymize_ip', false ) ) {
			$ip = ClientIp::anonymize( $ip );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$stored = $wpdb->insert(
			Installer::table( 'attempts' ),
			array(
				'identifier'   => self::identifier( $action, $subject ),
				'attempt_type' => substr( sanitize_key( $action ), 0, 20 ),
				'scope'        => $scope,
				'ip_address'   => $ip,
				'attempted_at' => Clock::mysql(),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		return false !== $stored;
	}

	/**
	 * Failures inside a window.
	 *
	 * @param string $action  Action key.
	 * @param string $scope   Scope.
	 * @param string $subject Subject.
	 * @param int    $window  Seconds.
	 * @return int
	 * @throws \InvalidArgumentException For an unknown scope.
	 */
	public static function count( $action, $scope, $subject, $window ) {
		global $wpdb;
		self::check_scope( $scope );
		$table = Installer::table( 'attempts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE identifier = %s AND scope = %s AND attempted_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
				self::identifier( $action, $subject ),
				$scope,
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
	 * @throws \InvalidArgumentException For an unknown scope.
	 */
	public static function retry_after( $action, $scope, $subject, $limit, $window, $lockout ) {
		global $wpdb;
		self::check_scope( $scope );
		$limit = max( 1, (int) $limit );
		$table = Installer::table( 'attempts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$times = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT attempted_at FROM {$table} WHERE identifier = %s AND scope = %s AND attempted_at >= %s ORDER BY attempted_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
				self::identifier( $action, $subject ),
				$scope,
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
	 * @throws \InvalidArgumentException For an unknown scope.
	 */
	public static function clear( $action, $scope, $subject ) {
		global $wpdb;
		self::check_scope( $scope );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->delete(
			Installer::table( 'attempts' ),
			array(
				'identifier' => self::identifier( $action, $subject ),
				'scope'      => $scope,
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
	 * Stored identifier: action plus a keyed hash of the normalised subject.
	 *
	 * @param string $action  Action key.
	 * @param string $subject Subject.
	 * @return string
	 */
	private static function identifier( $action, $subject ) {
		return substr( sanitize_key( $action ), 0, 40 ) . ':' . substr( Secrets::hmac( 'rl:' . strtolower( trim( (string) $subject ) ) ), 0, 40 );
	}

	/**
	 * Rejects a scope that is not ip, account or site.
	 *
	 * @param string $scope Scope.
	 * @return void
	 * @throws \InvalidArgumentException For an unknown scope.
	 */
	private static function check_scope( $scope ) {
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			throw new \InvalidArgumentException( 'HappyAccess does not know that rate limit scope.' );
		}
	}
}
