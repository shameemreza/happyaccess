<?php
/**
 * Grant owner fallback tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class OwnerTest extends WP_UnitTestCase {

	private $first_admin;
	private $second_admin;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$this->first_admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->second_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->second_admin );
		Grants::flush_cache();
	}

	/**
	 * Marks every administrator with a lower id than the given one as a temp user,
	 * so the test does not depend on admins the test suite installs.
	 *
	 * @param int $user_id User id.
	 */
	private function hide_admins_before( $user_id ) {
		foreach ( get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		) as $id ) {
			if ( (int) $id < $user_id ) {
				update_user_meta( (int) $id, 'happyaccess_temp_user', 1 );
			}
		}
	}

	public function test_returns_the_creator_when_they_are_a_normal_user() {
		$grant = Grants::get(
			Grants::create(
				array(
					'label'      => 'Acme',
					'created_by' => $this->second_admin,
				)
			)['id']
		);
		$this->assertSame( $this->second_admin, Grants::owner_id( $grant ) );
	}

	public function test_falls_back_to_the_first_non_temp_admin_when_created_by_is_0() {
		$this->hide_admins_before( $this->first_admin );
		$grant = Grants::get(
			Grants::create(
				array(
					'label'      => 'Acme',
					'created_by' => 0,
				)
			)['id']
		);
		$this->assertSame( $this->first_admin, Grants::owner_id( $grant ) );
	}

	public function test_falls_back_when_the_creator_is_gone() {
		$this->hide_admins_before( $this->first_admin );
		$grant               = Grants::get( Grants::create( array( 'label' => 'Acme' ) )['id'] );
		$grant['created_by'] = 99999999;
		$this->assertSame( $this->first_admin, Grants::owner_id( $grant ) );
	}

	public function test_falls_back_when_the_creator_is_a_temp_user() {
		$this->hide_admins_before( $this->first_admin );
		$other_grant = Grants::get( Grants::create( array( 'label' => 'Other' ) )['id'] );
		$temp        = TempUsers::get_or_create( $other_grant );
		$grant       = Grants::get(
			Grants::create(
				array(
					'label'      => 'Acme',
					'created_by' => $temp,
				)
			)['id']
		);
		$this->assertSame( $this->first_admin, Grants::owner_id( $grant ) );
	}

	public function test_a_temp_user_with_the_administrator_role_is_never_the_owner() {
		$this->hide_admins_before( $this->first_admin );
		$grant = Grants::get(
			Grants::create(
				array(
					'label'      => 'Acme',
					'created_by' => 0,
					'role'       => 'administrator',
				)
			)['id']
		);
		$temp  = TempUsers::get_or_create( $grant );
		update_user_meta( $this->first_admin, 'happyaccess_temp_user', 1 );
		update_user_meta( $this->second_admin, 'happyaccess_temp_user', 1 );
		$this->assertTrue( user_can( $temp, 'read' ) );
		$this->assertSame( 0, Grants::owner_id( $grant ) );
	}
}
