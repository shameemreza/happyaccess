<?php
/**
 * HappyAccess Uninstall Handler
 *
 * This file is executed when the plugin is deleted (not deactivated).
 * It respects the "Delete Data on Uninstall" setting.
 *
 * @package HappyAccess
 * @since   1.0.2
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check if user opted to delete data on uninstall.
$happyaccess_delete_data = get_option( 'happyaccess_delete_on_uninstall', false );

if ( ! $happyaccess_delete_data ) {
	// User chose to keep data - exit without deleting.
	return;
}

global $wpdb;

// Delete custom database tables.
$happyaccess_tokens_table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );
$happyaccess_logs_table   = esc_sql( $wpdb->prefix . 'happyaccess_logs' );
$happyaccess_magic_table  = esc_sql( $wpdb->prefix . 'happyaccess_magic_links' );
$happyaccess_shares_table = esc_sql( $wpdb->prefix . 'happyaccess_otp_shares' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are escaped, uninstall cleanup.
$wpdb->query( "DROP TABLE IF EXISTS `{$happyaccess_tokens_table}`" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are escaped, uninstall cleanup.
$wpdb->query( "DROP TABLE IF EXISTS `{$happyaccess_logs_table}`" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are escaped, uninstall cleanup.
$wpdb->query( "DROP TABLE IF EXISTS `{$happyaccess_magic_table}`" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are escaped, uninstall cleanup.
$wpdb->query( "DROP TABLE IF EXISTS `{$happyaccess_shares_table}`" );

// Delete all temporary users created by the plugin using direct query (more efficient).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup, runs once.
$happyaccess_temp_user_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
		'happyaccess_temp_user',
		'1'
	)
);

if ( $happyaccess_temp_user_ids ) {
	foreach ( $happyaccess_temp_user_ids as $happyaccess_user_id ) {
		wp_delete_user( (int) $happyaccess_user_id );
	}
}

// Delete all plugin options.
$happyaccess_options_to_delete = array(
	'happyaccess_version',
	'happyaccess_max_attempts',
	'happyaccess_lockout_duration',
	'happyaccess_token_expiry',
	'happyaccess_cleanup_days',
	'happyaccess_enable_logging',
	'happyaccess_delete_on_uninstall',
	'happyaccess_db_version',
	'happyaccess_recaptcha_enabled',
	'happyaccess_recaptcha_site_key',
	'happyaccess_recaptcha_secret_key',
	'happyaccess_recaptcha_threshold',
	'happyaccess_magic_link_expiry',
	'happyaccess_share_link_expiry',
);

foreach ( $happyaccess_options_to_delete as $happyaccess_option ) {
	delete_option( $happyaccess_option );
}

// Clear any scheduled cron events.
wp_clear_scheduled_hook( 'happyaccess_cleanup_expired' );
wp_clear_scheduled_hook( 'happyaccess_cleanup_attempts' );

// Clear transients.
delete_transient( 'happyaccess_rate_limit' );

// Delete all rate limit transients (they follow a pattern).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup transients on uninstall.
$wpdb->query(
	"DELETE FROM `{$wpdb->options}` 
	WHERE `option_name` LIKE '_transient_happyaccess_%' 
	OR `option_name` LIKE '_transient_timeout_happyaccess_%'"
);
