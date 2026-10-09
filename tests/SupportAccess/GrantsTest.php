<?php
/**
 * Grants create and read tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Features\SupportAccess\Grants;

class GrantsTest extends WP_UnitTestCase {

	private $owner;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Secrets::reset_cache();
		Clock::freeze( 1790000000 );
		$this->owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner );
	}

	public function tear_down() {
		delete_option( Codes::PURPOSE_SINCE_OPTION );
		Clock::freeze( null );
		parent::tear_down();
	}

	/**
	 * Makes the check for a code already in use say "taken" a number of times.
	 *
	 * @param int $times How many draws collide.
	 * @return callable The query filter, already added.
	 */
	private function collide( $times ) {
		$this->collisions = 0;
		$filter           = function ( $sql ) use ( $times ) {
			if ( false !== strpos( $sql, 'WHERE code_hash IN' ) && $this->collisions < $times ) {
				++$this->collisions;
				return 'SELECT 1';
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		return $filter;
	}

	/**
	 * Draws the collide() filter turned into "taken".
	 *
	 * @var int
	 */
	private $collisions = 0;

	public function test_a_code_that_is_already_in_use_is_drawn_again() {
		$filter = $this->collide( 2 );
		try {
			$made = Grants::create( array( 'label' => 'Acme' ) );
		} finally {
			remove_filter( 'query', $filter );
		}

		$this->assertSame( 2, $this->collisions );
		$this->assertSame( $made['id'], Grants::find_by_code( $made['code'] )['id'] );
	}

	public function test_five_codes_in_use_in_a_row_stop_the_create() {
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'tokens' ) );
		$filter = $this->collide( 100 );
		try {
			Grants::create( array( 'label' => 'Acme' ) );
			$this->fail( 'The create went on with a code in use.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Could not generate an unused code.', $e->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}

		$this->assertSame( Grants::CODE_ATTEMPTS, $this->collisions );
		$this->assertSame( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'tokens' ) ) );
	}

	public function test_create_returns_plain_secrets_once_and_stores_hashes() {
		global $wpdb;
		$made = Grants::create( array( 'label' => 'Acme support', 'duration' => DAY_IN_SECONDS ) );

		$this->assertMatchesRegularExpression( '/^\d{8}$/', $made['code'] );
		$this->assertSame( 43, strlen( $made['link_key'] ) );
		$this->assertSame( 1790000000 + DAY_IN_SECONDS, $made['expires_at'] );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'tokens' ) . ' WHERE id = %d', $made['id'] ), ARRAY_A );
		$this->assertStringNotContainsString( $made['code'], wp_json_encode( $row ) );
		$this->assertStringNotContainsString( $made['link_key'], wp_json_encode( $row ) );
		$this->assertSame( $row['link_hash'], $row['token_hash'] );

		$log = AuditLog::query( array( 'event' => 'grant_created' ) );
		$this->assertSame( 1, $log['total'] );
		$this->assertStringNotContainsString( $made['code'], wp_json_encode( $log['items'] ) );
		$this->assertStringNotContainsString( $made['link_key'], wp_json_encode( $log['items'] ) );
	}

	public function test_get_normalizes_and_defaults() {
		$made  = Grants::create( array( 'label' => 'Acme', 'role' => 'editor', 'one_time' => true, 'ips' => array( '203.0.113.9', 'nope' ), 'menus' => array( 'edit.php' ), 'allow_installs' => true, 'notify' => 'every' ) );
		$grant = Grants::get( $made['id'] );

		$this->assertSame( 'editor', $grant['role'] );
		$this->assertSame( 1, $grant['max_uses'] );
		$this->assertSame( array( '203.0.113.9' ), $grant['restrictions']['ips'] );
		$this->assertSame( array( 'edit.php' ), $grant['restrictions']['menus'] );
		$this->assertFalse( $grant['restrictions']['hide_admin_bar'] );
		$this->assertSame( 'protected_allow_installs', $grant['protection'] );
		$default = Grants::get( Grants::create( array( 'label' => 'Plain' ) )['id'] );
		$this->assertSame( 'protected', $default['protection'] );
		$this->assertSame( 'every', $grant['notify'] );
		$this->assertSame( $this->owner, $grant['created_by'] );
		$this->assertSame( 'active', $grant['status'] );
		$this->assertSame( 1790000000 + 259200, $grant['expires_at'] );
	}

	public function test_ips_are_stored_in_canonical_form_without_duplicates() {
		$made = Grants::create( array( 'label' => 'x', 'ips' => array( '::ffff:203.0.113.9', '203.0.113.9' ) ) );
		$this->assertSame( array( '203.0.113.9' ), Grants::get( $made['id'] )['restrictions']['ips'] );
	}

	public function test_duration_is_clamped() {
		$short = Grants::create( array( 'label' => 'a', 'duration' => 10 ) );
		$long  = Grants::create( array( 'label' => 'b', 'duration' => 999 * DAY_IN_SECONDS ) );
		$this->assertSame( 1790000000 + Grants::MIN_DURATION, $short['expires_at'] );
		$this->assertSame( 1790000000 + Grants::MAX_DURATION, $long['expires_at'] );
	}

	public function test_invalid_input_is_rejected() {
		foreach ( array(
			array( 'label' => '' ),
			array( 'label' => 'x', 'role' => 'not_a_role' ),
			array( 'label' => 'x', 'email' => 'not-an-email' ),
			array( 'label' => 'x', 'ips' => array( 'nope', 'also-nope' ) ),
		) as $args ) {
			try {
				Grants::create( $args );
				$this->fail( 'Expected InvalidArgumentException for ' . wp_json_encode( $args ) );
			} catch ( InvalidArgumentException $e ) {
				$this->assertNotEmpty( $e->getMessage() );
			}
		}
	}

	public function test_external_redirect_is_dropped() {
		$made = Grants::create( array( 'label' => 'x', 'redirect_to' => 'https://evil.example/' ) );
		$this->assertSame( '', Grants::get( $made['id'] )['redirect_to'] );
		$made = Grants::create( array( 'label' => 'y', 'redirect_to' => admin_url( 'edit.php' ) ) );
		$this->assertSame( admin_url( 'edit.php' ), Grants::get( $made['id'] )['redirect_to'] );
	}

	public function test_find_by_code_and_link() {
		$made = Grants::create( array( 'label' => 'x' ) );
		$this->assertSame( $made['id'], Grants::find_by_code( substr( $made['code'], 0, 4 ) . ' ' . substr( $made['code'], 4 ) )['id'] );
		$this->assertSame( $made['id'], Grants::find_by_link( $made['link_key'] )['id'] );
		$this->assertNull( Grants::find_by_code( '00000000' === $made['code'] ? '11111111' : '00000000' ) );
		$this->assertNull( Grants::find_by_link( $made['link_key'] . 'x' ) );
	}

	public function test_new_grant_codes_are_stored_with_the_support_purpose() {
		global $wpdb;
		$made = Grants::create( array( 'label' => 'x' ) );
		$hash = $wpdb->get_var( $wpdb->prepare( 'SELECT code_hash FROM ' . Installer::table( 'tokens' ) . ' WHERE id = %d', $made['id'] ) );
		$this->assertSame( Codes::hash_code( $made['code'], Codes::PURPOSE_SUPPORT ), $hash );
		$this->assertNotSame( Codes::legacy_hash_code( $made['code'] ), $hash );

		$fresh = Grants::regenerate( $made['id'] );
		$hash  = $wpdb->get_var( $wpdb->prepare( 'SELECT code_hash FROM ' . Installer::table( 'tokens' ) . ' WHERE id = %d', $made['id'] ) );
		$this->assertSame( Codes::hash_code( $fresh['code'], Codes::PURPOSE_SUPPORT ), $hash );
	}

	public function test_a_code_hashed_before_purposes_keeps_working_for_a_grant_made_before() {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		update_option( Codes::PURPOSE_SINCE_OPTION, Clock::mysql( 1790000000 + 60 ), false );
		$made = Grants::create( array( 'label' => 'old' ) );
		$wpdb->update( $table, array( 'code_hash' => Codes::legacy_hash_code( $made['code'] ) ), array( 'id' => $made['id'] ) );

		$found = Grants::find_by_code( $made['code'] );
		$this->assertNotNull( $found, 'A code issued before the change still logs in.' );
		$this->assertSame( $made['id'], $found['id'] );

		$wpdb->update( $table, array( 'created_at' => Clock::mysql( 1790000000 + 120 ) ), array( 'id' => $made['id'] ) );
		$this->assertNull( Grants::find_by_code( $made['code'] ), 'A grant made after the change only takes the new hash.' );
	}

	public function test_find_prefers_a_current_grant_over_a_newer_ended_one_with_the_same_hash() {
		global $wpdb;
		$table   = Installer::table( 'tokens' );
		$older   = Grants::create( array( 'label' => 'older' ) );
		$revoked = Grants::create( array( 'label' => 'revoked' ) );
		$expired = Grants::create( array( 'label' => 'expired', 'duration' => Grants::MIN_DURATION ) );
		Grants::revoke( $revoked['id'] );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT code_hash, link_hash FROM {$table} WHERE id = %d", $revoked['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update( $table, $row, array( 'id' => $older['id'] ) );
		$this->assertSame( $older['id'], Grants::find_by_code( $revoked['code'] )['id'] );
		$this->assertSame( $older['id'], Grants::find_by_link( $revoked['link_key'] )['id'] );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT code_hash, link_hash FROM {$table} WHERE id = %d", $expired['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update( $table, $row, array( 'id' => $older['id'] ) );
		Clock::freeze( 1790000000 + Grants::MIN_DURATION + 1 );
		$this->assertSame( $older['id'], Grants::find_by_code( $expired['code'] )['id'] );
		$this->assertSame( $older['id'], Grants::find_by_link( $expired['link_key'] )['id'] );

		Grants::revoke( $older['id'] );
		$this->assertSame( $expired['id'], Grants::find_by_code( $expired['code'] )['id'], 'With no current grant, the newest row is still found.' );
	}

	public function test_regenerate_refuses_when_site_key_is_not_stored() {
		$made = Grants::create( array( 'label' => 'x' ) );
		add_filter( 'pre_option_' . Secrets::OPTION, '__return_empty_string' );
		Secrets::reset_cache();
		$thrown = false;
		try {
			Grants::regenerate( $made['id'] );
		} catch ( RuntimeException $e ) {
			$thrown = true;
		}
		remove_filter( 'pre_option_' . Secrets::OPTION, '__return_empty_string' );
		Secrets::reset_cache();

		$this->assertTrue( $thrown );
		$this->assertSame( $made['id'], Grants::find_by_code( $made['code'] )['id'], 'The code that was out keeps working.' );
		$this->assertSame( $made['id'], Grants::find_by_link( $made['link_key'] )['id'] );
	}

	public function test_status_order() {
		$made = Grants::create( array( 'label' => 'x', 'one_time' => true ) );
		$g    = Grants::get( $made['id'] );

		$g['use_count'] = 1;
		$this->assertSame( 'used', Grants::status( $g ) );
		$g['suspended_at'] = 1790000000;
		$this->assertSame( 'suspended', Grants::status( $g ) );
		$g['expires_at'] = 1789999999;
		$this->assertSame( 'expired', Grants::status( $g ) );
		$g['revoked_at'] = 1790000000;
		$this->assertSame( 'revoked', Grants::status( $g ) );
		$g['end_reason'] = 'expired';
		$this->assertSame( 'expired', Grants::status( $g ) );
	}

	public function test_list_current_and_has_current() {
		$this->assertFalse( Grants::has_current() );
		$a = Grants::create( array( 'label' => 'a' ) );
		Grants::create( array( 'label' => 'b', 'duration' => 3600 ) );
		Clock::freeze( 1790000000 + 7200 );
		$this->assertSame( array( $a['id'] ), wp_list_pluck( Grants::list_current(), 'id' ) );
		$this->assertTrue( Grants::has_current() );
	}

	public function test_refuses_when_site_key_is_not_stored() {
		add_filter(
			'pre_option_' . Secrets::OPTION,
			function () {
				return '';
			}
		);
		Secrets::reset_cache();
		$this->expectException( RuntimeException::class );
		Grants::create( array( 'label' => 'x' ) );
	}

	public function test_has_current_is_read_once_until_a_write_the_next_expiry_or_a_blog_switch() {
		global $wpdb;
		$this->assertFalse( Grants::has_current() );
		$short = Grants::create( array( 'label' => 'short', 'duration' => HOUR_IN_SECONDS ) );
		$this->assertTrue( Grants::has_current() );

		$queries = $wpdb->num_queries;
		$this->assertTrue( Grants::has_current() );
		$this->assertSame( $queries, $wpdb->num_queries );

		Clock::freeze( 1790000000 + 2 * HOUR_IN_SECONDS );
		$this->assertFalse( Grants::has_current() );

		Clock::freeze( 1790000000 );
		$this->assertTrue( Grants::has_current() );
		$queries = $wpdb->num_queries;
		do_action( 'switch_blog', get_current_blog_id(), get_current_blog_id(), 'switch' );
		$this->assertTrue( Grants::has_current() );
		$this->assertSame( $queries + 1, $wpdb->num_queries );

		Grants::revoke( $short['id'] );
		$this->assertFalse( Grants::has_current() );
	}
}
