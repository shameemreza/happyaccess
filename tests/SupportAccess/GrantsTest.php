<?php
/**
 * Grants create and read tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
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
		Clock::freeze( null );
		parent::tear_down();
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
		$made  = Grants::create( array( 'label' => 'Acme', 'role' => 'editor', 'one_time' => true, 'ips' => array( '203.0.113.9', 'nope' ), 'menus' => array( 'edit.php' ), 'block_installs' => true, 'notify' => 'every' ) );
		$grant = Grants::get( $made['id'] );

		$this->assertSame( 'editor', $grant['role'] );
		$this->assertSame( 1, $grant['max_uses'] );
		$this->assertSame( array( '203.0.113.9' ), $grant['restrictions']['ips'] );
		$this->assertSame( array( 'edit.php' ), $grant['restrictions']['menus'] );
		$this->assertFalse( $grant['restrictions']['hide_admin_bar'] );
		$this->assertSame( 'protected_no_installs', $grant['protection'] );
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
}
