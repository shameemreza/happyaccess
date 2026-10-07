<?php
/**
 * Network upgrade and new site tests. Run with composer test:multisite.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Plugin;

/**
 * @group ms-required
 */
class MultisiteBootTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_site_option( Installer::NETWORK_OPTION );
		wp_clear_scheduled_hook( Installer::NETWORK_HOOK );
	}

	public function tear_down() {
		wp_clear_scheduled_hook( Installer::NETWORK_HOOK );
		parent::tear_down();
	}

	private function activate_network_wide() {
		update_site_option( 'active_sitewide_plugins', array( HAPPYACCESS_PLUGIN_BASENAME => time() ) );
	}

	/**
	 * How many of the given sites are at DB_VERSION.
	 *
	 * @param int[] $site_ids Site ids.
	 * @return int
	 */
	private function migrated( array $site_ids ) {
		$count = 0;
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			if ( Installer::DB_VERSION === get_option( 'happyaccess_db_version' ) ) {
				++$count;
			}
			restore_current_blog();
		}
		return $count;
	}

	/**
	 * Runs the scheduled network event the way WP-Cron does: the event is
	 * removed from the schedule, then its hook fires.
	 *
	 * @return void
	 */
	private function run_network_event() {
		$this->assertNotFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );
		wp_clear_scheduled_hook( Installer::NETWORK_HOOK );
		Installer::network_upgrade();
	}

	public function test_the_network_loop_migrates_25_sites_in_two_runs() {
		$site_ids = self::factory()->blog->create_many( 25 );
		$this->activate_network_wide();
		$this->assertSame( 0, $this->migrated( $site_ids ) );

		Installer::maybe_upgrade();

		$this->run_network_event();
		$this->assertSame( 20, $this->migrated( $site_ids ) );
		$this->assertNotSame( Installer::DB_VERSION, get_site_option( Installer::NETWORK_OPTION ) );

		$this->run_network_event();
		$this->assertSame( 25, $this->migrated( $site_ids ) );
		$this->assertFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );
		$this->assertSame( Installer::DB_VERSION, get_site_option( Installer::NETWORK_OPTION ) );

		Installer::maybe_upgrade();
		$this->assertFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );
	}

	public function test_the_loop_is_not_scheduled_from_a_subsite_or_without_network_activation() {
		$site_id = self::factory()->blog->create();

		Installer::maybe_upgrade();
		$this->assertFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );

		$this->activate_network_wide();
		switch_to_blog( $site_id );
		Installer::maybe_upgrade();
		restore_current_blog();
		$this->assertFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );
	}

	public function test_a_new_site_is_set_up_when_the_plugin_is_network_active() {
		$this->activate_network_wide();
		Plugin::init();

		$site_id = self::factory()->blog->create();

		$this->assertSame( 1, $this->migrated( array( $site_id ) ) );
	}
}
