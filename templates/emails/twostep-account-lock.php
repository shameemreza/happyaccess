<?php
/**
 * Email: two-step login is paused for the user's account after too many wrong codes.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 * @var int    $minutes   Minutes the pause lasts.
 * @var string $reset_url Lost password page.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;"><?php esc_html_e( 'Two-step login is paused for your account', 'happyaccess' ); ?></h1>
<p style="margin:0 0 16px;">
<?php
printf(
	/* translators: %s: site name. */
	esc_html__( 'Too many wrong two-step login codes were entered for your account on %s.', 'happyaccess' ),
	esc_html( $site_name )
);
echo ' ';
printf(
	/* translators: %s: number of minutes. */
	esc_html( _n( 'Your account takes no codes for about %s minute.', 'Your account takes no codes for about %s minutes.', $minutes, 'happyaccess' ) ),
	esc_html( number_format_i18n( $minutes ) )
);
?>
</p>
<p style="margin:0 0 16px;"><?php esc_html_e( 'Codes are asked for only after the right password or in a logged-in session, so whoever typed them may know your password.', 'happyaccess' ); ?></p>
<p style="margin:0;">
<?php
printf(
	/* translators: %s: link to the lost password page. */
	esc_html__( "If this wasn't you, %s. That also ends the pause.", 'happyaccess' ),
	'<a href="' . esc_url( $reset_url ) . '">' . esc_html__( 'reset your password', 'happyaccess' ) . '</a>'
);
?>
</p>
