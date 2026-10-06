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
}
