<?php
/**
 * Plain-text part of the email about an admin turning off two-step login.
 *
 * @package HappyAccess
 *
 * @var string $site_name  Site name.
 * @var string $admin_name Display name of the admin who turned it off.
 * @var string $setup_url  Where the user sets it up again.
 */

defined( 'ABSPATH' ) || exit;

// Plain text, so nothing here is HTML escaped. Values are cleaned for a text part, and the
// text is returned to the mailer, never printed.
$happyaccess_text_lines = array(
	__( 'Two-step login was turned off', 'happyaccess' ),
	'',
	/* translators: %s: display name of the admin. */
	sprintf( __( 'Two-step login was turned off for your account by %s.', 'happyaccess' ), wp_strip_all_tags( $admin_name ) ),
	'',
	/* translators: %s: site name. */
	sprintf( __( 'You can now log in to %s with your password alone.', 'happyaccess' ), wp_strip_all_tags( $site_name ) ),
	/* translators: %s: URL of the user's profile or account page. */
	sprintf( __( 'You can set it up again from your account: %s', 'happyaccess' ), sanitize_url( $setup_url ) ),
	'',
	__( "If you didn't ask for this, change your password and contact the site owner.", 'happyaccess' ),
);

return implode( "\n", $happyaccess_text_lines );
