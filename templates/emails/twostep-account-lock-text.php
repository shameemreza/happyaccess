<?php
/**
 * Plain-text part of the email about a paused two-step login.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 * @var int    $minutes   Minutes the pause lasts.
 * @var string $reset_url Lost password page.
 */

defined( 'ABSPATH' ) || exit;

// Plain text, so nothing here is HTML escaped. Values are cleaned for a text part.
$happyaccess_text_lines = array(
	__( 'Two-step login is paused for your account', 'happyaccess' ),
	'',
	/* translators: %s: site name. */
	sprintf( __( 'Too many wrong two-step login codes were entered for your account on %s.', 'happyaccess' ), wp_strip_all_tags( $site_name ) ),
	/* translators: %s: number of minutes. */
	sprintf( _n( 'Your account takes no codes for about %s minute.', 'Your account takes no codes for about %s minutes.', (int) $minutes, 'happyaccess' ), number_format_i18n( (int) $minutes ) ),
	'',
	__( 'Codes are asked for only after the right password or in a logged-in session, so whoever typed them may know your password.', 'happyaccess' ),
	/* translators: %s: URL of the lost password page. */
	sprintf( __( "If this wasn't you, reset your password: %s", 'happyaccess' ), esc_url_raw( $reset_url ) ),
	__( 'That also ends the pause.', 'happyaccess' ),
);

echo implode( "\n", $happyaccess_text_lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text part; each value is cleaned above.
