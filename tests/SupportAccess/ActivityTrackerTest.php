<?php
/**
 * ActivityTracker tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\ActivityTracker;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class ActivityTrackerTest extends WP_UnitTestCase {

	private $grant_id;
	private $temp;

	public function set_up() {
		parent::set_up();
		Installer::install();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$made           = Grants::create( array( 'label' => 'Acme' ) );
		$this->grant_id = $made['id'];
		$this->temp     = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		ActivityTracker::register();
	}

	public function tear_down() {
		ActivityTracker::reset();
		parent::tear_down();
	}

	private function rows_for( $user_login ) {
		ActivityTracker::flush();
		$rows = AuditLog::query( array( 'token_id' => $this->grant_id, 'feature' => 'support', 'per_page' => 100 ) )['items'];
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $user_login ) {
					return false !== strpos( $row['summary'], $user_login );
				}
			)
		);
	}

	private function summaries() {
		return wp_list_pluck( AuditLog::query( array( 'token_id' => $this->grant_id, 'feature' => 'support', 'per_page' => 100 ) )['items'], 'summary' );
	}

	public function test_normal_admin_changes_are_not_tracked() {
		update_option( 'blogname', 'Changed by owner' );
		ActivityTracker::flush();
		$this->assertSame( 0, AuditLog::query( array( 'event' => 'settings_saved' ) )['total'] );
	}

	public function test_settings_saves_are_grouped_without_values() {
		wp_set_current_user( $this->temp );
		update_option( 'blogname', 'Secret-ish value' );
		update_option( 'blogdescription', 'Another' );
		set_transient( 'noise', 1 );
		ActivityTracker::flush();
		$items = AuditLog::query( array( 'token_id' => $this->grant_id, 'event' => 'settings_saved' ) )['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'Saved settings: 2 options', $items[0]['summary'] );
		$this->assertSame( array( 'blogname', 'blogdescription' ), $items[0]['meta']['options'] );
		$this->assertStringNotContainsString( 'Secret-ish', wp_json_encode( $items ) );
	}

	public function test_post_changes_are_tracked() {
		wp_set_current_user( $this->temp );
		$id = self::factory()->post->create( array( 'post_title' => 'Hello', 'post_type' => 'page' ) );
		wp_trash_post( $id );
		$summaries = $this->summaries();
		$this->assertContains( 'Created Page: Hello (#' . $id . ')', $summaries );
		$this->assertContains( 'Trashed Page: Hello (#' . $id . ')', $summaries );
	}

	public function test_plugin_activation_is_tracked() {
		wp_set_current_user( $this->temp );
		do_action( 'activated_plugin', 'hello.php', false );
		$this->assertContains( 'Activated plugin: hello.php', $this->summaries() );
	}

	public function test_plugin_deactivation_is_tracked() {
		wp_set_current_user( $this->temp );
		do_action( 'deactivated_plugin', 'hello.php', false );
		$this->assertContains( 'Deactivated plugin: hello.php', $this->summaries() );
	}

	public function test_theme_switch_is_tracked() {
		wp_set_current_user( $this->temp );
		do_action( 'switch_theme', 'Storefront', null, null );
		$this->assertContains( 'Switched theme to Storefront', $this->summaries() );
	}

	public function test_user_update_is_tracked() {
		$other = self::factory()->user->create( array( 'user_login' => 'someone_else' ) );
		wp_set_current_user( $this->temp );
		wp_update_user( array( 'ID' => $other, 'display_name' => 'Changed' ) );
		$this->assertContains( 'Updated user: someone_else', $this->summaries() );
	}

	public function test_order_status_change_is_tracked_with_fake_ids() {
		wp_set_current_user( $this->temp );
		do_action( 'woocommerce_order_status_changed', 987654, 'pending', 'processing', null );
		$this->assertContains( 'Order #987654: pending to processing', $this->summaries() );
	}

	public function test_noise_options_are_skipped() {
		wp_set_current_user( $this->temp );
		update_option( 'active_plugins', array( 'hello.php' ) );
		update_option( 'cron', array() );
		update_option( 'happyaccess_anything', 'x' );
		update_option( 'rewrite_rules', 'x' );
		update_option( 'recently_activated', array( 'hello.php' => 1 ) );
		update_option( 'blogname', 'Visible change' );
		ActivityTracker::flush();
		$items = AuditLog::query( array( 'token_id' => $this->grant_id, 'event' => 'settings_saved' ) )['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( array( 'blogname' ), $items[0]['meta']['options'] );
		$this->assertSame( 'Saved settings: 1 options', $items[0]['summary'] );
	}

	public function test_only_noise_options_write_no_entry() {
		wp_set_current_user( $this->temp );
		update_option( 'active_plugins', array( 'hello.php' ) );
		update_option( 'happyaccess_anything', 'x' );
		ActivityTracker::flush();
		$this->assertSame( 0, AuditLog::query( array( 'event' => 'settings_saved' ) )['total'] );
	}

	public function test_flush_uses_the_user_stored_when_the_option_changed() {
		wp_set_current_user( $this->temp );
		update_option( 'blogname', 'Changed by support' );
		wp_set_current_user( 0 );
		ActivityTracker::flush();
		$items = AuditLog::query( array( 'token_id' => $this->grant_id, 'event' => 'settings_saved' ) )['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( (int) $this->temp, (int) $items[0]['user_id'] );
	}

	public function test_flush_empties_the_collected_options() {
		wp_set_current_user( $this->temp );
		update_option( 'blogname', 'Changed once' );
		ActivityTracker::flush();
		ActivityTracker::flush();
		$this->assertSame( 1, AuditLog::query( array( 'event' => 'settings_saved' ) )['total'] );
	}

	public function test_permanent_delete_is_tracked_even_after_trash() {
		wp_set_current_user( $this->temp );
		$id = self::factory()->post->create( array( 'post_title' => 'Gone', 'post_type' => 'page' ) );
		wp_trash_post( $id );
		wp_delete_post( $id, true );
		$this->assertContains( 'Deleted Page: Gone (#' . $id . ')', $this->summaries() );
	}

	public function test_permanent_delete_by_owner_is_not_tracked() {
		$id = self::factory()->post->create( array( 'post_title' => 'Owner page', 'post_type' => 'page' ) );
		wp_delete_post( $id, true );
		$this->assertSame( 0, AuditLog::query( array( 'event' => 'post_deleted' ) )['total'] );
	}

	/**
	 * Core reads DOING_AUTOSAVE, so the constant is defined in a separate
	 * process and cannot leak into other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_draft_update_is_still_logged_during_autosave() {
		wp_set_current_user( $this->temp );
		$id = self::factory()->post->create( array( 'post_title' => 'Draft', 'post_type' => 'post', 'post_status' => 'draft' ) );
		define( 'DOING_AUTOSAVE', true );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Draft v2' ) );
		$this->assertContains( 'Updated Post: Draft v2 (#' . $id . ')', $this->summaries() );
	}

	public function test_revisions_and_unlisted_types_are_not_tracked() {
		wp_set_current_user( $this->temp );
		$id = self::factory()->post->create( array( 'post_title' => 'Rev', 'post_type' => 'post' ) );
		wp_update_post( array( 'ID' => $id, 'post_content' => 'changed' ) );
		self::factory()->post->create( array( 'post_title' => 'Menu thing', 'post_type' => 'nav_menu_item' ) );
		$joined = implode( '|', $this->summaries() );
		$this->assertStringNotContainsString( 'Revision', $joined );
		$this->assertStringNotContainsString( 'Menu thing', $joined );
	}

	public function test_long_titles_are_clipped() {
		wp_set_current_user( $this->temp );
		$id = self::factory()->post->create( array( 'post_title' => str_repeat( 'a', 300 ), 'post_type' => 'page' ) );
		$this->assertContains( 'Created Page: ' . str_repeat( 'a', 150 ) . ' (#' . $id . ')', $this->summaries() );
	}

	public function test_core_and_translation_upgrades_are_tracked() {
		wp_set_current_user( $this->temp );
		ActivityTracker::upgrader_ran( null, array( 'type' => 'core', 'action' => 'update' ) );
		ActivityTracker::upgrader_ran( null, array( 'type' => 'translation', 'action' => 'update' ) );
		$summaries = $this->summaries();
		$this->assertContains( 'Updated WordPress core', $summaries );
		$this->assertContains( 'Updated translations', $summaries );
	}

	public function test_handlers_do_nothing_for_non_temp_users() {
		$before = AuditLog::query( array( 'feature' => 'support', 'token_id' => $this->grant_id ) )['total'];
		do_action( 'activated_plugin', 'hello.php', false );
		ActivityTracker::upgrader_ran( null, array( 'type' => 'core', 'action' => 'update' ) );
		do_action( 'woocommerce_order_status_changed', 1, 'pending', 'processing', null );
		$this->assertSame( $before, AuditLog::query( array( 'feature' => 'support', 'token_id' => $this->grant_id ) )['total'] );
	}

	public function test_user_creation_writes_one_row_with_roles() {
		wp_set_current_user( $this->temp );
		wp_insert_user(
			array(
				'user_login' => 'newbie',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'newbie@example.org',
				'role'       => 'editor',
			)
		);
		$rows = $this->rows_for( 'newbie' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'user_created', $rows[0]['event_type'] );
		$this->assertSame( 'Created user: newbie (editor)', $rows[0]['summary'] );
	}

	public function test_user_creation_without_roles_shows_none() {
		wp_set_current_user( $this->temp );
		wp_insert_user(
			array(
				'user_login' => 'roleless',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'roleless@example.org',
				'role'       => '',
			)
		);
		$rows = $this->rows_for( 'roleless' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Created user: roleless (none)', $rows[0]['summary'] );
	}

	public function test_set_role_writes_one_row_with_old_and_new_roles() {
		$target = self::factory()->user->create( array( 'user_login' => 'swapped', 'role' => 'subscriber' ) );
		wp_set_current_user( $this->temp );
		( new WP_User( $target ) )->set_role( 'editor' );
		$rows = $this->rows_for( 'swapped' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'user_role_changed', $rows[0]['event_type'] );
		$this->assertSame( 'Changed role for swapped: subscriber to editor', $rows[0]['summary'] );
		$this->assertSame( $this->temp, (int) $rows[0]['user_id'] );
	}

	public function test_set_role_from_no_roles_shows_none() {
		$target = self::factory()->user->create( array( 'user_login' => 'blank', 'role' => '' ) );
		wp_set_current_user( $this->temp );
		( new WP_User( $target ) )->set_role( 'author' );
		$rows = $this->rows_for( 'blank' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Changed role for blank: none to author', $rows[0]['summary'] );
	}

	public function test_standalone_add_role_writes_one_added_row() {
		$target = self::factory()->user->create( array( 'user_login' => 'extra', 'role' => 'subscriber' ) );
		wp_set_current_user( $this->temp );
		( new WP_User( $target ) )->add_role( 'shop_manager' );
		$rows = $this->rows_for( 'extra' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'user_role_added', $rows[0]['event_type'] );
		$this->assertSame( 'Added role to extra: shop_manager', $rows[0]['summary'] );
	}

	public function test_role_events_wait_for_the_flush() {
		$target = self::factory()->user->create( array( 'user_login' => 'pending', 'role' => 'subscriber' ) );
		wp_set_current_user( $this->temp );
		( new WP_User( $target ) )->add_role( 'author' );
		$this->assertSame( 0, AuditLog::query( array( 'event' => 'user_role_added' ) )['total'] );
		ActivityTracker::flush();
		$this->assertSame( 1, AuditLog::query( array( 'event' => 'user_role_added' ) )['total'] );
	}

	public function test_privacy_erasure_is_tracked() {
		wp_set_current_user( $this->temp );
		do_action( 'wp_privacy_personal_data_erased', 42 );
		$this->assertContains( 'Ran a personal data erasure (#42)', $this->summaries() );
	}

	public function test_webhook_creation_is_tracked() {
		wp_set_current_user( $this->temp );
		do_action( 'woocommerce_new_webhook', 7, null );
		$this->assertContains( 'Created WooCommerce webhook #7', $this->summaries() );
	}

	public function test_user_and_role_events_are_not_tracked_for_non_temp_users() {
		$before = AuditLog::query( array( 'feature' => 'support' ) )['total'];
		$target = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		( new WP_User( $target ) )->set_role( 'editor' );
		( new WP_User( $target ) )->add_role( 'author' );
		do_action( 'wp_privacy_personal_data_erased', 1 );
		do_action( 'woocommerce_new_webhook', 1, null );
		ActivityTracker::flush();
		$this->assertSame( $before, AuditLog::query( array( 'feature' => 'support' ) )['total'] );
	}
}
