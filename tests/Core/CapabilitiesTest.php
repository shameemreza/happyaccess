<?php
/**
 * Capabilities tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;

class CapabilitiesTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Capabilities::register();
	}

	public function test_admin_can_manage_editor_cannot() {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( user_can( $admin, Capabilities::MANAGE ) );
		$this->assertFalse( user_can( $editor, Capabilities::MANAGE ) );
	}

	public function test_temp_admin_can_never_manage() {
		$temp = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $temp, 'happyaccess_temp_user', true );
		update_user_meta( $temp, 'happyaccess_token_id', 12 );

		$this->assertTrue( Capabilities::is_temp_user( $temp ) );
		$this->assertSame( 12, Capabilities::grant_id( $temp ) );
		$this->assertFalse( user_can( $temp, Capabilities::MANAGE ) );
		$this->assertTrue( user_can( $temp, 'edit_posts' ) );
	}

	public function test_guest_is_not_temp() {
		$this->assertFalse( Capabilities::is_temp_user( 0 ) );
	}
}
