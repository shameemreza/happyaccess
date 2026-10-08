<?php
/**
 * Email: login code and login link for passwordless login.
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
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;">
<?php
printf(
	/* translators: %s: site name. */
	esc_html__( 'Log in to %s', 'happyaccess' ),
	esc_html( $site_name )
);
?>
</h1>
<p style="margin:0 0 8px;"><?php esc_html_e( 'Your login code is', 'happyaccess' ); ?></p>
<p style="margin:0 0 16px;font-family:Menlo,Consolas,monospace;font-size:32px;font-weight:600;letter-spacing:4px;"><?php echo esc_html( $code ); ?></p>
<p style="margin:0 0 8px;"><?php esc_html_e( 'Or open this login link:', 'happyaccess' ); ?></p>
<p style="margin:0 0 16px;word-break:break-all;"><a href="<?php echo esc_url( $link ); ?>" style="color:#2271b1;"><?php echo esc_html( $link ); ?></a></p>
<p style="margin:0 0 16px;">
<?php
echo esc_html(
	sprintf(
		/* translators: %d: minutes until the login code expires. */
		_n( 'The code and the link expire in %d minute.', 'The code and the link expire in %d minutes.', (int) $minutes, 'happyaccess' ),
		(int) $minutes
	)
);
?>
</p>
<p style="margin:0 0 16px;">
<?php
echo esc_html(
	sprintf(
		/* translators: %s: IP address. */
		__( 'The request came from IP address %s.', 'happyaccess' ),
		$ip
	)
);
?>
</p>
<p style="margin:0;color:#646970;"><?php esc_html_e( "If you didn't ask for this, you can ignore this email. Your password still works.", 'happyaccess' ); ?></p>
