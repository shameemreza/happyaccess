<?php
/**
 * Two-step coverage counts on a network. Run with composer test:multisite.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\Coverage;
use HappyAccess\Features\TwoStep\UserState;

/**
 * @group ms-required
 */
class CoverageNetworkTest extends WP_UnitTestCase {

	/**
	 * The second site.
	 *
	 * @var int
	 */
	private $site;

	public function set_up() {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		Installer::install();
		delete_option( Settings::OPTION );
		Capabilities::register();
		Features::set( 'two_step', true );
		$this->site = self::factory()->blog->create();
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			if ( is_multisite() ) {
				restore_current_blog();
				delete_transient( Coverage::TRANSIENT );
			}
		} finally {
			parent::tear_down();
		}
	}

	public function test_counts_only_the_users_of_the_current_site() {
		$main_only = self::factory()->user->create( array( 'role' => 'editor' ) );
		UserState::enable_email( $main_only );
		$both = self::factory()->user->create( array( 'role' => 'editor' ) );
		add_user_to_blog( $this->site, $both, 'editor' );

		switch_to_blog( $this->site );
		Installer::install();
		Features::set( 'two_step', true );
		delete_transient( Coverage::TRANSIENT );

		$editors = wp_list_filter( Coverage::counts()['roles'], array( 'slug' => 'editor' ) );
		$this->assertCount( 1, $editors );
		$row = reset( $editors );
		$this->assertSame( 1, $row['total'] );
		$this->assertSame( 0, $row['enabled'] );
	}

	/**
	 * Switches to the second site with HappyAccess set up there.
	 *
	 * @return void
	 */
	private function on_second_site() {
		switch_to_blog( $this->site );
		Installer::install();
		Features::set( 'two_step', true );
		delete_transient( Coverage::TRANSIENT );
	}

	/**
	 * The administrator row of the current site.
	 *
	 * @return array|null
	 */
	private function admin_row() {
		$rows = wp_list_filter( Coverage::counts()['roles'], array( 'slug' => 'administrator' ) );
		return $rows ? reset( $rows ) : null;
	}

	public function test_a_super_admin_counts_as_an_administrator_on_a_site_they_are_not_a_member_of() {
		$super = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		grant_super_admin( $super );
		UserState::enable_email( $super );

		$this->on_second_site();
		$admins = array_map(
			'intval',
			get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
				)
			)
		);
		$this->assertFalse( is_user_member_of_blog( $super, $this->site ) );
		$elsewhere = 0;
		foreach ( get_super_admins() as $login ) {
			if ( ! in_array( (int) get_user_by( 'login', $login )->ID, $admins, true ) ) {
				++$elsewhere;
			}
		}

		$row = $this->admin_row();

		$this->assertSame( count( $admins ) + $elsewhere, $row['total'] );
		$this->assertGreaterThanOrEqual( 1, $row['enabled'] );
		revoke_super_admin( $super );
	}

	public function test_a_super_admin_who_is_also_an_administrator_here_counts_once() {
		$super = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $super );
		$this->on_second_site();
		add_user_to_blog( $this->site, $super, 'administrator' );
		delete_transient( Coverage::TRANSIENT );
		$with = $this->admin_row()['total'];

		revoke_super_admin( $super );
		delete_transient( Coverage::TRANSIENT );
		$this->assertSame( $with, $this->admin_row()['total'] );
	}

	public function test_a_super_admin_with_another_role_here_counts_only_as_an_administrator() {
		$super = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		grant_super_admin( $super );
		$this->on_second_site();
		add_user_to_blog( $this->site, $super, 'editor' );
		delete_transient( Coverage::TRANSIENT );

		$this->assertEmpty( wp_list_filter( Coverage::counts()['roles'], array( 'slug' => 'editor' ) ) );
		revoke_super_admin( $super );
	}

	public function test_forgetting_on_one_site_drops_the_kept_counts_of_every_site() {
		$this->on_second_site();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		add_user_to_blog( $this->site, $editor, 'editor' );
		$kept = Coverage::counts();
		$this->assertSame( $kept, Coverage::counts(), 'The second read comes from the kept answer.' );
		restore_current_blog();

		// A two-step change for the user on the main site.
		UserState::enable_email( $editor );

		switch_to_blog( $this->site );
		$editors = wp_list_filter( Coverage::counts()['roles'], array( 'slug' => 'editor' ) );
		$this->assertSame( 1, reset( $editors )['enabled'] );
	}
}
