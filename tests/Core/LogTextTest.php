<?php
/**
 * LogText tests: every log line is built when it is read.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Installer;
use HappyAccess\Core\LogText;
use HappyAccess\Core\Settings;

class LogTextTest extends WP_UnitTestCase {

	/**
	 * Files in src whose AuditLog::add calls may pass a plain summary, with
	 * the reason.
	 */
	const PLAIN_SUMMARY_ALLOWED = array(
		'src/Core/AuditLog.php'           => 'The default, and the column it fills.',
		'src/Rest/SettingsController.php' => 'Settings rows are built from meta.keys by SettingLabels.',
		'src/Rest/ActivityController.php' => 'Output of the REST list, not a writer.',
	);

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		Installer::install();
	}

	/**
	 * Every text key, with values as its writer passes them, and the line
	 * it reads as in English. The English matches what each writer stored
	 * before 1.1.0, so new and old rows read the same.
	 *
	 * @return array
	 */
	public function provide_every_text() {
		return array(
			'grant_created'              => array( 'grant_created', array( 'Acme' ), 'Temporary access granted to Acme' ),
			'grant_extended'             => array( 'grant_extended', array( 'Acme' ), 'Temporary access extended for Acme' ),
			'grant_suspended'            => array( 'grant_suspended', array( 'Acme' ), 'Temporary access suspended for Acme' ),
			'grant_resumed'              => array( 'grant_resumed', array( 'Acme' ), 'Temporary access resumed for Acme' ),
			'grant_regenerated'          => array( 'grant_regenerated', array( 'Acme' ), 'Temporary access code and link replaced for Acme' ),
			'grant_ended'                => array( 'grant_ended', array( 'Acme' ), 'Temporary access ended for Acme' ),
			'bundle_emailed'             => array( 'bundle_emailed', array( 'Acme' ), 'Access details emailed for Acme' ),
			'emergency_lock one'         => array( 'emergency_lock', array( 1 ), 'Emergency lock ended 1 grant.' ),
			'emergency_lock many'        => array( 'emergency_lock', array( 3 ), 'Emergency lock ended 3 grants.' ),
			'login_success'              => array( 'login_success', array( 'Acme' ), 'Temporary access used: Acme' ),
			'login_setup_failed'         => array( 'login_setup_failed', array(), "Couldn't set up the support account" ),
			'admin_account_created'      => array( 'admin_account_created', array( 'jo' ), 'Made an administrator account: jo' ),
			'admin_account_changed'      => array( 'admin_account_changed', array( 'jo' ), 'Changed login details for administrator: jo' ),
			'admin_role_default'         => array( 'admin_role_default', array( 'Editor' ), 'Made new accounts get an admin-level role: Editor' ),
			'admin_role_granted'         => array( 'admin_role_granted', array( 'Editor' ), 'Gave admin-level permissions to the role: Editor' ),
			'wc_key_blocked'             => array( 'wc_key_blocked', array(), 'Blocked a WooCommerce API key request' ),
			'roles_changed_plugin_work'  => array( 'roles_changed_plugin_work', array(), 'Roles changed while activating or updating a plugin' ),
			'roles_changed'              => array( 'roles_changed', array(), 'Changed role permissions' ),
			'role_change_blocked'        => array( 'role_change_blocked', array(), 'Blocked a change to role permissions outside plugin activation or update' ),
			'plugin_activated'           => array( 'plugin_activated', array( 'Akismet' ), 'Activated plugin: Akismet' ),
			'plugin_deactivated'         => array( 'plugin_deactivated', array( 'Akismet' ), 'Deactivated plugin: Akismet' ),
			'plugin_deleted'             => array( 'plugin_deleted', array( 'akismet/akismet.php' ), 'Deleted plugin: akismet/akismet.php' ),
			'core_updated'               => array( 'core_updated', array(), 'Updated WordPress core' ),
			'translations_updated'       => array( 'translations_updated', array(), 'Updated translations' ),
			'plugins_updated'            => array( 'plugins_updated', array( array( 'Akismet', 'Hello Dolly' ) ), 'Installed or updated plugin: Akismet, Hello Dolly' ),
			'plugins_updated unknown'    => array( 'plugins_updated', array( array() ), 'Installed or updated plugin: (unknown)' ),
			'themes_updated'             => array( 'themes_updated', array( array( 'Twenty' ) ), 'Installed or updated theme: Twenty' ),
			'themes_updated unknown'     => array( 'themes_updated', array( array() ), 'Installed or updated theme: (unknown)' ),
			'theme_switched'             => array( 'theme_switched', array( 'Twenty' ), 'Switched theme to Twenty' ),
			'theme_deleted'              => array( 'theme_deleted', array( 'twenty' ), 'Deleted theme: twenty' ),
			'post_created'               => array( 'post_created', array( 'Page', 'About', 12 ), 'Created Page: About (#12)' ),
			'post_updated'               => array( 'post_updated', array( 'Post', 'Hello', 3 ), 'Updated Post: Hello (#3)' ),
			'post_updated untitled'      => array( 'post_updated', array( 'Post', '', 3 ), 'Updated Post: (no title) (#3)' ),
			'post_trashed'               => array( 'post_trashed', array( 'Product', 'Mug', 40 ), 'Trashed Product: Mug (#40)' ),
			'post_deleted'               => array( 'post_deleted', array( 'Page', 'Old', 9 ), 'Deleted Page: Old (#9)' ),
			'order_status_changed'       => array( 'order_status_changed', array( 51, 'pending', 'processing' ), 'Order #51: pending to processing' ),
			'user_updated'               => array( 'user_updated', array( 'jo' ), 'Updated user: jo' ),
			'user_created'               => array( 'user_created', array( 'jo', array( 'editor' ) ), 'Created user: jo (editor)' ),
			'user_created no role'       => array( 'user_created', array( 'jo', array() ), 'Created user: jo (none)' ),
			'user_role_changed'          => array( 'user_role_changed', array( 'jo', array( 'author', 'shop' ), array( 'editor' ) ), 'Changed role for jo: author, shop to editor' ),
			'user_role_changed from none' => array( 'user_role_changed', array( 'jo', array(), array( 'editor' ) ), 'Changed role for jo: none to editor' ),
			'user_role_added'            => array( 'user_role_added', array( 'jo', array( 'editor' ) ), 'Added role to jo: editor' ),
			'privacy_erased'             => array( 'privacy_erased', array( 7 ), 'Ran a personal data erasure (#7)' ),
			'wc_webhook_created'         => array( 'wc_webhook_created', array( 4 ), 'Created WooCommerce webhook #4' ),
			'settings_saved one'         => array( 'settings_saved', array( 1 ), 'Saved settings: 1 option' ),
			'settings_saved many'        => array( 'settings_saved', array( 4 ), 'Saved settings: 4 options' ),
			'plugin_upgraded'            => array( 'plugin_upgraded', array( '1.0.2', '1.1.0' ), 'Upgraded from 1.0.2 to 1.1.0' ),
			'captcha_unavailable'        => array( 'captcha_unavailable', array(), "The security check couldn't reach Google" ),
			'captcha_failed'             => array( 'captcha_failed', array(), 'A login failed the security check' ),
			'passwordless_requested'     => array( 'passwordless_requested', array(), 'Login code requested' ),
			'passwordless_rate_limited'  => array( 'passwordless_rate_limited', array(), 'Login code not sent: too many requests' ),
			'passwordless_not_created'   => array( 'passwordless_not_created', array(), 'Login code could not be made' ),
			'passwordless_attempts'      => array( 'passwordless_attempts', array(), 'Login code cancelled after too many wrong tries' ),
			'passwordless_invalid_code'  => array( 'passwordless_invalid_code', array(), 'Login code did not work' ),
			'passwordless_invalid_link'  => array( 'passwordless_invalid_link', array(), 'Login link did not work' ),
			'passwordless_login'         => array( 'passwordless_login', array(), 'Logged in with a login code or link' ),
			'passwordless_site_locked'   => array( 'passwordless_site_locked', array(), 'Login codes paused for the whole site after too many wrong codes' ),
			'passwordless_policy_paused' => array( 'passwordless_policy_paused', array(), 'Password allowed for an email code only account because login codes are not working' ),
			'twostep_secret_unreadable'  => array( 'twostep_secret_unreadable', array(), "Authenticator app secret can't be read, so email and backup codes stand in" ),
			'twostep_app_enabled'        => array( 'twostep_app_enabled', array(), 'Two-step login turned on with an authenticator app' ),
			'twostep_app_disabled'       => array( 'twostep_app_disabled', array(), 'Authenticator app turned off for two-step login' ),
			'twostep_email_enabled'      => array( 'twostep_email_enabled', array(), 'Two-step login turned on with email codes' ),
			'twostep_email_disabled'     => array( 'twostep_email_disabled', array(), 'Email codes turned off for two-step login' ),
			'twostep_reset'              => array( 'twostep_reset', array(), 'Two-step login reset' ),
			'twostep_passed'             => array( 'twostep_passed', array(), 'Two-step login passed' ),
			'twostep_failed'             => array( 'twostep_failed', array(), 'Two-step login code did not work' ),
			'twostep_locked'             => array( 'twostep_locked', array(), 'Two-step login cancelled after too many wrong codes' ),
			'twostep_account_paused'     => array( 'twostep_account_paused', array(), 'Two-step login paused for this account after too many wrong codes' ),
			'twostep_site_alert'         => array( 'twostep_site_alert', array(), 'Many wrong two-step login codes on the site in the last hour' ),
			'twostep_skipped'            => array( 'twostep_skipped', array(), 'Two-step login setup put off' ),
			'twostep_backup_regenerated' => array( 'twostep_backup_regenerated', array(), 'Backup codes renewed' ),
			'twostep_backup_used'        => array( 'twostep_backup_used', array(), 'Backup code used' ),
			'new_device_login'           => array( 'new_device_login', array( 'Chrome on macOS' ), 'Logged in from a new device: Chrome on macOS' ),
		);
	}

	/**
	 * @dataProvider provide_every_text
	 */
	public function test_each_text_reads_as_its_writer_stored_it( $key, $args, $expected ) {
		$this->assertSame( $expected, LogText::render( $key, $args ) );
	}

	/**
	 * @dataProvider provide_every_text
	 */
	public function test_each_text_is_stored_as_a_key_and_built_when_read( $key, $args, $expected ) {
		$id  = AuditLog::add(
			'note',
			array(
				'summary_key'  => $key,
				'summary_args' => $args,
			)
		);
		$row = AuditLog::query( array( 'event' => 'note' ) )['items'][0];

		$this->assertSame( $id, (int) $row['id'] );
		$this->assertSame( $key, $row['meta'][ LogText::META_KEY ] );
		$this->assertSame( array() === $args ? null : $args, isset( $row['meta'][ LogText::META_ARGS ] ) ? $row['meta'][ LogText::META_ARGS ] : null );
		$this->assertSame( $expected, $row['summary'], 'The stored column keeps the line as written.' );
		$this->assertSame( $expected, LogText::summary( 'note', $row['summary'], $row['meta'] ) );
	}

	public function test_the_provider_covers_every_template() {
		$covered = array_unique( array_column( $this->provide_every_text(), 0 ) );
		sort( $covered );
		$keys = array_keys( LogText::templates() );
		sort( $keys );
		$this->assertSame( $keys, $covered );
	}

	public function test_every_text_reads_in_the_viewers_language() {
		$translate = static function ( $translation, $text, $domain ) {
			return 'happyaccess' === $domain ? '[de] ' . $text : $translation;
		};
		$plural    = static function ( $translation, $single, $plural, $number, $domain ) {
			return 'happyaccess' === $domain ? '[de] ' . ( 1 === (int) $number ? $single : $plural ) : $translation;
		};
		$english = array();
		foreach ( $this->provide_every_text() as $case ) {
			$english[] = array( $case[0], $case[1], $case[2] );
		}

		add_filter( 'gettext', $translate, 10, 3 );
		add_filter( 'ngettext', $plural, 10, 5 );
		try {
			foreach ( $english as list( $key, $args, $expected ) ) {
				$line = LogText::summary( 'note', $expected, LogText::meta( $key, $args ) );
				$this->assertStringStartsWith( '[de] ', $line, $key );
			}
		} finally {
			remove_filter( 'gettext', $translate, 10 );
			remove_filter( 'ngettext', $plural, 10 );
		}
	}

	public function test_a_row_without_a_text_key_keeps_its_stored_summary() {
		$this->assertSame( 'Changed tax rates', LogText::summary( 'settings_saved', 'Changed tax rates', array() ) );
		$this->assertSame( 'Changed tax rates', LogText::summary( 'settings_saved', 'Changed tax rates', null ) );
		$this->assertSame( 'Kept', LogText::summary( 'note', 'Kept', array( LogText::META_KEY => 'not_a_text' ) ), 'An unknown key keeps the stored text.' );
		$this->assertSame( 'Kept', LogText::summary( 'note', 'Kept', array( LogText::META_KEY => array( 'grant_created' ) ) ) );
	}

	public function test_a_settings_row_is_still_built_from_its_keys() {
		$this->assertSame(
			'Changed settings: Keep a log',
			LogText::summary( 'settings_changed', 'Changed settings: privacy.logging', array( 'keys' => array( 'privacy.logging' ) ) )
		);
	}

	public function test_values_that_are_missing_extra_or_not_plain_read_safely() {
		$this->assertSame( 'Temporary access granted to ', LogText::render( 'grant_created', array() ) );
		$this->assertSame( 'Temporary access granted to Acme', LogText::render( 'grant_created', array( 'Acme', 'extra' ) ) );
		$this->assertSame( 'Temporary access granted to ', LogText::render( 'grant_created', array( new stdClass() ) ) );
		$this->assertSame( 'Temporary access granted to 50%_off', LogText::render( 'grant_created', array( '50%_off' ) ), 'A value is never read as a format.' );
		$this->assertSame( '', LogText::render( 'not_a_text', array( 'Acme' ) ) );
	}

	public function test_an_unknown_text_key_is_flagged_and_stores_no_line() {
		$this->setExpectedIncorrectUsage( 'HappyAccess\Core\AuditLog::add' );
		AuditLog::add( 'note', array( 'summary_key' => 'grant_craeted' ) );

		$row = AuditLog::query( array( 'event' => 'note' ) )['items'][0];
		$this->assertSame( '', $row['summary'] );
		$this->assertArrayNotHasKey( LogText::META_KEY, $row['meta'] );
	}

	public function test_keys_matching_looks_at_the_words_only() {
		$this->assertContains( 'grant_created', LogText::keys_matching( 'access GRANTED' ) );
		$this->assertContains( 'settings_saved', LogText::keys_matching( 'options' ), 'Both plural forms count.' );
		$this->assertContains( 'emergency_lock', LogText::keys_matching( 'Emergency lock ended' ) );
		$this->assertSame( array(), LogText::keys_matching( '1$s' ), 'Placeholders are not words.' );
		$this->assertSame( array(), LogText::keys_matching( '' ) );
		$this->assertSame( array(), LogText::keys_matching( 'Acme' ) );
	}

	public function test_search_finds_a_row_by_the_words_of_its_line_in_the_viewers_language() {
		$granted = AuditLog::add(
			'grant_created',
			array(
				'summary_key'  => 'grant_created',
				'summary_args' => array( 'Acme' ),
			)
		);
		AuditLog::add( 'twostep_passed', array( 'summary_key' => 'twostep_passed' ) );

		$translate = static function ( $translation, $text, $domain ) {
			return 'happyaccess' === $domain && 'Temporary access granted to %s' === $text ? 'Zugang erteilt für %s' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );
		try {
			$found = AuditLog::query( array( 'search' => 'zugang erteilt' ) )['items'];
			$this->assertSame( array( $granted ), array_map( 'intval', array_column( $found, 'id' ) ) );
			$this->assertSame( 'Zugang erteilt für Acme', LogText::summary( $found[0]['event_type'], $found[0]['summary'], $found[0]['meta'] ) );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		// A value is found through the stored line.
		$this->assertCount( 1, AuditLog::query( array( 'search' => 'Acme' ) )['items'] );
		$this->assertSame( array(), AuditLog::query( array( 'search' => 'Nothing like this' ) )['items'] );
	}

	public function test_no_writer_stores_a_plain_summary() {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( HAPPYACCESS_PLUGIN_DIR . 'src', FilesystemIterator::SKIP_DOTS ) );
		$found    = array();
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$path = substr( $file->getPathname(), strlen( HAPPYACCESS_PLUGIN_DIR ) );
			if ( isset( self::PLAIN_SUMMARY_ALLOWED[ $path ] ) ) {
				continue;
			}
			$tokens = array_values(
				array_filter(
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local plugin file.
					token_get_all( (string) file_get_contents( $file->getPathname() ) ),
					static function ( $token ) {
						return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
					}
				)
			);
			foreach ( $tokens as $index => $token ) {
				if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && "'summary'" === $token[1] && isset( $tokens[ $index + 1 ] ) && is_array( $tokens[ $index + 1 ] ) && T_DOUBLE_ARROW === $tokens[ $index + 1 ][0] ) {
					$found[] = $path . ':' . $token[2];
				}
			}
		}
		$this->assertSame( array(), $found, 'Pass summary_key and summary_args instead.' );
	}
}
