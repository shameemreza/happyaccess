<?php
/**
 * Email: many wrong two-step login codes on the site in the last hour.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 * @var int    $count     Wrong codes that sent this email.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;"><?php esc_html_e( 'Many wrong two-step login codes', 'happyaccess' ); ?></h1>
<p style="margin:0 0 16px;">
<?php
printf(
	/* translators: 1: number of wrong codes, 2: site name. */
	esc_html__( 'At least %1$s wrong two-step login codes were entered on %2$s in the last hour.', 'happyaccess' ),
	esc_html( number_format_i18n( $count ) ),
	esc_html( $site_name )
);
?>
</p>
<p style="margin:0 0 16px;"><?php esc_html_e( 'Nobody is locked out because of it. An account that gets 10 wrong codes in an hour pauses on its own, and its owner gets an email.', 'happyaccess' ); ?></p>
<p style="margin:0;color:#646970;"><?php esc_html_e( 'The HappyAccess activity log shows which accounts got the wrong codes.', 'happyaccess' ); ?></p>
