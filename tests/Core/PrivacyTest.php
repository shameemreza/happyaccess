<?php
/**
 * Privacy tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Privacy;
use HappyAccess\Features\SupportAccess\Grants;

class PrivacyTest extends WP_UnitTestCase {

	private $user;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$this->user = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'me@example.org' ) );
		wp_set_current_user( $this->user );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		AuditLog::add( 'login', array( 'user_id' => $this->user ) );
		Grants::create( array( 'label' => 'Acme', 'email' => 'agent@example.org' ) );
		Privacy::register();
	}

	public function test_exporter_returns_logs_and_grants() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$result    = call_user_func( $exporters['happyaccess']['callback'], 'me@example.org', 1 );
		$this->assertTrue( $result['done'] );
		$groups = wp_list_pluck( $result['data'], 'group_id' );
		$this->assertContains( 'happyaccess_logs', $groups );
		$this->assertContains( 'happyaccess_grants', $groups );
	}

	public function test_eraser_anonymizes_logs_and_recipient_email() {
		global $wpdb;
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		call_user_func( $erasers['happyaccess']['callback'], 'me@example.org', 1 );
		$this->assertSame( 0, AuditLog::query( array( 'user_id' => $this->user ) )['total'] );

		call_user_func( $erasers['happyaccess']['callback'], 'agent@example.org', 1 );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'tokens' ) . ' WHERE recipient_email = %s', 'agent@example.org' ) ) );
	}

	public function test_exporter_pages_by_fifty() {
		for ( $i = 0; $i < 50; $i++ ) {
			AuditLog::add( 'more', array( 'user_id' => $this->user ) );
		}
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$first     = call_user_func( $exporters['happyaccess']['callback'], 'me@example.org', 1 );
		$second    = call_user_func( $exporters['happyaccess']['callback'], 'me@example.org', 2 );
		$this->assertFalse( $first['done'] );
		$this->assertTrue( $second['done'] );
		$logged = AuditLog::query( array( 'user_id' => $this->user ) )['total'];
		$this->assertCount( $logged + 1, array_merge( $first['data'], $second['data'] ) );
	}

	public function test_eraser_reports_removed_and_clears_created_by() {
		global $wpdb;
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$result  = call_user_func( $erasers['happyaccess']['callback'], 'me@example.org', 1 );
		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'tokens' ) . ' WHERE created_by = %d', $this->user ) ) );
	}
}
