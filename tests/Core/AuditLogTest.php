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
		AuditLog::add(
			'plugin_upgraded',
			array(
				'feature' => 'core',
				'user_id' => 0,
			)
		);

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
		$page = AuditLog::query(
			array(
				'page'     => 2,
				'per_page' => 25,
			)
		);
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

	public function test_long_multibyte_user_agent_is_cut_on_a_character_boundary() {
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'a', 254 ) . 'é';

		$id = AuditLog::add( 'login', array( 'user_id' => 1 ) );

		unset( $_SERVER['HTTP_USER_AGENT'] );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( str_repeat( 'a', 254 ), AuditLog::query()['items'][0]['user_agent'] );
	}

	public function test_over_long_keys_still_insert() {
		$id = AuditLog::add(
			str_repeat( 'e', 80 ),
			array(
				'feature' => str_repeat( 'f', 40 ),
				'user_id' => 1,
			)
		);

		$this->assertGreaterThan( 0, $id );
		$item = AuditLog::query()['items'][0];
		$this->assertSame( str_repeat( 'e', 50 ), $item['event_type'] );
		$this->assertSame( str_repeat( 'f', 20 ), $item['feature'] );
	}

	public function test_a_given_user_id_does_not_resolve_the_current_user() {
		$resolved = 0;
		$count    = static function ( $user_id ) use ( &$resolved ) {
			++$resolved;
			return $user_id;
		};
		add_filter( 'determine_current_user', $count, 1 );

		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Force a fresh user lookup.
		AuditLog::add( 'login', array( 'user_id' => 7 ) );
		$this->assertSame( 0, $resolved, 'A given user_id must not resolve the current user.' );

		$GLOBALS['current_user'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Force a fresh user lookup.
		AuditLog::add( 'login' );
		$this->assertSame( 1, $resolved, 'Control: without a user_id the current user is resolved.' );

		remove_filter( 'determine_current_user', $count, 1 );
	}

	public function test_search_matches_the_summary_and_treats_wildcards_literally() {
		AuditLog::add( 'settings_saved', array( 'summary' => 'Changed shipping zones' ) );
		AuditLog::add( 'settings_saved', array( 'summary' => 'Changed tax rates' ) );
		AuditLog::add( 'settings_saved', array( 'summary' => 'Discount 50%_off set' ) );
		AuditLog::add( 'settings_saved', array( 'summary' => 'Discount 500 set' ) );

		$result = AuditLog::query( array( 'search' => 'shipping' ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'Changed shipping zones', $result['items'][0]['summary'] );

		$literal = AuditLog::query( array( 'search' => '50%_' ) );
		$this->assertSame( 1, $literal['total'] );
		$this->assertSame( 'Discount 50%_off set', $literal['items'][0]['summary'] );

		$this->assertSame( 4, AuditLog::query( array( 'search' => '' ) )['total'] );
	}

	public function test_search_is_cut_to_100_characters() {
		AuditLog::add( 'settings_saved', array( 'summary' => str_repeat( 'a', 100 ) ) );
		$this->assertSame( 1, AuditLog::query( array( 'search' => str_repeat( 'a', 100 ) . 'zzz' ) )['total'] );
	}

	public function test_features_filter_matches_any_listed_feature() {
		AuditLog::add( 'login_success', array( 'feature' => 'support' ) );
		AuditLog::add( 'plugin_upgraded', array( 'feature' => 'core' ) );
		AuditLog::add( 'other', array( 'feature' => 'extra' ) );

		$this->assertSame( 2, AuditLog::query( array( 'features' => array( 'support', 'core' ) ) )['total'] );
		$this->assertSame( 1, AuditLog::query( array( 'features' => array( 'extra' ) ) )['total'] );
		$this->assertSame( 3, AuditLog::query( array( 'features' => array() ) )['total'] );
		$this->assertCount( 0, AuditLog::query( array( 'features' => array( '!!' ) ) )['items'] );
	}

	public function test_event_in_filter_matches_any_listed_event() {
		AuditLog::add( 'grant_created' );
		AuditLog::add( 'emergency_lock' );
		AuditLog::add( 'login_success' );

		$this->assertSame( 2, AuditLog::query( array( 'event_in' => array( 'grant_created', 'emergency_lock' ) ) )['total'] );
		$this->assertSame( 3, AuditLog::query( array( 'event_in' => array() ) )['total'] );
		$this->assertSame( 0, AuditLog::query( array( 'event_in' => array( '!!' ) ) )['total'] );
	}

	public function test_summary_is_cut_to_500_characters() {
		$id = AuditLog::add( 'login', array( 'user_id' => 1, 'summary' => str_repeat( "\u{00e9}", 600 ) ) );

		$row = AuditLog::query()['items'][0];
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 500, mb_strlen( $row['summary'], 'UTF-8' ) );
		$this->assertSame( str_repeat( "\u{00e9}", 500 ), $row['summary'] );
	}

	public function test_a_short_summary_is_kept_whole() {
		AuditLog::add( 'login', array( 'user_id' => 1, 'summary' => str_repeat( 'a', 500 ) ) );
		$this->assertSame( str_repeat( 'a', 500 ), AuditLog::query()['items'][0]['summary'] );
	}

	public function test_meta_keys_that_can_hold_a_secret_are_redacted() {
		global $wpdb;
		AuditLog::add(
			'login',
			array(
				'user_id' => 1,
				'meta'    => array(
					'code'   => '123456',
					'Key'    => 'link-key-value',
					'TOKEN'  => 'token-value',
					'secret' => 'secret-value',
					'method' => 'link',
					'nested' => array(
						'Code' => '654321',
						'ok'   => 'fine',
						'deep' => array( 'token' => 'deep-token' ),
					),
					'list'   => array( 'one', 'two' ),
				),
			)
		);

		$meta = AuditLog::query()['items'][0]['meta'];
		$this->assertSame( '[redacted]', $meta['code'] );
		$this->assertSame( '[redacted]', $meta['Key'] );
		$this->assertSame( '[redacted]', $meta['TOKEN'] );
		$this->assertSame( '[redacted]', $meta['secret'] );
		$this->assertSame( 'link', $meta['method'] );
		$this->assertSame( '[redacted]', $meta['nested']['Code'] );
		$this->assertSame( 'fine', $meta['nested']['ok'] );
		$this->assertSame( '[redacted]', $meta['nested']['deep']['token'] );
		$this->assertSame( array( 'one', 'two' ), $meta['list'] );

		$stored = (string) $wpdb->get_var( 'SELECT metadata FROM ' . Installer::table( 'logs' ) . ' ORDER BY id DESC LIMIT 1' );
		foreach ( array( '123456', 'link-key-value', 'token-value', 'secret-value', '654321', 'deep-token' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $stored );
		}
	}

	public function test_a_redacted_array_value_is_replaced_whole() {
		AuditLog::add( 'login', array( 'user_id' => 1, 'meta' => array( 'secret' => array( 'a' => 'b' ) ) ) );
		$this->assertSame( '[redacted]', AuditLog::query()['items'][0]['meta']['secret'] );
	}

	public function test_a_keys_list_of_setting_names_is_kept() {
		$names = array( 'security.recaptcha_secret_key', 'privacy.retention_days' );
		AuditLog::add( 'settings_changed', array( 'feature' => 'core', 'user_id' => 1, 'meta' => array( 'keys' => $names ) ) );

		$this->assertSame( $names, AuditLog::query()['items'][0]['meta']['keys'] );
	}

	public function test_since_and_until_take_utc_datetimes() {
		global $wpdb;
		$table = Installer::table( 'logs' );
		foreach ( array( '2026-05-01 10:00:00', '2026-05-02 10:00:00', '2026-05-03 10:00:00' ) as $when ) {
			AuditLog::add( 'login', array( 'user_id' => 1 ) );
			$wpdb->update( $table, array( 'created_at' => $when ), array( 'id' => $wpdb->insert_id ) );
		}

		$this->assertSame( 2, AuditLog::query( array( 'since' => '2026-05-02 10:00:00' ) )['total'] );
		$this->assertSame( 2, AuditLog::query( array( 'until' => '2026-05-02 10:00:00' ) )['total'] );
		$this->assertSame( 1, AuditLog::query( array( 'since' => '2026-05-02 00:00:00', 'until' => '2026-05-02 23:59:59' ) )['total'] );
		$this->assertCount( 2, AuditLog::rows( array( 'since' => '2026-05-02 10:00:00' ), 10 ) );
	}

	public function provide_bad_dates() {
		return array(
			'date only'       => array( '2026-05-02' ),
			'iso with T'      => array( '2026-05-02T10:00:00' ),
			'impossible hour' => array( '2026-05-02 25:00:00' ),
			'impossible day'  => array( '2026-02-31 10:00:00' ),
			'trailing text'   => array( '2026-05-02 10:00:00 junk' ),
			'sql'             => array( "2026-05-02 10:00:00' OR '1'='1" ),
			'word'            => array( 'yesterday' ),
			'array'           => array( array( '2026-05-02 10:00:00' ) ),
		);
	}

	/**
	 * A bound that isn't a UTC datetime matches nothing, so a bad filter can't widen a read.
	 *
	 * @dataProvider provide_bad_dates
	 */
	public function test_a_bad_since_or_until_matches_nothing( $bad ) {
		AuditLog::add( 'login', array( 'user_id' => 1 ) );

		$this->assertSame( 0, AuditLog::query( array( 'since' => $bad ) )['total'] );
		$this->assertSame( 0, AuditLog::query( array( 'until' => $bad ) )['total'] );
		$this->assertSame( array(), AuditLog::rows( array( 'since' => $bad ), 10 ) );
	}

	public function test_user_id_zero_filters_when_passed_explicitly() {
		AuditLog::add( 'cron_cleanup', array( 'user_id' => 0 ) );
		AuditLog::add( 'login', array( 'user_id' => 5 ) );

		$this->assertSame( 2, AuditLog::query()['total'] );
		$this->assertSame( 2, AuditLog::query( array( 'user_id' => null ) )['total'] );
		$result = AuditLog::query( array( 'user_id' => 0 ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'cron_cleanup', $result['items'][0]['event_type'] );
		$this->assertCount( 1, AuditLog::rows( array( 'user_id' => 0 ), 10 ) );
		$this->assertSame( 1, AuditLog::query( array( 'user_id' => 5 ) )['total'] );
	}

	public function test_token_id_zero_filters_when_passed_explicitly() {
		AuditLog::add( 'plugin_upgraded', array( 'user_id' => 1, 'token_id' => 0 ) );
		AuditLog::add( 'login', array( 'user_id' => 1, 'token_id' => 4 ) );

		$this->assertSame( 2, AuditLog::query()['total'] );
		$result = AuditLog::query( array( 'token_id' => 0 ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'plugin_upgraded', $result['items'][0]['event_type'] );
		$this->assertSame( 1, AuditLog::query( array( 'token_id' => 4 ) )['total'] );
	}
}
