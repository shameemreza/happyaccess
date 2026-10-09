<?php
/**
 * GET twostep/coverage: who has two-step login, per role.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;
use HappyAccess\Features\TwoStep\Coverage;
use HappyAccess\Features\TwoStep\Feature;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;
use HappyAccess\Rest\Routes;

class CoverageTest extends WP_UnitTestCase {

	/**
	 * Settings globals as they were before rest_api_init ran.
	 *
	 * @var array
	 */
	private $settings_globals = array();

	/**
	 * Roles a test added, removed again in tear_down.
	 *
	 * @var string[]
	 */
	private $added_roles = array();

	/**
	 * The administrator making the requests.
	 *
	 * @var int
	 */
	private $owner;

	public function set_up() {
		parent::set_up();
		Installer::install();
		delete_option( Settings::OPTION );
		Clock::freeze( 1790000000 );
		Capabilities::register();
		delete_transient( Coverage::TRANSIENT );

		foreach ( array( 'new_allowed_options', 'wp_registered_settings' ) as $name ) {
			$this->settings_globals[ $name ] = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;
		}

		Features::set( 'two_step', true );
		Settings::update( array( 'two_step' => array( 'role_policy' => array( 'editor' => 'required' ) ) ) );
		Feature::register();
		Routes::register();
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$this->owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner );
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			$GLOBALS['wp_rest_server'] = null;
			foreach ( $this->settings_globals as $name => $value ) {
				$GLOBALS[ $name ] = $value;
			}
			foreach ( $this->added_roles as $role ) {
				remove_role( $role );
			}
			delete_transient( Coverage::TRANSIENT );
			Clock::freeze( null );
		} finally {
			parent::tear_down();
		}
	}

	/**
	 * GET twostep/coverage as the current user.
	 *
	 * @return WP_REST_Response
	 */
	private function get() {
		return rest_do_request( new WP_REST_Request( 'GET', '/' . Routes::NS . '/twostep/coverage' ) );
	}

	/**
	 * The rows of the answer, by role slug.
	 *
	 * @return array
	 */
	private function rows() {
		$response = $this->get();
		$this->assertSame( 200, $response->get_status() );
		$rows = array();
		foreach ( $response->get_data()['roles'] as $row ) {
			$rows[ $row['slug'] ] = $row;
		}
		return $rows;
	}

	/**
	 * Gives a user a grace period that ran out: more logins than the
	 * grace allows.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private function past_grace( $user_id ) {
		UserState::start_grace( $user_id, Clock::now() );
		for ( $i = 0; $i < 4; $i++ ) {
			UserState::count_grace_login( $user_id );
		}
	}

	public function test_a_logged_out_visitor_gets_401() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get()->get_status() );
	}

	public function test_an_editor_and_a_temp_user_get_403() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->get()->get_status() );

		wp_set_current_user( $this->owner );
		$made = Grants::create( array( 'label' => 'Agent' ) );
		wp_set_current_user( TempUsers::get_or_create( Grants::get( $made['id'] ) ) );
		Grants::flush_cache();
		$this->assertSame( 403, $this->get()->get_status() );
	}

	public function test_the_answer_is_not_stored_by_browsers_or_proxies() {
		$headers = $this->get()->get_headers();
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
	}

	public function test_counts_a_mix_of_users_by_role() {
		$app = self::factory()->user->create( array( 'role' => 'administrator' ) );
		UserState::enable_app( $app, Totp::new_secret() );
		$this->assertTrue( UserState::app_enabled( $app ) );

		$email = self::factory()->user->create( array( 'role' => 'editor' ) );
		UserState::enable_email( $email );
		$waiting = self::factory()->user->create( array( 'role' => 'editor' ) );
		UserState::start_grace( $waiting, Clock::now() );
		UserState::count_grace_login( $waiting );
		self::factory()->user->create( array( 'role' => 'editor' ) );
		$late = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->past_grace( $late );
		// Email turned off again, and backup codes left over, are not two-step login.
		$off = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		UserState::enable_email( $off );
		UserState::disable_email( $off );

		// A temp user is an administrator but never uses two-step login.
		$made = Grants::create( array( 'label' => 'Agent' ) );
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		Grants::flush_cache();
		$this->assertContains( 'administrator', get_userdata( $temp )->roles );

		$rows = $this->rows();

		$admins = count( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) ) - 1;
		$this->assertSame(
			array(
				'slug'       => 'administrator',
				'name'       => 'Administrators',
				'total'      => $admins,
				'enabled'    => 1,
				'required'   => false,
				'setting_up' => 0,
				'past_grace' => 0,
			),
			$rows['administrator']
		);
		$this->assertSame(
			array(
				'slug'       => 'editor',
				'name'       => 'Editors',
				'total'      => 4,
				'enabled'    => 1,
				'required'   => true,
				'setting_up' => 2,
				'past_grace' => 1,
			),
			$rows['editor']
		);
		$this->assertSame( 0, $rows['subscriber']['enabled'] );
		$this->assertSame( 1, $rows['subscriber']['total'] );
		$this->assertArrayNotHasKey( 'author', $rows, 'A role with no users is left out.' );
		$this->assertFalse( $this->get()->get_data()['large'] );
	}

	public function test_grace_by_days_runs_out_by_date() {
		Settings::update(
			array(
				'two_step' => array(
					'grace_type' => 'days',
					'grace_days' => 7,
				),
			)
		);
		$fresh = self::factory()->user->create( array( 'role' => 'editor' ) );
		UserState::start_grace( $fresh, Clock::now() - DAY_IN_SECONDS );
		$old = self::factory()->user->create( array( 'role' => 'editor' ) );
		UserState::start_grace( $old, Clock::now() - 8 * DAY_IN_SECONDS );

		$rows = $this->rows();
		$this->assertSame( 1, $rows['editor']['setting_up'] );
		$this->assertSame( 1, $rows['editor']['past_grace'] );
	}

	public function test_orders_by_user_count_and_keeps_ten_roles() {
		for ( $i = 1; $i <= 11; $i++ ) {
			$role                = 'ha_cov_' . $i;
			$this->added_roles[] = $role;
			add_role( $role, 'Coverage ' . $i, array( 'read' => true ) );
			self::factory()->user->create( array( 'role' => $role ) );
		}
		self::factory()->user->create_many( 3, array( 'role' => 'editor' ) );

		$roles = $this->get()->get_data()['roles'];
		$this->assertCount( 10, $roles );
		$totals = wp_list_pluck( $roles, 'total' );
		$sorted = $totals;
		rsort( $sorted );
		$this->assertSame( $sorted, $totals );
		$this->assertSame( 'Coverage 1', $roles[2]['name'], 'A custom role keeps its own name.' );
	}

	public function test_the_answer_is_kept_for_five_minutes() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->rows();
		$kept = get_transient( Coverage::TRANSIENT );
		$this->assertIsArray( $kept );

		// A write that skips UserState, and so the clear, is not seen until the cache goes.
		update_user_meta( $user, UserState::META_STATE, array( 'email' => true ) );
		$this->assertSame( $kept, $this->get()->get_data() );
	}

	public function test_a_two_step_change_clears_the_kept_answer() {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertSame( 0, $this->rows()['editor']['enabled'] );

		UserState::enable_email( $user );
		$this->assertFalse( get_transient( Coverage::TRANSIENT ) );
		$this->assertSame( 1, $this->rows()['editor']['enabled'] );

		UserState::disable_email( $user );
		$this->assertSame( 0, $this->rows()['editor']['enabled'] );

		UserState::enable_app( $user, Totp::new_secret() );
		$this->assertSame( 1, $this->rows()['editor']['enabled'] );

		UserState::reset( $user );
		$this->assertSame( 0, $this->rows()['editor']['enabled'] );

		UserState::start_grace( $user, Clock::now() );
		$this->assertFalse( get_transient( Coverage::TRANSIENT ) );
	}

	public function test_a_settings_save_or_a_role_change_clears_the_kept_answer() {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertTrue( $this->rows()['editor']['required'] );

		Settings::update( array( 'two_step' => array( 'role_policy' => array( 'editor' => 'optional' ) ) ) );
		$this->assertFalse( $this->rows()['editor']['required'] );

		( new WP_User( $user ) )->set_role( 'author' );
		$this->assertArrayHasKey( 'author', $this->rows() );
	}

	public function test_a_site_over_5000_users_is_large() {
		$this->assertFalse( Coverage::is_large( 5000 ) );
		$this->assertTrue( Coverage::is_large( 5001 ) );
	}
}
