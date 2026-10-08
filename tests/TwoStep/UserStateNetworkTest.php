<?php
/**
 * Two-step state across the sites of a network. Run with composer test:multisite.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;

/**
 * @group ms-required
 */
class UserStateNetworkTest extends WP_UnitTestCase {

	/**
	 * The user under test.
	 *
	 * @var int
	 */
	private $user;

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
		$this->user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->site = self::factory()->blog->create();
		add_user_to_blog( $this->site, $this->user, 'editor' );

		switch_to_blog( $this->site );
		Installer::install();
		// Each site has its own key, so a per-site cipher would not open on the other site.
		Secrets::key();
		restore_current_blog();
	}

	/**
	 * Whether the app code of a secret works for the user on the current site.
	 *
	 * @param string $secret Base32 secret.
	 * @return bool
	 */
	private function code_works( $secret ) {
		$now = 1790000000;
		return false !== Totp::match( (string) UserState::totp_secret( $this->user ), Totp::code( $secret, Totp::step_for( $now ) ), $now, 0 );
	}

	/**
	 * A stored key that is not the one in use, only on the main site.
	 *
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	public function other_main_site_key( $value ) {
		if ( get_current_blog_id() !== get_main_site_id() ) {
			return $value;
		}
		return base64_encode( str_repeat( 'x', 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A stored key value.
	}

	public function test_the_two_sites_have_their_own_site_keys() {
		$main = Secrets::key();
		switch_to_blog( $this->site );
		$other = Secrets::key();
		restore_current_blog();
		$this->assertNotSame( $main, $other );
	}

	public function test_an_app_set_up_on_site_two_works_on_both_sites() {
		$secret = Totp::new_secret();

		switch_to_blog( $this->site );
		$this->assertTrue( UserState::enable_app( $this->user, $secret ) );
		$this->assertTrue( $this->code_works( $secret ) );
		restore_current_blog();

		$this->assertSame( $secret, UserState::totp_secret( $this->user ) );
		$this->assertTrue( $this->code_works( $secret ) );
	}

	public function test_setting_up_on_site_one_afterwards_keeps_site_two_working() {
		switch_to_blog( $this->site );
		UserState::enable_app( $this->user, Totp::new_secret() );
		restore_current_blog();

		$secret = Totp::new_secret();
		$this->assertTrue( UserState::enable_app( $this->user, $secret ) );
		$this->assertTrue( $this->code_works( $secret ) );

		switch_to_blog( $this->site );
		$this->assertSame( $secret, UserState::totp_secret( $this->user ) );
		$this->assertTrue( $this->code_works( $secret ) );
		restore_current_blog();
	}

	public function test_the_network_key_is_made_on_the_main_site_when_missing() {
		global $wpdb;
		delete_option( Secrets::OPTION );
		Secrets::reset_cache();

		switch_to_blog( $this->site );
		$payload = Secrets::encrypt_network( 'JBSWY3DPEHPK3PXP' );
		$this->assertTrue( Secrets::is_network_persisted() );
		restore_current_blog();

		$this->assertNotFalse( get_option( Secrets::OPTION ) );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Secrets::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );

		Secrets::reset_cache();
		$this->assertSame( 'JBSWY3DPEHPK3PXP', Secrets::decrypt_network( $payload ) );
	}

	public function test_enable_app_refuses_when_the_main_site_key_is_not_saved() {
		Secrets::key();
		switch_to_blog( $this->site );
		Secrets::key();
		$this->assertTrue( Secrets::is_persisted() );

		add_filter( 'pre_option_' . Secrets::OPTION, array( $this, 'other_main_site_key' ) );
		$result = UserState::enable_app( $this->user, Totp::new_secret() );
		remove_filter( 'pre_option_' . Secrets::OPTION, array( $this, 'other_main_site_key' ) );
		restore_current_blog();

		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_no_site_key', $result->get_error_code() );
		$this->assertFalse( metadata_exists( 'user', $this->user, '_happyaccess_totp' ) );
	}
}
