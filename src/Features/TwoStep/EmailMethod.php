<?php
/**
 * Two-step login codes sent by email.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\ClientIp;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Mailer;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One row in the challenges table is one emailed code. Only the hash is
 * stored. The row belongs to a user, not to a browser: the pending login that
 * asks for the code is what ties it to one.
 *
 * Every consume is one UPDATE that sets used_at where it is still empty, and
 * the login follows only when that UPDATE changed one row.
 */
final class EmailMethod {

	const PURPOSE = 'twostep_email';

	/**
	 * Wrong codes a code takes before it is cancelled.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Digits in a code.
	 */
	const CODE_DIGITS = 6;

	/**
	 * Seconds a code lives.
	 */
	const LIFETIME = 600;

	/**
	 * Emails waiting for the end of the request.
	 *
	 * @var array
	 */
	private static $queue = array();

	/**
	 * Makes a code for the user and queues the email for the end of the
	 * request. Any earlier open code of that user is cancelled first.
	 *
	 * @param \WP_User $user       The user logging in.
	 * @param int      $pending_id The pending login the code is for. Not used yet: the
	 *                             step that creates pending logins ties its code to this id.
	 * @return true|\WP_Error
	 */
	public static function send( \WP_User $user, $pending_id ) {
		global $wpdb;

		unset( $pending_id );

		if ( $user->ID < 1 ) {
			return new \WP_Error( 'happyaccess_bad_request', __( 'The login code could not be made. Try again later.', 'happyaccess' ) );
		}
		if ( ! Secrets::is_persisted() ) {
			return new \WP_Error( 'happyaccess_no_site_key', __( 'The login code could not be made. Try again later.', 'happyaccess' ) );
		}

		$table = Installer::table( 'challenges' );
		$now   = Clock::now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE user_id = %d AND purpose = %s AND used_at IS NULL", Clock::mysql( $now ), (int) $user->ID, self::PURPOSE ) );

		$code      = Codes::numeric( self::CODE_DIGITS );
		$expires   = $now + self::LIFETIME;
		$client_ip = ClientIp::get();
		if ( Settings::get( 'privacy.anonymize_ip', false ) ) {
			$client_ip = ClientIp::anonymize( $client_ip );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$stored = $wpdb->insert(
			$table,
			array(
				'user_id'    => (int) $user->ID,
				'purpose'    => self::PURPOSE,
				'code_hash'  => Codes::hash_code( $code, Codes::PURPOSE_TWOSTEP_EMAIL ),
				'attempts'   => 0,
				'ip'         => substr( $client_ip, 0, 45 ),
				'created_at' => Clock::mysql( $now ),
				'expires_at' => Clock::mysql( $expires ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $stored ) {
			return new \WP_Error( 'happyaccess_not_stored', __( 'The login code could not be made. Try again later.', 'happyaccess' ) );
		}

		self::$queue[] = array(
			'to'         => $user->user_email,
			'code'       => $code,
			'expires_at' => $expires,
			'ip'         => $client_ip,
		);
		add_action( 'shutdown', array( __CLASS__, 'flush_queue' ) );
		return true;
	}

	/**
	 * Checks a typed code against the newest open code of a user. A wrong
	 * code counts a try. The fifth wrong try cancels the code.
	 *
	 * @param int    $user_id User id.
	 * @param string $code    Typed code.
	 * @return true|\WP_Error
	 */
	public static function verify( $user_id, $code ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return self::invalid_code();
		}

		$table = Installer::table( 'challenges' );
		$now   = Clock::mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, code_hash, created_at FROM {$table} WHERE purpose = %s AND user_id = %d AND used_at IS NULL AND expires_at > %s ORDER BY id DESC LIMIT 1", self::PURPOSE, $user_id, $now ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return self::invalid_code();
		}

		$id = (int) $row['id'];
		if ( is_string( $code ) && self::CODE_DIGITS === strlen( Codes::normalize_code( $code ) ) && Codes::verify_code( $code, (string) $row['code_hash'], Codes::PURPOSE_TWOSTEP_EMAIL, Codes::accepts_legacy( $row['created_at'] ) ) ) {
			return self::consume( $id ) ? true : self::invalid_code();
		}

		// One statement. used_at comes first and reads the count before this try, which MySQL and SQLite both do for the first assignment, so the try that reaches the limit closes the code on both.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$counted = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = CASE WHEN attempts + 1 >= %d THEN %s ELSE used_at END, attempts = attempts + 1 WHERE id = %d AND used_at IS NULL AND attempts < %d", self::MAX_ATTEMPTS, $now, $id, self::MAX_ATTEMPTS ) );
		if ( 1 !== $counted ) {
			return self::invalid_code();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", $id ) );
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return new \WP_Error( 'happyaccess_code_locked', __( 'Too many wrong codes. Ask for a new code.', 'happyaccess' ) );
		}
		return self::invalid_code();
	}

	/**
	 * Sends the queued emails. Runs on shutdown, after the response has gone
	 * to the visitor where the server allows it, so the response time doesn't
	 * depend on wp_mail().
	 *
	 * @return void
	 */
	public static function flush_queue() {
		if ( empty( self::$queue ) ) {
			return;
		}

		$queue       = self::$queue;
		self::$queue = array();

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		foreach ( $queue as $item ) {
			$minutes = max( 1, (int) ceil( max( 0, $item['expires_at'] - Clock::now() ) / MINUTE_IN_SECONDS ) );
			Mailer::send(
				$item['to'],
				__( 'Your two-step login code', 'happyaccess' ),
				'twostep-code',
				array(
					'code'    => Codes::format_code( $item['code'] ),
					'minutes' => $minutes,
					'ip'      => $item['ip'],
				)
			);
		}
	}

	/**
	 * Marks one code used. Only the call that changes the row wins.
	 *
	 * @param int $id Row id.
	 * @return bool Whether this call used it up.
	 */
	private static function consume( $id ) {
		global $wpdb;

		$table = Installer::table( 'challenges' );
		$now   = Clock::mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE id = %d AND used_at IS NULL AND expires_at > %s AND attempts < %d", $now, (int) $id, $now, self::MAX_ATTEMPTS ) );
	}

	/**
	 * The error for a code that is wrong, expired or already used. One
	 * message for all, so it says nothing about which.
	 *
	 * @return \WP_Error
	 */
	private static function invalid_code() {
		return new \WP_Error( 'happyaccess_invalid_code', __( 'That code is not right or has expired.', 'happyaccess' ) );
	}
}
