<?php
/**
 * Email: login link and code for the person getting support access.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 * @var string $link      Login link.
 * @var string $code_url  Address of the code screen.
 * @var string $code      Code, already formatted.
 * @var string $expires   Expiry in the site timezone.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;">
<?php
printf(
	/* translators: %s: site name. */
	esc_html__( 'Temporary access to %s', 'happyaccess' ),
	esc_html( $site_name )
);
?>
</h1>
<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Login link', 'happyaccess' ); ?></strong></p>
<p style="margin:0 0 16px;word-break:break-all;"><a href="<?php echo esc_url( $link ); ?>" style="color:#2271b1;"><?php echo esc_html( $link ); ?></a></p>
<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Or sign in with a code', 'happyaccess' ); ?></strong></p>
<p style="margin:0 0 8px;word-break:break-all;">
<?php
printf(
	/* translators: %s: address of the code screen. */
	esc_html__( 'Open %s and enter this code:', 'happyaccess' ),
	'<a href="' . esc_url( $code_url ) . '" style="color:#2271b1;">' . esc_html( $code_url ) . '</a>'
);
?>
</p>
<p style="margin:0 0 16px;font-family:Menlo,Consolas,monospace;font-size:24px;letter-spacing:2px;"><?php echo esc_html( $code ); ?></p>
<p style="margin:0 0 16px;">
<?php
printf(
	/* translators: %s: date and time the access ends. */
	esc_html__( 'Access ends %s.', 'happyaccess' ),
	esc_html( $expires )
);
?>
</p>
<p style="margin:0;color:#646970;"><?php esc_html_e( 'Anyone with the link or the code can log in. Keep this email private.', 'happyaccess' ); ?></p>
