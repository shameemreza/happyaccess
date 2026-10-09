<?php
/**
 * AdminBar tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\AdminBar;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class AdminBarTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::register();
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Grants::flush_cache();
	}

	public function tear_down() {
		Clock::freeze( null );
		parent::tear_down();
	}

	private function bar() {
		$bar = new WP_Admin_Bar();
		$bar->initialize();
		AdminBar::add_nodes( $bar );
		return $bar;
	}

	public function test_owner_sees_lock_only_when_grants_exist() {
		$this->assertNull( $this->bar()->get_node( 'happyaccess-lock' ) );
		Grants::create( array( 'label' => 'Acme' ) );
		$this->assertNotNull( $this->bar()->get_node( 'happyaccess-lock' ) );
	}

	public function test_temp_user_sees_timer_and_end_session_not_lock() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		wp_set_current_user( $temp );
		$bar = $this->bar();
		$this->assertNotNull( $bar->get_node( 'happyaccess-timer' ) );
		$this->assertNotNull( $bar->get_node( 'happyaccess-end' ) );
		$this->assertNull( $bar->get_node( 'happyaccess-lock' ) );
	}

	public function test_the_countdown_script_picks_plurals_through_wp_i18n() {
		$GLOBALS['wp_scripts'] = null;
		$made                  = Grants::create( array( 'label' => 'Acme' ) );
		wp_set_current_user( TempUsers::get_or_create( Grants::get( $made['id'] ) ) );
		add_filter( 'show_admin_bar', '__return_true' );

		AdminBar::enqueue();

		$script = wp_scripts()->registered['happyaccess-admin-bar'];
		$this->assertContains( 'wp-i18n', $script->deps );
		$this->assertSame( 'happyaccess', $script->textdomain );
		$this->assertSame( HAPPYACCESS_PLUGIN_DIR . 'languages', $script->translations_path );
		$data = (string) wp_scripts()->get_data( 'happyaccess-admin-bar', 'data' );
		$this->assertStringNotContainsString( '%d mins', $data, 'No singular and plural pair picked by the English rule.' );
		$this->assertStringContainsString( 'less than a minute', $data );

		$js = (string) file_get_contents( HAPPYACCESS_PLUGIN_DIR . 'assets/admin-bar.js' );
		foreach ( array( "_n( '%d min', '%d mins', n, 'happyaccess' )", "_n( '%d hour', '%d hours', n, 'happyaccess' )", "_n( '%d day', '%d days', n, 'happyaccess' )" ) as $call ) {
			$this->assertStringContainsString( $call, $js );
		}
		$this->assertStringNotContainsString( 'n === 1', $js );
		$GLOBALS['wp_scripts'] = null;
	}

	public function test_emergency_lock_revokes_everything() {
		Grants::create( array( 'label' => 'a' ) );
		Grants::create( array( 'label' => 'b' ) );
		$this->assertSame( 2, AdminBar::emergency_lock() );
		$this->assertFalse( Grants::has_current() );
	}

	public function test_timer_carries_expiry_and_owner_has_no_timer() {
		$made  = Grants::create( array( 'label' => 'Acme' ) );
		$grant = Grants::get( $made['id'] );
		$this->assertNull( $this->bar()->get_node( 'happyaccess-timer' ) );

		wp_set_current_user( TempUsers::get_or_create( $grant ) );
		$node = $this->bar()->get_node( 'happyaccess-timer' );
		$this->assertStringContainsString( 'data-expires="' . $grant['expires_at'] . '"', $node->title );
	}

	public function test_time_left_shows_days_and_hours_under_three_days() {
		$this->assertSame( '2 days 23 hours', AdminBar::time_left( 2 * DAY_IN_SECONDS + 23 * HOUR_IN_SECONDS + 59 ) );
		$this->assertSame( '1 day 1 hour', AdminBar::time_left( DAY_IN_SECONDS + HOUR_IN_SECONDS ) );
		$this->assertSame( '2 days', AdminBar::time_left( 2 * DAY_IN_SECONDS + 30 * MINUTE_IN_SECONDS ) );
	}

	public function test_time_left_rounds_to_the_nearest_day_from_three_days() {
		$this->assertSame( '4 days', AdminBar::time_left( 3 * DAY_IN_SECONDS + 20 * HOUR_IN_SECONDS ) );
		$this->assertSame( '3 days', AdminBar::time_left( 3 * DAY_IN_SECONDS + 5 * HOUR_IN_SECONDS ) );
	}

	public function test_time_left_under_a_day() {
		$this->assertSame( '2 hours 45 mins', AdminBar::time_left( 2 * HOUR_IN_SECONDS + 45 * MINUTE_IN_SECONDS ) );
		$this->assertSame( '1 hour', AdminBar::time_left( HOUR_IN_SECONDS + 20 ) );
		$this->assertSame( '5 hours', AdminBar::time_left( 4 * HOUR_IN_SECONDS + 40 * MINUTE_IN_SECONDS ) );
		$this->assertSame( '1 min', AdminBar::time_left( 90 ) );
		$this->assertSame( '59 mins', AdminBar::time_left( HOUR_IN_SECONDS - 1 ) );
		$this->assertSame( 'less than a minute', AdminBar::time_left( 59 ) );
	}

	public function test_timer_text_does_not_floor_two_days_23_hours_to_two_days() {
		$made  = Grants::create( array( 'label' => 'Acme' ) );
		$grant = Grants::get( $made['id'] );
		Clock::freeze( (int) $grant['expires_at'] - ( 2 * DAY_IN_SECONDS + 23 * HOUR_IN_SECONDS ) );

		wp_set_current_user( TempUsers::get_or_create( $grant ) );
		$node = $this->bar()->get_node( 'happyaccess-timer' );
		$this->assertStringContainsString( 'Temporary access ends in 2 days 23 hours', $node->title );
	}

	public function test_emergency_lock_counts_only_current_passes() {
		Clock::freeze( 1790000000 );
		$current = Grants::create( array( 'label' => 'Current' ) );
		$stale   = Grants::create( array( 'label' => 'Stale', 'duration' => HOUR_IN_SECONDS ) );
		Clock::freeze( 1790000000 + 2 * HOUR_IN_SECONDS );

		$this->assertSame( 1, AdminBar::emergency_lock() );
		$this->assertNotSame( 0, Grants::get( $current['id'] )['revoked_at'] );
		$this->assertNotSame( 0, Grants::get( $stale['id'] )['revoked_at'] );
		$row = \HappyAccess\Core\AuditLog::query( array( 'event' => 'emergency_lock' ) )['items'][0];
		$this->assertSame( 'Emergency lock ended 1 grant.', $row['summary'] );
	}
}
