<?php
/**
 * Per-role two-step policy and the grace period.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Clock;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\Challenge;
use HappyAccess\Features\TwoStep\Enforcement;
use HappyAccess\Features\TwoStep\Feature;
use HappyAccess\Features\TwoStep\UserState;
use HappyAccess\Login\Router;

class EnforcementTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
		Challenge::reset();
		Features::set( 'two_step', true );
		Feature::register();
		Clock::freeze( 1790000000 );
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			Clock::freeze( null );
			Router::reset();
			Challenge::reset();
		} finally {
			parent::tear_down();
		}
	}

	/**
	 * Saves the two-step settings.
	 *
	 * @param array $policy Role policy.
	 * @param array $extra  Other two_step settings.
	 * @return void
	 */
	private function set_policy( array $policy, array $extra = array() ) {
		// update() merges into the stored policy, so the old one is cleared first.
		Settings::update( array( 'two_step' => array( 'role_policy' => array_fill_keys( array_keys( wp_roles()->roles ), 'off' ) ) ) );
		Settings::update( array( 'two_step' => array_merge( array( 'role_policy' => $policy ), $extra ) ) );
	}

	/**
	 * A user with these roles.
	 *
	 * @param string[] $roles Roles.
	 * @return WP_User
	 */
	private function user_with( array $roles ) {
		$user = self::factory()->user->create_and_get( array( 'role' => array_shift( $roles ) ) );
		foreach ( $roles as $role ) {
			$user->add_role( $role );
		}
		return get_userdata( $user->ID );
	}

	public function test_policy_resolution_for_mixed_roles() {
		$user = $this->user_with( array( 'editor', 'author' ) );

		$this->assertSame( 'off', Enforcement::policy( $user ), 'No policy set.' );

		$this->set_policy( array( 'editor' => 'optional' ) );
		$this->assertSame( 'optional', Enforcement::policy( $user ) );

		$this->set_policy(
			array(
				'editor' => 'optional',
				'author' => 'required',
			)
		);
		$this->assertSame( 'required', Enforcement::policy( $user ), 'Any required role wins.' );

		$this->set_policy(
			array(
				'editor' => 'required',
				'author' => 'off',
			)
		);
		$this->assertSame( 'required', Enforcement::policy( $user ), 'Required wins over off.' );

		$this->set_policy(
			array(
				'editor' => 'off',
				'author' => 'optional',
			)
		);
		$this->assertSame( 'optional', Enforcement::policy( $user ), 'Optional wins over off.' );

		$this->set_policy( array( 'subscriber' => 'required' ) );
		$this->assertSame( 'off', Enforcement::policy( $user ), 'Roles the user lacks do not count.' );
	}

	public function test_needs_setup_only_for_a_required_role_without_a_method() {
		$this->set_policy( array( 'administrator' => 'required' ) );
		$admin  = $this->user_with( array( 'administrator' ) );
		$editor = $this->user_with( array( 'editor' ) );

		$this->assertTrue( Challenge::needs_setup( $admin ) );
		$this->assertTrue( Challenge::applies( $admin ) );
		$this->assertFalse( Challenge::needs_setup( $editor ) );
		$this->assertFalse( Challenge::applies( $editor ) );

		UserState::enable_email( $admin->ID );
		$this->assertFalse( Challenge::needs_setup( $admin ), 'A user with a method gets the code step instead.' );
		$this->assertTrue( Challenge::applies( $admin ) );
	}

	public function test_optional_and_off_users_never_need_setup() {
		$this->set_policy(
			array(
				'editor' => 'optional',
				'author' => 'off',
			)
		);
		$this->assertFalse( Challenge::needs_setup( $this->user_with( array( 'editor' ) ) ) );
		$this->assertFalse( Challenge::needs_setup( $this->user_with( array( 'author' ) ) ) );
		$this->assertFalse( Challenge::needs_setup( $this->user_with( array( 'subscriber' ) ) ) );
	}

	public function test_exempt_users_never_need_setup() {
		$this->set_policy( array( 'administrator' => 'required' ) );

		$temp = $this->user_with( array( 'administrator' ) );
		update_user_meta( $temp->ID, 'happyaccess_temp_user', 1 );
		$this->assertFalse( Challenge::needs_setup( $temp ), 'Support Access temp users are exempt.' );
		$this->assertFalse( Challenge::applies( $temp ) );

		$other = $this->user_with( array( 'administrator' ) );
		add_filter( 'happyaccess_user_has_other_2fa', '__return_true' );
		try {
			$this->assertFalse( Challenge::needs_setup( $other ), 'Another plugin already asks for a second step.' );
		} finally {
			remove_filter( 'happyaccess_user_has_other_2fa', '__return_true' );
		}
	}

	/**
	 * The constant is defined here, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_wp_config_constant_turns_enforcement_off() {
		define( 'HAPPYACCESS_DISABLE_TWOSTEP', true );
		$this->set_policy( array( 'administrator' => 'required' ) );
		$this->assertFalse( Challenge::needs_setup( $this->user_with( array( 'administrator' ) ) ) );
	}

	public function test_grace_by_logins_counts_each_login() {
		$this->set_policy(
			array( 'administrator' => 'required' ),
			array(
				'grace_type'   => 'logins',
				'grace_logins' => 3,
			)
		);
		$user = $this->user_with( array( 'administrator' ) );

		$this->assertFalse( Enforcement::in_grace( $user->ID ), 'Grace starts at the first login.' );
		for ( $login = 1; $login <= 3; $login++ ) {
			Enforcement::note_login( $user->ID );
			$this->assertSame( $login, UserState::grace_logins_used( $user->ID ) );
			$this->assertTrue( Enforcement::in_grace( $user->ID ), "Login $login is inside the grace period." );
			$this->assertSame( 3 - $login, Enforcement::skips_left( $user->ID ) );
		}
		$this->assertSame( 1790000000, UserState::grace_started_at( $user->ID ) );

		Enforcement::note_login( $user->ID );
		$this->assertFalse( Enforcement::in_grace( $user->ID ), 'Login 4 is past the grace period.' );

		Enforcement::note_login( $user->ID );
		$this->assertSame( 4, UserState::grace_logins_used( $user->ID ), 'The count stops one past the limit.' );
	}

	public function test_grace_by_days_uses_the_clock() {
		$this->set_policy(
			array( 'administrator' => 'required' ),
			array(
				'grace_type' => 'days',
				'grace_days' => 7,
			)
		);
		$user = $this->user_with( array( 'administrator' ) );

		Enforcement::note_login( $user->ID );
		$this->assertTrue( Enforcement::in_grace( $user->ID ) );
		$this->assertSame( 1790000000 + 7 * DAY_IN_SECONDS, Enforcement::grace_ends_at( $user->ID ) );
		$this->assertSame( 0, UserState::grace_logins_used( $user->ID ), 'Days grace counts no logins.' );

		Clock::freeze( 1790000000 + 7 * DAY_IN_SECONDS - 1 );
		Enforcement::note_login( $user->ID );
		$this->assertTrue( Enforcement::in_grace( $user->ID ) );
		$this->assertSame( 1790000000, UserState::grace_started_at( $user->ID ), 'A later login keeps the start time.' );

		Clock::freeze( 1790000000 + 7 * DAY_IN_SECONDS );
		$this->assertFalse( Enforcement::in_grace( $user->ID ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_a_super_admin_follows_the_main_site_policy() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		$this->set_policy( array( 'administrator' => 'required' ) );

		$site  = self::factory()->blog->create();
		$admin = self::factory()->user->create_and_get();
		grant_super_admin( $admin->ID );
		add_user_to_blog( $site, $admin->ID, 'subscriber' );

		switch_to_blog( $site );
		try {
			Installer::install();
			Settings::update( array( 'two_step' => array( 'role_policy' => array( 'administrator' => 'off' ) ) ) );
			$this->assertSame( 'required', Enforcement::policy( get_userdata( $admin->ID ) ), 'The main site requires it for administrators.' );

			$plain = self::factory()->user->create_and_get();
			add_user_to_blog( $site, $plain->ID, 'administrator' );
			$this->assertSame( 'off', Enforcement::policy( get_userdata( $plain->ID ) ), 'Other users follow this site.' );
		} finally {
			restore_current_blog();
			revoke_super_admin( $admin->ID );
		}
	}
}
