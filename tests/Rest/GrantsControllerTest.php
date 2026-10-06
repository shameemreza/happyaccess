<?php
/**
 * Grant route tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\Grants;

require_once __DIR__ . '/RestTestCase.php';

class GrantsControllerTest extends RestTestCase {

	/**
	 * Every grant route, with params that pass validation so only the
	 * permission check decides. {id} is replaced with a real grant id.
	 *
	 * @return array
	 */
	public function routes_provider() {
		return array(
			'list'       => array( 'GET', '/grants', array() ),
			'create'     => array( 'POST', '/grants', array( 'label' => 'Acme' ) ),
			'read'       => array( 'GET', '/grants/{id}', array() ),
			'extend'     => array( 'POST', '/grants/{id}/extend', array( 'seconds' => DAY_IN_SECONDS ) ),
			'suspend'    => array( 'POST', '/grants/{id}/suspend', array() ),
			'resume'     => array( 'POST', '/grants/{id}/resume', array() ),
			'regenerate' => array( 'POST', '/grants/{id}/regenerate', array() ),
			'revoke'     => array( 'DELETE', '/grants/{id}', array() ),
			'revoke all' => array( 'POST', '/grants/revoke-all', array() ),
		);
	}

	/**
	 * @dataProvider routes_provider
	 */
	public function test_logged_out_gets_401( $method, $path, $params ) {
		$id = Grants::create( array( 'label' => 'Target' ) )['id'];
		wp_set_current_user( 0 );

		$before   = $this->grant_count();
		$response = $this->request( $method, str_replace( '{id}', (string) $id, $path ), $params );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'active', Grants::get( $id )['status'] );
		if ( 'POST' === $method && '/grants' === $path ) {
			$this->assertSame( $before, $this->grant_count() );
		}
	}

	/**
	 * @dataProvider routes_provider
	 */
	public function test_protected_temp_user_gets_403( $method, $path, $params ) {
		$id = Grants::create( array( 'label' => 'Target' ) )['id'];
		$this->as_temp_user();

		$before   = $this->grant_count();
		$response = $this->request( $method, str_replace( '{id}', (string) $id, $path ), $params );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'active', Grants::get( $id )['status'] );
		if ( 'POST' === $method && '/grants' === $path ) {
			$this->assertSame( $before, $this->grant_count() );
		}
	}

	/**
	 * @dataProvider routes_provider
	 */
	public function test_full_temp_user_gets_403( $method, $path, $params ) {
		$id = Grants::create( array( 'label' => 'Target' ) )['id'];
		$this->as_temp_user(
			array(
				'level'        => 'full',
				'confirm_full' => true,
			)
		);

		$before   = $this->grant_count();
		$response = $this->request( $method, str_replace( '{id}', (string) $id, $path ), $params );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'active', Grants::get( $id )['status'] );
		if ( 'POST' === $method && '/grants' === $path ) {
			$this->assertSame( $before, $this->grant_count() );
		}
	}

	public function test_create_returns_secrets_once_with_no_store() {
		$response = $this->request( 'POST', '/grants', array( 'label' => 'Acme' ) );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertMatchesRegularExpression( '/^\d{4} \d{4}$/', $data['code'] );
		$this->assertStringContainsString( 'step=link&k=', $data['link_url'] );
		$this->assertStringContainsString( 'step=code', $data['code_url'] );
		$this->assertStringContainsString( $data['code'], $data['message'] );
		$this->assertSame( 'protected', $data['level'] );
		$this->assertSame( 'active', $data['status'] );
		$this->assertSame( $this->owner, $data['created_by'] );
		$this->assertFalse( $data['emailed'] );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 'no-cache', $response->get_headers()['Pragma'] );

		$stored = $this->request( 'GET', '/grants/' . $data['id'] );
		$this->assertSame( 200, $stored->get_status() );
		$this->assertArrayNotHasKey( 'code', $stored->get_data() );
		$this->assertArrayNotHasKey( 'link_url', $stored->get_data() );
		$this->assertArrayNotHasKey( 'message', $stored->get_data() );
		$this->assertSame( 'Acme', $stored->get_data()['label'] );
	}

	public function test_present_returns_only_the_listed_fields() {
		$id    = $this->request( 'POST', '/grants', array( 'label' => 'Acme' ) )->get_data()['id'];
		$grant = $this->request( 'GET', '/grants/' . $id )->get_data();

		$expected = array( 'id', 'label', 'email', 'level', 'role', 'caps', 'allow_installs', 'status', 'one_time', 'notify', 'restrictions', 'redirect_to', 'login_count', 'use_count', 'created_at', 'expires_at', 'last_login_at', 'created_by', 'seconds_left', 'duration' );
		$this->assertEqualsCanonicalizing( $expected, array_keys( $grant ) );
		$this->assertEqualsCanonicalizing( array( 'ips', 'menus', 'hide_admin_bar' ), array_keys( $grant['restrictions'] ) );
		$this->assertSame( 1790000000, $grant['created_at'] );
		$this->assertSame( $grant['expires_at'] - 1790000000, $grant['seconds_left'] );
		$this->assertSame( $grant['expires_at'] - $grant['created_at'], $grant['duration'] );
	}

	public function test_no_response_includes_a_hash() {
		$made = $this->request(
			'POST',
			'/grants',
			array(
				'label'    => 'Acme',
				'one_time' => true,
				'ips'      => array( '203.0.113.9' ),
			)
		);
		$id   = $made->get_data()['id'];

		$responses = array(
			$made,
			$this->request( 'GET', '/grants' ),
			$this->request( 'GET', '/grants/' . $id ),
			$this->request( 'POST', '/grants/' . $id . '/extend', array( 'seconds' => HOUR_IN_SECONDS ) ),
			$this->request( 'POST', '/grants/' . $id . '/suspend' ),
			$this->request( 'POST', '/grants/' . $id . '/resume' ),
			$this->request( 'POST', '/grants/' . $id . '/regenerate' ),
		);
		foreach ( $responses as $response ) {
			$this->assertLessThan( 300, $response->get_status() );
			foreach ( $this->keys( $response->get_data() ) as $key ) {
				$this->assertDoesNotMatchRegularExpression( '/_hash$/', (string) $key );
				$this->assertNotSame( 'otp_code', $key );
			}
		}
	}

	public function test_full_create_without_confirm_is_400() {
		$response = $this->request(
			'POST',
			'/grants',
			array(
				'label' => 'Acme',
				'level' => 'full',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'happyaccess_invalid', $response->get_data()['code'] );
		$this->assertFalse( Grants::has_current() );
	}

	public function test_full_create_with_confirm_is_201() {
		$response = $this->request(
			'POST',
			'/grants',
			array(
				'label'        => 'Acme',
				'level'        => 'full',
				'confirm_full' => true,
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'full', $response->get_data()['level'] );
	}

	public function test_create_rejects_a_duration_out_of_range() {
		$response = $this->request(
			'POST',
			'/grants',
			array(
				'label'    => 'Acme',
				'duration' => 60,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( Grants::has_current() );
	}

	public function test_send_email_sends_one_mail() {
		reset_phpmailer_instance();
		$response = $this->request(
			'POST',
			'/grants',
			array(
				'label'      => 'Acme',
				'email'      => 'agent@example.org',
				'send_email' => true,
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['emailed'] );
		$this->assertSame( 'agent@example.org', $response->get_data()['email'] );
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertSame( 'agent@example.org', $sent[0]['to'][0][0] );
	}

	public function test_send_email_without_an_address_sends_nothing() {
		reset_phpmailer_instance();
		$response = $this->request(
			'POST',
			'/grants',
			array(
				'label'      => 'Acme',
				'send_email' => true,
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertFalse( $response->get_data()['emailed'] );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_list_returns_current_grants() {
		$this->request( 'POST', '/grants', array( 'label' => 'One' ) );
		$this->request( 'POST', '/grants', array( 'label' => 'Two' ) );

		$response = $this->request( 'GET', '/grants' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'Two', 'One' ), wp_list_pluck( $response->get_data()['items'], 'label' ) );
	}

	public function test_read_unknown_grant_is_404() {
		$this->assertSame( 404, $this->request( 'GET', '/grants/999999' )->get_status() );
	}

	public function test_extend_moves_expiry_by_a_day() {
		$made   = $this->request(
			'POST',
			'/grants',
			array(
				'label'    => 'Acme',
				'duration' => DAY_IN_SECONDS,
			)
		)->get_data();
		$extend = $this->request( 'POST', '/grants/' . $made['id'] . '/extend', array( 'seconds' => DAY_IN_SECONDS ) );

		$this->assertSame( 200, $extend->get_status() );
		$this->assertSame( $made['expires_at'] + DAY_IN_SECONDS, $extend->get_data()['expires_at'] );
	}

	public function test_extend_revoked_grant_is_409() {
		$id = $this->request( 'POST', '/grants', array( 'label' => 'Acme' ) )->get_data()['id'];
		Grants::revoke( $id );

		$response = $this->request( 'POST', '/grants/' . $id . '/extend', array( 'seconds' => DAY_IN_SECONDS ) );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_extend_rejects_seconds_out_of_range() {
		$id = $this->request( 'POST', '/grants', array( 'label' => 'Acme' ) )->get_data()['id'];

		$this->assertSame( 400, $this->request( 'POST', '/grants/' . $id . '/extend', array( 'seconds' => 60 ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/grants/' . $id . '/extend', array( 'seconds' => 2592001 ) )->get_status() );
	}

	public function test_suspend_then_resume_round_trips() {
		$id = $this->request( 'POST', '/grants', array( 'label' => 'Acme' ) )->get_data()['id'];

		$suspended = $this->request( 'POST', '/grants/' . $id . '/suspend' );
		$this->assertSame( 200, $suspended->get_status() );
		$this->assertSame( 'suspended', $suspended->get_data()['status'] );
		$this->assertSame( 409, $this->request( 'POST', '/grants/' . $id . '/suspend' )->get_status() );

		$resumed = $this->request( 'POST', '/grants/' . $id . '/resume' );
		$this->assertSame( 200, $resumed->get_status() );
		$this->assertSame( 'active', $resumed->get_data()['status'] );
		$this->assertSame( 409, $this->request( 'POST', '/grants/' . $id . '/resume' )->get_status() );
	}

	public function test_regenerate_replaces_the_code() {
		$made = $this->request( 'POST', '/grants', array( 'label' => 'Acme' ) )->get_data();

		$response = $this->request( 'POST', '/grants/' . $made['id'] . '/regenerate' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertMatchesRegularExpression( '/^\d{4} \d{4}$/', $data['code'] );
		$this->assertNotSame( $made['code'], $data['code'] );
		$this->assertNotSame( $made['link_url'], $data['link_url'] );
		$this->assertStringContainsString( $data['code'], $data['message'] );
		$this->assertFalse( $data['emailed'] );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertNull( Grants::find_by_code( $made['code'] ) );
		$this->assertSame( $made['id'], Grants::find_by_code( $data['code'] )['id'] );
	}

	public function test_regenerate_revoked_grant_is_409() {
		$id = $this->request( 'POST', '/grants', array( 'label' => 'Acme' ) )->get_data()['id'];
		Grants::revoke( $id );

		$this->assertSame( 409, $this->request( 'POST', '/grants/' . $id . '/regenerate' )->get_status() );
	}

	public function test_delete_revokes_once() {
		$id = $this->request( 'POST', '/grants', array( 'label' => 'Acme' ) )->get_data()['id'];

		$first = $this->request( 'DELETE', '/grants/' . $id );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( array( 'revoked' => true ), $first->get_data() );
		$this->assertSame( 'revoked', Grants::get( $id )['status'] );

		$this->assertSame( 409, $this->request( 'DELETE', '/grants/' . $id )->get_status() );
	}

	public function test_revoke_all_returns_the_count() {
		$this->request( 'POST', '/grants', array( 'label' => 'One' ) );
		$this->request( 'POST', '/grants', array( 'label' => 'Two' ) );

		$response = $this->request( 'POST', '/grants/revoke-all' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'count' => 2 ), $response->get_data() );
		$this->assertFalse( Grants::has_current() );
	}

	public function test_mutations_on_an_unknown_pass_are_404() {
		$routes = array(
			array( 'POST', '/grants/999999/extend', array( 'seconds' => DAY_IN_SECONDS ) ),
			array( 'POST', '/grants/999999/suspend', array() ),
			array( 'POST', '/grants/999999/resume', array() ),
			array( 'POST', '/grants/999999/regenerate', array() ),
			array( 'DELETE', '/grants/999999', array() ),
		);
		foreach ( $routes as $route ) {
			$response = $this->request( $route[0], $route[1], $route[2] );
			$this->assertSame( 404, $response->get_status(), $route[0] . ' ' . $route[1] );
			$this->assertSame( 'happyaccess_not_found', $response->get_data()['code'] );
		}
	}

	public function test_regenerate_keeps_a_suspended_pass_suspended() {
		$id = $this->request( 'POST', '/grants', array( 'label' => 'Acme' ) )->get_data()['id'];
		$this->request( 'POST', '/grants/' . $id . '/suspend' );

		$response = $this->request( 'POST', '/grants/' . $id . '/regenerate' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'suspended', $response->get_data()['status'] );
		$this->assertSame( 'suspended', Grants::get( $id )['status'] );
	}

	public function test_create_error_message_is_translatable() {
		add_filter( 'gettext_happyaccess', array( $this, 'mark_translated' ) );

		$response = $this->request(
			'POST',
			'/grants',
			array(
				'label' => 'Acme',
				'role'  => 'no_such_role',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'happyaccess_invalid', $response->get_data()['code'] );
		$this->assertSame( '[t] The role does not exist.', $response->get_data()['message'] );
	}

	public function mark_translated( $text ) {
		return '[t] ' . $text;
	}

	/**
	 * Number of rows in the grants table.
	 *
	 * @return int
	 */
	private function grant_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'tokens' ) );
	}

	/**
	 * Every key at any depth of a response body.
	 *
	 * @param mixed $data Response data.
	 * @return array
	 */
	private function keys( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		$keys = array();
		foreach ( $data as $key => $value ) {
			$keys[] = $key;
			$keys   = array_merge( $keys, $this->keys( $value ) );
		}
		return $keys;
	}
}
