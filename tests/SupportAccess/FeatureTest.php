<?php
/**
 * Feature wiring tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\SupportAccess\Feature;
use HappyAccess\Features\SupportAccess\AdminBar;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\LoginSteps;
use HappyAccess\Features\SupportAccess\TempUsers;
use HappyAccess\Login\Router;

class FeatureTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		delete_option( Settings::OPTION );
		Router::reset();
		remove_action( 'login_form', array( LoginSteps::class, 'print_code_link' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_enabled_registers_guards_and_login_steps() {
		Feature::register();
		$this->assertNotFalse( has_filter( 'wp_is_application_passwords_available_for_user' ) );
		$this->assertNotNull( Router::resolve( 'code' ) );
	}

	public function test_disabled_with_live_grant_keeps_guards_but_not_login() {
		Grants::create( array( 'label' => 'Acme' ) );
		Features::set( 'support_access', false );
		Feature::register();
		$this->assertNotFalse( has_filter( 'wp_is_application_passwords_available_for_user' ) );
		$this->assertNull( Router::resolve( 'code' ) );
	}

	public function test_disabled_without_grants_registers_nothing() {
		Features::set( 'support_access', false );
		remove_all_filters( 'wp_is_application_passwords_available_for_user' );
		Feature::register();
		$this->assertFalse( has_filter( 'wp_is_application_passwords_available_for_user' ) );
		$this->assertNull( Router::resolve( 'code' ) );
	}

	public function test_disabled_with_a_leftover_temp_user_and_no_grant_keeps_the_guards() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );
		$this->assertFalse( Grants::has_current() );
		Features::set( 'support_access', false );
		remove_all_filters( 'wp_is_application_passwords_available_for_user' );
		Feature::register();
		$this->assertNotFalse( has_filter( 'wp_is_application_passwords_available_for_user' ) );
		$this->assertNull( Router::resolve( 'code' ) );
	}

	public function test_disabled_with_a_live_grant_keeps_the_ended_step_and_the_router_hook() {
		Grants::create( array( 'label' => 'Acme' ) );
		Features::set( 'support_access', false );
		Feature::register();
		$this->assertNotNull( Router::resolve( 'ended' ) );
		$this->assertNull( Router::resolve( 'link' ) );
		$this->assertNotFalse( has_action( 'login_form_happyaccess', array( Router::class, 'dispatch' ) ) );
		$this->assertFalse( has_action( 'login_form', array( LoginSteps::class, 'print_code_link' ) ) );
	}

	public function test_disabled_with_a_leftover_temp_user_still_serves_the_ended_step() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, 'happyaccess_temp_user', 1 );
		Features::set( 'support_access', false );
		Feature::register();
		$this->assertNotNull( Router::resolve( 'ended' ) );
	}

	public function test_ended_screen_works_for_a_logged_out_agent_with_the_feature_off() {
		Grants::create( array( 'label' => 'Acme' ) );
		Features::set( 'support_access', false );
		Feature::register();
		wp_set_current_user( 0 );
		$res = call_user_func( array( LoginSteps::class, 'handle_ended' ), array( 'reason' => 'revoked' ) );
		$this->assertSame( 'render', $res['type'] );
		$this->assertStringContainsString( 'revoked by the site owner', $res['body'] );
	}

	public function test_enabled_registers_the_code_link_even_with_no_grants() {
		$this->assertFalse( Grants::has_current() );
		Feature::register();
		$this->assertNotFalse( has_action( 'login_form', array( LoginSteps::class, 'print_code_link' ) ) );
		$this->assertNotNull( Router::resolve( 'ended' ) );
	}

	public function test_register_twice_adds_each_hook_once() {
		Feature::register();
		Feature::register();
		$this->assertSame( 10, has_action( 'login_form', array( LoginSteps::class, 'print_code_link' ) ) );
		$this->assertSame( 10, has_action( 'login_form_happyaccess', array( Router::class, 'dispatch' ) ) );
	}

	public function test_on_disable_revokes_all() {
		Grants::create( array( 'label' => 'a' ) );
		Feature::on_disable();
		$this->assertFalse( Grants::has_current() );
	}

	/**
	 * Records, for each user, the sessions that still existed when the user was about to be deleted.
	 * Deleting a user wipes its session meta anyway, so only this moment proves the sessions were destroyed.
	 *
	 * @param array $seen Receives user id => session list.
	 * @return callable The hook, so the caller can remove it.
	 */
	private function spy_sessions_before_delete( array &$seen ) {
		$spy = function ( $user_id ) use ( &$seen ) {
			$seen[ (int) $user_id ] = WP_Session_Tokens::get_instance( $user_id )->get_all();
		};
		add_action( 'delete_user', $spy, 0 );
		return $spy;
	}

	public function test_on_disable_ends_every_pass_and_destroys_their_sessions() {
		$one = Grants::create( array( 'label' => 'a' ) );
		$two = Grants::create( array( 'label' => 'b' ) );
		$ids = array();
		foreach ( array( $one, $two ) as $made ) {
			$user_id = TempUsers::get_or_create( Grants::get( $made['id'] ) );
			WP_Session_Tokens::get_instance( $user_id )->create( time() + 3600 );
			$this->assertCount( 1, WP_Session_Tokens::get_instance( $user_id )->get_all() );
			$ids[] = $user_id;
		}
		$seen = array();
		$spy  = $this->spy_sessions_before_delete( $seen );
		Feature::on_disable();
		remove_action( 'delete_user', $spy, 0 );
		$this->assertSame( 'revoked', Grants::get( $one['id'] )['status'] );
		$this->assertSame( 'revoked', Grants::get( $two['id'] )['status'] );
		$this->assertSame( $ids, array_keys( $seen ) );
		foreach ( $seen as $sessions ) {
			$this->assertSame( array(), $sessions );
		}
	}

	public function test_emergency_lock_destroys_sessions_before_the_user_is_deleted() {
		$made    = Grants::create( array( 'label' => 'a' ) );
		$user_id = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		WP_Session_Tokens::get_instance( $user_id )->create( time() + 3600 );
		$this->assertCount( 1, WP_Session_Tokens::get_instance( $user_id )->get_all() );
		$seen = array();
		$spy  = $this->spy_sessions_before_delete( $seen );
		AdminBar::emergency_lock();
		remove_action( 'delete_user', $spy, 0 );
		$this->assertSame( array( $user_id => array() ), $seen );
	}
}
