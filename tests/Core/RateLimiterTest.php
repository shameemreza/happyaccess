<?php
/**
 * RateLimiter tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Clock;
use HappyAccess\Core\Installer;
use HappyAccess\Core\RateLimiter;
use HappyAccess\Core\Settings;

class RateLimiterTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Installer::install();
		Clock::freeze( 1790000000 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	public function tear_down() {
		Clock::freeze( null );
		parent::tear_down();
	}

	private function hits( $count, $subject = '203.0.113.9' ) {
		for ( $i = 0; $i < $count; $i++ ) {
			RateLimiter::hit( 'support_code', 'ip', $subject );
		}
	}

	public function test_under_limit_is_allowed() {
		$this->hits( 4 );
		$this->assertSame( 4, RateLimiter::count( 'support_code', 'ip', '203.0.113.9', 900 ) );
		$this->assertSame( 0, RateLimiter::retry_after( 'support_code', 'ip', '203.0.113.9', 5, 900, 1800 ) );
	}

	public function test_limit_locks_until_lockout_passes() {
		$this->hits( 5 );
		$this->assertSame( 1800, RateLimiter::retry_after( 'support_code', 'ip', '203.0.113.9', 5, 900, 1800 ) );

		Clock::freeze( 1790000000 + 1000 );
		$this->assertSame( 800, RateLimiter::retry_after( 'support_code', 'ip', '203.0.113.9', 5, 900, 1800 ) );

		Clock::freeze( 1790000000 + 1801 );
		$this->assertSame( 0, RateLimiter::retry_after( 'support_code', 'ip', '203.0.113.9', 5, 900, 1800 ) );
	}

	public function test_hits_spread_wider_than_window_do_not_lock() {
		for ( $i = 0; $i < 5; $i++ ) {
			Clock::freeze( 1790000000 + $i * 300 );
			RateLimiter::hit( 'support_code', 'ip', '203.0.113.9' );
		}
		$this->assertSame( 0, RateLimiter::retry_after( 'support_code', 'ip', '203.0.113.9', 5, 900, 1800 ) );
	}

	public function test_subjects_scopes_and_clear_are_isolated() {
		$this->hits( 5 );
		$this->assertSame( 0, RateLimiter::retry_after( 'support_code', 'ip', '198.51.100.1', 5, 900, 1800 ) );
		$this->assertSame( 0, RateLimiter::retry_after( 'support_code', 'site', '203.0.113.9', 5, 900, 1800 ) );

		RateLimiter::clear( 'support_code', 'ip', '203.0.113.9' );
		$this->assertSame( 0, RateLimiter::count( 'support_code', 'ip', '203.0.113.9', 900 ) );
	}

	public function test_ip_subject_groups_ipv6_by_64() {
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::a';
		$first                  = RateLimiter::ip_subject();
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:ffff::9';
		$this->assertSame( $first, RateLimiter::ip_subject() );
	}

	public function test_purge() {
		$this->hits( 2 );
		Clock::freeze( 1790000000 + 2 * DAY_IN_SECONDS );
		$this->assertSame( 2, RateLimiter::purge( DAY_IN_SECONDS ) );
	}
}
