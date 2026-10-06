<?php
/**
 * TempUsers tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\TempUsers;

class TempUsersTest extends WP_UnitTestCase {

	private $owner;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$this->owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function grant( $id = 7 ) {
		global $wpdb;
		$wpdb->insert(
			Installer::table( 'tokens' ),
			array(
				'id'         => $id,
				'token_hash' => 'h' . $id,
				'created_by' => $this->owner,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			)
		);
		return array(
			'id'         => $id,
			'role'       => 'editor',
			'label'      => 'Acme support',
			'created_by' => $this->owner,
			'user_id'    => 0,
		);
	}

	public function test_create_makes_a_marked_user_and_links_the_grant() {
		global $wpdb;
		$grant   = $this->grant();
		$user_id = TempUsers::create( $grant );
		$user    = get_userdata( $user_id );

		$this->assertStringStartsWith( 'happyaccess_', $user->user_login );
		$this->assertStringEndsWith( '@happyaccess.invalid', $user->user_email );
		$this->assertSame( array( 'editor' ), $user->roles );
		$this->assertSame( 'Support access: Acme support', $user->display_name );
		$this->assertSame( '1', get_user_meta( $user_id, 'happyaccess_temp_user', true ) );
		$this->assertSame( '7', get_user_meta( $user_id, 'happyaccess_token_id', true ) );
		$this->assertSame( (string) get_current_blog_id(), get_user_meta( $user_id, 'happyaccess_blog_id', true ) );
		$this->assertSame( (string) $user_id, $wpdb->get_var( 'SELECT user_id FROM ' . Installer::table( 'tokens' ) . ' WHERE id = 7' ) );
	}

	public function test_losing_a_parallel_create_keeps_one_temp_user_and_returns_the_winner() {
		global $wpdb;
		$stale  = $this->grant();
		$winner = TempUsers::create( $stale );
		$this->assertSame( 0, $stale['user_id'] );

		$returned = TempUsers::get_or_create( $stale );

		$this->assertSame( $winner, $returned );
		$this->assertSame( $winner, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . Installer::table( 'tokens' ) . ' WHERE id = %d', 7 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$temp = get_users( array( 'meta_key' => 'happyaccess_token_id', 'meta_value' => 7, 'fields' => 'ID' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$this->assertSame( array( $winner ), array_map( 'intval', $temp ) );
	}

	public function test_a_stale_link_to_a_deleted_user_is_replaced() {
		global $wpdb;
		$grant = $this->grant();
		$gone  = TempUsers::create( $grant );
		wp_delete_user( $gone );
		$grant['user_id'] = $gone;

		$fresh = TempUsers::get_or_create( $grant );

		$this->assertNotSame( $gone, $fresh );
		$this->assertSame( $fresh, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . Installer::table( 'tokens' ) . ' WHERE id = %d', 7 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotFalse( get_userdata( $fresh ) );
	}

	public function test_get_or_create_reuses_a_live_temp_user() {
		$grant            = $this->grant();
		$first            = TempUsers::get_or_create( $grant );
		$grant['user_id'] = $first;
		$this->assertSame( $first, TempUsers::get_or_create( $grant ) );

		$grant['user_id'] = $this->owner;
		$this->assertNotSame( $this->owner, TempUsers::get_or_create( $grant ) );
	}

	public function test_delete_reassigns_posts_destroys_sessions_and_logs() {
		$grant            = $this->grant();
		$user_id          = TempUsers::create( $grant );
		$grant['user_id'] = $user_id;
		$post_id          = self::factory()->post->create( array( 'post_author' => $user_id ) );
		WP_Session_Tokens::get_instance( $user_id )->create( time() + 3600 );

		$this->assertTrue( TempUsers::delete( $grant ) );
		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertSame( $this->owner, (int) get_post( $post_id )->post_author );
		$this->assertSame(
			1,
			AuditLog::query(
				array(
					'event'    => 'temp_user_deleted',
					'token_id' => 7,
				)
			)['total']
		);
	}

	public function test_delete_reassigns_to_the_fallback_admin_when_the_creator_is_0() {
		$backup           = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$grant            = $this->grant();
		$grant['created_by'] = 0;
		$user_id          = TempUsers::create( $grant );
		$grant['user_id'] = $user_id;
		$post_id          = self::factory()->post->create( array( 'post_author' => $user_id ) );
		foreach ( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) as $id ) {
			if ( (int) $id < $backup ) {
				update_user_meta( (int) $id, 'happyaccess_temp_user', 1 );
			}
		}

		$this->assertTrue( TempUsers::delete( $grant ) );
		$this->assertSame( $backup, (int) get_post( $post_id )->post_author );
	}

	public function test_delete_does_not_reassign_to_the_user_being_deleted() {
		$grant               = $this->grant();
		$grant['created_by'] = 0;
		$grant['role']       = 'administrator';
		$user_id             = TempUsers::create( $grant );
		$grant['user_id']    = $user_id;
		$post_id             = self::factory()->post->create( array( 'post_author' => $user_id ) );
		foreach ( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) as $id ) {
			if ( (int) $id !== $user_id ) {
				update_user_meta( (int) $id, 'happyaccess_temp_user', 1 );
			}
		}

		$this->assertTrue( TempUsers::delete( $grant ) );
		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertSame( 'trash', get_post_status( $post_id ) );
	}

	public function test_delete_refuses_a_normal_user() {
		$grant            = $this->grant();
		$grant['user_id'] = $this->owner;
		$this->assertFalse( TempUsers::delete( $grant ) );
		$this->assertNotFalse( get_userdata( $this->owner ) );
	}

	public function test_destroy_sessions_ends_sessions_but_keeps_the_user() {
		$grant   = $this->grant();
		$user_id = TempUsers::create( $grant );
		$tokens  = WP_Session_Tokens::get_instance( $user_id );
		$tokens->create( time() + 3600 );
		$this->assertNotEmpty( $tokens->get_all() );

		TempUsers::destroy_sessions( $user_id );

		$this->assertEmpty( WP_Session_Tokens::get_instance( $user_id )->get_all() );
		$this->assertNotFalse( get_userdata( $user_id ) );
	}

	public function test_delete_refuses_another_grants_temp_user() {
		$other_grant      = $this->grant( 8 );
		$other_user       = TempUsers::create( $other_grant );
		$grant            = $this->grant( 7 );
		$grant['user_id'] = $other_user;

		$this->assertFalse( TempUsers::delete( $grant ) );
		$this->assertNotFalse( get_userdata( $other_user ) );
	}

	public function test_get_or_create_does_not_reuse_another_grants_temp_user() {
		$other_grant      = $this->grant( 8 );
		$other_user       = TempUsers::create( $other_grant );
		$grant            = $this->grant( 7 );
		$grant['user_id'] = $other_user;

		$this->assertNotSame( $other_user, TempUsers::get_or_create( $grant ) );
	}

	public function test_delete_clears_the_link_when_the_user_is_already_gone() {
		global $wpdb;
		$grant            = $this->grant();
		$user_id          = TempUsers::create( $grant );
		$grant['user_id'] = $user_id;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );

		$this->assertFalse( TempUsers::delete( $grant ) );
		$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT user_id FROM ' . Installer::table( 'tokens' ) . ' WHERE id = 7' ) );
	}
}
