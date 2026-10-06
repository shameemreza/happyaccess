<?php
/**
 * Time source with a freeze switch for tests.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * All HappyAccess time math goes through this class. Times are UTC.
 */
final class Clock {

	/**
	 * Frozen timestamp, or null for the real time.
	 *
	 * @var int|null
	 */
	private static $frozen = null;

	/**
	 * Current Unix timestamp.
	 *
	 * @return int
	 */
	public static function now() {
		return null === self::$frozen ? time() : self::$frozen;
	}

	/**
	 * Freezes the clock. Pass null to unfreeze.
	 *
	 * @param int|null $timestamp Timestamp or null.
	 * @return void
	 */
	public static function freeze( $timestamp ) {
		self::$frozen = null === $timestamp ? null : (int) $timestamp;
	}

	/**
	 * UTC MySQL datetime for a timestamp (default: now).
	 *
	 * @param int|null $timestamp Timestamp.
	 * @return string
	 */
	public static function mysql( $timestamp = null ) {
		return gmdate( 'Y-m-d H:i:s', null === $timestamp ? self::now() : (int) $timestamp );
	}

	/**
	 * Timestamp from a UTC MySQL datetime. Returns 0 for empty input.
	 *
	 * @param string $datetime Datetime string.
	 * @return int
	 */
	public static function from_mysql( $datetime ) {
		if ( ! is_string( $datetime ) || '' === $datetime ) {
			return 0;
		}
		$time = strtotime( $datetime . ' UTC' );
		return false === $time ? 0 : $time;
	}
}
