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
		parent::tear_down();
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
}
