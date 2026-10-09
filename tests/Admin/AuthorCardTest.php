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
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\Grants;

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
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		Grants::flush_cache();
	}

	public function tear_down() {
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

	public function test_a_new_install_starts_early() {
		$this->installed_days_ago( 1 );

		$this->assertSame( 'early', AuthorCard::state( $this->admin ) );
	}

	public function test_six_days_after_install_with_no_expiry_is_still_early() {
		$this->installed_days_ago( 6.9 );

		$this->assertSame( 'early', AuthorCard::state( $this->admin ) );
	}

	public function test_seven_days_after_install_it_asks() {
		$this->installed_days_ago( 7 );

		$this->assertSame( 'ask', AuthorCard::state( $this->admin ) );
	}

	public function test_a_pass_that_expired_makes_it_ask_before_seven_days() {
		$this->installed_days_ago( 1 );
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

		$this->assertSame( 'early', AuthorCard::state( $this->admin ) );
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

		$this->assertSame( 'early', AuthorCard::state( $this->admin ) );
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

	public function test_a_missing_install_time_is_noted_on_first_read_and_starts_early() {
		$this->assertFalse( get_option( Installer::INSTALLED_OPTION ) );

		$this->assertSame( 'early', AuthorCard::state( $this->admin ) );
		$this->assertSame( Clock::mysql(), get_option( Installer::INSTALLED_OPTION ) );
	}

	public function test_dismissed_persists_for_that_user_only() {
		$this->installed_days_ago( 8 );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( AuthorCard::save( $this->admin, 'dismissed' ) );

		$this->assertSame( 'dismissed', get_user_meta( $this->admin, AuthorCard::META, true ) );
		$this->assertSame( 'credit', AuthorCard::state( $this->admin ) );
		$this->assertSame( 'ask', AuthorCard::state( $other ) );
	}

	public function test_rated_persists_for_that_user_only() {
		$this->installed_days_ago( 8 );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( AuthorCard::save( $this->admin, 'rated' ) );

		$this->assertSame( 'rated', get_user_meta( $this->admin, AuthorCard::META, true ) );
		$this->assertSame( 'credit', AuthorCard::state( $this->admin ) );
		$this->assertSame( 'ask', AuthorCard::state( $other ) );
	}

	public function test_a_choice_made_early_also_never_asks_later() {
		$this->installed_days_ago( 1 );
		AuthorCard::save( $this->admin, 'dismissed' );

		$this->installed_days_ago( 30 );

		$this->assertSame( 'credit', AuthorCard::state( $this->admin ) );
	}

	public function test_an_unknown_choice_is_refused() {
		$this->assertFalse( AuthorCard::save( $this->admin, 'later' ) );
		$this->assertSame( '', get_user_meta( $this->admin, AuthorCard::META, true ) );
	}

	public function test_boot_data_has_the_state_and_the_bundled_photo() {
		$this->installed_days_ago( 8 );

		$data = Page::boot_data()['authorCard'];

		$this->assertSame( array( 'state', 'photo' ), array_keys( $data ) );
		$this->assertSame( 'ask', $data['state'] );
		$this->assertSame( HAPPYACCESS_PLUGIN_URL . 'assets/author.jpg', $data['photo'] );
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
