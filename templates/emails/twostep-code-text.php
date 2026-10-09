<?php
/**
 * Plain-text part of the two-step login code email.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 * @var string $code      Login code, already formatted.
 * @var int    $minutes   Minutes until the code expires.
 * @var string $ip        IP address of the login attempt.
 */

defined( 'ABSPATH' ) || exit;

// Plain text, so nothing here is HTML escaped. Values are cleaned for a text part, and the
// text is returned to the mailer, never printed.
$happyaccess_text_lines = array(
	/* translators: %s: site name. */
	sprintf( __( 'Log in to %s', 'happyaccess' ), wp_strip_all_tags( $site_name ) ),
	'',
	/* translators: %s: login code. */
	sprintf( __( 'Your two-step login code is %s', 'happyaccess' ), wp_strip_all_tags( $code ) ),
	'',
	sprintf(
		/* translators: %d: minutes until the login code expires. */
		_n( 'It expires in %d minute.', 'It expires in %d minutes.', (int) $minutes, 'happyaccess' ),
		(int) $minutes
	),
	sprintf(
		/* translators: %s: IP address. */
		__( 'The login attempt came from IP address %s.', 'happyaccess' ),
		wp_strip_all_tags( $ip )
	),
	'',
	__( "If you didn't try to log in, change your password.", 'happyaccess' ),
);

return implode( "\n", $happyaccess_text_lines );
