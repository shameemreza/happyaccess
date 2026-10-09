<?php
/**
 * Author card state tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Admin\AuthorCard;
use HappyAccess\Admin\Page;
use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;
use HappyAccess\Features\TwoStep\Coverage;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;

class AuthorCardTest extends WP_UnitTestCase {

	const NOW = 1790000000;

	/**
	 * Administrator who views the page.
	 *
	 * @var int
	 */
	private $admin;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Clock::freeze( self::NOW );
		Capabilities::register();
		AuthorCard::register();
		delete_option( Installer::INSTALLED_OPTION );
		delete_option( AuthorCard::EXPIRY_OPTION );
		delete_option( Settings::OPTION );
		delete_transient( Coverage::TRANSIENT );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Grants::flush_cache();
	}

	public function tear_down() {
		delete_transient( Coverage::TRANSIENT );
		Clock::freeze( null );
		parent::tear_down();
	}

	private function installed_days_ago( $days ) {
		update_option( Installer::INSTALLED_OPTION, Clock::mysql( self::NOW - (int) ( $days * DAY_IN_SECONDS ) ), false );
	}

	/**
	 * Makes a pass and ends it.
	 *
	 * @param string $reason Why it ended.
	 * @return int Grant id.
	 */
	private function ended_pass( $reason ) {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		Grants::revoke( $made['id'], $reason );
		return (int) $made['id'];
	}

	public function test_a_new_install_says_hello_for_its_first_day() {
		$this->installed_days_ago( 0.5 );

		$this->assertSame( 'intro', AuthorCard::state( $this->admin ) );
	}

	public function test_after_the_first_day_it_shows_tips() {
		$this->installed_days_ago( 1 );

		$this->assertSame( 'tips', AuthorCard::state( $this->admin ) );
	}

	public function test_six_days_after_install_with_no_expiry_still_shows_tips() {
		$this->installed_days_ago( 6.9 );

		$this->assertSame( 'tips', AuthorCard::state( $this->admin ) );
	}

	public function test_seven_days_after_install_it_asks() {
		$this->installed_days_ago( 7 );

		$this->assertSame( 'ask', AuthorCard::state( $this->admin ) );
	}

	public function test_a_pass_that_expired_makes_it_ask_before_seven_days() {
		$this->installed_days_ago( 0.5 );
		AuthorCard::state( $this->admin );

		$this->ended_pass( 'expired' );

		$this->assertSame( 'ask', AuthorCard::state( $this->admin ) );
		$this->assertSame( Clock::mysql(), get_option( AuthorCard::EXPIRY_OPTION ) );
	}

	public function test_a_revoked_pass_does_not_count_as_ending_by_itself() {
		$this->installed_days_ago( 1 );
		AuthorCard::state( $this->admin );

		$this->ended_pass( 'revoked' );
		$this->ended_pass( 'emergency_lock' );

		$this->assertSame( 'tips', AuthorCard::state( $this->admin ) );
	}

	public function test_a_later_expiry_keeps_the_first_time() {
		$this->installed_days_ago( 1 );
		$this->ended_pass( 'expired' );
		$first = get_option( AuthorCard::EXPIRY_OPTION );

		Clock::freeze( self::NOW + HOUR_IN_SECONDS );
		$this->ended_pass( 'expired' );

		$this->assertSame( $first, get_option( AuthorCard::EXPIRY_OPTION ) );
	}

	public function test_an_expiry_from_before_the_card_existed_is_found_once_in_the_grants() {
		global $wpdb;
		$this->installed_days_ago( 1 );
		// An expiry written before the hook existed, with no log row.
		$made = Grants::create( array( 'label' => 'Old' ) );
		$wpdb->update(
			Installer::table( 'tokens' ),
			array(
				'revoked_at' => Clock::mysql( self::NOW - HOUR_IN_SECONDS ),
				'metadata'   => wp_json_encode( array( 'end_reason' => 'expired' ) ),
			),
			array( 'id' => $made['id'] )
		);
		$this->assertFalse( get_option( AuthorCard::EXPIRY_OPTION ) );

		$this->assertSame( 'ask', AuthorCard::state( $this->admin ) );
		$this->assertSame( Clock::mysql( self::NOW - HOUR_IN_SECONDS ), get_option( AuthorCard::EXPIRY_OPTION ) );
	}

	public function test_an_expiry_kept_only_in_the_log_is_found() {
		$this->installed_days_ago( 1 );
		AuditLog::add(
			'grant_ended',
			array(
				'token_id' => 99,
				'meta'     => array( 'reason' => 'expired' ),
			)
		);

		$this->assertSame( 'ask', AuthorCard::state( $this->admin ) );
	}

	public function test_the_lookup_runs_once_and_then_reads_the_option() {
		$this->installed_days_ago( 1 );

		$this->assertSame( 'tips', AuthorCard::state( $this->admin ) );
		$this->assertSame( '', get_option( AuthorCard::EXPIRY_OPTION ), 'A lookup that found nothing is remembered.' );

		$queries = array();
		$watch   = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $watch );
		AuthorCard::state( $this->admin );
		remove_filter( 'query', $watch );

		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( 'happyaccess_logs', $query );
			$this->assertStringNotContainsString( 'happyaccess_tokens', $query );
		}
	}

	public function test_a_missing_install_time_is_noted_on_first_read_and_starts_with_the_intro() {
		$this->assertFalse( get_option( Installer::INSTALLED_OPTION ) );

		$this->assertSame( 'intro', AuthorCard::state( $this->admin ) );
		$this->assertSame( Clock::mysql(), get_option( Installer::INSTALLED_OPTION ) );
	}

	public function test_not_now_shows_tips_for_that_user_only() {
		$this->installed_days_ago( 8 );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( AuthorCard::save( $this->admin, 'later' ) );

		$this->assertSame( 'later', get_user_meta( $this->admin, AuthorCard::META, true ) );
		$this->assertSame( 'tips', AuthorCard::state( $this->admin ) );
		$this->assertSame( 'ask', AuthorCard::state( $other ) );
	}

	public function test_rated_shows_tips_for_that_user_only() {
		$this->installed_days_ago( 8 );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( AuthorCard::save( $this->admin, 'rated' ) );

		$this->assertSame( 'rated', get_user_meta( $this->admin, AuthorCard::META, true ) );
		$this->assertSame( 'tips', AuthorCard::state( $this->admin ) );
		$this->assertSame( 'ask', AuthorCard::state( $other ) );
	}

	public function test_hidden_shows_nothing_in_any_phase() {
		$this->installed_days_ago( 0.5 );
		$this->assertTrue( AuthorCard::save( $this->admin, 'hidden' ) );

		$this->assertSame( 'none', AuthorCard::state( $this->admin ) );
		$this->installed_days_ago( 30 );
		$this->assertSame( 'none', AuthorCard::state( $this->admin ) );
	}

	public function test_dismissed_from_a_beta_reads_as_later() {
		$this->installed_days_ago( 8 );
		update_user_meta( $this->admin, AuthorCard::META, 'dismissed' );

		$this->assertSame( 'later', AuthorCard::choice( $this->admin ) );
		$this->assertSame( 'tips', AuthorCard::state( $this->admin ) );
	}

	public function test_dismissed_is_saved_as_later() {
		$this->assertTrue( AuthorCard::save( $this->admin, 'dismissed' ) );

		$this->assertSame( 'later', get_user_meta( $this->admin, AuthorCard::META, true ) );
	}

	public function test_a_choice_made_during_the_intro_also_never_asks_later() {
		$this->installed_days_ago( 0.5 );
		AuthorCard::save( $this->admin, 'later' );

		$this->installed_days_ago( 30 );

		$this->assertSame( 'tips', AuthorCard::state( $this->admin ) );
	}

	public function test_an_unknown_choice_is_refused() {
		$this->assertFalse( AuthorCard::save( $this->admin, 'credit' ) );
		$this->assertFalse( AuthorCard::save( $this->admin, '' ) );
		$this->assertSame( '', get_user_meta( $this->admin, AuthorCard::META, true ) );
	}

	public function test_an_unknown_stored_value_counts_as_no_choice() {
		$this->installed_days_ago( 8 );
		update_user_meta( $this->admin, AuthorCard::META, 'credit' );

		$this->assertSame( '', AuthorCard::choice( $this->admin ) );
		$this->assertSame( 'ask', AuthorCard::state( $this->admin ) );
	}

	public function test_boot_data_has_the_state_the_photo_the_user_and_the_facts() {
		$this->installed_days_ago( 8 );

		$data = Page::boot_data()['authorCard'];

		$this->assertSame( array( 'state', 'photo', 'user', 'facts' ), array_keys( $data ) );
		$this->assertSame( 'ask', $data['state'] );
		$this->assertSame( HAPPYACCESS_PLUGIN_URL . 'assets/author.jpg', $data['photo'] );
		$this->assertSame( $this->admin, $data['user'] );
		$this->assertSame( array( 'admins', 'lastPassLogin', 'twoStepAdmins', 'deviceAlerts', 'woocommerce', 'multisite' ), array_keys( $data['facts'] ) );
		$this->assertSame( class_exists( 'WooCommerce' ), $data['facts']['woocommerce'] );
		$this->assertSame( is_multisite(), $data['facts']['multisite'] );
	}

	public function test_hidden_tips_read_no_facts() {
		AuthorCard::save( $this->admin, 'hidden' );

		$data = Page::boot_data()['authorCard'];

		$this->assertSame( 'none', $data['state'] );
		$this->assertSame( array(), $data['facts'] );
	}

	public function test_the_admin_count_leaves_temp_users_out() {
		Features::set( 'support_access', true );
		$before = AuthorCard::boot_data()['facts']['admins'];
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		$made = Grants::create( array( 'label' => 'Agent' ) );
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		$this->assertContains( 'administrator', get_userdata( $temp )->roles );

		$this->assertSame( $before + 1, AuthorCard::boot_data()['facts']['admins'] );
	}

	public function test_the_last_pass_login_is_the_latest_of_any_pass() {
		global $wpdb;
		Features::set( 'support_access', true );
		$this->assertSame( 0, AuthorCard::boot_data()['facts']['lastPassLogin'] );

		$old   = Grants::create( array( 'label' => 'Old' ) );
		$fresh = Grants::create( array( 'label' => 'Fresh' ) );
		$wpdb->update( Installer::table( 'tokens' ), array( 'last_login_at' => Clock::mysql( self::NOW - DAY_IN_SECONDS ) ), array( 'id' => $old['id'] ) );
		$wpdb->update( Installer::table( 'tokens' ), array( 'last_login_at' => Clock::mysql( self::NOW - HOUR_IN_SECONDS ) ), array( 'id' => $fresh['id'] ) );
		Grants::revoke( $fresh['id'], 'revoked' );

		$this->assertSame( self::NOW - HOUR_IN_SECONDS, AuthorCard::boot_data()['facts']['lastPassLogin'] );
	}

	public function test_the_last_pass_login_is_left_out_without_temporary_access() {
		global $wpdb;
		Features::set( 'support_access', false );
		$made = Grants::create( array( 'label' => 'Old' ) );
		$wpdb->update( Installer::table( 'tokens' ), array( 'last_login_at' => Clock::mysql( self::NOW - HOUR_IN_SECONDS ) ), array( 'id' => $made['id'] ) );

		$this->assertSame( 0, AuthorCard::boot_data()['facts']['lastPassLogin'] );
	}

	public function test_two_step_facts_are_left_out_while_two_step_is_off() {
		Features::set( 'two_step', false );
		Settings::update( array( 'two_step' => array( 'device_alert_roles' => array( 'administrator' ) ) ) );

		$facts = AuthorCard::boot_data()['facts'];

		$this->assertNull( $facts['twoStepAdmins'] );
		$this->assertFalse( $facts['deviceAlerts'] );
	}

	public function test_two_step_facts_count_administrators_and_read_the_alert_roles() {
		Features::set( 'two_step', true );
		$app = self::factory()->user->create( array( 'role' => 'administrator' ) );
		UserState::enable_app( $app, Totp::new_secret() );
		$total = count(
			get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
				)
			)
		);

		Settings::update( array( 'two_step' => array( 'device_alert_roles' => array( 'administrator' ) ) ) );
		$facts = AuthorCard::boot_data()['facts'];
		$this->assertSame(
			array(
				'enabled' => 1,
				'total'   => $total,
			),
			$facts['twoStepAdmins']
		);
		$this->assertTrue( $facts['deviceAlerts'] );

		Settings::update( array( 'two_step' => array( 'device_alert_roles' => array( 'editor' ) ) ) );
		$this->assertFalse( AuthorCard::boot_data()['facts']['deviceAlerts'] );
	}

	public function test_the_two_step_count_reads_the_kept_coverage_when_there_is_one() {
		Features::set( 'two_step', true );
		set_transient(
			Coverage::TRANSIENT,
			array(
				'roles' => array(
					array(
						'slug'    => 'administrator',
						'enabled' => 3,
						'total'   => 5,
					),
				),
				'large' => false,
			),
			60
		);

		$this->assertSame(
			array(
				'enabled' => 3,
				'total'   => 5,
			),
			AuthorCard::boot_data()['facts']['twoStepAdmins']
		);
	}

	public function test_role_counts_without_counting_reads_only_the_kept_coverage() {
		$this->assertNull( Coverage::role_counts( 'administrator', false ) );
		$this->assertGreaterThan( 0, Coverage::role_counts( 'administrator' )['total'] );
		$this->assertNull( Coverage::role_counts( 'no_such_role' ) );
	}

	public function test_the_bundled_photo_is_a_small_square_jpeg() {
		$file = HAPPYACCESS_PLUGIN_DIR . 'assets/author.jpg';
		$this->assertFileExists( $file );
		$this->assertLessThan( 10240, filesize( $file ) );
		$size = getimagesize( $file );
		$this->assertSame( array( 80, 80, 'image/jpeg' ), array( $size[0], $size[1], $size['mime'] ) );
	}

	public function test_the_uninstaller_names_the_same_meta_key() {
		$this->assertSame( AuthorCard::META, \HappyAccess\Core\Uninstaller::AUTHOR_CARD_META );
	}

	public function test_the_route_takes_every_choice_and_the_old_name() {
		$this->assertSame( array( 'rated', 'later', 'hidden', 'dismissed' ), AuthorCard::accepted_choices() );
	}

	public function test_nothing_prints_on_the_front_end() {
		$this->installed_days_ago( 8 );
		ob_start();
		do_action( 'wp_head' );
		do_action( 'wp_footer' );
		$out = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'author.jpg', $out );
		$this->assertStringNotContainsString( 'shameem.dev', $out );
	}
}
