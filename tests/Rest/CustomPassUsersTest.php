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

		// Other tests can leave read-only roles such as customer behind, so check by name.
		$roles = array_keys( get_editable_roles() );

		$this->assertContains( 'contributor', $roles );
		$this->assertContains( 'subscriber', $roles );
		foreach ( array( 'administrator', 'editor', 'author' ) as $role ) {
			$this->assertNotContains( $role, $roles );
		}
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

	public function test_users_route_hides_the_creator_from_a_pass_that_lists_users() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->custom( array( 'list_users' ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$request->set_query_params(
			array(
				'context'  => 'view',
				'per_page' => 100,
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$ids = array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );
		$this->assertContains( $subscriber, $ids );
		$this->assertNotContains( $this->owner, $ids );
	}

	public function test_custom_pass_with_create_users_only_makes_a_subscriber_when_the_default_role_is_beyond_it() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'On a network the users route makes the account with wpmu_create_user and adds it with no role, so default_role is never used.' );
		}
		update_option( 'default_role', 'editor' );
		$this->custom( array( 'create_users', 'list_users' ) );

		$response = $this->users_request(
			'POST',
			'',
			array(
				'username' => 'defaultrole',
				'email'    => 'defaultrole@example.org',
				'password' => 'x-strong-password',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( array( 'subscriber' ), get_user_by( 'login', 'defaultrole' )->roles );
	}

	/**
	 * Runs a GET on the core users routes.
	 *
	 * @param string $path  Path after /wp/v2/users.
	 * @param array  $query Query params.
	 * @return WP_REST_Response
	 */
	private function users_get( $path, array $query = array() ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/users' . $path );
		$request->set_query_params( $query );
		return rest_do_request( $request );
	}

	public function test_include_cannot_bring_the_creator_back_for_a_temp_user() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->custom( array( 'list_users' ) );

		$only   = $this->users_get( '', array( 'include' => array( $this->owner ) ) );
		$mixed  = $this->users_get( '', array( 'include' => array( $this->owner, $subscriber ) ) );

		$this->assertSame( 200, $only->get_status() );
		$this->assertSame( array(), $only->get_data() );
		$this->assertSame( array( $subscriber ), array_map( 'intval', wp_list_pluck( $mixed->get_data(), 'id' ) ) );
	}

	public function test_the_single_user_route_hides_the_creator_from_a_temp_user() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->custom( array( 'list_users' ) );

		$hidden = $this->users_get( '/' . $this->owner );
		$this->assertSame( 404, $hidden->get_status() );
		$this->assertSame( 'rest_user_invalid_id', $hidden->as_error()->get_error_code() );
		$this->assertSame( 200, $this->users_get( '/' . $subscriber )->get_status() );
	}

	public function test_an_admin_still_sees_the_creator_on_both_routes() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->custom( array( 'list_users' ) );
		wp_set_current_user( $admin );

		$listed = $this->users_get( '', array( 'include' => array( $this->owner ) ) );
		$this->assertSame( array( $this->owner ), array_map( 'intval', wp_list_pluck( $listed->get_data(), 'id' ) ) );
		$this->assertSame( 200, $this->users_get( '/' . $this->owner )->get_status() );
	}
}
