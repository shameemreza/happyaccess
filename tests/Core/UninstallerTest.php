<?php
/**
 * Uninstaller tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Cron;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Core\Uninstaller;
use HappyAccess\Plugin;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class UninstallerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		wp_clear_scheduled_hook( Cron::HOOK );
		wp_clear_scheduled_hook( Installer::NETWORK_HOOK );
		parent::tear_down();
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
		$this->assertNotFalse( get_post( $post_id ) );
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
}
