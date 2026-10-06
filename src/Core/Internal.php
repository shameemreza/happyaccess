<?php
/**
 * Marks HappyAccess's own writes so the guards let them through.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Core cannot import Features, so the option guard reads this flag instead.
 * Wrap a write in run() and the guard skips its protection for that call.
 */
final class Internal {

	/**
	 * How many run() calls are open right now.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Runs a callable with the bypass on and returns what it returns. The
	 * counter is restored even when the callable throws.
	 *
	 * @param callable $callback Work to run.
	 * @return mixed
	 */
	public static function run( callable $callback ) {
		++self::$depth;
		try {
			return $callback();
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Whether a run() call is open.
	 *
	 * @return bool
	 */
	public static function active() {
		return self::$depth > 0;
	}

	/**
	 * Closes every run() call. For tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$depth = 0;
	}
}
