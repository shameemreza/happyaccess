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
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'tokens' ) . ' WHERE created_by = %d', $this->user ) ) );
	}

	public function test_export_and_erase_with_empty_or_unknown_email() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		foreach ( array( '', 'nobody@example.org' ) as $email ) {
			$result = call_user_func( $exporters['happyaccess']['callback'], $email, 1 );
			$this->assertSame( array(), $result['data'] );
			$this->assertTrue( $result['done'] );
		}
		// An empty email must not match every grant that has no recipient.
		Grants::create( array( 'label' => 'No email' ) );
		$result = call_user_func( $erasers['happyaccess']['callback'], '', 1 );
		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 'No email', $this->grant_by_label( 'No email' )['label'] );
	}

	public function test_export_by_recipient_email_includes_grant_logs() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$result    = call_user_func( $exporters['happyaccess']['callback'], 'agent@example.org', 1 );
		$groups    = wp_list_pluck( $result['data'], 'group_id' );
		$this->assertContains( 'happyaccess_logs', $groups );
		$this->assertContains( 'happyaccess_grants', $groups );
	}

	public function test_erase_by_recipient_email_anonymizes_grant_logs() {
		global $wpdb;
		$_SERVER['HTTP_USER_AGENT'] = 'Agent Browser';
		$created = Grants::create( array( 'label' => 'Agent job', 'email' => 'outside@example.net' ) );
		AuditLog::add( 'login', array( 'token_id' => $created['id'], 'user_id' => 0 ) );
		$logs = Installer::table( 'logs' );

		$before = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE token_id = %d AND ip_address = %s", $created['id'], '203.0.113.9' ) );
		$this->assertGreaterThan( 0, (int) $before );

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$result  = call_user_func( $erasers['happyaccess']['callback'], 'outside@example.net', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE token_id = %d AND ( ip_address <> %s OR user_agent <> %s )", $created['id'], '0.0.0.0', '' ) ) );
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE token_id = %d AND ip_address = %s", $created['id'], '0.0.0.0' ) ) );
	}

	public function test_erase_ends_active_grant_and_clears_label_allowlist_and_summaries() {
		global $wpdb;
		$created = Grants::create(
			array(
				'label'          => 'Zetacorp',
				'email'          => 'zeta@example.net',
				'ips'            => array( '203.0.113.5' ),
				'menus'          => array( 'tools.php' ),
				'hide_admin_bar' => true,
			)
		);
		$logs = Installer::table( 'logs' );
		// Make sure at least one summary carries the label.
		AuditLog::add( 'note', array( 'token_id' => $created['id'], 'summary' => 'Opened by Zetacorp' ) );

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		call_user_func( $erasers['happyaccess']['callback'], 'zeta@example.net', 1 );

		$grant = Grants::get( $created['id'] );
		$this->assertSame( 'revoked', $grant['status'] );
		$this->assertSame( 'privacy_erased', $grant['end_reason'] );
		$this->assertSame( '', $grant['label'] );
		$this->assertSame( '', $grant['recipient_email'] );
		$this->assertSame( array(), $grant['restrictions']['ips'] );
		$this->assertSame( array( 'tools.php' ), $grant['restrictions']['menus'] );
		$this->assertTrue( $grant['restrictions']['hide_admin_bar'] );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT ip_restrictions FROM ' . Installer::table( 'tokens' ) . ' WHERE id = %d', $created['id'] ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE token_id = %d AND summary LIKE %s", $created['id'], '%Zetacorp%' ) ) );
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE token_id = %d AND summary LIKE %s", $created['id'], '%[removed]%' ) ) );
	}

	private function grant_by_label( $label ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'tokens' ) . ' WHERE label = %s', $label ) );
		return Grants::get( $id );
	}
}
