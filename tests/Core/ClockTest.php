<?php
/**
 * Clock tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Clock;

class ClockTest extends WP_UnitTestCase {

	public function tear_down() {
		Clock::freeze( null );
		parent::tear_down();
	}

	public function test_freeze_and_mysql_round_trip() {
		Clock::freeze( 1790000000 );
		$this->assertSame( 1790000000, Clock::now() );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', 1790000000 ), Clock::mysql() );
		$this->assertSame( 1790000000, Clock::from_mysql( Clock::mysql() ) );
	}

	public function test_unfrozen_clock_follows_time() {
		Clock::freeze( null );
		$this->assertEqualsWithDelta( time(), Clock::now(), 2 );
	}
}
