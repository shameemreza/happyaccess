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
$users = get_users( array(
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for cleanup during uninstall.
	'meta_key'   => 'happyaccess_temp_user',
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for cleanup during uninstall.
	'meta_value' => true,
) );

foreach ( $users as $user ) {
	wp_delete_user( $user->ID );
}

// Drop custom tables.
global $wpdb;

$tables = array(
	$wpdb->prefix . 'happyaccess_tokens',
	$wpdb->prefix . 'happyaccess_logs',
	$wpdb->prefix . 'happyaccess_attempts',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup, safe table name.
	$wpdb->query( "DROP TABLE IF EXISTS $table" );
}

// Delete options.
$options = array(
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

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'happyaccess_cleanup_expired' );
wp_clear_scheduled_hook( 'happyaccess_cleanup_attempts' );

// Clear any transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_happyaccess_%'" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_happyaccess_%'" );
