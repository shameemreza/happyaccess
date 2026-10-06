<?php
/**
 * Resets plugin static state before every test.
 *
 * @package HappyAccess
 */

use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;

/**
 * The site key is cached in a static property, but the WP test transaction
 * rolls back the option row after each test. Without this reset a test can
 * see a cached key whose option no longer exists.
 */
class HappyAccess_Test_Reset_State_Listener implements TestListener {

	use TestListenerDefaultImplementation;

	/**
	 * Clears static caches before a test starts.
	 *
	 * @param Test $test Test about to run.
	 * @return void
	 */
	public function startTest( Test $test ): void {
		\HappyAccess\Core\Secrets::reset_cache();
		if ( class_exists( '\HappyAccess\Features\SupportAccess\Grants' ) ) {
			\HappyAccess\Features\SupportAccess\Grants::flush_cache();
		}
	}
}
