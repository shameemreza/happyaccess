<?php
/**
 * AdminWatch tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\ActivityTracker;
use HappyAccess\Features\SupportAccess\AdminWatch;
use HappyAccess\Features\SupportAccess\CapabilityGuard;
use HappyAccess\Features\SupportAccess\Feature;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class AdminWatchTest extends WP_UnitTestCase {

	private $owner;
	private $temp;
	private $grant_id;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::register();
		$this->owner = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'owner@example.org' ) );
		wp_set_current_user( $this->owner );
		Grants::flush_cache();
		$made           = Grants::create(
			array(
				'label'        => 'Acme',
				'level'        => 'full',
				'confirm_full' => true,
			)
		);
		$this->grant_id = $made['id'];
		$this->temp     = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		Feature::register();
		// Core's own email change notice would add a second mail to every count.
		add_filter( 'send_email_change_email', '__return_false' );
		add_filter( 'send_password_change_email', '__return_false' );
		AdminWatch::reset();
		reset_phpmailer_instance();
	}

	public function tear_down() {
		AdminWatch::reset();
		ActivityTracker::reset();
		CapabilityGuard::reset();
		remove_role( 'happyaccess_promoter' );
		parent::tear_down();
	}

	/**
	 * Saves a change to one role straight to the role table, as core's add_cap() does.
	 *
	 * @param string $role Role slug.
	 * @param string $cap  Cap to add.
	 * @return void
	 */
	private function add_role_cap( $role, $cap ) {
		global $wpdb;
		$key   = $wpdb->prefix . 'user_roles';
		$table = get_option( $key );
		$table[ $role ]['capabilities'][ $cap ] = true;
		update_option( $key, $table );
	}

	private function custom_temp( array $caps ) {
		wp_set_current_user( $this->owner );
		$made = Grants::create(
			array(
				'label'        => 'Custom',
				'level'        => 'custom',
				'caps'         => $caps,
				'confirm_full' => true,
			)
		);
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		reset_phpmailer_instance();
		return $temp;
	}

	private function sent() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	private function rows( $event ) {
		return AuditLog::query( array( 'event' => $event, 'per_page' => 50 ) )['items'];
	}

	public function test_full_pass_making_an_administrator_logs_and_emails_the_owner() {
		wp_set_current_user( $this->temp );
		$id = wp_insert_user( array( 'user_login' => 'newadmin', 'user_pass' => 'x', 'role' => 'administrator' ) );
		$this->assertIsInt( $id );

		$rows = $this->rows( 'admin_account_created' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Made an administrator account: newadmin', $rows[0]['summary'] );
		$this->assertSame( $this->grant_id, (int) $rows[0]['token_id'] );

		$this->assertCount( 1, $this->sent() );
		$this->assertSame( 'owner@example.org', $this->sent()[0]['to'][0][0] );
		$this->assertStringContainsString( 'newadmin', $this->sent()[0]['body'] );
		$this->assertStringContainsString( 'A support pass made an administrator account', $this->sent()[0]['subject'] );
	}

	public function test_promoting_a_subscriber_logs_one_row() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->temp );
		( new WP_User( $subscriber ) )->set_role( 'administrator' );

		$this->assertCount( 1, $this->rows( 'admin_account_created' ) );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_making_a_subscriber_writes_nothing() {
		wp_set_current_user( $this->temp );
		wp_insert_user( array( 'user_login' => 'plainuser', 'user_pass' => 'x', 'role' => 'subscriber' ) );

		$this->assertCount( 0, $this->rows( 'admin_account_created' ) );
		$this->assertCount( 0, $this->sent() );
	}

	public function test_a_real_administrator_making_an_administrator_writes_nothing() {
		wp_insert_user( array( 'user_login' => 'newadmin', 'user_pass' => 'x', 'role' => 'administrator' ) );

		$this->assertCount( 0, $this->rows( 'admin_account_created' ) );
		$this->assertCount( 0, $this->sent() );
	}

	public function test_changing_another_administrators_email_logs_and_emails() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'old-admin@example.org' ) );
		reset_phpmailer_instance();
		wp_set_current_user( $this->temp );
		wp_update_user( array( 'ID' => $admin, 'user_email' => 'new-admin@example.org' ) );

		$rows = $this->rows( 'admin_account_changed' );
		$this->assertCount( 1, $rows );
		$this->assertStringStartsWith( 'Changed login details for administrator: ', $rows[0]['summary'] );
		$this->assertSame( array( 'email' ), $rows[0]['meta']['fields'] );
		$this->assertSame( $admin, (int) $rows[0]['meta']['user_id'] );

		$this->assertCount( 1, $this->sent() );
		$this->assertSame( 'owner@example.org', $this->sent()[0]['to'][0][0] );
		$this->assertStringContainsString( "changed an administrator's login details", $this->sent()[0]['subject'] );
	}

	public function test_changing_a_password_is_flagged_without_the_value() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		reset_phpmailer_instance();
		wp_set_current_user( $this->temp );
		wp_update_user( array( 'ID' => $admin, 'user_pass' => 'brand-new-secret' ) );

		$rows = $this->rows( 'admin_account_changed' );
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'password' ), $rows[0]['meta']['fields'] );
		$this->assertStringNotContainsString( 'brand-new-secret', wp_json_encode( $rows ) );
		$this->assertStringNotContainsString( 'brand-new-secret', $this->sent()[0]['body'] );
	}

	public function test_changing_a_subscribers_email_writes_nothing() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		reset_phpmailer_instance();
		wp_set_current_user( $this->temp );
		wp_update_user( array( 'ID' => $subscriber, 'user_email' => 'changed@example.org' ) );

		$this->assertCount( 0, $this->rows( 'admin_account_changed' ) );
		$this->assertCount( 0, $this->sent() );
	}

	public function test_a_real_administrator_changing_an_administrator_writes_nothing() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		reset_phpmailer_instance();
		wp_update_user( array( 'ID' => $admin, 'user_email' => 'changed@example.org' ) );

		$this->assertCount( 0, $this->rows( 'admin_account_changed' ) );
		$this->assertCount( 0, $this->sent() );
	}

	public function test_a_user_is_flagged_once_per_request_across_both_events() {
		wp_set_current_user( $this->temp );
		$id = wp_insert_user( array( 'user_login' => 'newadmin', 'user_pass' => 'x', 'role' => 'administrator' ) );
		wp_update_user( array( 'ID' => $id, 'user_email' => 'changed@example.org' ) );

		$this->assertCount( 1, $this->rows( 'admin_account_created' ) );
		$this->assertCount( 0, $this->rows( 'admin_account_changed' ) );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_happyaccess_own_writes_never_alert() {
		wp_set_current_user( $this->temp );
		ActivityTracker::quietly(
			static function () {
				wp_insert_user( array( 'user_login' => 'quietadmin', 'user_pass' => 'x', 'role' => 'administrator' ) );
			}
		);
		\HappyAccess\Core\Internal::run(
			static function () {
				wp_insert_user( array( 'user_login' => 'internaladmin', 'user_pass' => 'x', 'role' => 'administrator' ) );
			}
		);

		$this->assertCount( 0, $this->rows( 'admin_account_created' ) );
		$this->assertCount( 0, $this->sent() );
	}

	public function test_alert_for_the_fallback_owner_goes_to_the_address_before_the_change() {
		$admins = get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1 ) );
		$fallback = $admins[0];
		wp_update_user( array( 'ID' => $fallback->ID, 'user_email' => 'real-owner@example.org' ) );
		$made = Grants::create( array( 'label' => 'Orphan', 'level' => 'full', 'confirm_full' => true, 'created_by' => 0 ) );
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		$this->assertSame( $fallback->ID, Grants::owner_id( Grants::get( $made['id'] ) ) );
		reset_phpmailer_instance();

		wp_set_current_user( $temp );
		wp_update_user( array( 'ID' => $fallback->ID, 'user_email' => 'evil@example.com' ) );

		$this->assertCount( 1, $this->rows( 'admin_account_changed' ) );
		$this->assertCount( 1, $this->sent() );
		$this->assertSame( 'real-owner@example.org', $this->sent()[0]['to'][0][0] );
		foreach ( $this->sent() as $mail ) {
			foreach ( $mail['to'] as $recipient ) {
				$this->assertNotSame( 'evil@example.com', $recipient[0] );
			}
		}
	}

	public function test_emails_are_capped_per_pass_but_every_event_is_logged() {
		wp_set_current_user( $this->temp );
		for ( $i = 1; $i <= 7; $i++ ) {
			wp_insert_user( array( 'user_login' => 'bulkadmin' . $i, 'user_pass' => 'x', 'role' => 'administrator' ) );
		}

		$this->assertCount( 7, $this->rows( 'admin_account_created' ) );
		$this->assertCount( 5, $this->sent() );
		$this->assertStringNotContainsString( 'More changes like this may follow', $this->sent()[3]['body'] );
		$this->assertStringContainsString( 'More changes like this may follow. See the activity log for the full list.', $this->sent()[4]['body'] );
		$this->assertStringContainsString( 'page=happyaccess', $this->sent()[4]['body'] );
	}

	public function test_adding_the_administrator_role_logs_one_row() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->temp );
		( new WP_User( $subscriber ) )->add_role( 'administrator' );

		$this->assertCount( 1, $this->rows( 'admin_account_created' ) );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_adding_another_role_writes_nothing() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->temp );
		( new WP_User( $subscriber ) )->add_role( 'editor' );

		$this->assertCount( 0, $this->rows( 'admin_account_created' ) );
	}

	private function counter() {
		return get_transient( 'happyaccess_admin_alerts_' . $this->grant_id );
	}

	public function test_the_alert_counter_cannot_be_deleted_by_the_pass() {
		wp_set_current_user( $this->temp );
		wp_insert_user( array( 'user_login' => 'firstadmin', 'user_pass' => 'x', 'role' => 'administrator' ) );
		$this->assertSame( 1, $this->counter()['count'] );

		try {
			delete_transient( 'happyaccess_admin_alerts_' . $this->grant_id );
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		$this->assertSame( 1, $this->counter()['count'] );
	}

	public function test_the_alert_counter_cannot_be_overwritten_by_the_pass() {
		wp_set_current_user( $this->temp );
		wp_insert_user( array( 'user_login' => 'firstadmin', 'user_pass' => 'x', 'role' => 'administrator' ) );

		set_transient( 'happyaccess_admin_alerts_' . $this->grant_id, array( 'count' => 5, 'until' => time() + 3600 ), 3600 );
		$this->assertSame( 1, $this->counter()['count'] );

		wp_insert_user( array( 'user_login' => 'secondadmin', 'user_pass' => 'x', 'role' => 'administrator' ) );
		$this->assertCount( 2, $this->sent() );
	}

	public function test_the_alert_counter_cannot_be_pre_filled_by_the_pass() {
		wp_set_current_user( $this->temp );
		$this->assertFalse( $this->counter() );

		try {
			set_transient( 'happyaccess_admin_alerts_' . $this->grant_id, array( 'count' => 5, 'until' => time() + 3600 ), 3600 );
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		$this->assertFalse( $this->counter() );

		wp_insert_user( array( 'user_login' => 'firstadmin', 'user_pass' => 'x', 'role' => 'administrator' ) );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_an_owner_account_change_still_sends_after_the_cap_is_used_up() {
		// The creator's account is protected, so the owner here is the fallback administrator.
		$admins   = get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1 ) );
		$fallback = $admins[0];
		wp_update_user( array( 'ID' => $fallback->ID, 'user_email' => 'real-owner@example.org' ) );
		$made = Grants::create( array( 'label' => 'Orphan', 'level' => 'full', 'confirm_full' => true, 'created_by' => 0 ) );
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		$this->assertSame( $fallback->ID, Grants::owner_id( Grants::get( $made['id'] ) ) );
		reset_phpmailer_instance();

		wp_set_current_user( $temp );
		for ( $i = 1; $i <= 6; $i++ ) {
			wp_insert_user( array( 'user_login' => 'bulkadmin' . $i, 'user_pass' => 'x', 'role' => 'administrator' ) );
		}
		$this->assertCount( 5, $this->sent() );

		wp_update_user( array( 'ID' => $fallback->ID, 'user_email' => 'evil@example.com' ) );

		$this->assertCount( 6, $this->sent() );
		$this->assertSame( 'real-owner@example.org', $this->sent()[5]['to'][0][0] );
		$this->assertSame( 5, get_transient( 'happyaccess_admin_alerts_' . $made['id'] )['count'] );
	}

	public function test_a_failed_send_is_not_counted() {
		add_filter( 'pre_wp_mail', '__return_false' );
		wp_set_current_user( $this->temp );
		wp_insert_user( array( 'user_login' => 'firstadmin', 'user_pass' => 'x', 'role' => 'administrator' ) );
		remove_filter( 'pre_wp_mail', '__return_false' );

		$this->assertCount( 1, $this->rows( 'admin_account_created' ) );
		$this->assertFalse( $this->counter() );
	}

	public function test_a_user_with_promote_users_but_not_manage_options_is_flagged() {
		add_role( 'happyaccess_promoter', 'Promoter', array( 'read' => true, 'list_users' => true, 'promote_users' => true ) );
		wp_set_current_user( $this->temp );
		wp_insert_user( array( 'user_login' => 'promoter', 'user_pass' => 'x', 'role' => 'happyaccess_promoter' ) );

		$this->assertCount( 1, $this->rows( 'admin_account_created' ) );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_adding_a_role_with_edit_users_is_flagged() {
		add_role( 'happyaccess_promoter', 'Promoter', array( 'read' => true, 'edit_users' => true ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->temp );
		( new WP_User( $subscriber ) )->add_role( 'happyaccess_promoter' );

		$this->assertCount( 1, $this->rows( 'admin_account_created' ) );
	}

	public function test_changing_a_promote_users_holders_email_is_flagged() {
		add_role( 'happyaccess_promoter', 'Promoter', array( 'read' => true, 'promote_users' => true ) );
		$promoter = self::factory()->user->create( array( 'role' => 'happyaccess_promoter' ) );
		reset_phpmailer_instance();
		wp_set_current_user( $this->temp );
		wp_update_user( array( 'ID' => $promoter, 'user_email' => 'moved@example.org' ) );

		$this->assertCount( 1, $this->rows( 'admin_account_changed' ) );
	}

	public function test_full_pass_giving_a_role_manage_options_logs_and_emails() {
		wp_set_current_user( $this->temp );
		$this->add_role_cap( 'subscriber', 'manage_options' );

		$rows = $this->rows( 'admin_role_granted' );
		$this->assertCount( 1, $rows );
		$this->assertSame( $this->grant_id, (int) $rows[0]['token_id'] );
		$this->assertSame( 'subscriber', $rows[0]['meta']['role'] );
		$this->assertSame( array( 'manage_options' ), $rows[0]['meta']['caps'] );
		$this->assertCount( 1, $this->sent() );
		$this->assertSame( 'owner@example.org', $this->sent()[0]['to'][0][0] );
		$this->assertStringContainsString( 'admin-level', $this->sent()[0]['subject'] );
		$this->assertStringContainsString( 'Subscriber', $this->sent()[0]['body'] );
	}

	public function test_a_role_gaining_admin_caps_is_flagged_once_per_request() {
		wp_set_current_user( $this->temp );
		$this->add_role_cap( 'subscriber', 'promote_users' );
		$this->add_role_cap( 'subscriber', 'edit_users' );

		$this->assertCount( 1, $this->rows( 'admin_role_granted' ) );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_a_role_gaining_other_caps_writes_nothing() {
		wp_set_current_user( $this->temp );
		$this->add_role_cap( 'subscriber', 'edit_posts' );
		$this->add_role_cap( 'administrator', 'manage_options' );

		$this->assertCount( 0, $this->rows( 'admin_role_granted' ) );
		$this->assertCount( 0, $this->sent() );
	}

	public function test_a_new_role_with_admin_caps_is_flagged() {
		wp_set_current_user( $this->temp );
		add_role( 'happyaccess_promoter', 'Promoter', array( 'read' => true, 'edit_users' => true ) );

		// Roles other tests left in memory are saved with it, so look for this one.
		$rows = array_values(
			array_filter(
				$this->rows( 'admin_role_granted' ),
				static function ( $row ) {
					return 'happyaccess_promoter' === $row['meta']['role'];
				}
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'edit_users' ), $rows[0]['meta']['caps'] );
	}

	public function test_full_pass_pointing_default_role_at_an_admin_role_logs_and_emails() {
		wp_set_current_user( $this->temp );
		update_option( 'default_role', 'editor' );
		$this->assertCount( 0, $this->rows( 'admin_role_granted' ) );

		update_option( 'default_role', 'administrator' );

		$rows = $this->rows( 'admin_role_granted' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'administrator', $rows[0]['meta']['role'] );
		$this->assertSame( 'default_role', $rows[0]['meta']['change'] );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_default_role_through_another_letter_case_is_flagged() {
		wp_set_current_user( $this->temp );
		update_option( 'Default_Role', 'administrator' );

		$this->assertCount( 1, $this->rows( 'admin_role_granted' ) );
	}

	public function test_custom_pass_with_trust_is_flagged_during_plugin_work() {
		$temp = $this->custom_temp( array( 'activate_plugins' ) );
		wp_set_current_user( $temp );
		CapabilityGuard::plugin_work_started();
		$this->add_role_cap( 'subscriber', 'manage_options' );
		CapabilityGuard::plugin_work_finished();

		$this->assertCount( 1, $this->rows( 'admin_role_granted' ) );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_role_alerts_share_the_email_cap() {
		wp_set_current_user( $this->temp );
		for ( $i = 1; $i <= 5; $i++ ) {
			wp_insert_user( array( 'user_login' => 'capadmin' . $i, 'user_pass' => 'x', 'role' => 'administrator' ) );
		}
		$this->add_role_cap( 'subscriber', 'manage_options' );

		$this->assertCount( 1, $this->rows( 'admin_role_granted' ) );
		$this->assertCount( 5, $this->sent() );
	}

	public function test_role_changes_by_a_real_administrator_or_happyaccess_write_nothing() {
		$this->add_role_cap( 'subscriber', 'manage_options' );
		update_option( 'default_role', 'administrator' );
		wp_set_current_user( $this->temp );
		\HappyAccess\Core\Internal::run(
			function () {
				$this->add_role_cap( 'contributor', 'edit_users' );
			}
		);

		$this->assertCount( 0, $this->rows( 'admin_role_granted' ) );
		$this->assertCount( 0, $this->sent() );
	}
}
