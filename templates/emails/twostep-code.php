<?php
/**
 * Email: the code for the second step of a two-step login.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 * @var string $code      Login code, already formatted.
 * @var int    $minutes   Minutes until the code expires.
 * @var string $ip        IP address of the login attempt.
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
<p style="margin:0 0 8px;"><?php esc_html_e( 'Your two-step login code is', 'happyaccess' ); ?></p>
<p style="margin:0 0 16px;font-family:Menlo,Consolas,monospace;font-size:32px;font-weight:600;letter-spacing:4px;"><?php echo esc_html( $code ); ?></p>
<p style="margin:0 0 16px;">
<?php
echo esc_html(
	sprintf(
		/* translators: %d: minutes until the login code expires. */
		_n( 'It expires in %d minute.', 'It expires in %d minutes.', (int) $minutes, 'happyaccess' ),
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
		__( 'The login attempt came from IP address %s.', 'happyaccess' ),
		$ip
	)
);
?>
</p>
<p style="margin:0;color:#646970;"><?php esc_html_e( "If you didn't try to log in, change your password.", 'happyaccess' ); ?></p>
