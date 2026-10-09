<?php
/**
 * Notifications tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Mailer;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\Notifications;

class NotificationsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		reset_phpmailer_instance();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'owner@example.org' ) ) );
		Grants::flush_cache();
		delete_transient( 'happyaccess_site_lock_alerted' );
	}

	private function sent() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	public function test_login_alert_respects_notify_setting() {
		$first = Grants::get( Grants::create( array( 'label' => 'Acme' ) )['id'] );
		Notifications::login( $first, true );
		Notifications::login( $first, false );
		$this->assertCount( 1, $this->sent() );
		$this->assertSame( 'owner@example.org', $this->sent()[0]['to'][0][0] );
		$this->assertStringContainsString( 'Acme', $this->sent()[0]['body'] );

		reset_phpmailer_instance();
		$off = Grants::get( Grants::create( array( 'label' => 'Quiet', 'notify' => 'off' ) )['id'] );
		Notifications::login( $off, true );
		$this->assertCount( 0, $this->sent() );
	}

	public function test_a_full_pass_alerts_on_every_login_whatever_its_notify_value() {
		foreach ( array( 'first', 'off' ) as $notify ) {
			reset_phpmailer_instance();
			$full = Grants::get(
				Grants::create(
					array(
						'label'        => 'Host ' . $notify,
						'level'        => 'full',
						'confirm_full' => true,
						'notify'       => $notify,
					)
				)['id']
			);
			$this->assertSame( $notify, $full['notify'], 'The stored value stays as given.' );

			Notifications::login( $full, true );
			Notifications::login( $full, false );
			Notifications::login( $full, false );
			$this->assertCount( 3, $this->sent(), "A full pass with notify {$notify} alerts on every login." );
		}
	}

	public function test_login_alert_for_a_grant_without_a_creator_goes_to_an_admin() {
		$grant = Grants::get( Grants::create( array( 'label' => 'Orphan', 'created_by' => 0 ) )['id'] );
		Notifications::login( $grant, true );
		$this->assertCount( 1, $this->sent() );
		$owner = get_userdata( Grants::owner_id( $grant ) );
		$this->assertTrue( in_array( 'administrator', $owner->roles, true ) );
		$this->assertSame( $owner->user_email, $this->sent()[0]['to'][0][0] );
	}

	public function test_login_alert_falls_back_to_the_site_email_when_no_admin_is_left() {
		foreach ( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) as $id ) {
			update_user_meta( (int) $id, 'happyaccess_temp_user', 1 );
		}
		$grant = Grants::get( Grants::create( array( 'label' => 'Orphan', 'created_by' => 0 ) )['id'] );
		$this->assertSame( 0, Grants::owner_id( $grant ) );
		Notifications::login( $grant, true );
		$this->assertCount( 1, $this->sent() );
		$this->assertSame( get_option( 'admin_email' ), $this->sent()[0]['to'][0][0] );
	}

	public function test_bundle_email_has_link_and_code_but_log_does_not() {
		$made  = Grants::create( array( 'label' => 'Acme', 'email' => 'agent@example.org' ) );
		$grant = Grants::get( $made['id'] );
		$this->assertTrue( Notifications::send_bundle( $grant, $made['code'], $made['link_key'] ) );
		$body = $this->sent()[0]['body'];
		$this->assertStringContainsString( $made['link_key'], $body );
		$this->assertStringContainsString( substr( $made['code'], 0, 4 ), $body );
		$this->assertStringNotContainsString( $made['code'], wp_json_encode( AuditLog::query()['items'] ) );
	}

	public function test_bundle_text_contains_all_parts() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		$text = Notifications::bundle_text( Grants::get( $made['id'] ), $made['code'], $made['link_key'] );
		$this->assertStringContainsString( 'step=link', $text );
		$this->assertStringContainsString( 'step=code', $text );
		$this->assertStringContainsString( substr( $made['code'], 0, 4 ) . ' ' . substr( $made['code'], 4 ), $text );
	}

	public function test_access_ended_summary_lists_activity() {
		$made = Grants::create( array( 'label' => 'Acme' ) );
		AuditLog::add( 'plugin_activated', array( 'token_id' => $made['id'], 'summary' => 'Activated plugin: Query Monitor' ) );
		Notifications::register();
		Grants::revoke( $made['id'] );
		$sent = $this->sent();
		$last = end( $sent );
		$this->assertStringContainsString( 'Activated plugin: Query Monitor', $last['body'] );
	}

	public function test_the_emails_say_temporary_access() {
		$made  = Grants::create( array( 'label' => 'Acme' ) );
		$grant = Grants::get( $made['id'] );
		Notifications::login( $grant, true );
		Notifications::register();
		Grants::revoke( $made['id'] );

		$sent = $this->sent();
		$this->assertStringContainsString( 'Temporary access used: Acme', $sent[0]['subject'] );
		$this->assertStringContainsString( 'Temporary access was used', $sent[0]['body'] );
		$this->assertStringContainsString( 'with the temporary access', $sent[0]['body'] );
		$last = end( $sent );
		$this->assertStringContainsString( 'Temporary access ended: Acme', $last['subject'] );
		$this->assertStringContainsString( 'Temporary access ended', $last['body'] );
		$this->assertStringContainsString( 'The temporary access', $last['body'] );
	}

	public function test_site_lock_alert_is_sent_once() {
		Notifications::site_lock( 3600 );
		Notifications::site_lock( 3600 );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_invalid_recipient_returns_false() {
		$this->assertFalse( Mailer::send( 'nope', 'x', 'login-alert', array() ) );
	}

	public function test_site_lock_alert_works_inside_a_temp_users_request() {
		\HappyAccess\Core\Capabilities::register();
		\HappyAccess\Features\SupportAccess\CapabilityGuard::register();
		$made = Grants::create( array( 'label' => 'Acme' ) );
		wp_set_current_user( \HappyAccess\Features\SupportAccess\TempUsers::get_or_create( Grants::get( $made['id'] ) ) );
		reset_phpmailer_instance();

		Notifications::site_lock( 60 );
		Notifications::site_lock( 60 );

		$this->assertCount( 1, $this->sent() );
	}
}
