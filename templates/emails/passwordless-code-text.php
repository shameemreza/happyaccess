<?php
/**
 * Plain-text part of the login code email.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 * @var string $code      Login code, already formatted.
 * @var string $link      Login link.
 * @var int    $minutes   Minutes until the code and link expire.
 * @var string $ip        IP address that asked for the code.
 */

defined( 'ABSPATH' ) || exit;

// Plain text, so nothing here is HTML escaped. Values are cleaned for a text part.
$happyaccess_text_lines = array(
	/* translators: %s: site name. */
	sprintf( __( 'Log in to %s', 'happyaccess' ), wp_strip_all_tags( $site_name ) ),
	'',
	/* translators: %s: login code. */
	sprintf( __( 'Your login code is %s', 'happyaccess' ), wp_strip_all_tags( $code ) ),
	'',
	__( 'Or open this login link:', 'happyaccess' ),
	esc_url_raw( $link ),
	'',
	sprintf(
		/* translators: %d: minutes until the login code expires. */
		_n( 'The code and the link expire in %d minute.', 'The code and the link expire in %d minutes.', (int) $minutes, 'happyaccess' ),
		(int) $minutes
	),
	sprintf(
		/* translators: %s: IP address. */
		__( 'The request came from IP address %s.', 'happyaccess' ),
		wp_strip_all_tags( $ip )
	),
	'',
	__( "If you didn't ask for this, you can ignore this email. Your password still works.", 'happyaccess' ),
);

echo implode( "\n", $happyaccess_text_lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text part; each value is cleaned above.
