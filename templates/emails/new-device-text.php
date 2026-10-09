<?php
/**
 * Plain-text part of the email about a login from a new device.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 * @var string $time      Login time in the site timezone.
 * @var string $device    Browser and system, like "Chrome on macOS".
 * @var string $ip        IP address, anonymized when the setting is on.
 * @var string $reset_url Lost password page.
 * @var string $setup_url Where to turn on two-step login, empty when the tip doesn't apply.
 */

defined( 'ABSPATH' ) || exit;

// Plain text, so nothing here is HTML escaped. Values are cleaned for a text part, and the
// text is returned to the mailer, never printed.
$happyaccess_text_lines = array(
	__( 'New login to your account', 'happyaccess' ),
	'',
	/* translators: %s: site name. */
	sprintf( __( 'Your account on %s just logged in from a device it has not used before.', 'happyaccess' ), wp_strip_all_tags( $site_name ) ),
	'',
	/* translators: %s: login time. */
	sprintf( __( 'Time: %s', 'happyaccess' ), wp_strip_all_tags( $time ) ),
	/* translators: %s: browser and system, like "Chrome on macOS". */
	sprintf( __( 'Browser: %s', 'happyaccess' ), wp_strip_all_tags( $device ) ),
	/* translators: %s: IP address. */
	sprintf( __( 'IP address: %s', 'happyaccess' ), wp_strip_all_tags( $ip ) ),
	'',
	__( "If this wasn't you, change your password now.", 'happyaccess' ),
	sanitize_url( $reset_url ),
);
if ( '' !== $setup_url ) {
	$happyaccess_text_lines[] = '';
	$happyaccess_text_lines[] = __( 'Turn on two-step login to keep your account safe.', 'happyaccess' );
	$happyaccess_text_lines[] = sanitize_url( $setup_url );
}

return implode( "\n", $happyaccess_text_lines );
