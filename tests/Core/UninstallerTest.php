<?php
/**
 * Uninstaller tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Cron;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;
use HappyAccess\Core\Uninstaller;
use HappyAccess\Plugin;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;
use HappyAccess\Features\TwoStep\DeviceAlerts;
use HappyAccess\Features\TwoStep\UserState;

class UninstallerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			remove_role( 'happyaccess_merchant' );
			wp_clear_scheduled_hook( Cron::HOOK );
			wp_clear_scheduled_hook( Installer::NETWORK_HOOK );
		} finally {
			parent::tear_down();
		}
	}

	/**
	 * Makes a pass and its temp user.
	 *
	 * @param string $label Pass label.
	 * @return array Grant id and temp user id.
	 */
	private function pass_with_user( $label ) {
		$made = Grants::create(
			array(
				'label'    => $label,
				'duration' => DAY_IN_SECONDS,
			)
		);
		return array( $made['id'], TempUsers::get_or_create( Grants::get( $made['id'] ) ) );
	}

	private function delete_data( $on ) {
		Settings::update( array( 'privacy' => array( 'delete_on_uninstall' => $on ) ) );
	}

	private function happyaccess_options() {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'happyaccess_' ) . '%', $wpdb->esc_like( '_transient_happyaccess_' ) . '%' ) );
	}

	public function test_with_delete_off_the_data_stays_and_the_temp_users_go() {
		$this->delete_data( false );
		Cron::register();
		AuditLog::add( 'grant_created' );
		list( $id, $user_id ) = $this->pass_with_user( 'Acme' );
		$settings             = get_option( Settings::OPTION );

		Uninstaller::run();

		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertFalse( TempUsers::any_exist() );
		$this->assertGreaterThan( 0, Grants::get( $id )['revoked_at'] );
		$this->assertFalse( wp_next_scheduled( Cron::HOOK ) );
		foreach ( Uninstaller::TABLES as $name ) {
			$this->assertTrue( Installer::table_exists( $name ), $name . ' table was dropped' );
		}
		$this->assertSame( $settings, get_option( Settings::OPTION ) );
		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
	}

	public function test_the_uninstall_file_runs_the_uninstaller() {
		$this->delete_data( false );
		list( $id, $user_id ) = $this->pass_with_user( 'Acme' );

		// Only the uninstall file defines this, and nothing else reads it.
		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', HAPPYACCESS_PLUGIN_BASENAME );
		include HAPPYACCESS_PLUGIN_DIR . 'uninstall.php';

		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertGreaterThan( 0, Grants::get( $id )['revoked_at'] );
	}

	public function test_a_temp_user_without_a_grant_row_is_deleted_and_its_content_kept() {
		global $wpdb;
		$this->delete_data( false );
		$admin   = get_current_user_id();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );
		update_user_meta( $user_id, 'happyaccess_blog_id', get_current_blog_id() );
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );
		$normal  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		Uninstaller::run();

		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertNotNull( get_post( $post_id ) );
		$this->assertNotFalse( get_userdata( $admin ) );
		$this->assertNotFalse( get_userdata( $normal ) );
		$this->assertSame( '', $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", 'happyaccess_temp_user' ) ) ?? '' );
	}

	public function test_with_delete_on_the_tables_and_every_happyaccess_option_go() {
		global $wpdb;
		$this->delete_data( true );
		Cron::register();
		set_transient( 'happyaccess_cleanup_ran', 1, 600 );
		add_option( 'happyaccess_secret', 'x', '', false );
		add_option( 'happyaccess_recaptcha_secret_key', 'y', '', false );
		update_option( 'happyaccess_network_db_version', '1.1.0' );
		add_option( 'unrelated_option', 'keep' );
		list( $id, $user_id ) = $this->pass_with_user( 'Acme' );
		$this->assertNotSame( array(), $this->happyaccess_options() );

		Uninstaller::run();

		foreach ( array_merge( Uninstaller::TABLES, Installer::LEGACY_TABLES ) as $name ) {
			$this->assertFalse( Installer::table_exists( $name ), $name . ' table is still there' );
		}
		$this->assertSame( array(), $this->happyaccess_options() );
		$this->assertSame( 'keep', get_option( 'unrelated_option' ) );
		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'happyaccess_' ) . '%' ) ) );
		$this->assertFalse( wp_next_scheduled( Cron::HOOK ) );
		$this->assertNotFalse( get_userdata( get_current_user_id() ) );
	}

	public function test_the_user_meta_of_other_plugins_stays_when_data_is_deleted() {
		$this->delete_data( true );
		$user_id = get_current_user_id();
		update_user_meta( $user_id, 'happyaccess_note', 'x' );
		update_user_meta( $user_id, 'other_plugin_note', 'y' );

		Uninstaller::run();

		$this->assertSame( '', get_user_meta( $user_id, 'happyaccess_note', true ) );
		$this->assertSame( 'y', get_user_meta( $user_id, 'other_plugin_note', true ) );
	}

	public function test_a_deleted_subsite_drops_the_four_tables() {
		global $wpdb;
		Plugin::boot();
		do_action( 'plugins_loaded' );

		$tables = apply_filters( 'wpmu_drop_tables', array( 'wp_2_posts' => 'wp_2_posts' ), 2 );

		$prefix = $wpdb->get_blog_prefix( 2 );
		foreach ( array( 'tokens', 'logs', 'attempts', 'challenges' ) as $name ) {
			$this->assertArrayHasKey( $prefix . 'happyaccess_' . $name, $tables );
		}
		$this->assertArrayHasKey( 'wp_2_posts', $tables );
		$this->assertSame(
			1,
			count(
				array_filter(
					$GLOBALS['wp_filter']['wpmu_drop_tables']->callbacks[10],
					static function ( $registered ) {
						return array( Uninstaller::class, 'drop_tables' ) === $registered['function'];
					}
				)
			)
		);
	}

	public function test_the_1_0_6_choice_to_delete_is_honored_when_there_is_no_new_settings_option() {
		delete_option( Settings::OPTION );
		update_option( 'happyaccess_delete_on_uninstall', 1 );

		Uninstaller::run();

		foreach ( Uninstaller::TABLES as $name ) {
			$this->assertFalse( Installer::table_exists( $name ), $name . ' table is still there' );
		}
	}

	public function test_the_1_0_6_choice_to_keep_is_honored_when_there_is_no_new_settings_option() {
		delete_option( Settings::OPTION );
		update_option( 'happyaccess_delete_on_uninstall', 0 );

		Uninstaller::run();

		foreach ( Uninstaller::TABLES as $name ) {
			$this->assertTrue( Installer::table_exists( $name ), $name . ' table was dropped' );
		}
		$this->assertSame( '0', (string) get_option( 'happyaccess_delete_on_uninstall' ) );
	}

	public function test_the_new_settings_option_wins_over_the_1_0_6_option() {
		update_option( 'happyaccess_delete_on_uninstall', 1 );
		$this->delete_data( false );

		Uninstaller::run();

		$this->assertTrue( Installer::table_exists( 'tokens' ) );
	}

	public function test_remove_user_is_a_public_helper_the_uninstaller_can_reuse() {
		$this->assertTrue( ( new ReflectionMethod( TempUsers::class, 'remove_user' ) )->isPublic() );
	}

	/**
	 * Makes a temp user whose grant already ended, so only the leftover sweep can find it.
	 *
	 * @param bool $keep_marker Whether the happyaccess_temp_user marker stays.
	 * @return array Grant id and user id.
	 */
	private function ended_pass_with_user( $keep_marker ) {
		global $wpdb;
		list( $id, $user_id ) = $this->pass_with_user( 'Ended' );
		$wpdb->update( Installer::table( 'tokens' ), array( 'revoked_at' => Clock::mysql() ), array( 'id' => $id ) );
		Grants::flush_cache();
		if ( ! $keep_marker ) {
			delete_user_meta( $user_id, 'happyaccess_temp_user' );
		}
		return array( $id, $user_id );
	}

	public function test_a_user_with_only_a_token_id_pointing_at_a_grant_row_is_deleted() {
		list( , $user_id ) = $this->ended_pass_with_user( false );

		Uninstaller::run();

		$this->assertFalse( get_userdata( $user_id ) );
	}

	public function test_a_user_with_only_a_token_id_is_deleted_when_the_grants_table_is_gone() {
		global $wpdb;
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, 'happyaccess_token_id', 987654 );
		update_user_meta( $user_id, 'happyaccess_blog_id', get_current_blog_id() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Installer::table( 'tokens' ) );
		$this->assertFalse( Installer::table_exists( 'tokens' ) );

		Uninstaller::run();

		$this->assertFalse( get_userdata( $user_id ) );
	}

	public function test_a_user_with_only_a_token_id_is_deleted_even_when_its_grant_row_was_purged() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, 'happyaccess_token_id', 987654 );
		update_user_meta( $user_id, 'happyaccess_blog_id', get_current_blog_id() );
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );

		Uninstaller::run();

		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertNotNull( get_post( $post_id ) );
		$this->assertNotSame( $user_id, (int) get_post( $post_id )->post_author );
	}

	/**
	 * Leaves no administrator and no one who can manage options.
	 *
	 * @return void
	 */
	private function demote_everyone() {
		foreach ( get_users() as $user ) {
			$user->set_role( 'subscriber' );
		}
	}

	private function add_merchant_role() {
		remove_role( 'happyaccess_merchant' );
		add_role(
			'happyaccess_merchant',
			'Merchant',
			array(
				'read'           => true,
				'manage_options' => true,
			)
		);
	}

	public function test_posts_go_to_the_merchant_when_no_user_has_the_administrator_role() {
		$this->demote_everyone();
		$this->add_merchant_role();
		$first    = self::factory()->user->create( array( 'role' => 'happyaccess_merchant' ) );
		$merchant = self::factory()->user->create( array( 'role' => 'happyaccess_merchant' ) );
		$temp     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $temp, 'happyaccess_temp_user', 1 );
		$post_id = self::factory()->post->create( array( 'post_author' => $temp ) );

		wp_set_current_user( $merchant );
		Uninstaller::run();
		$this->assertFalse( get_userdata( $temp ) );
		$this->assertNotNull( get_post( $post_id ) );
		$this->assertSame( $merchant, (int) get_post( $post_id )->post_author );
		$this->assertNotSame( $first, $merchant );
	}

	public function test_posts_go_to_the_lowest_id_user_who_can_manage_options_when_no_one_is_logged_in() {
		$this->demote_everyone();
		$this->add_merchant_role();
		$first = self::factory()->user->create( array( 'role' => 'happyaccess_merchant' ) );
		self::factory()->user->create( array( 'role' => 'happyaccess_merchant' ) );
		$temp = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $temp, 'happyaccess_temp_user', 1 );
		$post_id = self::factory()->post->create( array( 'post_author' => $temp ) );

		wp_set_current_user( 0 );
		Uninstaller::run();

		$this->assertFalse( get_userdata( $temp ) );
		$this->assertSame( $first, (int) get_post( $post_id )->post_author );
	}

	public function test_with_no_one_to_inherit_the_temp_user_and_its_posts_stay() {
		$this->demote_everyone();
		$temp = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $temp, 'happyaccess_temp_user', 1 );
		$post_id = self::factory()->post->create( array( 'post_author' => $temp ) );

		wp_set_current_user( 0 );
		Uninstaller::run();

		$this->assertNotFalse( get_userdata( $temp ) );
		$this->assertSame( '1', get_user_meta( $temp, 'happyaccess_temp_user', true ) );
		$this->assertNotNull( get_post( $post_id ) );
		$this->assertSame( $temp, (int) get_post( $post_id )->post_author );
	}

	public function test_a_token_only_account_with_no_one_to_inherit_loses_its_role_but_keeps_its_posts() {
		$this->demote_everyone();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, 'happyaccess_token_id', 987654 );
		update_user_meta( $user_id, 'happyaccess_blog_id', get_current_blog_id() );
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );

		wp_set_current_user( 0 );
		Uninstaller::run();

		$this->assertNotFalse( get_userdata( $user_id ) );
		$this->assertSame( array(), ( new WP_User( $user_id ) )->roles );
		$this->assertSame( $user_id, (int) get_post( $post_id )->post_author );
	}

	public function test_a_leftover_temp_users_posts_go_to_the_pass_owner() {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $owner );
		list( , $user_id ) = $this->ended_pass_with_user( true );
		$post_id           = self::factory()->post->create( array( 'post_author' => $user_id ) );

		Uninstaller::run();

		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertNotNull( get_post( $post_id ) );
		$this->assertSame( $owner, (int) get_post( $post_id )->post_author );
	}

	public function test_posts_of_a_temp_user_with_no_grant_go_to_a_real_administrator() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );
		// A second marked administrator with a low id must never be picked.
		$decoy = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $decoy, 'happyaccess_temp_user', 1 );

		Uninstaller::run();

		$this->assertNotNull( get_post( $post_id ) );
		$author = (int) get_post( $post_id )->post_author;
		$this->assertNotSame( $user_id, $author );
		$this->assertNotSame( $decoy, $author );
		$this->assertTrue( user_can( $author, 'manage_options' ) );
		$this->assertFalse( Capabilities::is_temp_user( $author ) );
	}

	public function test_a_marked_user_of_another_site_id_is_deleted_on_a_single_site() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single site only.' );
		}
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );
		update_user_meta( $user_id, 'happyaccess_blog_id', 4242 );
		$this->delete_data( true );

		Uninstaller::run();

		$this->assertFalse( get_userdata( $user_id ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_a_temp_user_whose_site_was_deleted_is_removed_and_never_stranded() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		$this->delete_data( true );
		$site_id = self::factory()->blog->create();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );
		update_user_meta( $user_id, 'happyaccess_blog_id', $site_id );
		$kept = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_delete_site( $site_id );

		Uninstaller::run();

		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertNotFalse( get_userdata( $kept ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_a_temp_user_that_belongs_to_two_sites_is_deleted_not_left_as_a_normal_account() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		$this->delete_data( true );
		$site_id = self::factory()->blog->create();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_user_to_blog( $site_id, $user_id, 'administrator' );
		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );
		update_user_meta( $user_id, 'happyaccess_blog_id', $site_id );

		Uninstaller::run();

		$this->assertFalse( get_userdata( $user_id ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_uninstall_on_a_network_cleans_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		$this->delete_data( true );
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		wp_set_current_user( get_user_by( 'id', 1 )->ID );
		$made     = Grants::create(
			array(
				'label'    => 'Sub',
				'duration' => DAY_IN_SECONDS,
			)
		);
		$sub_user = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		restore_current_blog();
		$this->assertNotFalse( get_userdata( $sub_user ) );

		// The subsite keeps its own choice; the main site has data deletion on.
		Uninstaller::run();

		$this->assertFalse( get_userdata( $sub_user ) );
		switch_to_blog( $site_id );
		$this->assertTrue( Installer::table_exists( 'tokens' ), 'the subsite had delete off, so its table stays' );
		$this->assertGreaterThan( 0, Grants::get( $made['id'] )['revoked_at'] );
		restore_current_blog();
		$this->assertFalse( Installer::table_exists( 'tokens' ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_a_failed_removal_from_a_site_stops_before_the_network_delete() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		$site_a = self::factory()->blog->create();
		$site_b = self::factory()->blog->create();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_user_to_blog( $site_a, $user_id, 'administrator' );
		add_user_to_blog( $site_b, $user_id, 'administrator' );
		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );
		// Names no live site, so only the network sweep handles it.
		update_user_meta( $user_id, 'happyaccess_blog_id', 987654 );

		$calls   = 0;
		$network = 0;
		$vanish  = static function ( $removed ) use ( &$calls ) {
			global $wpdb;
			++$calls;
			// The account disappears mid way, so remove_user_from_blog returns an error.
			$wpdb->delete( $wpdb->users, array( 'ID' => $removed ) );
			clean_user_cache( $removed );
		};
		add_action( 'remove_user_from_blog', $vanish );
		add_action(
			'wpmu_delete_user',
			static function () use ( &$network ) {
				++$network;
			}
		);

		Uninstaller::run();

		$this->assertSame( 1, $calls, 'the sweep went on after the first failure' );
		$this->assertSame( 0, $network );
	}

	/**
	 * @group ms-required
	 */
	public function test_a_network_account_with_no_inheritor_on_one_site_is_stripped_on_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		$lonely  = self::factory()->blog->create();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_user_to_blog( $lonely, $user_id, 'administrator' );
		// The site's creator is its only other administrator; without them nobody can inherit there.
		remove_user_from_blog( 1, $lonely );
		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );
		// Names no live site, so only the network sweep handles it.
		update_user_meta( $user_id, 'happyaccess_blog_id', 987654 );
		wp_set_current_user( 0 );

		Uninstaller::run();

		$this->assertNotFalse( get_userdata( $user_id ), 'With nobody to inherit on one site, the account stays.' );
		foreach ( array( get_current_blog_id(), $lonely ) as $blog_id ) {
			switch_to_blog( $blog_id );
			$user = new WP_User( $user_id );
			$this->assertSame( array(), $user->roles, 'site ' . $blog_id );
			$this->assertFalse( $user->has_cap( 'manage_options' ), 'site ' . $blog_id );
			restore_current_blog();
		}
	}

	/**
	 * Gives a user every two-step meta key.
	 *
	 * @param int $user_id User id.
	 * @return string[] The keys.
	 */
	private function two_step_meta( $user_id ) {
		$keys = array( '_happyaccess_twostep', '_happyaccess_totp', '_happyaccess_backup_codes', '_happyaccess_twostep_recheck', '_happyaccess_devices' );
		foreach ( $keys as $key ) {
			update_user_meta( $user_id, $key, array( 'x' => 1 ) );
		}
		update_user_meta( $user_id, '_other_plugin_note', 'y' );
		return $keys;
	}

	public function test_with_delete_on_the_two_step_meta_goes() {
		$this->delete_data( true );
		$user_id = self::factory()->user->create();
		$keys    = $this->two_step_meta( $user_id );

		Uninstaller::run();

		foreach ( $keys as $key ) {
			$this->assertFalse( metadata_exists( 'user', $user_id, $key ), $key . ' is still there' );
		}
		$this->assertSame( 'y', get_user_meta( $user_id, '_other_plugin_note', true ) );
	}

	public function test_with_delete_on_the_settings_secret_challenges_lock_failure_note_and_two_step_meta_go() {
		$this->delete_data( true );
		add_option( Secrets::OPTION, 'x', '', false );
		add_option( Installer::LOCK_OPTION, 'held', '', false );
		set_transient( Installer::FAILED_TRANSIENT, array( 'step' => 'x' ), 600 );
		$user_id = self::factory()->user->create();
		$keys    = $this->two_step_meta( $user_id );
		$this->assertNotFalse( get_option( Settings::OPTION ) );
		$this->assertTrue( Installer::table_exists( 'challenges' ) );

		Uninstaller::run();

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Secrets::OPTION ) );
		$this->assertFalse( Installer::table_exists( 'challenges' ) );
		$this->assertFalse( get_option( Installer::LOCK_OPTION ) );
		$this->assertFalse( get_transient( Installer::FAILED_TRANSIENT ) );
		foreach ( $keys as $key ) {
			$this->assertFalse( metadata_exists( 'user', $user_id, $key ), $key . ' is still there' );
		}
	}

	public function test_the_two_step_meta_list_names_every_key_the_feature_writes() {
		$written = array( DeviceAlerts::META );
		foreach ( ( new ReflectionClass( UserState::class ) )->getConstants() as $name => $value ) {
			if ( 0 === strpos( $name, 'META_' ) ) {
				$written[] = $value;
			}
		}

		$this->assertCount( 5, $written );
		foreach ( $written as $key ) {
			$this->assertContains( $key, Uninstaller::TWOSTEP_META );
		}
		$this->assertEqualsCanonicalizing( $written, $this->two_step_meta( self::factory()->user->create() ) );
	}

	public function test_with_delete_off_the_two_step_meta_stays() {
		$this->delete_data( false );
		$user_id = self::factory()->user->create();
		$keys    = $this->two_step_meta( $user_id );

		Uninstaller::run();

		foreach ( $keys as $key ) {
			$this->assertTrue( metadata_exists( 'user', $user_id, $key ), $key . ' was deleted' );
		}
	}

	public function test_with_delete_on_the_author_card_meta_and_options_go() {
		$this->delete_data( true );
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_happyaccess_author_card', 'dismissed' );
		update_option( 'happyaccess_installed_at', '2026-09-21 14:13:20', false );
		update_option( 'happyaccess_first_expiry_seen', '2026-09-22 14:13:20', false );

		Uninstaller::run();

		$this->assertFalse( metadata_exists( 'user', $user_id, '_happyaccess_author_card' ) );
		$this->assertFalse( get_option( 'happyaccess_installed_at' ) );
		$this->assertFalse( get_option( 'happyaccess_first_expiry_seen' ) );
	}

	public function test_with_delete_off_the_author_card_meta_and_options_stay() {
		$this->delete_data( false );
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_happyaccess_author_card', 'rated' );
		update_option( 'happyaccess_installed_at', '2026-09-21 14:13:20', false );

		Uninstaller::run();

		$this->assertSame( 'rated', get_user_meta( $user_id, '_happyaccess_author_card', true ) );
		$this->assertSame( '2026-09-21 14:13:20', get_option( 'happyaccess_installed_at' ) );
	}
}
