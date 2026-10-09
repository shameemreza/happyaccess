<?php
/**
 * Network upgrade and new site tests. Run with composer test:multisite.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Core\Internal;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;
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
		delete_site_option( Installer::NETWORK_STATE_OPTION );
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

	public function test_network_activation_migrates_the_main_site_and_leaves_the_rest_to_the_loop() {
		$site_ids = self::factory()->blog->create_many( 3 );
		delete_option( 'happyaccess_db_version' );

		Installer::activate( true );

		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		$this->assertSame( 0, $this->migrated( $site_ids ), 'Only the main site is migrated during activation.' );
		$this->assertNotFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );

		// Core marks the plugin network active once the activation hook has run.
		$this->activate_network_wide();
		$this->run_network_event();

		$this->assertSame( 3, $this->migrated( $site_ids ) );
		$this->assertSame( Installer::DB_VERSION, get_site_option( Installer::NETWORK_OPTION ) );
	}

	public function test_network_activation_from_a_subsite_still_migrates_the_main_site() {
		$site_id = self::factory()->blog->create();
		delete_option( 'happyaccess_db_version' );

		switch_to_blog( $site_id );
		Installer::activate( true );
		restore_current_blog();

		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		$this->assertSame( 0, $this->migrated( array( $site_id ) ) );
		$this->assertNotFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );
	}

	public function test_a_new_site_is_left_alone_when_the_plugin_is_not_network_active() {
		Plugin::init();

		$site_id = self::factory()->blog->create();

		$this->assertSame( 0, $this->migrated( array( $site_id ) ) );
		switch_to_blog( $site_id );
		$this->assertFalse( Installer::table_exists( 'tokens' ) );
		restore_current_blog();
	}

	public function test_the_network_loop_gives_a_subsite_legacy_temp_user_its_blog_id() {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		$user_id = self::factory()->user->create();
		add_user_to_blog( $site_id, $user_id, 'administrator' );
		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );

		switch_to_blog( $site_id );
		Installer::install();
		$wpdb->insert(
			Installer::table( 'tokens' ),
			array(
				'token_hash' => 'legacy-sub',
				'user_id'    => $user_id,
				'created_by' => 1,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		delete_option( 'happyaccess_db_version' );
		restore_current_blog();
		$this->activate_network_wide();

		Installer::network_upgrade();

		$this->assertSame( 1, $this->migrated( array( $site_id ) ) );
		$this->assertSame( (string) $site_id, get_user_meta( $user_id, 'happyaccess_blog_id', true ) );
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

	/**
	 * Marks a site's last migration as failed.
	 *
	 * @param int $site_id Site id.
	 * @return void
	 */
	private function mark_failed( $site_id ) {
		switch_to_blog( $site_id );
		Internal::run(
			static function () {
				set_transient( Installer::FAILED_TRANSIENT, 'write_failed', 15 * MINUTE_IN_SECONDS );
			}
		);
		restore_current_blog();
	}

	public function test_a_site_backing_off_does_not_take_a_batch_slot() {
		$site_ids = self::factory()->blog->create_many( 25 );
		$failed   = array_slice( $site_ids, 0, 3 );
		$healthy  = array_slice( $site_ids, 3 );
		foreach ( $failed as $site_id ) {
			$this->mark_failed( $site_id );
		}
		$this->activate_network_wide();

		Installer::network_upgrade();

		$this->assertSame( 0, $this->migrated( $failed ) );
		$this->assertSame( 20, $this->migrated( $healthy ) );
		$this->assertNotSame( Installer::DB_VERSION, get_site_option( Installer::NETWORK_OPTION ) );
	}

	/**
	 * Site ids switch_to_blog() visited.
	 *
	 * @var int[]
	 */
	private $visited = array();

	/**
	 * Notes each switch_to_blog() call.
	 *
	 * @param int $site_id Site switched to.
	 * @return void
	 */
	public function note_switch( $site_id ) {
		$this->visited[] = (int) $site_id;
	}

	public function test_a_run_visits_one_page_of_sites_and_picks_up_where_it_stopped() {
		$site_ids = self::factory()->blog->create_many( Installer::NETWORK_PAGE + 10 );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			Installer::install();
			update_option( 'happyaccess_db_version', Installer::DB_VERSION );
			restore_current_blog();
		}
		$this->activate_network_wide();
		$this->visited = array();
		add_action( 'switch_blog', array( $this, 'note_switch' ), 10, 1 );

		Installer::network_upgrade();
		$first         = array_unique( $this->visited );
		$this->visited = array();
		$this->run_network_event();
		$second = array_unique( $this->visited );

		remove_action( 'switch_blog', array( $this, 'note_switch' ), 10 );
		$this->assertLessThanOrEqual( Installer::NETWORK_PAGE + 1, count( $first ), 'One page per run, plus the switch back.' );
		$this->assertSame( array(), array_intersect( array_diff( $first, array( get_main_site_id() ) ), array_diff( $second, array( get_main_site_id() ) ) ), 'The second run starts after the first.' );
		$this->assertSame( Installer::DB_VERSION, get_site_option( Installer::NETWORK_OPTION ), 'The whole network is current after two runs.' );
		$this->assertFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );
	}

	public function test_archived_spam_and_deleted_sites_are_skipped() {
		$live    = self::factory()->blog->create();
		$skipped = self::factory()->blog->create_many( 3 );
		update_blog_status( $skipped[0], 'archived', 1 );
		update_blog_status( $skipped[1], 'spam', 1 );
		update_blog_status( $skipped[2], 'deleted', 1 );
		$this->activate_network_wide();

		Installer::network_upgrade();

		$this->assertSame( 1, $this->migrated( array( $live ) ) );
		$this->assertSame( 0, $this->migrated( $skipped ) );
		$this->assertSame( Installer::DB_VERSION, get_site_option( Installer::NETWORK_OPTION ), 'A site that is not live does not keep the loop going.' );
		$this->assertFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );
	}

	public function test_a_site_that_keeps_failing_spaces_out_the_passes() {
		$site_id = self::factory()->blog->create();
		$this->mark_failed( $site_id );
		$this->activate_network_wide();

		Installer::network_upgrade();
		$first = wp_next_scheduled( Installer::NETWORK_HOOK );
		$this->assertNotFalse( $first );
		$this->assertGreaterThanOrEqual( time() + 15 * MINUTE_IN_SECONDS - 5, $first );

		wp_clear_scheduled_hook( Installer::NETWORK_HOOK );
		Installer::network_upgrade();
		$second = wp_next_scheduled( Installer::NETWORK_HOOK );
		$this->assertGreaterThanOrEqual( time() + 30 * MINUTE_IN_SECONDS - 5, $second, 'A pass that moved no site waits twice as long.' );
		$this->assertSame( 0, $this->migrated( array( $site_id ) ) );
	}

	public function test_a_pass_left_from_an_older_version_starts_over() {
		$site_ids = self::factory()->blog->create_many( 2 );
		$this->activate_network_wide();
		update_site_option(
			Installer::NETWORK_STATE_OPTION,
			array(
				'version' => '0.0.1',
				'cursor'  => max( $site_ids ),
				'behind'  => false,
				'moved'   => false,
				'idle'    => 0,
			)
		);

		Installer::network_upgrade();

		$this->assertSame( 2, $this->migrated( $site_ids ) );
	}

	public function test_the_loop_is_scheduled_even_when_this_site_is_backing_off() {
		$this->activate_network_wide();
		self::factory()->blog->create();
		Internal::run(
			static function () {
				set_transient( Installer::FAILED_TRANSIENT, 'write_failed', 15 * MINUTE_IN_SECONDS );
			}
		);

		Installer::maybe_upgrade();

		$this->assertNotFalse( wp_next_scheduled( Installer::NETWORK_HOOK ) );
	}

	public function test_network_deactivation_revokes_the_passes_of_every_site() {
		$site_ids = self::factory()->blog->create_many( 3 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$made = array();
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			Installer::install();
			update_option( 'happyaccess_db_version', Installer::DB_VERSION );
			$grant            = Grants::create(
				array(
					'label'    => 'Site ' . $site_id,
					'duration' => DAY_IN_SECONDS,
				)
			);
			$made[ $site_id ] = array( $grant['id'], TempUsers::get_or_create( Grants::get( $grant['id'] ) ) );
			restore_current_blog();
		}

		Plugin::deactivate( true );

		foreach ( $made as $site_id => $pair ) {
			switch_to_blog( $site_id );
			$this->assertGreaterThan( 0, Grants::get( $pair[0] )['revoked_at'], 'site ' . $site_id );
			$this->assertFalse( get_userdata( $pair[1] ), 'site ' . $site_id );
			restore_current_blog();
		}
	}
}
