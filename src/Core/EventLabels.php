<?php
/**
 * Readable names for log event keys.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stored summaries keep the text written at the time. Labels are translated
 * when read, for the person viewing.
 */
final class EventLabels {

	/**
	 * Label for one event key.
	 *
	 * @param string $event Event key, for example "grant_created".
	 * @return string Translated, sentence-case label.
	 */
	public static function label( $event ) {
		$event  = (string) $event;
		$labels = self::all();
		if ( isset( $labels[ $event ] ) ) {
			return $labels[ $event ];
		}

		$name = trim( str_replace( array( '_', '-' ), ' ', $event ) );
		return '' === $name ? '' : ucfirst( $name );
	}

	/**
	 * Every known event key with its label.
	 *
	 * @return array<string,string>
	 */
	public static function all() {
		return array(
			'grant_created'              => __( 'Support pass created', 'happyaccess' ),
			'grant_extended'             => __( 'Support pass extended', 'happyaccess' ),
			'grant_suspended'            => __( 'Support pass paused', 'happyaccess' ),
			'grant_resumed'              => __( 'Support pass resumed', 'happyaccess' ),
			'grant_regenerated'          => __( 'Login details renewed', 'happyaccess' ),
			'grant_ended'                => __( 'Support pass ended', 'happyaccess' ),
			'temp_user_deleted'          => __( 'Temporary user deleted', 'happyaccess' ),
			'temp_user_delete_failed'    => __( 'Temporary user could not be deleted', 'happyaccess' ),
			'bundle_emailed'             => __( 'Access details emailed', 'happyaccess' ),
			'emergency_lock'             => __( 'Emergency Lock turned on', 'happyaccess' ),
			'settings_changed'           => __( 'HappyAccess settings changed', 'happyaccess' ),
			'login_success'              => __( 'Logged in', 'happyaccess' ),
			'login_failed'               => __( 'Login failed', 'happyaccess' ),
			'access_blocked'             => __( 'Login blocked', 'happyaccess' ),
			'plugin_activated'           => __( 'Plugin activated', 'happyaccess' ),
			'plugin_deactivated'         => __( 'Plugin deactivated', 'happyaccess' ),
			'plugin_deleted'             => __( 'Plugin deleted', 'happyaccess' ),
			'upgrader_ran'               => __( 'Plugins or themes updated', 'happyaccess' ),
			'theme_switched'             => __( 'Theme switched', 'happyaccess' ),
			'theme_deleted'              => __( 'Theme deleted', 'happyaccess' ),
			'post_created'               => __( 'Content created', 'happyaccess' ),
			'post_updated'               => __( 'Content updated', 'happyaccess' ),
			'post_trashed'               => __( 'Content moved to trash', 'happyaccess' ),
			'post_deleted'               => __( 'Content deleted', 'happyaccess' ),
			'settings_saved'             => __( 'Settings saved', 'happyaccess' ),
			'order_status_changed'       => __( 'Order status changed', 'happyaccess' ),
			'user_updated'               => __( 'User updated', 'happyaccess' ),
			'user_created'               => __( 'User created', 'happyaccess' ),
			'user_role_changed'          => __( 'User role changed', 'happyaccess' ),
			'user_role_added'            => __( 'User role added', 'happyaccess' ),
			'roles_changed'              => __( 'Role permissions changed', 'happyaccess' ),
			'role_change_blocked'        => __( 'Role change blocked', 'happyaccess' ),
			'privacy_erased'             => __( 'Personal data erased', 'happyaccess' ),
			'wc_webhook_created'         => __( 'Webhook created', 'happyaccess' ),
			'wc_key_blocked'             => __( 'API key request blocked', 'happyaccess' ),
			'admin_account_created'      => __( 'Administrator account made', 'happyaccess' ),
			'admin_account_changed'      => __( 'Administrator login details changed', 'happyaccess' ),
			'admin_role_granted'         => __( 'Role given admin-level permissions', 'happyaccess' ),
			'plugin_upgraded'            => __( 'HappyAccess updated', 'happyaccess' ),
			'passwordless_requested'     => __( 'Login code requested', 'happyaccess' ),
			'passwordless_login'         => __( 'Logged in without a password', 'happyaccess' ),
			'passwordless_failed'        => __( 'Passwordless login failed', 'happyaccess' ),
			'passwordless_locked'        => __( 'Passwordless login locked', 'happyaccess' ),
			'twostep_enabled'            => __( 'Two-step login turned on', 'happyaccess' ),
			'twostep_disabled'           => __( 'Two-step login turned off', 'happyaccess' ),
			'twostep_passed'             => __( 'Two-step login passed', 'happyaccess' ),
			'twostep_failed'             => __( 'Two-step login failed', 'happyaccess' ),
			'twostep_locked'             => __( 'Two-step login locked', 'happyaccess' ),
			'twostep_reset'              => __( 'Two-step login reset', 'happyaccess' ),
			'twostep_backup_used'        => __( 'Backup code used', 'happyaccess' ),
			'twostep_backup_regenerated' => __( 'Backup codes renewed', 'happyaccess' ),
		);
	}
}
