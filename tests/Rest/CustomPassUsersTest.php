<?php
/**
 * A custom pass with user permissions can't reach the administrator role.
 *
 * @package HappyAccess
 */

use HappyAccess\Features\SupportAccess\AccountGuard;
use HappyAccess\Features\SupportAccess\CapabilityGuard;

require_once __DIR__ . '/RestTestCase.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

class CustomPassUsersTest extends RestTestCase {

	public function set_up() {
		parent::set_up();
		CapabilityGuard::register();
		AccountGuard::register();
	}

	private function custom( array $caps ) {
		return $this->as_temp_user(
			array(
				'level'        => 'custom',
				'caps'         => $caps,
				'confirm_full' => true,
			)
		);
	}

	private function users_request( $method, $path, array $params ) {
		$request = new WP_REST_Request( $method, '/wp/v2/users' . $path );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	public function test_custom_pass_cannot_promote_itself_to_administrator() {
		$temp = $this->custom( array( 'list_users', 'edit_users', 'promote_users' ) );

		$response = $this->users_request( 'PUT', '/' . $temp, array( 'roles' => array( 'administrator' ) ) );

		$this->assertSame( 403, $response->get_status() );
		clean_user_cache( $temp );
		$this->assertSame( array(), get_userdata( $temp )->roles );
		$this->assertFalse( user_can( $temp, 'manage_options' ) );
		$this->assertFalse( current_user_can( 'promote_user', $temp ) );
	}

	public function test_custom_pass_cannot_create_an_administrator() {
		$this->custom( array( 'create_users', 'list_users' ) );

		$response = $this->users_request(
			'POST',
			'',
			array(
				'username' => 'madeadmin',
				'email'    => 'madeadmin@example.org',
				'password' => 'x-strong-password',
				'roles'    => array( 'administrator' ),
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( get_user_by( 'login', 'madeadmin' ) );
	}

	public function test_custom_pass_can_create_a_subscriber() {
		$this->custom( array( 'create_users', 'list_users' ) );

		$response = $this->users_request(
			'POST',
			'',
			array(
				'username' => 'madecustomer',
				'email'    => 'madecustomer@example.org',
				'password' => 'x-strong-password',
				'roles'    => array( 'subscriber' ),
			)
		);

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_custom_pass_can_give_only_roles_inside_its_own_caps() {
		$this->custom( array( 'list_users', 'edit_users', 'promote_users', 'edit_posts', 'upload_files', 'delete_posts' ) );

		$roles = array_keys( get_editable_roles() );
		sort( $roles );

		$this->assertSame( array( 'contributor', 'subscriber' ), $roles );
	}

	public function test_custom_pass_may_promote_a_subscriber_to_a_role_it_covers() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->custom( array( 'list_users', 'edit_users', 'promote_users', 'edit_posts', 'delete_posts' ) );

		$response = $this->users_request( 'PUT', '/' . $subscriber, array( 'roles' => array( 'contributor' ) ) );
		$denied   = $this->users_request( 'PUT', '/' . $subscriber, array( 'roles' => array( 'editor' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 403, $denied->get_status() );
		clean_user_cache( $subscriber );
		$this->assertSame( array( 'contributor' ), get_userdata( $subscriber )->roles );
	}

	public function test_full_pass_keeps_every_role_and_may_change_its_own() {
		$temp = $this->as_temp_user(
			array(
				'level'        => 'full',
				'confirm_full' => true,
			)
		);

		$this->assertArrayHasKey( 'administrator', get_editable_roles() );
		$this->assertTrue( current_user_can( 'promote_user', $temp ) );
	}
}
