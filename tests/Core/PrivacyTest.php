<?php
/**
 * Privacy tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Privacy;
use HappyAccess\Features\SupportAccess\CapabilityGuard;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;
use HappyAccess\Features\TwoStep\BackupCodes;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;

class PrivacyTest extends WP_UnitTestCase {

	private $user;

	public function set_up() {
		parent::set_up();
		Installer::install();
		$this->user = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'me@example.org',
			)
		);
		wp_set_current_user( $this->user );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		AuditLog::add( 'login', array( 'user_id' => $this->user ) );
		Grants::create(
			array(
				'label' => 'Acme',
				'email' => 'agent@example.org',
			)
		);
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

	public function test_export_by_recipient_email_has_agent_logs_but_not_the_admins_ip() {
		$grant                  = $this->grant_by_label( 'Acme' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		AuditLog::add(
			'login',
			array(
				'token_id' => $grant['id'],
				'user_id'  => 0,
			)
		);

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$result    = call_user_func( $exporters['happyaccess']['callback'], 'agent@example.org', 1 );
		$groups    = wp_list_pluck( $result['data'], 'group_id' );
		$this->assertContains( 'happyaccess_logs', $groups );
		$this->assertContains( 'happyaccess_grants', $groups );

		$values = array();
		foreach ( $result['data'] as $item ) {
			$values = array_merge( $values, wp_list_pluck( $item['data'], 'value' ) );
		}
		$this->assertContains( '198.51.100.7', $values );
		$this->assertNotContains( '203.0.113.9', $values );
	}

	public function test_creator_export_leaves_out_the_recipient_email_and_label() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$result    = call_user_func( $exporters['happyaccess']['callback'], 'me@example.org', 1 );
		$names     = array();
		foreach ( $result['data'] as $item ) {
			if ( 'happyaccess_grants' === $item['group_id'] ) {
				$names = array_merge( $names, wp_list_pluck( $item['data'], 'name' ) );
			}
		}
		$this->assertNotEmpty( $names );
		$this->assertNotContains( 'Label', $names );
		$this->assertNotContains( 'Recipient email', $names );
		$this->assertContains( 'Role', $names );
	}

	public function test_erase_by_recipient_email_anonymizes_agent_logs_and_leaves_the_admins() {
		global $wpdb;
		$_SERVER['HTTP_USER_AGENT'] = 'Admin Browser';
		$created                    = Grants::create(
			array(
				'label' => 'Agent job',
				'email' => 'outside@example.net',
			)
		);
		$_SERVER['REMOTE_ADDR']     = '198.51.100.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Agent Browser';
		AuditLog::add(
			'login',
			array(
				'token_id' => $created['id'],
				'user_id'  => 0,
			)
		);
		$logs = Installer::table( 'logs' );

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$result  = call_user_func( $erasers['happyaccess']['callback'], 'outside@example.net', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertTrue( $result['done'] );

		$agent = $wpdb->get_row( $wpdb->prepare( "SELECT ip_address, user_agent FROM {$logs} WHERE token_id = %d AND event_type = %s", $created['id'], 'login' ), ARRAY_A );
		$this->assertSame( '0.0.0.0', $agent['ip_address'] );
		$this->assertSame( '', $agent['user_agent'] );

		$admin = $wpdb->get_row( $wpdb->prepare( "SELECT ip_address, user_agent FROM {$logs} WHERE token_id = %d AND event_type = %s", $created['id'], 'grant_created' ), ARRAY_A );
		$this->assertSame( '203.0.113.9', $admin['ip_address'] );
		$this->assertSame( 'Admin Browser', $admin['user_agent'] );
	}

	public function test_label_scrub_also_clears_the_label_from_the_text_values() {
		global $wpdb;
		$logs    = Installer::table( 'logs' );
		$created = Grants::create(
			array(
				'label' => 'Acme',
				'email' => 'acme@example.net',
			)
		);
		Grants::extend( $created['id'], HOUR_IN_SECONDS );

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$result  = call_user_func( $erasers['happyaccess']['callback'], 'acme@example.net', 1 );
		$this->assertNotContains( 'Some log summaries may still contain the grant label.', $result['messages'] );

		$rows = AuditLog::query( array( 'token_id' => $created['id'] ) )['items'];
		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$line = \HappyAccess\Core\LogText::summary( $row['event_type'], $row['summary'], $row['meta'] );
			$this->assertStringNotContainsString( 'Acme', $line, $row['event_type'] );
			$this->assertStringNotContainsString( 'Acme', (string) wp_json_encode( $row['meta'] ), $row['event_type'] );
		}
		$by_event = wp_list_pluck( $rows, 'meta', 'event_type' );
		$this->assertSame( array( '[removed]' ), $by_event['grant_created']['summary_args'] );
		$this->assertSame( array( '[removed]' ), $by_event['grant_extended']['summary_args'] );
		$this->assertSame( 'Temporary access granted to [removed]', \HappyAccess\Core\LogText::summary( 'grant_created', '', $by_event['grant_created'] ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE token_id = %d AND metadata LIKE %s", $created['id'], '%Acme%' ) ) );
	}

	public function test_label_scrub_only_replaces_the_trailing_label() {
		global $wpdb;
		$logs    = Installer::table( 'logs' );
		$created = Grants::create(
			array(
				'label' => 'Acme',
				'email' => 'acme@example.net',
			)
		);
		$short   = Grants::create(
			array(
				'label' => 'ti',
				'email' => 'short@example.net',
			)
		);
		AuditLog::add(
			'note',
			array(
				'token_id' => $short['id'],
				'summary'  => 'Saved settings: 3 options',
			)
		);

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$full    = call_user_func( $erasers['happyaccess']['callback'], 'acme@example.net', 1 );
		$result  = call_user_func( $erasers['happyaccess']['callback'], 'short@example.net', 1 );
		$this->assertNotContains( 'Some log summaries may still contain the grant label.', $full['messages'] );
		$this->assertContains( 'Some log summaries may still contain the grant label.', $result['messages'] );

		$this->assertSame( 'Temporary access granted to [removed]', $wpdb->get_var( $wpdb->prepare( "SELECT summary FROM {$logs} WHERE token_id = %d AND event_type = %s", $created['id'], 'grant_created' ) ) );
		$this->assertSame( 'Saved settings: 3 options', $wpdb->get_var( $wpdb->prepare( "SELECT summary FROM {$logs} WHERE token_id = %d AND event_type = %s", $short['id'], 'note' ) ) );
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
		$logs    = Installer::table( 'logs' );
		// Make sure at least one summary carries the label.
		AuditLog::add(
			'note',
			array(
				'token_id' => $created['id'],
				'summary'  => 'Opened by Zetacorp',
			)
		);

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

	/**
	 * Admin creates a grant, the agent logs in, the admin revokes it.
	 *
	 * @return array Grant id and temp user id.
	 */
	private function run_grant_lifecycle() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$created                = Grants::create(
			array(
				'label' => 'Orbit',
				'email' => 'orbit@example.net',
			)
		);
		$temp                   = TempUsers::get_or_create( Grants::get( $created['id'] ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		AuditLog::add(
			'login_success',
			array(
				'token_id' => $created['id'],
				'user_id'  => $temp,
				'summary'  => 'Support access used: Orbit',
			)
		);

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		wp_set_current_user( $this->user );
		Grants::revoke( $created['id'] );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		return array( $created['id'], $temp );
	}

	public function test_export_after_a_revoke_has_the_agent_login_and_no_admin_ip() {
		global $wpdb;
		list( $id ) = $this->run_grant_lifecycle();
		$logs       = Installer::table( 'logs' );
		$this->assertSame( '203.0.113.9', $wpdb->get_var( $wpdb->prepare( "SELECT ip_address FROM {$logs} WHERE token_id = %d AND event_type = %s", $id, 'temp_user_deleted' ) ) );

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$result    = call_user_func( $exporters['happyaccess']['callback'], 'orbit@example.net', 1 );
		$values    = array();
		foreach ( $result['data'] as $item ) {
			if ( 'happyaccess_logs' === $item['group_id'] ) {
				$values = array_merge( $values, wp_list_pluck( $item['data'], 'value' ) );
			}
		}
		$this->assertContains( 'login_success', $values );
		$this->assertContains( '198.51.100.7', $values );
		$this->assertNotContains( '203.0.113.9', $values );
	}

	public function test_erase_after_a_revoke_leaves_the_admins_rows_alone() {
		global $wpdb;
		list( $id ) = $this->run_grant_lifecycle();
		$logs       = Installer::table( 'logs' );

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		call_user_func( $erasers['happyaccess']['callback'], 'orbit@example.net', 1 );

		foreach ( array( 'grant_created', 'temp_user_deleted', 'grant_ended' ) as $event ) {
			$this->assertSame( '203.0.113.9', $wpdb->get_var( $wpdb->prepare( "SELECT ip_address FROM {$logs} WHERE token_id = %d AND event_type = %s", $id, $event ) ), $event );
		}
		$this->assertSame( '0.0.0.0', $wpdb->get_var( $wpdb->prepare( "SELECT ip_address FROM {$logs} WHERE token_id = %d AND event_type = %s", $id, 'login_success' ) ) );
	}

	public function test_a_temp_user_has_neither_privacy_capability() {
		Capabilities::register();
		CapabilityGuard::register();
		$temp = TempUsers::get_or_create( $this->grant_by_label( 'Acme' ) );
		// On a network core gives both privacy caps to network admins only.
		$this->assertSame( ! is_multisite(), user_can( $this->user, 'export_others_personal_data' ) );
		$this->assertFalse( user_can( $temp, 'export_others_personal_data' ) );
		$this->assertFalse( user_can( $temp, 'erase_others_personal_data' ) );
		if ( is_multisite() ) {
			// The guard, not only core, denies them: even a temp user made super admin can't use them.
			grant_super_admin( $temp );
			$this->assertFalse( user_can( $temp, 'export_others_personal_data' ) );
			$this->assertFalse( user_can( $temp, 'erase_others_personal_data' ) );
			revoke_super_admin( $temp );
		}
	}

	public function test_erase_as_a_temp_user_changes_nothing() {
		global $wpdb;
		$temp     = TempUsers::get_or_create( $this->grant_by_label( 'Acme' ) );
		$logs     = Installer::table( 'logs' );
		$tokens   = Installer::table( 'tokens' );
		$logs_now = $wpdb->get_results( "SELECT * FROM {$logs} ORDER BY id", ARRAY_A );
		$grants   = $wpdb->get_results( "SELECT * FROM {$tokens} ORDER BY id", ARRAY_A );
		$this->assertNotEmpty( $logs_now );

		wp_set_current_user( $temp );
		$result = Privacy::erase( 'me@example.org', 1 );
		$this->assertSame(
			array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$result
		);
		$this->assertSame( $logs_now, $wpdb->get_results( "SELECT * FROM {$logs} ORDER BY id", ARRAY_A ) );
		$this->assertSame( $grants, $wpdb->get_results( "SELECT * FROM {$tokens} ORDER BY id", ARRAY_A ) );
	}

	private function grant_by_label( $label ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'tokens' ) . ' WHERE label = %s', $label ) );
		return Grants::get( $id );
	}

	public function test_export_as_a_temp_user_returns_nothing() {
		$temp = TempUsers::get_or_create( $this->grant_by_label( 'Acme' ) );
		wp_set_current_user( $temp );

		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			Privacy::export( 'me@example.org', 1 )
		);
		$this->assertSame( array( 'data' => array(), 'done' => true ), Privacy::export( 'agent@example.org', 1 ) );

		wp_set_current_user( $this->user );
		$this->assertNotEmpty( Privacy::export( 'me@example.org', 1 )['data'] );
	}

	public function test_passwordless_events_are_exported_and_erased_by_user() {
		global $wpdb;
		$other = self::factory()->user->create( array( 'user_email' => 'other@example.org' ) );
		foreach ( Privacy::USER_EVENTS as $event ) {
			AuditLog::add(
				$event,
				array(
					'feature' => 'passwordless',
					'user_id' => $this->user,
					'summary' => 'Mine ' . $event,
				)
			);
			AuditLog::add(
				$event,
				array(
					'feature' => 'passwordless',
					'user_id' => $other,
					'summary' => 'Theirs ' . $event,
				)
			);
		}

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$result    = call_user_func( $exporters['happyaccess']['callback'], 'me@example.org', 1 );
		$summaries = array();
		foreach ( $result['data'] as $item ) {
			foreach ( $item['data'] as $field ) {
				if ( 'Summary' === $field['name'] ) {
					$summaries[] = $field['value'];
				}
			}
		}
		foreach ( Privacy::USER_EVENTS as $event ) {
			$this->assertContains( 'Mine ' . $event, $summaries );
			$this->assertNotContains( 'Theirs ' . $event, $summaries );
		}

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		call_user_func( $erasers['happyaccess']['callback'], 'me@example.org', 1 );

		$logs = Installer::table( 'logs' );
		$mine = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE user_id = %d AND feature = %s", $this->user, 'passwordless' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 0, $mine );
		$kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE user_id = %d AND feature = %s", $other, 'passwordless' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( count( Privacy::USER_EVENTS ), $kept );
		$erased = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE summary LIKE %s AND ip_address = %s AND user_agent = %s", 'Mine %', '0.0.0.0', '' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( count( Privacy::USER_EVENTS ), $erased );
	}

	public function test_user_events_are_not_in_the_grant_lists() {
		$grant_lists = array_merge( Privacy::ADMIN_EVENTS, Privacy::AGENT_EVENTS, Privacy::CORE_EVENTS );
		$this->assertSame( array(), array_values( array_intersect( Privacy::USER_EVENTS, $grant_lists ) ) );
	}

	public function test_policy_text_covers_two_step_data_and_the_device_cookie() {
		$text = Privacy::policy_text();

		$this->assertStringContainsString( 'happyaccess_dev', $text );
		$this->assertStringContainsString( 'one year', $text );
		$this->assertStringContainsString( '20', $text );
		$this->assertStringContainsString( 'last seen', $text );
		$this->assertStringContainsString( 'encrypted', $text );
		$this->assertStringContainsString( 'backup codes', $text );
		$this->assertStringContainsString( 'two-step login', $text );
	}

	/**
	 * Gives the test user the app and email methods, a set of backup codes
	 * and two known devices.
	 *
	 * @return array The plain backup codes, which must never be exported.
	 */
	private function set_up_two_step() {
		UserState::enable_app( $this->user, Totp::new_secret() );
		UserState::enable_email( $this->user );
		$codes = BackupCodes::generate( $this->user );
		update_user_meta(
			$this->user,
			'_happyaccess_devices',
			array(
				array(
					'hash' => 'device-hash-one',
					'seen' => 1790000000,
				),
				array(
					'hash' => 'device-hash-two',
					'seen' => 1790003600,
				),
			)
		);
		return $codes;
	}

	/**
	 * Every exported value of one group.
	 *
	 * @param array  $result   Exporter result.
	 * @param string $group_id Group id.
	 * @return array name => list of values.
	 */
	private function group_values( array $result, $group_id ) {
		$values = array();
		foreach ( $result['data'] as $item ) {
			if ( $group_id !== $item['group_id'] ) {
				continue;
			}
			foreach ( $item['data'] as $field ) {
				$values[ $field['name'] ][] = $field['value'];
			}
		}
		return $values;
	}

	public function test_export_has_a_two_step_group_with_methods_codes_left_and_device_times() {
		$codes = $this->set_up_two_step();
		BackupCodes::use_code( $this->user, $codes[0] );

		$result = Privacy::export( 'me@example.org', 1 );
		$groups = array_unique( wp_list_pluck( $result['data'], 'group_id' ) );
		$this->assertContains( 'happyaccess_twostep', $groups );
		foreach ( $result['data'] as $item ) {
			if ( 'happyaccess_twostep' === $item['group_id'] ) {
				$this->assertSame( 'Two-step login', $item['group_label'] );
			}
		}

		$values = $this->group_values( $result, 'happyaccess_twostep' );
		$this->assertSame( array( 'Authenticator app, Email codes' ), $values['Methods turned on'] );
		$this->assertSame( array( '9' ), $values['Backup codes left'] );
		$this->assertSame( array( '2026-09-21 14:13:20', '2026-09-21 15:13:20' ), $values['Last seen (UTC)'] );

		$flat = wp_json_encode( $result );
		foreach ( $codes as $code ) {
			$this->assertStringNotContainsString( $code, $flat, 'A backup code is never exported.' );
		}
		$this->assertStringNotContainsString( 'device-hash-one', $flat, 'A device hash is never exported.' );
		$this->assertStringNotContainsString( 'device-hash-two', $flat );
		$totp = get_user_meta( $this->user, '_happyaccess_totp', true );
		$this->assertStringNotContainsString( $totp['secret'], $flat, 'The sealed app secret is never exported.' );

		$second = Privacy::export( 'me@example.org', 2 );
		$this->assertSame( array(), $this->group_values( $second, 'happyaccess_twostep' ), 'The group is on the first page only.' );
	}

	public function test_export_with_no_two_step_method_says_none() {
		update_user_meta(
			$this->user,
			'_happyaccess_devices',
			array(
				array(
					'hash' => 'device-hash-one',
					'seen' => 1790000000,
				),
			)
		);

		$values = $this->group_values( Privacy::export( 'me@example.org', 1 ), 'happyaccess_twostep' );
		$this->assertSame( array( 'None' ), $values['Methods turned on'] );
		$this->assertSame( array( '0' ), $values['Backup codes left'] );
		$this->assertSame( array( '2026-09-21 14:13:20' ), $values['Last seen (UTC)'] );
	}

	public function test_export_has_no_two_step_group_without_two_step_data() {
		$result = Privacy::export( 'me@example.org', 1 );
		$this->assertNotContains( 'happyaccess_twostep', wp_list_pluck( $result['data'], 'group_id' ) );
	}

	public function test_erase_removes_known_devices_and_keeps_two_step_settings() {
		$this->set_up_two_step();
		$other = self::factory()->user->create( array( 'user_email' => 'other@example.org' ) );
		update_user_meta( $other, '_happyaccess_devices', array( array( 'hash' => 'theirs', 'seen' => 1790000000 ) ) );

		$result = Privacy::erase( 'me@example.org', 1 );

		$this->assertFalse( metadata_exists( 'user', $this->user, '_happyaccess_devices' ) );
		$this->assertTrue( metadata_exists( 'user', $other, '_happyaccess_devices' ), 'Only this user\'s devices go.' );
		$this->assertTrue( UserState::app_enabled( $this->user ) );
		$this->assertTrue( UserState::email_enabled( $this->user ) );
		$this->assertSame( 10, BackupCodes::remaining( $this->user ) );
		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertContains( 'Two-step login settings were kept, because they protect the account.', $result['messages'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_erase_with_devices_only_removes_them_without_the_kept_message() {
		$solo = self::factory()->user->create( array( 'user_email' => 'solo@example.org' ) );
		update_user_meta( $solo, '_happyaccess_devices', array( array( 'hash' => 'mine', 'seen' => 1790000000 ) ) );

		$result = Privacy::erase( 'solo@example.org', 1 );

		$this->assertFalse( metadata_exists( 'user', $solo, '_happyaccess_devices' ) );
		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertNotContains( 'Two-step login settings were kept, because they protect the account.', $result['messages'] );
	}
}
