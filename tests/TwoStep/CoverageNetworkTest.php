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
}
