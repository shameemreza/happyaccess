<?php
/**
 * Two-step settings on a network: the main site's while HappyAccess is
 * network active. Run with composer test:multisite.
 *
 * @package HappyAccess
 */

use HappyAccess\Admin\Page;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\Challenge;
use HappyAccess\Features\TwoStep\Enforcement;

/**
 * @group ms-required
 */
class NetworkSettingsTest extends WP_UnitTestCase {

	/**
	 * The second site.
	 *
	 * @var int
	 */
	private $site;

	/**
	 * An administrator of the second site, not a super admin.
	 *
	 * @var WP_User
	 */
	private $admin;

	public function set_up() {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}
		Installer::install();
		delete_option( Settings::OPTION );
		Capabilities::register();
		Features::set( 'two_step', true );
		Settings::update(
			array(
				'two_step' => array(
					'role_policy'  => array( 'administrator' => 'required' ),
					'grace_type'   => 'days',
					'grace_days'   => 3,
					'block_xmlrpc' => false,
				),
			)
		);

		$this->site  = self::factory()->blog->create();
		$this->admin = self::factory()->user->create_and_get();
		add_user_to_blog( $this->site, $this->admin->ID, 'administrator' );

		switch_to_blog( $this->site );
		Installer::install();
		delete_option( Settings::OPTION );
		Settings::update( array( 'two_step' => array( 'role_policy' => array( 'administrator' => 'optional' ) ) ) );
		Enforcement::flush_cache();
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			if ( is_multisite() ) {
				restore_current_blog();
				delete_site_option( 'active_sitewide_plugins' );
				Enforcement::flush_cache();
			}
		} finally {
			parent::tear_down();
		}
	}

	private function activate_network_wide() {
		update_site_option( 'active_sitewide_plugins', array( HAPPYACCESS_PLUGIN_BASENAME => time() ) );
	}

	public function test_network_active_sites_follow_the_main_sites_switch_policy_and_grace() {
		$this->activate_network_wide();
		$user = get_userdata( $this->admin->ID );

		$this->assertFalse( Settings::get( 'features.two_step' ), 'This site has it off itself.' );
		$this->assertTrue( Features::is_enabled( 'two_step' ), 'The main site turns it on for the network.' );
		$this->assertSame( Enforcement::REQUIRED, Enforcement::policy( $user ) );
		$this->assertTrue( Challenge::needs_setup( $user ), 'A login on this site can not skip the main site policy.' );
		$this->assertSame( 'days', Enforcement::grace_type() );
		$this->assertSame( 3, Settings::shared( 'two_step.grace_days' ) );
		$this->assertFalse( Settings::shared( 'two_step.block_xmlrpc' ) );
		$this->assertSame( 'link', Settings::shared( 'passwordless.toggle_style' ), 'Other settings stay per site.' );
	}

	public function test_without_network_activation_each_site_keeps_its_own_settings() {
		$user = get_userdata( $this->admin->ID );

		$this->assertFalse( Features::is_enabled( 'two_step' ) );
		$this->assertSame( Enforcement::OPTIONAL, Enforcement::policy( $user ) );
		$this->assertSame( 'logins', Enforcement::grace_type() );
	}

	public function test_the_admin_boot_data_names_how_the_network_shares_logins() {
		wp_set_current_user( $this->admin->ID );
		$this->assertSame( 'subdir', Page::boot_data()['twoStepNetwork'], 'Cookies are shared and each site decides alone.' );

		$this->activate_network_wide();
		$this->assertSame( 'main', Page::boot_data()['twoStepNetwork'] );

		restore_current_blog();
		$this->assertSame( '', Page::boot_data()['twoStepNetwork'], 'The main site owns the settings.' );
		switch_to_blog( $this->site );
	}
}
