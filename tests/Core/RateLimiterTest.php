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
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			Clock::freeze( null );
		} finally {
			parent::tear_down();
		}
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

	public function test_forget_removes_only_the_newest_tries_since_a_time() {
		Clock::freeze( 1790000000 - 60 );
		$this->hits( 2 );
		Clock::freeze( 1790000000 );
		$this->hits( 4 );
		$this->hits( 3, '198.51.100.1' );

		RateLimiter::forget( 'support_code', 'ip', '203.0.113.9', 3, 1790000000 );
		$this->assertSame( 3, RateLimiter::count( 'support_code', 'ip', '203.0.113.9', 900 ) );
		$this->assertSame( 3, RateLimiter::count( 'support_code', 'ip', '198.51.100.1', 900 ), 'Other subjects keep their tries.' );

		RateLimiter::forget( 'support_code', 'ip', '203.0.113.9', 5, 1790000000 );
		$this->assertSame( 2, RateLimiter::count( 'support_code', 'ip', '203.0.113.9', 900 ), 'Tries before the time stay.' );
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

	public function test_attempt_allows_limit_tries_then_locks() {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertSame( 0, RateLimiter::attempt( 'support_code', 'ip', '203.0.113.9', 5, 900, 1800 ), 'Try ' . $i );
		}
		$this->assertSame( 1800, RateLimiter::attempt( 'support_code', 'ip', '203.0.113.9', 5, 900, 1800 ) );
	}

	public function test_a_locked_attempt_does_not_insert() {
		$this->hits( 5 );

		$this->assertGreaterThan( 0, RateLimiter::attempt( 'support_code', 'ip', '203.0.113.9', 5, 900, 1800 ) );
		$this->assertGreaterThan( 0, RateLimiter::attempt( 'support_code', 'ip', '203.0.113.9', 5, 900, 1800 ) );

		$this->assertSame( 5, RateLimiter::count( 'support_code', 'ip', '203.0.113.9', 900 ) );
	}

	public function test_attempt_fails_closed_when_the_insert_fails() {
		global $wpdb;
		$attempts = Installer::table( 'attempts' );
		$break    = static function ( $query ) use ( $attempts ) {
			return 0 === strpos( $query, "INSERT INTO `{$attempts}`" ) ? 'INSERT this is not valid sql' : $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		$wait = RateLimiter::attempt( 'support_code', 'ip', '203.0.113.9', 5, 900, 1800 );
		$hit  = RateLimiter::hit( 'support_code', 'ip', '203.0.113.9' );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertSame( 1800, $wait );
		$this->assertFalse( $hit );
		$this->assertSame( 0, RateLimiter::count( 'support_code', 'ip', '203.0.113.9', 900 ) );
	}

	public function test_a_working_insert_returns_true() {
		$this->assertTrue( RateLimiter::hit( 'support_code', 'ip', '203.0.113.9' ) );
	}

	public function test_unknown_scope_throws_everywhere() {
		$calls = array(
			static function () {
				RateLimiter::hit( 'support_code', 'user', 'x' );
			},
			static function () {
				RateLimiter::count( 'support_code', 'user', 'x', 900 );
			},
			static function () {
				RateLimiter::retry_after( 'support_code', 'user', 'x', 5, 900, 1800 );
			},
			static function () {
				RateLimiter::attempt( 'support_code', 'user', 'x', 5, 900, 1800 );
			},
			static function () {
				RateLimiter::clear( 'support_code', 'user', 'x' );
			},
		);
		foreach ( $calls as $index => $call ) {
			try {
				$call();
				$this->fail( 'Call ' . $index . ' accepted an unknown scope.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'scope', $e->getMessage() );
			}
		}
	}

	public function test_raw_subject_never_reaches_the_identifier_column() {
		global $wpdb;
		RateLimiter::hit( 'support_code', 'account', 'Person@Example.com' );

		$identifier = $wpdb->get_var( 'SELECT identifier FROM ' . Installer::table( 'attempts' ) );

		$this->assertStringStartsWith( 'support_code:', $identifier );
		$this->assertStringNotContainsStringIgnoringCase( 'person', $identifier );
		$this->assertStringNotContainsStringIgnoringCase( 'example', $identifier );
	}

	public function test_subject_is_trimmed_and_case_folded() {
		RateLimiter::hit( 'support_code', 'account', 'Person@Example.com' );
		RateLimiter::hit( 'support_code', 'account', '  person@example.COM ' );

		$this->assertSame( 2, RateLimiter::count( 'support_code', 'account', 'PERSON@example.com', 900 ) );
	}

	public function test_exactly_limit_hits_inside_the_window_lock_and_one_fewer_does_not() {
		for ( $i = 0; $i < 3; $i++ ) {
			Clock::freeze( 1790000000 + $i * 300 );
			RateLimiter::hit( 'support_code', 'ip', 'exact' );
		}
		$this->assertSame( 1800, RateLimiter::retry_after( 'support_code', 'ip', 'exact', 3, 600, 1800 ), 'Newest minus oldest equals the window.' );
		$this->assertSame( 0, RateLimiter::retry_after( 'support_code', 'ip', 'exact', 4, 600, 1800 ), 'One hit short of the limit.' );

		Clock::freeze( 1790000000 );
		RateLimiter::hit( 'support_code', 'ip', 'wide' );
		RateLimiter::hit( 'support_code', 'ip', 'wide' );
		Clock::freeze( 1790000000 + 601 );
		RateLimiter::hit( 'support_code', 'ip', 'wide' );
		$this->assertSame( 0, RateLimiter::retry_after( 'support_code', 'ip', 'wide', 3, 600, 1800 ), 'Newest minus oldest is one second over the window.' );
	}
}
