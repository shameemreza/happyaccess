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
use HappyAccess\Rest\SettingsController;

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

	public function test_the_admin_boot_data_has_the_main_sites_two_step_switch_on_a_subsite() {
		wp_set_current_user( $this->admin->ID );
		$this->assertFalse( Page::boot_data()['features']['two_step'], 'Without network activation the site has its own switch.' );

		$this->activate_network_wide();
		$this->assertTrue( Page::boot_data()['features']['two_step'], 'The main site turned it on for the network.' );

		restore_current_blog();
		Features::set( 'two_step', false );
		switch_to_blog( $this->site );
		Features::set( 'two_step', true );
		$this->assertFalse( Page::boot_data()['features']['two_step'], "The subsite's own switch doesn't apply." );
	}

	/**
	 * A settings save on this site.
	 *
	 * @param array $params Body params.
	 * @return WP_REST_Response|WP_Error
	 */
	private function save( array $params ) {
		$request = new WP_REST_Request( 'POST', '/happyaccess/v1/settings' );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}
		return SettingsController::save_settings( $request );
	}

	public function test_the_settings_route_refuses_two_step_keys_on_a_subsite_that_follows_the_main_site() {
		wp_set_current_user( $this->admin->ID );
		$this->activate_network_wide();
		$before = get_option( Settings::OPTION );

		foreach ( array(
			array( 'features' => array( 'two_step' => true ) ),
			array( 'two_step' => array( 'role_policy' => array( 'editor' => 'required' ) ) ),
			array( 'two_step' => array( 'grace_days' => 9 ) ),
			array(
				'privacy'  => array( 'retention_days' => 60 ),
				'two_step' => array( 'block_xmlrpc' => true ),
			),
		) as $params ) {
			$result = $this->save( $params );
			$this->assertWPError( $result, wp_json_encode( $params ) );
			$this->assertSame( 'happyaccess_two_step_network', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
			$this->assertSame( "HappyAccess is on for the whole network, so two-step login follows the main site's settings. Change them on the main site.", $result->get_error_message() );
		}
		$this->assertSame( $before, get_option( Settings::OPTION ), 'Nothing was saved, not even the other keys.' );

		$saved = $this->save( array( 'privacy' => array( 'retention_days' => 60 ) ) );
		$this->assertNotWPError( $saved, 'Other settings still save.' );
		$this->assertSame( 60, Settings::get( 'privacy.retention_days' ) );
	}

	public function test_the_main_site_and_a_site_without_network_activation_still_save_two_step_keys() {
		wp_set_current_user( $this->admin->ID );
		$this->assertNotWPError( $this->save( array( 'two_step' => array( 'grace_days' => 9 ) ) ) );
		$this->assertSame( 9, Settings::get( 'two_step.grace_days' ) );

		$this->activate_network_wide();
		restore_current_blog();
		$this->assertNotWPError( $this->save( array( 'two_step' => array( 'grace_days' => 4 ) ) ) );
		$this->assertSame( 4, Settings::get( 'two_step.grace_days' ) );
		switch_to_blog( $this->site );
	}

	/**
	 * First-run setup on this site.
	 *
	 * @param array $features Feature switches.
	 * @return WP_REST_Response|WP_Error
	 */
	private function first_run( array $features ) {
		$request = new WP_REST_Request( 'POST', '/happyaccess/v1/setup' );
		$request->set_param( 'features', $features );
		$request->set_param( 'consent', true );
		return SettingsController::setup( $request );
	}

	public function test_setup_refuses_the_two_step_switch_on_a_subsite_that_follows_the_main_site() {
		wp_set_current_user( $this->admin->ID );
		$this->activate_network_wide();
		$before = get_option( Settings::OPTION );

		$result = $this->first_run(
			array(
				'support_access' => true,
				'two_step'       => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_two_step_network', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( "HappyAccess is on for the whole network, so two-step login follows the main site's settings. Change them on the main site.", $result->get_error_message() );
		$this->assertSame( $before, get_option( Settings::OPTION ), 'Nothing was saved, consent included.' );

		$this->assertNotWPError( $this->first_run( array( 'support_access' => true ) ), 'Setup without the two-step switch still works.' );
		$this->assertNotSame( '', Settings::get( 'support.consent_given_at' ) );
	}

	public function test_setup_takes_the_two_step_switch_on_the_main_site_and_without_network_activation() {
		wp_set_current_user( $this->admin->ID );
		$this->assertNotWPError( $this->first_run( array( 'two_step' => true ) ) );
		$this->assertTrue( Settings::get( 'features.two_step' ) );

		$this->activate_network_wide();
		restore_current_blog();
		$this->assertNotWPError( $this->first_run( array( 'two_step' => false ) ) );
		$this->assertFalse( Settings::get( 'features.two_step' ) );
		switch_to_blog( $this->site );
	}
}
