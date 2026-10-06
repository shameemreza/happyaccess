<?php
/**
 * Email: someone signed in with support access.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 * @var string $label     Grant label.
 * @var string $time      Login time in the site timezone.
 * @var string $ip        IP address.
 * @var string $method    How they signed in.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;"><?php esc_html_e( 'Support access was used', 'happyaccess' ); ?></h1>
<p style="margin:0 0 16px;">
<?php
printf(
	/* translators: 1: grant label, 2: site name. */
	esc_html__( 'Someone signed in to %2$s with the support access "%1$s".', 'happyaccess' ),
	esc_html( $label ),
	esc_html( $site_name )
);
?>
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Time', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $time ); ?></td>
</tr>
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'IP address', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $ip ); ?></td>
</tr>
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Method', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $method ); ?></td>
</tr>
</table>
<p style="margin:0;color:#646970;"><?php esc_html_e( 'If you did not expect this, revoke the access from the HappyAccess screen.', 'happyaccess' ); ?></p>
