<?php
/**
 * Activity route tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Privacy;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Rest\Routes;

require_once __DIR__ . '/RestTestCase.php';

class ActivityControllerTest extends RestTestCase {

	/**
	 * Every activity route, with params that pass validation so only the
	 * permission check decides.
	 *
	 * @return array
	 */
	public function routes_provider() {
		return array(
			'list'    => array( '/activity', array() ),
			'summary' => array( '/activity/summary', array( 'token_id' => 1 ) ),
			'export'  => array( '/activity/export', array() ),
		);
	}

	/**
	 * @dataProvider routes_provider
	 */
	public function test_logged_out_gets_401( $path, $params ) {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get( $path, $params )->get_status() );
	}

	/**
	 * @dataProvider routes_provider
	 */
	public function test_protected_temp_user_gets_403( $path, $params ) {
		$this->as_temp_user();
		$this->assertSame( 403, $this->get( $path, $params )->get_status() );
	}

	/**
	 * @dataProvider routes_provider
	 */
	public function test_full_temp_user_gets_403( $path, $params ) {
		$this->as_temp_user(
			array(
				'level'        => 'full',
				'confirm_full' => true,
			)
		);
		$this->assertSame( 403, $this->get( $path, $params )->get_status() );
	}

	public function test_list_filters_by_feature_and_search() {
		AuditLog::add(
			'login_success',
			array(
				'feature' => 'support',
				'summary' => 'Signed in by link',
			)
		);
		AuditLog::add(
			'post_updated',
			array(
				'feature' => 'support',
				'summary' => 'Updated post: Pricing',
			)
		);
		AuditLog::add(
			'login_success',
			array(
				'feature' => 'passwordless',
				'summary' => 'Passwordless login',
			)
		);

		$data = $this->get( '/activity', array( 'feature' => 'support' ) )->get_data();
		$this->assertSame( 2, $data['total'] );
		$this->assertSame( array( 'support' ), array_values( array_unique( wp_list_pluck( $data['items'], 'feature' ) ) ) );

		$data = $this->get( '/activity', array( 'search' => 'Pricing' ) )->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'post_updated', $data['items'][0]['event'] );
	}

	public function test_admin_feature_maps_to_admin_events() {
		AuditLog::add( 'grant_created', array( 'feature' => 'support' ) );
		AuditLog::add( 'emergency_lock', array( 'feature' => 'support' ) );
		AuditLog::add( 'login_success', array( 'feature' => 'support' ) );
		AuditLog::add( 'plugin_upgraded', array( 'feature' => 'core' ) );

		$data = $this->get( '/activity', array( 'feature' => 'admin' ) )->get_data();

		$this->assertSame( 2, $data['total'] );
		foreach ( $data['items'] as $item ) {
			$this->assertContains( $item['event'], Privacy::ADMIN_EVENTS );
		}
	}

	public function test_pagination_returns_second_row_and_total() {
		$first  = AuditLog::add( 'login_success' );
		$second = AuditLog::add( 'post_updated' );
		AuditLog::add( 'post_created' );

		$data = $this->get(
			'/activity',
			array(
				'per_page' => 1,
				'page'     => 2,
			)
		)->get_data();

		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 2, $data['page'] );
		$this->assertSame( 1, $data['per_page'] );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( $second, $data['items'][0]['id'] );
		$this->assertNotSame( $first, $data['items'][0]['id'] );
	}

	public function test_per_page_out_of_range_is_rejected() {
		$this->assertSame( 400, $this->get( '/activity', array( 'per_page' => 101 ) )->get_status() );
		$this->assertSame( 400, $this->get( '/activity', array( 'page' => 0 ) )->get_status() );
		$this->assertSame( 400, $this->get( '/activity', array( 'feature' => 'nope' ) )->get_status() );
		$this->assertSame( 400, $this->get( '/activity', array( 'since' => '06/10/2026' ) )->get_status() );
	}

	public function test_since_and_until_use_the_site_timezone() {
		update_option( 'timezone_string', 'Asia/Dhaka' );

		// 2026-10-05 19:00 UTC is 2026-10-06 01:00 in Dhaka (UTC+6).
		Clock::freeze( gmmktime( 19, 0, 0, 10, 5, 2026 ) );
		AuditLog::add( 'login_success', array( 'summary' => 'inside' ) );
		// 2026-10-05 17:59:59 UTC is still 2026-10-05 23:59:59 in Dhaka.
		Clock::freeze( gmmktime( 17, 59, 59, 10, 5, 2026 ) );
		AuditLog::add( 'login_success', array( 'summary' => 'day before' ) );
		// 2026-10-06 17:59:59 UTC is the last second of 2026-10-06 in Dhaka.
		Clock::freeze( gmmktime( 17, 59, 59, 10, 6, 2026 ) );
		AuditLog::add( 'login_success', array( 'summary' => 'last second' ) );
		Clock::freeze( gmmktime( 18, 0, 0, 10, 6, 2026 ) );
		AuditLog::add( 'login_success', array( 'summary' => 'day after' ) );

		$data = $this->get(
			'/activity',
			array(
				'since' => '2026-10-06',
				'until' => '2026-10-06',
			)
		)->get_data();

		$this->assertSame( array( 'last second', 'inside' ), wp_list_pluck( $data['items'], 'summary' ) );
	}

	public function test_item_shape_names_actor_and_pass() {
		$grant = Grants::create( array( 'label' => 'Acme support' ) );
		$other = self::factory()->user->create( array( 'display_name' => 'Pat Agent' ) );
		AuditLog::add(
			'login_success',
			array(
				'token_id' => $grant['id'],
				'user_id'  => $other,
				'summary'  => 'Signed in',
			)
		);
		AuditLog::add(
			'temp_user_deleted',
			array(
				'token_id' => $grant['id'],
				'user_id'  => 424242,
				'meta'     => array( 'user_login' => 'gone-user' ),
			)
		);
		AuditLog::add(
			'plugin_upgraded',
			array(
				'feature' => 'core',
				'user_id' => 424243,
			)
		);

		$items = $this->get( '/activity' )->get_data()['items'];

		$this->assertSame( 424243, $items[0]['actor']['id'] );
		$this->assertSame( '', $items[0]['actor']['name'] );
		$this->assertSame( '', $items[0]['pass'] );
		$this->assertSame( 'gone-user', $items[1]['actor']['name'] );
		$this->assertSame( 424242, $items[1]['actor']['id'] );
		$this->assertSame( 'Acme support', $items[1]['pass'] );
		$this->assertSame( 'Pat Agent', $items[2]['actor']['name'] );
		$this->assertSame( $other, $items[2]['actor']['id'] );
		$this->assertSame( 'login_success', $items[2]['event'] );
		$this->assertSame( 'Signed in', $items[2]['summary'] );
		$this->assertSame( $grant['id'], $items[2]['token_id'] );
		$this->assertSame( 1790000000, $items[2]['time'] );
		$this->assertNotSame( '', $items[2]['event_label'] );
		$this->assertArrayHasKey( 'ip', $items[2] );
	}

	public function test_each_item_has_a_kind_from_the_event_lists() {
		AuditLog::add( 'some_unlisted_event', array( 'feature' => 'support' ) );
		AuditLog::add( 'plugin_upgraded', array( 'feature' => 'core' ) );
		AuditLog::add( 'settings_saved', array( 'feature' => 'support' ) );
		AuditLog::add( 'grant_created', array( 'feature' => 'support' ) );

		$items = $this->get( '/activity' )->get_data()['items'];

		$this->assertSame(
			array(
				'grant_created'       => 'admin',
				'settings_saved'      => 'agent',
				'plugin_upgraded'     => 'core',
				'some_unlisted_event' => 'other',
			),
			array_column( $items, 'kind', 'event' )
		);
	}

	public function test_summary_counts_match_seeded_rows() {
		$grant = Grants::create( array( 'label' => 'Acme' ) )['id'];
		$other = Grants::create( array( 'label' => 'Other' ) )['id'];
		$ip    = 'REMOTE_ADDR';

		$seed = array(
			array( 'login_success', $grant, '203.0.113.1' ),
			array( 'login_success', $grant, '203.0.113.2' ),
			array( 'login_failed', $grant, '203.0.113.3' ),
			array( 'access_blocked', $grant, '203.0.113.3' ),
			array( 'post_updated', $grant, '203.0.113.1' ),
			array( 'plugin_activated', $grant, '203.0.113.2' ),
			array( 'order_status_changed', $grant, '203.0.113.2' ),
			array( 'login_success', $other, '198.51.100.9' ),
			array( 'post_updated', $other, '198.51.100.9' ),
		);
		foreach ( $seed as $row ) {
			$_SERVER[ $ip ] = $row[2];
			AuditLog::add( $row[0], array( 'token_id' => $row[1] ) );
		}

		$data = $this->get( '/activity/summary', array( 'token_id' => $grant ) )->get_data();

		$this->assertSame( 2, $data['logins'] );
		$this->assertSame( 3, $data['changes'] );
		$this->assertNull( $data['minutes'] );
		$this->assertEqualsCanonicalizing( array( '203.0.113.1', '203.0.113.2', '203.0.113.3' ), $data['ips'] );
	}

	public function test_user_id_zero_filters_only_when_the_request_sends_it() {
		AuditLog::add( 'plugin_upgraded', array( 'feature' => 'core', 'user_id' => 0 ) );
		AuditLog::add( 'login_success', array( 'user_id' => 5 ) );
		$total = static function ( $response ) {
			return $response->get_data()['total'];
		};

		$this->assertSame( 2, $total( $this->get( '/activity' ) ) );
		$this->assertSame( 1, $total( $this->get( '/activity', array( 'user_id' => 0 ) ) ) );
		$this->assertSame( 1, $total( $this->get( '/activity', array( 'user_id' => 5 ) ) ) );
		$this->assertSame( 2, $total( $this->get( '/activity', array( 'token_id' => 0 ) ) ) );
	}

	public function test_summary_lists_at_most_five_ips() {
		for ( $i = 1; $i <= 7; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.' . $i;
			AuditLog::add( 'login_success', array( 'token_id' => 9 ) );
		}
		$this->assertCount( 5, $this->get( '/activity/summary', array( 'token_id' => 9 ) )->get_data()['ips'] );
	}

	public function test_summary_requires_a_token_id() {
		$this->assertSame( 400, $this->get( '/activity/summary' )->get_status() );
	}

	public function test_export_has_header_and_guards_every_cell() {
		$grant = Grants::create( array( 'label' => '=cmd|calc' ) );
		$agent = self::factory()->user->create( array( 'display_name' => '@evil' ) );
		AuditLog::add(
			'post_updated',
			array(
				'token_id' => $grant['id'],
				'user_id'  => $agent,
				'summary'  => '=HYPERLINK("x")',
			)
		);

		$data = $this->get( '/activity/export' )->get_data();
		$rows = $this->parse_csv( $data['csv'] );

		$this->assertSame( 'happyaccess-activity-' . wp_date( 'Y-m-d', 1790000000 ) . '.csv', $data['filename'] );
		$this->assertSame( array( 'time', 'feature', 'event', 'summary', 'user', 'pass', 'ip' ), $rows[0] );
		$this->assertCount( 3, $rows, 'Header, the new row and the grant_created row.' );
		$this->assertSame( wp_date( 'Y-m-d H:i:s', 1790000000 ), $rows[1][0] );
		$this->assertSame( 'support', $rows[1][1] );
		$this->assertSame( 'post_updated', $rows[1][2] );
		$this->assertSame( '\'=HYPERLINK("x")', $rows[1][3] );
		$this->assertSame( "'@evil", $rows[1][4] );
		$this->assertSame( "'=cmd|calc", $rows[1][5] );
	}

	public function test_export_guards_every_leading_character() {
		global $wpdb;
		// Written straight to the table: AuditLog::add() would trim a leading tab or return.
		foreach ( array( '=1+1', '+1', '-1', '@sum', "\tcell", "\rcell", 'plain text' ) as $summary ) {
			$wpdb->insert(
				Installer::table( 'logs' ),
				array(
					'feature'    => 'support',
					'event_type' => 'post_updated',
					'summary'    => $summary,
					'created_at' => Clock::mysql(),
				)
			);
		}

		$rows = $this->parse_csv( $this->get( '/activity/export' )->get_data()['csv'] );
		array_shift( $rows );

		$this->assertSame( array( 'plain text', "'\rcell", "'\tcell", "'@sum", "'-1", "'+1", "'=1+1" ), array_column( $rows, 3 ) );
	}

	public function test_export_is_capped_at_5000_rows() {
		global $wpdb;
		$table = Installer::table( 'logs' );
		$now   = Clock::mysql();
		for ( $batch = 0; $batch < 6; $batch++ ) {
			$values = array();
			for ( $i = 0; $i < 1000 && ( $batch * 1000 + $i ) < 5001; $i++ ) {
				$values[] = $wpdb->prepare( '( %d, %s, %s, %d, %s, %s, %s, %s )', 0, 'support', 'post_updated', 0, '', '', 'row', $now );
			}
			if ( $values ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test seed, values prepared above.
				$wpdb->query( "INSERT INTO {$table} ( token_id, feature, event_type, user_id, ip_address, user_agent, summary, created_at ) VALUES " . implode( ', ', $values ) );
			}
		}
		$this->assertSame( 5001, AuditLog::query()['total'] );

		$rows = $this->parse_csv( $this->get( '/activity/export' )->get_data()['csv'] );

		$this->assertCount( 5001, $rows, 'One header row plus 5000 data rows.' );
	}

	public function test_export_applies_the_same_filters() {
		AuditLog::add( 'login_success', array( 'feature' => 'passwordless' ) );
		AuditLog::add( 'post_updated', array( 'feature' => 'support' ) );

		$rows = $this->parse_csv( $this->get( '/activity/export', array( 'feature' => 'passwordless' ) )->get_data()['csv'] );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'login_success', $rows[1][2] );
	}

	/**
	 * Runs a GET request with query params.
	 *
	 * @param string $path   Path after the namespace.
	 * @param array  $params Query params.
	 * @return WP_REST_Response
	 */
	private function get( string $path, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . Routes::NS . $path );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	/**
	 * Parses a CSV string into rows.
	 *
	 * @param string $csv CSV text.
	 * @return array
	 */
	private function parse_csv( string $csv ): array {
		$handle = fopen( 'php://temp', 'w+' );
		fwrite( $handle, $csv );
		rewind( $handle );
		$rows = array();
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard fgetcsv loop.
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			$rows[] = $row;
		}
		fclose( $handle );
		return $rows;
	}

	/**
	 * @dataProvider routes_provider
	 */
	public function test_custom_temp_user_gets_403( $path, $params ) {
		$this->as_temp_user(
			array(
				'level'        => 'custom',
				'caps'         => array( 'manage_options', 'list_users' ),
				'confirm_full' => true,
			)
		);
		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertSame( 403, $this->get( $path, $params )->get_status() );
	}

	public function test_since_and_until_cover_a_whole_dst_change_day() {
		update_option( 'timezone_string', 'America/New_York' );

		// 2026-03-08 is 23 hours long in New York: it starts at 05:00 UTC and ends at 03:59:59 UTC the next day.
		Clock::freeze( gmmktime( 4, 59, 59, 3, 8, 2026 ) );
		AuditLog::add( 'login_success', array( 'summary' => 'day before' ) );
		Clock::freeze( gmmktime( 5, 0, 0, 3, 8, 2026 ) );
		AuditLog::add( 'login_success', array( 'summary' => 'first second' ) );
		Clock::freeze( gmmktime( 3, 59, 59, 3, 9, 2026 ) );
		AuditLog::add( 'login_success', array( 'summary' => 'last second' ) );
		Clock::freeze( gmmktime( 4, 0, 0, 3, 9, 2026 ) );
		AuditLog::add( 'login_success', array( 'summary' => 'day after' ) );

		$data = $this->get(
			'/activity',
			array(
				'since' => '2026-03-08',
				'until' => '2026-03-08',
			)
		)->get_data();

		$this->assertSame( array( 'last second', 'first second' ), wp_list_pluck( $data['items'], 'summary' ) );
	}

	public function test_export_guards_formulas_behind_leading_spaces_and_tabs() {
		global $wpdb;
		foreach ( array( ' =1+1', "\t\t+1", " \t@sum", '  plain', ' -' ) as $summary ) {
			$wpdb->insert(
				Installer::table( 'logs' ),
				array(
					'feature'    => 'support',
					'event_type' => 'post_updated',
					'summary'    => $summary,
					'created_at' => Clock::mysql(),
				)
			);
		}

		$rows = $this->parse_csv( $this->get( '/activity/export' )->get_data()['csv'] );
		array_shift( $rows );

		$this->assertSame( array( "' -", '  plain', "' \t@sum", "'\t\t+1", "' =1+1" ), array_column( $rows, 3 ) );
	}

	public function test_export_reads_the_log_in_one_query() {
		global $wpdb;
		AuditLog::add( 'post_updated', array( 'summary' => 'one' ) );
		AuditLog::add( 'post_updated', array( 'summary' => 'two' ) );
		$logs  = Installer::table( 'logs' );
		$reads = array();
		$spy   = static function ( $query ) use ( &$reads, $logs ) {
			if ( false !== strpos( $query, $logs ) ) {
				$reads[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $spy );
		$csv = $this->get( '/activity/export' )->get_data()['csv'];
		remove_filter( 'query', $spy );

		$this->assertCount( 3, $this->parse_csv( $csv ) );
		$this->assertCount( 1, $reads, implode( "\n", $reads ) );
		$this->assertStringContainsString( 'LIMIT 5000', $reads[0] );
		$this->assertStringNotContainsString( 'COUNT(', $reads[0] );
	}

	public function test_list_csv_and_privacy_export_read_a_settings_change_the_same_way() {
		// A row as 1.0 stored it: dotted keys in the summary and no feature states.
		AuditLog::add(
			'settings_changed',
			array(
				'feature' => 'core',
				'summary' => 'Changed settings: privacy.anonymize_ip, privacy.retention_days, support.consent_user_id',
				'meta'    => array( 'keys' => array( 'privacy.anonymize_ip', 'privacy.retention_days', 'support.consent_user_id' ) ),
			)
		);
		$expected = 'Changed settings: Keep activity for, Shorten IP addresses in the log';

		$items = $this->get( '/activity', array( 'event' => 'settings_changed' ) )->get_data()['items'];
		$this->assertSame( $expected, $items[0]['summary'] );

		$rows = $this->parse_csv( $this->get( '/activity/export', array( 'event' => 'settings_changed' ) )->get_data()['csv'] );
		$this->assertSame( $expected, $rows[1][3] );

		wp_update_user(
			array(
				'ID'         => $this->owner,
				'user_email' => 'owner@example.org',
			)
		);
		Privacy::register();
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$export    = call_user_func( $exporters['happyaccess']['callback'], 'owner@example.org', 1 );
		$summaries = array();
		foreach ( $export['data'] as $item ) {
			if ( 'happyaccess_logs' === $item['group_id'] ) {
				$summaries[] = wp_list_pluck( $item['data'], 'value', 'name' )['Summary'];
			}
		}
		$this->assertContains( $expected, $summaries );
		$this->assertStringNotContainsString( 'consent', implode( ' ', $summaries ) );
	}

	public function test_list_csv_and_privacy_export_build_a_new_row_in_the_viewers_language() {
		wp_update_user(
			array(
				'ID'         => $this->owner,
				'user_email' => 'owner@example.org',
			)
		);
		$grant = Grants::create( array( 'label' => 'Acme' ) );
		\HappyAccess\Features\SupportAccess\AdminBar::emergency_lock();

		$translate = static function ( $translation, $text, $domain ) {
			$map = array( 'Temporary access granted to %s' => 'Zugang erteilt für %s' );
			return 'happyaccess' === $domain && isset( $map[ $text ] ) ? $map[ $text ] : $translation;
		};
		$plural    = static function ( $translation, $single, $plural, $number, $domain ) {
			return 'happyaccess' === $domain && 'Emergency lock ended %d grant.' === $single ? 'Notsperre beendete %d Zugänge.' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );
		add_filter( 'ngettext', $plural, 10, 5 );
		try {
			$items = $this->get( '/activity', array( 'token_id' => $grant['id'] ) )->get_data()['items'];
			$this->assertSame( 'Zugang erteilt für Acme', wp_list_pluck( $items, 'summary', 'event' )['grant_created'] );

			$lock = $this->get( '/activity', array( 'event' => 'emergency_lock' ) )->get_data()['items'];
			$this->assertSame( 'Notsperre beendete 1 Zugänge.', $lock[0]['summary'] );

			$rows = $this->parse_csv( $this->get( '/activity/export', array( 'event' => 'grant_created' ) )->get_data()['csv'] );
			$this->assertSame( 'Zugang erteilt für Acme', $rows[1][3] );

			Privacy::register();
			$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
			$export    = call_user_func( $exporters['happyaccess']['callback'], 'owner@example.org', 1 );
			$summaries = array();
			foreach ( $export['data'] as $item ) {
				if ( 'happyaccess_logs' === $item['group_id'] ) {
					$summaries[] = wp_list_pluck( $item['data'], 'value', 'name' )['Summary'];
				}
			}
			$this->assertContains( 'Notsperre beendete 1 Zugänge.', $summaries );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
			remove_filter( 'ngettext', $plural, 10 );
		}

		// The stored column keeps the line as it was written.
		$stored = AuditLog::query( array( 'event' => 'grant_created' ) )['items'][0];
		$this->assertSame( 'Temporary access granted to Acme', $stored['summary'] );
	}

	public function test_an_old_row_without_a_text_key_keeps_its_stored_summary() {
		// A row as 1.0 stored it, translated when it was written.
		AuditLog::add(
			'grant_created',
			array(
				'summary' => 'Zugang erteilt für Altkunde',
				'meta'    => array( 'role' => 'administrator' ),
			)
		);

		$items = $this->get( '/activity', array( 'event' => 'grant_created' ) )->get_data()['items'];
		$this->assertSame( 'Zugang erteilt für Altkunde', $items[0]['summary'] );
		$rows = $this->parse_csv( $this->get( '/activity/export', array( 'event' => 'grant_created' ) )->get_data()['csv'] );
		$this->assertSame( 'Zugang erteilt für Altkunde', $rows[1][3] );
	}

	public function test_a_row_without_meta_keys_keeps_its_stored_summary() {
		AuditLog::add(
			'settings_changed',
			array(
				'feature' => 'core',
				'summary' => 'Changed settings: something',
			)
		);

		$items = $this->get( '/activity', array( 'event' => 'settings_changed' ) )->get_data()['items'];
		$this->assertSame( 'Changed settings: something', $items[0]['summary'] );
	}
}
