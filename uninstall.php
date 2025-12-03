<?php
/**
 * Uninstall handler for HappyAccess plugin.
 *
 * @package HappyAccess
 * @since   1.0.0
 */

// Exit if uninstall not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete all temporary users first.
$happyaccess_users = get_users( array(
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for cleanup during uninstall.
	'meta_key'   => 'happyaccess_temp_user',
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for cleanup during uninstall.
	'meta_value' => true,
) );

foreach ( $happyaccess_users as $happyaccess_user ) {
	wp_delete_user( $happyaccess_user->ID );
}

// Drop custom tables.
global $wpdb;

$happyaccess_tables = array(
	$wpdb->prefix . 'happyaccess_tokens',
	$wpdb->prefix . 'happyaccess_logs',
	$wpdb->prefix . 'happyaccess_attempts',
);

foreach ( $happyaccess_tables as $happyaccess_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup, safe table name.
	$wpdb->query( "DROP TABLE IF EXISTS $happyaccess_table" );
}

// Delete options.
$happyaccess_options = array(
	'happyaccess_version',
	'happyaccess_db_version',
	'happyaccess_activated',
	'happyaccess_max_attempts',
	'happyaccess_lockout_duration',
	'happyaccess_token_expiry',
	'happyaccess_cleanup_days',
	'happyaccess_enable_logging',
	'happyaccess_enable_email',
	'happyaccess_gdpr_consent_text',
);

foreach ( $happyaccess_options as $happyaccess_option ) {
	delete_option( $happyaccess_option );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'happyaccess_cleanup_expired' );
wp_clear_scheduled_hook( 'happyaccess_cleanup_attempts' );

// Clear any transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_happyaccess_%'" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_happyaccess_%'" );
