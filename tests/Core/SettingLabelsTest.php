<?php
/**
 * SettingLabels tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\SettingLabels;
use HappyAccess\Core\Settings;

class SettingLabelsTest extends WP_UnitTestCase {

	/**
	 * Every dotted key of the defaults, at the depth changed_keys() logs.
	 *
	 * @param array  $values Settings level.
	 * @param string $prefix Dotted path of this level.
	 * @return string[]
	 */
	private function flat_keys( array $values, $prefix = '' ) {
		$keys = array();
		foreach ( $values as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) && array() !== $value ) {
				$keys = array_merge( $keys, $this->flat_keys( $value, $path ) );
			} else {
				$keys[] = $path;
			}
		}
		return $keys;
	}

	private function line( array $keys, ?array $features = null, $stored = 'stored' ) {
		$meta = array( 'keys' => $keys );
		if ( null !== $features ) {
			$meta['features'] = $features;
		}
		return SettingLabels::summary( 'settings_changed', $stored, $meta );
	}

	public function test_every_default_key_has_a_plain_label() {
		$missing = array();
		foreach ( $this->flat_keys( Settings::defaults() ) as $key ) {
			if ( in_array( $key, SettingLabels::CONSENT_KEYS, true ) ) {
				continue;
			}
			if ( SettingLabels::label( $key ) === $key ) {
				$missing[] = $key;
			}
		}
		$this->assertSame( array(), $missing );
	}

	public function test_labels_are_plain_text_without_dashes_or_underscores() {
		foreach ( SettingLabels::all() as $key => $label ) {
			$this->assertNotSame( '', $label, $key );
			$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}_]/u', $label, $key );
		}
	}

	public function test_nested_keys_match_by_prefix() {
		$this->assertSame( 'Passwordless login by role', SettingLabels::label( 'passwordless.role_policy.editor' ) );
		$this->assertSame( 'Two-step login by role', SettingLabels::label( 'two_step.role_policy.shop_manager' ) );
		$this->assertSame( 'Show the email code option on', SettingLabels::label( 'passwordless.show_on.woo_checkout' ) );
		$this->assertSame( 'New device alerts', SettingLabels::label( 'two_step.device_alert_roles.1' ) );
		$this->assertSame( 'passwordless.role_policyx', SettingLabels::label( 'passwordless.role_policyx' ) );
	}

	public function test_a_settings_line_uses_screen_labels_in_screen_order() {
		$this->assertSame(
			'Changed settings: Keep activity for, Shorten IP addresses in the log',
			$this->line( array( 'privacy.anonymize_ip', 'privacy.retention_days' ) )
		);
	}

	public function test_keys_that_share_a_control_are_named_once() {
		$this->assertSame(
			'Changed settings: Wrong codes before a pause',
			$this->line( array( 'security.lockout_duration', 'security.max_attempts' ) )
		);
		$this->assertSame(
			'Changed settings: Passwordless login by role',
			$this->line( array( 'passwordless.role_policy.administrator', 'passwordless.role_policy.editor' ) )
		);
	}

	public function test_an_unknown_key_is_kept_as_it_is_and_comes_last() {
		$this->assertSame(
			'Changed settings: Keep a log, later.new_thing',
			$this->line( array( 'later.new_thing', 'privacy.logging' ) )
		);
	}

	public function test_feature_switches_read_as_actions() {
		$this->assertSame( 'Turned on Passwordless login', $this->line( array( 'features.passwordless' ), array( 'passwordless' => true ) ) );
		$this->assertSame( 'Turned off Two-step login', $this->line( array( 'features.two_step' ), array( 'two_step' => false ) ) );
		$this->assertSame(
			'Turned on Temporary access and Passwordless login and turned off Two-step login',
			$this->line(
				array( 'features.passwordless', 'features.support_access', 'features.two_step' ),
				array(
					'passwordless'   => true,
					'support_access' => true,
					'two_step'       => false,
				)
			)
		);
	}

	public function test_a_switch_and_other_settings_read_as_two_sentences() {
		$this->assertSame(
			'Turned off Passwordless login. Changed settings: Code lifetime',
			$this->line( array( 'features.passwordless', 'passwordless.code_lifetime' ), array( 'passwordless' => false ) )
		);
	}

	public function test_an_old_row_without_feature_states_uses_the_plain_label() {
		$this->assertSame( 'Changed settings: Passwordless login', $this->line( array( 'features.passwordless' ) ) );
	}

	public function test_a_feature_state_that_is_not_a_boolean_is_ignored() {
		$this->assertSame( 'Changed settings: Two-step login', $this->line( array( 'features.two_step' ), array( 'two_step' => 'yes' ) ) );
	}

	public function test_finishing_setup_never_lists_the_consent_keys() {
		$this->assertSame( 'Finished setup', $this->line( array( 'support.consent_given_at', 'support.consent_user_id' ) ) );
		$this->assertSame(
			'Finished setup and turned on Temporary access and Passwordless login',
			$this->line(
				array( 'features.passwordless', 'features.support_access', 'support.consent_given_at', 'support.consent_user_id' ),
				array(
					'passwordless'   => true,
					'support_access' => true,
				)
			)
		);
		$this->assertSame(
			'Finished setup, turned on Passwordless login and turned off Temporary access',
			$this->line(
				array( 'features.passwordless', 'features.support_access', 'support.consent_given_at', 'support.consent_user_id' ),
				array(
					'passwordless'   => true,
					'support_access' => false,
				)
			)
		);
	}

	public function test_the_consent_user_alone_is_never_listed() {
		$line = $this->line( array( 'support.consent_user_id' ) );
		$this->assertStringNotContainsString( 'consent', $line );
		$this->assertSame( 'HappyAccess settings changed', $line );
	}

	public function test_rows_without_keys_keep_their_stored_summary() {
		$this->assertSame( 'Changed settings: a.b', SettingLabels::summary( 'settings_changed', 'Changed settings: a.b', array() ) );
		$this->assertSame( 'Changed settings: a.b', SettingLabels::summary( 'settings_changed', 'Changed settings: a.b', null ) );
		$this->assertSame( 'Kept', SettingLabels::summary( 'post_updated', 'Kept', array( 'keys' => array( 'privacy.logging' ) ) ) );
	}

	public function test_feature_states_hold_booleans_for_changed_switches_only() {
		$settings = Settings::merge( array( 'features' => array( 'passwordless' => true ) ) );
		$this->assertSame(
			array( 'passwordless' => true ),
			SettingLabels::feature_states( array( 'features.passwordless', 'privacy.logging' ), $settings )
		);
		$this->assertSame( array(), SettingLabels::feature_states( array( 'privacy.logging' ), $settings ) );
	}
}
