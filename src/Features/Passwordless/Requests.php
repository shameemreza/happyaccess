<?php
/**
 * Passwordless login requests: a login code and a login link per email.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\Passwordless;

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\ClientIp;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One row in the challenges table is one emailed login code and login link.
 * Only hashes are stored. The code is tied to the browser that asked for it
 * through a request key, which that browser keeps in a cookie.
 *
 * Every consume is one UPDATE that sets used_at where it is still empty, and
 * a login follows only when that UPDATE changed one row.
 */
final class Requests {

	const PURPOSE = 'passwordless';

	/**
	 * Wrong codes a request takes before it is cancelled.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Digits in a login code.
	 */
	const CODE_DIGITS = 6;

	/**
	 * Starts a request for a user. Any earlier unused request of that user
	 * is cancelled first, so a new email replaces the old code and link.
	 *
	 * @param \WP_User $user        User asking to log in.
	 * @param string   $request_key Random key that the asking browser keeps.
	 * @return array|\WP_Error code, link_key and expires_at.
	 */
	public static function create( $user, $request_key ) {
		global $wpdb;

		if ( ! $user instanceof \WP_User || $user->ID < 1 || ! is_string( $request_key ) || '' === $request_key ) {
			return new \WP_Error( 'happyaccess_bad_request', __( 'The login request is not valid.', 'happyaccess' ) );
		}
		if ( ! Secrets::is_persisted() ) {
			return new \WP_Error( 'happyaccess_no_site_key', __( 'Login codes could not be made. Try again later.', 'happyaccess' ) );
		}

		if ( ! self::allowed( $user ) ) {
			return new \WP_Error( 'happyaccess_not_allowed', __( 'Login codes could not be made. Try again later.', 'happyaccess' ) );
		}

		$table = Installer::table( 'challenges' );
		$now   = Clock::now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE user_id = %d AND purpose = %s AND used_at IS NULL", Clock::mysql( $now ), (int) $user->ID, self::PURPOSE ) );

		$code      = Codes::numeric( self::CODE_DIGITS );
		$link_key  = Codes::link_key();
		$expires   = $now + (int) Settings::get( 'passwordless.code_lifetime' );
		$client_ip = ClientIp::get();
		if ( Settings::get( 'privacy.anonymize_ip', false ) ) {
			$client_ip = ClientIp::anonymize( $client_ip );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$stored = $wpdb->insert(
			$table,
			array(
				'user_id'          => (int) $user->ID,
				'purpose'          => self::PURPOSE,
				'code_hash'        => Codes::hash_code( $code ),
				'link_hash'        => Codes::hash_key( $link_key ),
				'request_key_hash' => Codes::hash_key( $request_key ),
				'attempts'         => 0,
				'ip'               => substr( $client_ip, 0, 45 ),
				'created_at'       => Clock::mysql( $now ),
				'expires_at'       => Clock::mysql( $expires ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $stored ) {
			return new \WP_Error( 'happyaccess_not_stored', __( 'Login codes could not be made. Try again later.', 'happyaccess' ) );
		}

		return array(
			'code'       => $code,
			'link_key'   => $link_key,
			'expires_at' => $expires,
		);
	}

	/**
	 * Checks a typed login code against the newest open request of a browser.
	 * A wrong code counts a try. The fifth wrong try cancels the request.
	 *
	 * @param string $request_key Key the browser kept from create().
	 * @param string $code        Typed code.
	 * @return \WP_User|\WP_Error
	 */
	public static function verify_code( $request_key, $code ) {
		global $wpdb;

		if ( ! is_string( $request_key ) || '' === $request_key ) {
			return self::invalid_code();
		}

		$table = Installer::table( 'challenges' );
		$now   = Clock::mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, code_hash FROM {$table} WHERE purpose = %s AND request_key_hash = %s AND used_at IS NULL AND expires_at > %s ORDER BY id DESC LIMIT 1", self::PURPOSE, Codes::hash_key( $request_key ), $now ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return self::invalid_code();
		}

		$id = (int) $row['id'];
		if ( is_string( $code ) && self::CODE_DIGITS === strlen( Codes::normalize_code( $code ) ) && Codes::verify_code( $code, (string) $row['code_hash'] ) ) {
			if ( ! self::consume( $id ) ) {
				return self::invalid_code();
			}
			return self::user_for( (int) $row['user_id'], false );
		}

		// One statement: MySQL applies the assignments left to right, so the lockout sees the raised count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$counted = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1, used_at = IF( attempts >= %d, %s, used_at ) WHERE id = %d AND used_at IS NULL AND attempts < %d", self::MAX_ATTEMPTS, $now, $id, self::MAX_ATTEMPTS ) );
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
	 * Finds the open request for a link key without using it up. The confirm
	 * screen reads it before the visitor presses the button.
	 *
	 * @param string $link_key Key from the emailed link.
	 * @return array|null id, user_id, created_at and expires_at (Unix times), or null when there is no open request.
	 */
	public static function find_by_link( $link_key ) {
		global $wpdb;

		if ( ! is_string( $link_key ) || '' === $link_key ) {
			return null;
		}

		$table = Installer::table( 'challenges' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, link_hash, created_at, expires_at FROM {$table} WHERE purpose = %s AND link_hash = %s AND used_at IS NULL AND expires_at > %s ORDER BY id DESC LIMIT 1", self::PURPOSE, Codes::hash_key( $link_key ), Clock::mysql() ), ARRAY_A );
		if ( ! is_array( $row ) || ! Codes::verify_key( $link_key, (string) $row['link_hash'] ) ) {
			return null;
		}

		return array(
			'id'         => (int) $row['id'],
			'user_id'    => (int) $row['user_id'],
			'created_at' => Clock::from_mysql( $row['created_at'] ),
			'expires_at' => Clock::from_mysql( $row['expires_at'] ),
		);
	}

	/**
	 * Uses up a link key and returns its user. Single use.
	 *
	 * @param string $link_key Key from the emailed link.
	 * @return \WP_User|\WP_Error
	 */
	public static function consume_link( $link_key ) {
		$row = self::find_by_link( $link_key );
		if ( null === $row || ! self::consume( $row['id'] ) ) {
			return self::invalid_link();
		}
		return self::user_for( $row['user_id'], true );
	}

	/**
	 * The account a login request may be made for.
	 *
	 * A support user made by Support Access is treated as missing. On a
	 * network, the user must belong to the current site.
	 *
	 * @param string $login_or_email Email address or username as typed.
	 * @return \WP_User|null
	 */
	public static function eligible( $login_or_email ) {
		if ( ! is_string( $login_or_email ) ) {
			return null;
		}
		$typed = trim( $login_or_email );
		if ( '' === $typed ) {
			return null;
		}

		$user = false;
		if ( false !== strpos( $typed, '@' ) ) {
			$user = get_user_by( 'email', $typed );
		}
		if ( ! $user ) {
			$user = get_user_by( 'login', $typed );
		}
		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		return self::allowed( $user ) ? $user : null;
	}

	/**
	 * Whether a found user may use passwordless login. Checked when a request
	 * is made and again when a code or link is used, because a user can become
	 * a support user or be filtered out in between.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	private static function allowed( $user ) {
		if ( Capabilities::is_temp_user( $user->ID ) ) {
			return false;
		}
		if ( is_multisite() && ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
			return false;
		}
		/**
		 * Filters whether a user may log in without a password.
		 *
		 * @param bool     $allowed Whether the user may. Default true.
		 * @param \WP_User $user    The user.
		 */
		return (bool) apply_filters( 'happyaccess_passwordless_allowed', true, $user );
	}

	/**
	 * Marks one request used. Only the call that changes the row wins.
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
	 * The user of a consumed request. The request stays used up when this
	 * returns an error.
	 *
	 * @param int  $user_id  User id.
	 * @param bool $for_link Whether the request was used through its link.
	 * @return \WP_User|\WP_Error
	 */
	private static function user_for( $user_id, $for_link ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User || ! self::allowed( $user ) ) {
			return $for_link ? self::invalid_link() : self::invalid_code();
		}
		return $user;
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

	/**
	 * The error for a link that is wrong, expired or already used.
	 *
	 * @return \WP_Error
	 */
	private static function invalid_link() {
		return new \WP_Error( 'happyaccess_invalid_link', __( 'This login link is not valid or has expired. Ask for a new one.', 'happyaccess' ) );
	}
}
