<?php
/**
 * Passwordless request engine tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\Passwordless\Requests;

class RequestsTest extends WP_UnitTestCase {

	const KEY = 'browser-request-key-one';

	/**
	 * A user the requests are for.
	 *
	 * @var WP_User
	 */
	private $user;

	public function set_up() {
		parent::set_up();
		Installer::install();
		delete_option( Settings::OPTION );
		Clock::freeze( 1790000000 );
		$this->user = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
	}

	public function tear_down() {
		Clock::freeze( null );
		parent::tear_down();
	}

	/**
	 * All challenge rows.
	 *
	 * @return array
	 */
	private function rows() {
		global $wpdb;
		$table = Installer::table( 'challenges' );
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * One challenge row by id.
	 *
	 * @param int $id Row id.
	 * @return array
	 */
	private function row( $id ) {
		foreach ( $this->rows() as $row ) {
			if ( (int) $row['id'] === (int) $id ) {
				return $row;
			}
		}
		return array();
	}

	/**
	 * Creates a challenge and returns the made values.
	 *
	 * @param WP_User|null $user User, default the test user.
	 * @param string       $key  Request key.
	 * @return array
	 */
	private function make( $user = null, $key = self::KEY ) {
		$made = Requests::create( null === $user ? $this->user : $user, $key );
		$this->assertIsArray( $made );
		return $made;
	}

	public function test_create_returns_a_six_digit_code_a_link_key_and_the_expiry() {
		$made = $this->make();

		$this->assertMatchesRegularExpression( '/^\d{6}$/', $made['code'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $made['link_key'] );
		$this->assertSame( Clock::now() + 600, $made['expires_at'] );
	}

	public function test_expiry_follows_the_code_lifetime_setting() {
		Settings::update( array( 'passwordless' => array( 'code_lifetime' => 1200 ) ) );
		$made = $this->make();
		$this->assertSame( Clock::now() + 1200, $made['expires_at'] );
	}

	public function test_create_stores_only_hashes_and_the_request_context() {
		$made = $this->make();
		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$row = $rows[0];
		$this->assertSame( 'passwordless', $row['purpose'] );
		$this->assertSame( (int) $this->user->ID, (int) $row['user_id'] );
		$this->assertSame( Codes::hash_code( $made['code'] ), $row['code_hash'] );
		$this->assertSame( Codes::hash_key( $made['link_key'] ), $row['link_hash'] );
		$this->assertSame( Codes::hash_key( self::KEY ), $row['request_key_hash'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $made['expires_at'] ), $row['expires_at'] );
		$this->assertSame( Clock::mysql(), $row['created_at'] );
		$this->assertNull( $row['used_at'] );
		$this->assertSame( 0, (int) $row['attempts'] );
		$this->assertNotSame( '', $row['ip'] );
	}

	public function test_no_plain_code_or_key_is_ever_in_the_table() {
		$made = $this->make();
		Requests::verify_code( self::KEY, '000000' );
		Requests::find_by_link( $made['link_key'] );

		$dump = wp_json_encode( $this->rows() );
		$this->assertStringNotContainsString( $made['code'], $dump );
		$this->assertStringNotContainsString( $made['link_key'], $dump );
		$this->assertStringNotContainsString( self::KEY, $dump );
	}

	public function test_create_cancels_the_earlier_unused_challenge_of_the_same_user_only() {
		$other = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$first = $this->make( $this->user, 'key-first' );
		$mine  = $this->make( $other, 'key-other' );
		$again = $this->make( $this->user, 'key-second' );

		$rows = $this->rows();
		$this->assertNotNull( $rows[0]['used_at'] );
		$this->assertNull( $rows[1]['used_at'] );
		$this->assertNull( $rows[2]['used_at'] );

		$this->assertWPError( Requests::verify_code( 'key-first', $first['code'] ) );
		$this->assertNull( Requests::find_by_link( $first['link_key'] ) );
		$this->assertWPError( Requests::consume_link( $first['link_key'] ) );
		$this->assertInstanceOf( WP_User::class, Requests::verify_code( 'key-other', $mine['code'] ) );
		$this->assertInstanceOf( WP_User::class, Requests::verify_code( 'key-second', $again['code'] ) );
	}

	public function test_create_leaves_other_purposes_alone() {
		global $wpdb;
		$table = Installer::table( 'challenges' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'user_id'    => $this->user->ID,
				'purpose'    => 'two_step',
				'created_at' => Clock::mysql(),
				'expires_at' => Clock::mysql( Clock::now() + 600 ),
			)
		);
		$this->make();

		$rows = $this->rows();
		$this->assertSame( 'two_step', $rows[0]['purpose'] );
		$this->assertNull( $rows[0]['used_at'] );
	}

	public function test_create_refuses_an_empty_request_key_and_a_missing_user() {
		$this->assertWPError( Requests::create( $this->user, '' ) );
		$this->assertWPError( Requests::create( new WP_User( 0 ), self::KEY ) );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_the_right_code_returns_the_user_and_is_single_use() {
		$made = $this->make();

		$user = Requests::verify_code( self::KEY, $made['code'] );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( $this->user->ID, $user->ID );
		$this->assertNotNull( $this->rows()[0]['used_at'] );

		$this->assertWPError( Requests::verify_code( self::KEY, $made['code'] ) );
	}

	public function test_a_code_typed_with_a_space_or_dash_works() {
		$made  = $this->make();
		$typed = substr( $made['code'], 0, 3 ) . ' ' . substr( $made['code'], 3 );
		$this->assertInstanceOf( WP_User::class, Requests::verify_code( self::KEY, $typed ) );
	}

	public function test_wrong_code_four_times_then_the_right_code_works() {
		$made  = $this->make();
		$wrong = '000000' === $made['code'] ? '111111' : '000000';

		for ( $i = 1; $i <= 4; $i++ ) {
			$result = Requests::verify_code( self::KEY, $wrong );
			$this->assertWPError( $result );
			$this->assertSame( 'happyaccess_invalid_code', $result->get_error_code() );
			$this->assertSame( $i, (int) $this->rows()[0]['attempts'] );
		}

		$this->assertInstanceOf( WP_User::class, Requests::verify_code( self::KEY, $made['code'] ) );
	}

	public function test_wrong_code_five_times_cancels_the_request_and_the_right_code_then_fails() {
		$made  = $this->make();
		$wrong = '000000' === $made['code'] ? '111111' : '000000';

		for ( $i = 1; $i <= 4; $i++ ) {
			$this->assertWPError( Requests::verify_code( self::KEY, $wrong ) );
		}
		$fifth = Requests::verify_code( self::KEY, $wrong );
		$this->assertWPError( $fifth );
		$this->assertSame( 'happyaccess_code_locked', $fifth->get_error_code() );
		$this->assertStringContainsString( 'new code', $fifth->get_error_message() );
		$this->assertNotNull( $this->rows()[0]['used_at'] );

		$this->assertWPError( Requests::verify_code( self::KEY, $made['code'] ) );
		$this->assertNull( Requests::find_by_link( $made['link_key'] ) );
		$this->assertWPError( Requests::consume_link( $made['link_key'] ) );
		$this->assertSame( 5, (int) $this->rows()[0]['attempts'] );
	}

	public function test_the_code_fails_with_another_browsers_request_key() {
		$made = $this->make();

		$this->assertWPError( Requests::verify_code( 'some-other-browser', $made['code'] ) );
		$this->assertWPError( Requests::verify_code( '', $made['code'] ) );
		$this->assertNull( $this->rows()[0]['used_at'] );
		$this->assertSame( 0, (int) $this->rows()[0]['attempts'] );
		$this->assertInstanceOf( WP_User::class, Requests::verify_code( self::KEY, $made['code'] ) );
	}

	public function test_a_code_that_is_not_text_or_not_six_digits_counts_as_a_wrong_try() {
		$made = $this->make();

		$this->assertWPError( Requests::verify_code( self::KEY, array( $made['code'] ) ) );
		$this->assertWPError( Requests::verify_code( self::KEY, '' ) );
		$this->assertWPError( Requests::verify_code( self::KEY, substr( $made['code'], 0, 5 ) ) );
		$this->assertSame( 3, (int) $this->rows()[0]['attempts'] );
	}

	public function test_an_expired_challenge_fails_for_code_and_link() {
		$made = $this->make();
		Clock::freeze( $made['expires_at'] );

		$this->assertWPError( Requests::verify_code( self::KEY, $made['code'] ) );
		$this->assertNull( Requests::find_by_link( $made['link_key'] ) );
		$this->assertWPError( Requests::consume_link( $made['link_key'] ) );
		$this->assertNull( $this->rows()[0]['used_at'] );
	}

	public function test_a_challenge_one_second_before_expiry_still_works() {
		$made = $this->make();
		Clock::freeze( $made['expires_at'] - 1 );
		$this->assertInstanceOf( WP_User::class, Requests::verify_code( self::KEY, $made['code'] ) );
	}

	public function test_the_code_works_only_for_the_newest_challenge_of_a_request_key() {
		$old = $this->make( $this->user, self::KEY );
		$new = $this->make( $this->user, self::KEY );

		$this->assertWPError( Requests::verify_code( self::KEY, $old['code'] ) );
		$this->assertInstanceOf( WP_User::class, Requests::verify_code( self::KEY, $new['code'] ) );
	}

	public function test_link_is_single_use() {
		$made = $this->make();

		$user = Requests::consume_link( $made['link_key'] );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( $this->user->ID, $user->ID );

		$second = Requests::consume_link( $made['link_key'] );
		$this->assertWPError( $second );
		$this->assertNull( Requests::find_by_link( $made['link_key'] ) );
	}

	public function test_using_the_link_cancels_the_code_and_the_code_cancels_the_link() {
		$one = $this->make( $this->user, 'key-one' );
		Requests::consume_link( $one['link_key'] );
		$this->assertWPError( Requests::verify_code( 'key-one', $one['code'] ) );

		$two = $this->make( $this->user, 'key-two' );
		Requests::verify_code( 'key-two', $two['code'] );
		$this->assertWPError( Requests::consume_link( $two['link_key'] ) );
	}

	public function test_find_by_link_never_consumes() {
		$made = $this->make();

		for ( $i = 0; $i < 3; $i++ ) {
			$row = Requests::find_by_link( $made['link_key'] );
			$this->assertIsArray( $row );
			$this->assertSame( $this->user->ID, $row['user_id'] );
			$this->assertSame( $made['expires_at'], $row['expires_at'] );
		}
		$this->assertNull( $this->rows()[0]['used_at'] );
		$this->assertInstanceOf( WP_User::class, Requests::consume_link( $made['link_key'] ) );
	}

	public function test_find_by_link_does_not_return_hashes() {
		$made = $this->make();
		$row  = Requests::find_by_link( $made['link_key'] );
		$this->assertArrayNotHasKey( 'code_hash', $row );
		$this->assertArrayNotHasKey( 'link_hash', $row );
		$this->assertArrayNotHasKey( 'request_key_hash', $row );
	}

	public function test_an_unknown_or_empty_link_key_finds_nothing() {
		$this->make();
		$this->assertNull( Requests::find_by_link( 'nope' ) );
		$this->assertNull( Requests::find_by_link( '' ) );
		$this->assertNull( Requests::find_by_link( array( 'x' ) ) );
		$this->assertWPError( Requests::consume_link( 'nope' ) );
		$this->assertWPError( Requests::consume_link( '' ) );
	}

	public function test_a_link_for_a_deleted_user_fails_and_is_used_up() {
		$gone = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$made = $this->make( $gone, 'key-gone' );
		// A direct delete, because wp_delete_user() only removes a user from the site on a network.
		global $wpdb;
		$wpdb->delete( $wpdb->users, array( 'ID' => $gone->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		clean_user_cache( $gone->ID );

		$link = Requests::consume_link( $made['link_key'] );
		$this->assertWPError( $link );
		$this->assertSame( 'happyaccess_invalid_link', $link->get_error_code() );

		$code = Requests::verify_code( 'key-gone', $made['code'] );
		$this->assertWPError( $code );
		$this->assertSame( 'happyaccess_invalid_code', $code->get_error_code() );
	}

	public function test_create_refuses_a_temp_support_user() {
		$temp = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		update_user_meta( $temp->ID, 'happyaccess_temp_user', 1 );

		$this->assertWPError( Requests::create( $temp, self::KEY ) );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_create_refuses_a_user_the_filter_refuses_and_leaves_the_earlier_request_alone() {
		$made = $this->make();

		add_filter( 'happyaccess_passwordless_allowed', '__return_false' );
		$this->assertWPError( Requests::create( $this->user, 'key-two' ) );
		remove_filter( 'happyaccess_passwordless_allowed', '__return_false' );

		$this->assertCount( 1, $this->rows() );
		$this->assertInstanceOf( WP_User::class, Requests::verify_code( self::KEY, $made['code'] ) );
	}

	public function test_a_user_the_filter_refuses_after_create_cannot_use_the_code() {
		$made = $this->make();

		add_filter( 'happyaccess_passwordless_allowed', '__return_false' );
		$result = Requests::verify_code( self::KEY, $made['code'] );
		remove_filter( 'happyaccess_passwordless_allowed', '__return_false' );

		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_invalid_code', $result->get_error_code() );
		$this->assertNotNull( $this->rows()[0]['used_at'] );
		$this->assertWPError( Requests::verify_code( self::KEY, $made['code'] ) );
	}

	public function test_a_user_the_filter_refuses_after_create_cannot_use_the_link() {
		$made = $this->make();

		add_filter( 'happyaccess_passwordless_allowed', '__return_false' );
		$result = Requests::consume_link( $made['link_key'] );
		remove_filter( 'happyaccess_passwordless_allowed', '__return_false' );

		$this->assertWPError( $result );
		$this->assertSame( 'happyaccess_invalid_link', $result->get_error_code() );
		$this->assertNotNull( $this->rows()[0]['used_at'] );
		$this->assertWPError( Requests::consume_link( $made['link_key'] ) );
	}

	public function test_a_user_who_became_a_temp_support_user_after_create_cannot_use_the_code_or_link() {
		$made  = $this->make();
		$other = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$two   = $this->make( $other, 'key-two' );
		update_user_meta( $this->user->ID, 'happyaccess_temp_user', 1 );
		update_user_meta( $other->ID, 'happyaccess_temp_user', 1 );

		$this->assertWPError( Requests::verify_code( self::KEY, $made['code'] ) );
		$this->assertWPError( Requests::consume_link( $two['link_key'] ) );
	}

	public function test_eligible_takes_an_email_or_a_username() {
		$user = self::factory()->user->create_and_get(
			array(
				'user_login' => 'dana_editor',
				'user_email' => 'dana@example.org',
			)
		);

		$this->assertSame( $user->ID, Requests::eligible( 'dana@example.org' )->ID );
		$this->assertSame( $user->ID, Requests::eligible( '  DANA@example.org ' )->ID );
		$this->assertSame( $user->ID, Requests::eligible( 'dana_editor' )->ID );
	}

	public function test_eligible_is_null_for_a_missing_user_or_bad_input() {
		$this->assertNull( Requests::eligible( 'nobody@example.org' ) );
		$this->assertNull( Requests::eligible( 'nobody' ) );
		$this->assertNull( Requests::eligible( '' ) );
		$this->assertNull( Requests::eligible( array( 'x' ) ) );
		$this->assertNull( Requests::eligible( null ) );
	}

	public function test_eligible_is_null_for_a_temp_support_user() {
		$temp = self::factory()->user->create_and_get(
			array(
				'user_login' => 'support_temp',
				'user_email' => 'temp@example.org',
			)
		);
		update_user_meta( $temp->ID, 'happyaccess_temp_user', 1 );

		$this->assertNull( Requests::eligible( 'temp@example.org' ) );
		$this->assertNull( Requests::eligible( 'support_temp' ) );
	}

	public function test_the_allowed_filter_can_refuse_a_user() {
		$seen = array();
		add_filter(
			'happyaccess_passwordless_allowed',
			function ( $allowed, $user ) use ( &$seen ) {
				$seen[] = array( $allowed, $user instanceof WP_User ? $user->ID : 0 );
				return false;
			},
			10,
			2
		);

		$this->assertNull( Requests::eligible( $this->user->user_email ) );
		$this->assertSame( array( array( true, $this->user->ID ) ), $seen );
	}

	public function test_a_user_with_another_plugins_2fa_is_treated_as_missing() {
		add_filter( 'happyaccess_user_has_other_2fa', '__return_true' );
		$eligible = Requests::eligible( $this->user->user_email );
		$created  = Requests::create( $this->user, self::KEY );
		remove_filter( 'happyaccess_user_has_other_2fa', '__return_true' );

		$this->assertNull( $eligible );
		$this->assertWPError( $created );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_a_user_who_turned_on_another_plugins_2fa_after_create_cannot_use_the_code_or_link() {
		$made  = $this->make();
		$other = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$two   = $this->make( $other, 'key-two' );

		add_filter( 'happyaccess_user_has_other_2fa', '__return_true' );
		$code = Requests::verify_code( self::KEY, $made['code'] );
		$link = Requests::consume_link( $two['link_key'] );
		remove_filter( 'happyaccess_user_has_other_2fa', '__return_true' );

		$this->assertWPError( $code );
		$this->assertWPError( $link );
	}

	public function test_the_allowed_filter_has_the_last_word_over_another_plugins_2fa() {
		$seen = array();
		add_filter( 'happyaccess_user_has_other_2fa', '__return_true' );
		add_filter(
			'happyaccess_passwordless_allowed',
			function ( $allowed ) use ( &$seen ) {
				$seen[] = $allowed;
				return true;
			}
		);

		$this->assertSame( $this->user->ID, Requests::eligible( $this->user->user_email )->ID );
		$this->assertSame( array( false ), $seen );
		remove_filter( 'happyaccess_user_has_other_2fa', '__return_true' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_two_factor_plugin_user_is_treated_as_missing() {
		require dirname( __DIR__ ) . '/Support/Stubs/two-factor-core.php';
		Two_Factor_Core::$users = array( $this->user->ID );
		$plain                  = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );

		$this->assertNull( Requests::eligible( $this->user->user_email ) );
		$this->assertSame( $plain->ID, Requests::eligible( $plain->user_email )->ID );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_wordfence_login_security_user_is_treated_as_missing() {
		require dirname( __DIR__ ) . '/Support/Stubs/wordfence-ls-users.php';
		\WordfenceLS\Controller_Users::$users = array( $this->user->ID );

		$this->assertNull( Requests::eligible( $this->user->user_email ) );
		$this->assertWPError( Requests::create( $this->user, self::KEY ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_eligible_is_null_for_a_user_on_another_site() {
		$blog_id = self::factory()->blog->create();
		$other   = self::factory()->user->create_and_get(
			array(
				'user_login' => 'otherblog',
				'user_email' => 'otherblog@example.org',
				'role'       => '',
			)
		);
		add_user_to_blog( $blog_id, $other->ID, 'editor' );
		remove_user_from_blog( $other->ID, get_current_blog_id() );

		$this->assertNull( Requests::eligible( 'otherblog@example.org' ) );

		switch_to_blog( $blog_id );
		$this->assertSame( $other->ID, Requests::eligible( 'otherblog@example.org' )->ID );
		restore_current_blog();
	}

	/**
	 * @group ms-required
	 */
	public function test_eligible_accepts_a_member_of_the_current_site_on_multisite() {
		$this->assertSame( $this->user->ID, Requests::eligible( $this->user->user_email )->ID );
	}

	/**
	 * @group ms-required
	 */
	public function test_a_spam_user_cannot_use_passwordless_on_multisite() {
		global $wpdb;
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite.' );
		}

		$spam    = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$other   = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$by_code = Requests::create( $spam, 'request-key' );
		$by_link = Requests::create( $other, 'other-key' );
		$this->assertIsArray( $by_code );
		$this->assertIsArray( $by_link );

		$wpdb->update( $wpdb->users, array( 'spam' => 1 ), array( 'ID' => $spam->ID ) );
		$wpdb->update( $wpdb->users, array( 'spam' => 1 ), array( 'ID' => $other->ID ) );
		clean_user_cache( $spam->ID );
		clean_user_cache( $other->ID );

		$this->assertNull( Requests::eligible( $spam->user_email ) );
		$this->assertInstanceOf( 'WP_Error', Requests::create( get_userdata( $spam->ID ), 'second-key' ) );
		$this->assertInstanceOf( 'WP_Error', Requests::verify_code( 'request-key', $by_code['code'] ) );
		$this->assertInstanceOf( 'WP_Error', Requests::consume_link( $by_link['link_key'] ) );
	}
}
