<?php
/**
 * AuditLog tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;

class AuditLogTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Installer::install();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	public function tear_down() {
		Clock::freeze( null );
		parent::tear_down();
	}

	public function test_add_and_query_with_filters() {
		$id = AuditLog::add(
			'login',
			array(
				'feature'  => 'support',
				'token_id' => 4,
				'user_id'  => 9,
				'summary'  => 'Logged in by link',
				'meta'     => array( 'method' => 'link' ),
			)
		);
		AuditLog::add( 'plugin_upgraded', array( 'feature' => 'core', 'user_id' => 0 ) );

		$this->assertGreaterThan( 0, $id );

		$result = AuditLog::query( array( 'feature' => 'support' ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'login', $result['items'][0]['event_type'] );
		$this->assertSame( 'link', $result['items'][0]['meta']['method'] );
		$this->assertSame( '203.0.113.9', $result['items'][0]['ip_address'] );

		$this->assertSame( 1, AuditLog::query( array( 'event' => 'plugin_upgraded' ) )['total'] );
		$this->assertSame( 1, AuditLog::query( array( 'token_id' => 4 ) )['total'] );
		$this->assertSame( 2, AuditLog::query()['total'] );
	}

	public function test_pagination() {
		for ( $i = 0; $i < 30; $i++ ) {
			AuditLog::add( 'login', array( 'user_id' => 1 ) );
		}
		$page = AuditLog::query( array( 'page' => 2, 'per_page' => 25 ) );
		$this->assertSame( 30, $page['total'] );
		$this->assertCount( 5, $page['items'] );
	}

	public function test_anonymize_and_logging_off() {
		Settings::update( array( 'privacy' => array( 'anonymize_ip' => true ) ) );
		AuditLog::add( 'login' );
		$this->assertSame( '203.0.113.0', AuditLog::query()['items'][0]['ip_address'] );

		Settings::update( array( 'privacy' => array( 'logging' => false ) ) );
		$this->assertSame( 0, AuditLog::add( 'login' ) );
	}

	public function test_purge_removes_old_rows() {
		Clock::freeze( 1790000000 - 40 * DAY_IN_SECONDS );
		AuditLog::add( 'old' );
		Clock::freeze( 1790000000 );
		AuditLog::add( 'new' );

		$this->assertSame( 1, AuditLog::purge( 30 ) );
		$this->assertSame( 'new', AuditLog::query()['items'][0]['event_type'] );
	}
}
