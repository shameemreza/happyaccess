<?php
/**
 * Email: login codes are paused for the whole site.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 * @var int    $minutes   Minutes the pause lasts.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;"><?php esc_html_e( 'Login codes are paused', 'happyaccess' ); ?></h1>
<p style="margin:0 0 16px;">
<?php
printf(
	/* translators: %s: site name. */
	esc_html__( 'Too many wrong login codes were entered on %s. Emailed login codes are paused for the whole site.', 'happyaccess' ),
	esc_html( $site_name )
);
?>
</p>
<p style="margin:0 0 16px;">
<?php
printf(
	/* translators: %s: number of minutes. */
	esc_html( _n( 'The pause lasts about %s minute.', 'The pause lasts about %s minutes.', $minutes, 'happyaccess' ) ),
	esc_html( number_format_i18n( $minutes ) )
);
?>
</p>
<p style="margin:0;color:#646970;"><?php esc_html_e( "Login links still work. If you didn't expect this, check the HappyAccess activity log.", 'happyaccess' ); ?></p>
