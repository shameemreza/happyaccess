<?php
/**
 * Two-step wrong-code cap across a network. Run with composer test:multisite.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Core\RateLimiter;
use HappyAccess\Features\TwoStep\Challenge;

/**
 * @group ms-required
 */
class AccountCapNetworkTest extends WP_UnitTestCase {

	/**
	 * Two other sites of the network.
	 *
	 * @var int[]
	 */
	private $sites = array();

	public function set_up() {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		Installer::install();
		$this->sites = self::factory()->blog->create_many( 2 );
		foreach ( $this->sites as $site_id ) {
			switch_to_blog( $site_id );
			Installer::install();
			restore_current_blog();
		}
		reset_phpmailer_instance();
	}

	/**
	 * Counts wrong codes for a user on a site.
	 *
	 * @param WP_User $user    User.
	 * @param int     $site_id Site.
	 * @param int     $count   How many.
	 * @return void
	 */
	private function wrong_codes( WP_User $user, $site_id, $count ) {
		switch_to_blog( $site_id );
		for ( $i = 0; $i < $count; $i++ ) {
			Challenge::count_account_wrong_code( $user );
		}
		restore_current_blog();
	}

	public function test_wrong_codes_on_different_sites_count_toward_one_cap() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		foreach ( $this->sites as $site_id ) {
			add_user_to_blog( $site_id, $user->ID, 'administrator' );
		}

		$this->wrong_codes( $user, $this->sites[0], 5 );
		$this->wrong_codes( $user, $this->sites[1], 4 );
		$this->assertSame( 0, Challenge::account_wait( $user->ID ), 'Nine wrong codes leave the account open.' );

		$this->wrong_codes( $user, get_main_site_id(), 1 );

		foreach ( array_merge( array( get_main_site_id() ), $this->sites ) as $site_id ) {
			switch_to_blog( $site_id );
			$this->assertGreaterThan( 0, Challenge::account_wait( $user->ID ), 'site ' . $site_id );
			restore_current_blog();
		}
	}

	public function test_the_count_lives_in_the_main_sites_table() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$this->wrong_codes( $user, $this->sites[0], 2 );

		$this->assertSame( 2, RateLimiter::count( Challenge::CODE_ACTION, 'account', Challenge::account_subject( $user->ID ), HOUR_IN_SECONDS ) );
		switch_to_blog( $this->sites[0] );
		$this->assertSame( 0, RateLimiter::count( Challenge::CODE_ACTION, 'account', Challenge::account_subject( $user->ID ), HOUR_IN_SECONDS ) );
		restore_current_blog();
	}

	public function test_a_password_reset_on_a_subsite_ends_the_network_pause() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		add_user_to_blog( $this->sites[0], $user->ID, 'administrator' );
		$this->wrong_codes( $user, $this->sites[1], 10 );
		$this->assertGreaterThan( 0, Challenge::account_wait( $user->ID ) );

		switch_to_blog( $this->sites[0] );
		Challenge::end_account_lock( $user );
		restore_current_blog();

		$this->assertSame( 0, Challenge::account_wait( $user->ID ) );
	}
}
