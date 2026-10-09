<?php
/**
 * The two-step section on Profile and Edit User, and the admin reset.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\BackupCodes;
use HappyAccess\Features\TwoStep\Challenge;
use HappyAccess\Features\TwoStep\Feature;
use HappyAccess\Features\TwoStep\Profile;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;
use HappyAccess\Login\Router;

class TwoStepProfileTest extends WP_UnitTestCase {

	/**
	 * Mails caught before they are sent.
	 *
	 * @var array
	 */
	private $mails = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
		Challenge::reset();
		Features::set( 'two_step', true );
		$this->set_policy( array( 'editor' => 'optional' ) );
		Feature::register();
		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			unset( $_GET['user_id'] );
			foreach ( array( Profile::HANDLE, 'happyaccess-twostep-setup', 'happyaccess-qrcode' ) as $handle ) {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
			Router::reset();
			Challenge::reset();
		} finally {
			parent::tear_down();
		}
	}

	public function catch_mail( $null, $atts ) {
		unset( $null );
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * Saves the role policy, replacing the old one.
	 *
	 * @param array $policy Role slug to choice.
	 * @return void
	 */
	private function set_policy( array $policy ) {
		Settings::update( array( 'two_step' => array( 'role_policy' => array_fill_keys( array_keys( wp_roles()->roles ), 'off' ) ) ) );
		Settings::update( array( 'two_step' => array( 'role_policy' => $policy ) ) );
	}

	private function user( $role = 'editor', array $extra = array() ) {
		return self::factory()->user->create_and_get( array_merge( array( 'role' => $role ), $extra ) );
	}

	/**
	 * An administrator who can edit users on single site and multisite.
	 *
	 * @return WP_User
	 */
	private function admin() {
		$admin = $this->user(
			'administrator',
			array( 'display_name' => 'Ada Admin' )
		);
		if ( is_multisite() ) {
			grant_super_admin( $admin->ID );
		}
		return $admin;
	}

	private function log_rows( $event ) {
		return AuditLog::query( array( 'event' => $event ) )['items'];
	}

	public function test_the_own_profile_section_offers_set_up_and_turn_on() {
		$user = $this->user();
		wp_set_current_user( $user->ID );

		$html = Profile::section( $user );
		$this->assertStringContainsString( 'id="happyaccess-twostep"', $html );
		$this->assertStringContainsString( 'Two-step login', $html );
		$this->assertStringContainsString( 'data-happyaccess-action="app-begin"', $html );
		$this->assertStringContainsString( 'data-happyaccess-action="email-begin"', $html );
		$this->assertStringContainsString( 'data-happyaccess-action="email-confirm"', $html, 'Email codes turn on with one emailed code.' );
		$this->assertStringContainsString( 'id="happyaccess-ts-profile-email-code"', $html );
		$this->assertStringNotContainsString( 'data-happyaccess-action="backup-regenerate"', $html, 'No backup codes before a method is on.' );
		$this->assertStringContainsString( 'Turn on the app or email codes first.', $html );
		$this->assertStringContainsString( 'class="happyaccess-ts-qr"', $html, 'The app setup reuses the QR box.' );
		$this->assertStringContainsString( 'id="happyaccess-ts-codes"', $html, 'The codes reuse the setup list.' );
		$this->assertStringNotContainsString( '<form', $html, 'The section sits inside the profile form.' );
		$this->assertStringNotContainsString( ' required', $html, 'Nothing hidden can block the profile form.' );
	}

	public function test_the_profile_panels_keep_the_wp_admin_layout() {
		$user = $this->user();
		wp_set_current_user( $user->ID );

		$html = Profile::section( $user );
		$this->assertStringContainsString( '<div class="happyaccess-ts-panel" data-happyaccess-panel="app" hidden>', $html );
		$this->assertStringContainsString( '<p><label for="happyaccess-ts-profile-code">Code from the app</label><br /><input type="text" id="happyaccess-ts-profile-code" class="regular-text happyaccess-ts-code"', $html );
		$this->assertStringContainsString( '<p><button type="button" class="button button-primary" data-happyaccess-action="app-confirm" data-happyaccess-primary>Turn on two-step login</button> <button type="button" class="button-link" data-happyaccess-action="cancel">Cancel</button></p>', $html );
		$this->assertStringNotContainsString( 'happyaccess-ts-actions', $html );
		$this->assertStringNotContainsString( 'form-row', $html );
	}

	public function test_an_unreadable_app_secret_shows_a_notice_and_offers_set_up_again() {
		$user = $this->user();
		update_user_meta(
			$user->ID,
			UserState::META_TOTP,
			array(
				'secret'    => 's1:' . base64_encode( random_bytes( 60 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Same shape as a sealed secret.
				'last_step' => 0,
			)
		);
		wp_set_current_user( $user->ID );

		$html = Profile::section( $user );
		$this->assertStringContainsString( 'Set up your authenticator app again', $html );
		$this->assertStringContainsString( 'data-happyaccess-action="app-begin"', $html );
		$this->assertSame( '', Profile::section( $this->user() ), 'Others see nothing.' );
	}

	public function test_with_methods_on_it_offers_turn_off_and_new_backup_codes() {
		$user = $this->user();
		wp_set_current_user( $user->ID );
		UserState::enable_app( $user->ID, Totp::new_secret() );
		UserState::enable_email( $user->ID );
		BackupCodes::generate( $user->ID );

		$html = Profile::section( $user );
		$this->assertStringContainsString( 'data-happyaccess-action="app-disable"', $html );
		$this->assertStringContainsString( 'data-happyaccess-action="email-disable"', $html );
		$this->assertStringContainsString( 'data-happyaccess-action="backup-regenerate"', $html );
		$this->assertStringContainsString( '<span class="happyaccess-ts-state">10 codes left</span>', $html );
		$this->assertSame( 3, substr_count( $html, 'class="happyaccess-ts-row"' ), 'Every method row has a status line.' );
		$this->assertStringNotContainsString( 'data-happyaccess-action="app-begin"', $html );
	}

	public function test_a_required_user_sees_no_turn_off_for_the_last_method() {
		$this->set_policy( array( 'editor' => 'required' ) );
		$user = $this->user();
		wp_set_current_user( $user->ID );
		UserState::enable_app( $user->ID, Totp::new_secret() );

		$html = Profile::section( $user );
		$this->assertStringNotContainsString( 'data-happyaccess-action="app-disable"', $html );
		$this->assertStringContainsString( 'Your role needs two-step login.', $html );
	}

	public function test_a_role_with_two_step_off_sees_no_section_until_a_method_is_on() {
		$this->set_policy( array( 'editor' => 'off' ) );
		$user = $this->user();
		wp_set_current_user( $user->ID );
		$this->assertSame( '', Profile::section( $user ) );

		UserState::enable_email( $user->ID );
		$this->assertStringContainsString( 'data-happyaccess-action="email-disable"', Profile::section( $user ) );
	}

	public function test_a_support_temp_user_sees_no_section() {
		$user = $this->user( 'administrator' );
		update_user_meta( $user->ID, 'happyaccess_temp_user', 1 );
		wp_set_current_user( $user->ID );
		$this->assertSame( '', Profile::section( $user ) );
		$this->assertSame( '', Profile::section( $this->user() ), 'Nor on anyone else.' );
	}

	public function test_an_admin_sees_only_the_status_and_the_reset_on_another_profile() {
		$admin = $this->admin();
		$user  = $this->user();
		UserState::enable_app( $user->ID, Totp::new_secret() );
		wp_set_current_user( $admin->ID );

		$html = Profile::section( $user );
		$this->assertStringContainsString( 'id="happyaccess-twostep"', $html );
		$this->assertStringContainsString( 'Turn off two-step login for this user', $html );
		$this->assertStringContainsString( 'form="happyaccess-ts-reset"', $html );
		$this->assertStringNotContainsString( 'data-happyaccess-action', $html, 'No setup controls for someone else.' );

		UserState::reset( $user->ID );
		$this->assertStringNotContainsString( 'Turn off two-step login for this user', Profile::section( $user ), 'Nothing to turn off.' );
	}

	public function test_an_admin_turns_off_another_users_two_step() {
		$admin = $this->admin();
		$user  = $this->user( 'editor', array( 'user_email' => 'sam@example.org' ) );
		UserState::enable_app( $user->ID, Totp::new_secret() );
		BackupCodes::generate( $user->ID );
		wp_set_current_user( $admin->ID );

		$result = Profile::reset_user( $user->ID, wp_create_nonce( Profile::NONCE . '_' . $user->ID ) );
		$this->assertTrue( $result );
		$this->assertFalse( UserState::is_enabled( $user->ID ) );
		$this->assertSame( 0, BackupCodes::remaining( $user->ID ) );

		$rows = $this->log_rows( 'twostep_reset' );
		$this->assertCount( 1, $rows );
		$this->assertSame( $user->ID, (int) $rows[0]['user_id'] );
		$this->assertSame( $admin->ID, (int) $rows[0]['meta']['user_id'] );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'sam@example.org', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Two-step login was turned off for your account by Ada Admin.', $this->mails[0]['message'] );
	}

	public function test_the_reset_email_links_to_where_the_user_sets_it_up_again() {
		$admin = $this->admin();
		$user  = $this->user( 'editor', array( 'user_email' => 'sam@example.org' ) );
		UserState::enable_app( $user->ID, Totp::new_secret() );
		wp_set_current_user( $admin->ID );

		Profile::reset_user( $user->ID, wp_create_nonce( Profile::NONCE . '_' . $user->ID ) );

		$message = $this->mails[0]['message'];
		$this->assertStringNotContainsString( 'from your profile.', $message );
		$this->assertStringContainsString( 'You can set it up again from ' . Profile::link_for( $user ) . '.', $message );

		$site_name  = 'Test site';
		$admin_name = 'Ada Admin';
		$setup_url  = Profile::url_for( $user );
		ob_start();
		include HAPPYACCESS_PLUGIN_DIR . 'templates/emails/twostep-reset-text.php';
		$text = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'your profile', $text );
		$this->assertStringContainsString( 'You can set it up again from your account: ' . $setup_url, $text );
	}

	public function test_the_admin_reset_help_fits_customers_too() {
		$admin = $this->admin();
		$user  = $this->user();
		UserState::enable_app( $user->ID, Totp::new_secret() );
		wp_set_current_user( $admin->ID );

		$html = Profile::section( $user );
		$this->assertStringNotContainsString( 'from their profile', $html );
		$this->assertStringContainsString( 'They get an email, and they can set it up again from their account.', $html );
	}

	public function test_a_non_admin_cannot_turn_off_another_users_two_step() {
		$editor = $this->user();
		$user   = $this->user();
		UserState::enable_app( $user->ID, Totp::new_secret() );
		wp_set_current_user( $editor->ID );

		$this->assertSame( '', Profile::section( $user ) );
		$this->assertWPError( Profile::reset_user( $user->ID, wp_create_nonce( Profile::NONCE . '_' . $user->ID ) ) );
		$this->assertTrue( UserState::app_enabled( $user->ID ) );
		$this->assertCount( 0, $this->mails );
	}

	public function test_the_admin_reset_needs_the_nonce_and_another_user() {
		$admin = $this->admin();
		$user  = $this->user();
		UserState::enable_app( $user->ID, Totp::new_secret() );
		UserState::enable_app( $admin->ID, Totp::new_secret() );
		wp_set_current_user( $admin->ID );

		$this->assertWPError( Profile::reset_user( $user->ID, 'bad' ) );
		$this->assertWPError( Profile::reset_user( $user->ID, wp_create_nonce( Profile::NONCE . '_' . $admin->ID ) ), 'A nonce for another user does not count.' );
		$this->assertTrue( UserState::app_enabled( $user->ID ) );

		$this->assertWPError( Profile::reset_user( $admin->ID, wp_create_nonce( Profile::NONCE . '_' . $admin->ID ) ), 'Not on your own profile.' );
		$this->assertTrue( UserState::app_enabled( $admin->ID ) );
	}

	public function test_a_temp_user_with_edit_users_cannot_reset_anyone() {
		$temp = $this->admin();
		update_user_meta( $temp->ID, 'happyaccess_temp_user', 1 );
		$user = $this->user();
		UserState::enable_app( $user->ID, Totp::new_secret() );
		wp_set_current_user( $temp->ID );

		$this->assertWPError( Profile::reset_user( $user->ID, wp_create_nonce( Profile::NONCE . '_' . $user->ID ) ) );
		$this->assertTrue( UserState::app_enabled( $user->ID ) );
	}

	public function test_the_done_screen_links_to_the_profile_section() {
		$this->assertStringContainsString( 'profile.php#happyaccess-twostep', Profile::url() );
	}

	public function test_without_the_constant_there_is_no_pause_notice() {
		wp_set_current_user( $this->admin()->ID );
		$this->assertSame( '', Profile::pause_notice() );
	}

	/**
	 * The constant is defined here, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_shows_the_notice_and_turns_off_the_step() {
		define( 'HAPPYACCESS_DISABLE_TWOSTEP', true );
		$admin = $this->admin();
		UserState::enable_app( $admin->ID, Totp::new_secret() );

		wp_set_current_user( $admin->ID );
		$this->assertStringContainsString( 'Two-step login is paused by HAPPYACCESS_DISABLE_TWOSTEP in wp-config.php. Remove it when you&#039;re back in.', Profile::pause_notice() );
		ob_start();
		do_action( 'admin_notices' );
		$this->assertStringContainsString( 'HAPPYACCESS_DISABLE_TWOSTEP', (string) ob_get_clean(), 'It prints on admin_notices.' );
		ob_start();
		do_action( 'network_admin_notices' );
		$this->assertStringContainsString( 'HAPPYACCESS_DISABLE_TWOSTEP', (string) ob_get_clean(), 'And on network_admin_notices.' );
		$this->assertFalse( Challenge::applies( $admin ) );

		wp_set_current_user( $this->user()->ID );
		$this->assertSame( '', Profile::pause_notice(), 'Only for users who can manage options.' );
	}

	public function test_your_own_profile_on_user_edit_loads_the_script_like_profile_php() {
		$user = $this->user();
		wp_set_current_user( $user->ID );
		$_GET['user_id'] = (string) $user->ID;

		Profile::enqueue( 'user-edit.php' );

		$this->assertTrue( wp_script_is( Profile::HANDLE, 'enqueued' ), 'The profile script loads.' );
		$this->assertTrue( wp_style_is( Profile::HANDLE, 'enqueued' ) );
	}

	public function test_someone_elses_profile_loads_only_the_stylesheet() {
		$admin = $this->admin();
		$user  = $this->user();
		UserState::enable_app( $user->ID, Totp::new_secret() );
		wp_set_current_user( $admin->ID );
		$_GET['user_id'] = (string) $user->ID;

		Profile::enqueue( 'user-edit.php' );

		$this->assertTrue( wp_style_is( Profile::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Profile::HANDLE, 'enqueued' ) );
	}

	public function test_a_reset_with_only_a_recheck_left_logs_but_emails_nothing() {
		$admin = $this->admin();
		$user  = $this->user( 'editor', array( 'user_email' => 'sam@example.org' ) );
		update_user_meta( $user->ID, UserState::META_RECHECK, array( 'session' => time() ) );
		wp_set_current_user( $admin->ID );

		$this->assertTrue( Profile::reset_user( $user->ID, wp_create_nonce( Profile::NONCE . '_' . $user->ID ) ) );

		$this->assertCount( 1, $this->log_rows( 'twostep_reset' ) );
		$this->assertCount( 0, $this->mails, 'Nothing they use was turned off.' );
		$this->assertFalse( metadata_exists( 'user', $user->ID, UserState::META_RECHECK ) );
	}

	public function test_a_reset_with_only_grace_counters_logs_but_emails_nothing() {
		$admin = $this->admin();
		$user  = $this->user( 'editor', array( 'user_email' => 'sam@example.org' ) );
		UserState::start_grace( $user->ID );
		UserState::count_grace_login( $user->ID );
		wp_set_current_user( $admin->ID );

		$this->assertTrue( Profile::reset_user( $user->ID, wp_create_nonce( Profile::NONCE . '_' . $user->ID ) ) );

		$this->assertCount( 1, $this->log_rows( 'twostep_reset' ) );
		$this->assertCount( 0, $this->mails );
		$this->assertFalse( metadata_exists( 'user', $user->ID, UserState::META_STATE ) );
	}

	public function test_a_reset_with_only_an_empty_lock_row_logs_but_emails_nothing() {
		$admin = $this->admin();
		$user  = $this->user( 'editor', array( 'user_email' => 'sam@example.org' ) );
		UserState::locked( $user->ID, '__return_true' );
		$this->assertTrue( metadata_exists( 'user', $user->ID, UserState::META_STATE ), 'The lock leaves its row.' );
		wp_set_current_user( $admin->ID );

		$this->assertTrue( Profile::reset_user( $user->ID, wp_create_nonce( Profile::NONCE . '_' . $user->ID ) ) );

		$this->assertCount( 1, $this->log_rows( 'twostep_reset' ) );
		$this->assertCount( 0, $this->mails );
	}

	public function test_a_reset_of_email_codes_or_backup_codes_alone_emails_the_user() {
		$admin  = $this->admin();
		$emails = $this->user( 'editor', array( 'user_email' => 'em@example.org' ) );
		$codes  = $this->user( 'editor', array( 'user_email' => 'bc@example.org' ) );
		UserState::enable_email( $emails->ID );
		BackupCodes::generate( $codes->ID );
		wp_set_current_user( $admin->ID );

		$this->assertTrue( Profile::reset_user( $emails->ID, wp_create_nonce( Profile::NONCE . '_' . $emails->ID ) ) );
		$this->assertTrue( Profile::reset_user( $codes->ID, wp_create_nonce( Profile::NONCE . '_' . $codes->ID ) ) );

		$this->assertSame( array( 'em@example.org', 'bc@example.org' ), wp_list_pluck( $this->mails, 'to' ) );
	}

	public function test_a_reset_with_nothing_stored_logs_and_emails_nothing() {
		$admin = $this->admin();
		$user  = $this->user();
		wp_set_current_user( $admin->ID );

		$this->assertTrue( Profile::reset_user( $user->ID, wp_create_nonce( Profile::NONCE . '_' . $user->ID ) ) );

		$this->assertCount( 0, $this->log_rows( 'twostep_reset' ) );
		$this->assertCount( 0, $this->mails );
	}
}
