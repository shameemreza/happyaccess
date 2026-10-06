<?php
/**
 * AdminBar tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;
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
}
