<?php
/**
 * Email: the account logged in from a device it hasn't used before.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 * @var string $time      Login time in the site timezone.
 * @var string $device    Browser and system, like "Chrome on macOS".
 * @var string $ip        IP address, anonymized when the setting is on.
 * @var string $reset_url Lost password page.
 * @var string $setup_url Where to turn on two-step login, empty when the tip doesn't apply.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;"><?php esc_html_e( 'New login to your account', 'happyaccess' ); ?></h1>
<p style="margin:0 0 16px;">
<?php
printf(
	/* translators: %s: site name. */
	esc_html__( 'Your account on %s just logged in from a device it has not used before.', 'happyaccess' ),
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
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Browser', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $device ); ?></td>
</tr>
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'IP address', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $ip ); ?></td>
</tr>
</table>
<p style="margin:0 0 16px;"><a href="<?php echo esc_url( $reset_url ); ?>"><?php esc_html_e( "If this wasn't you, change your password now.", 'happyaccess' ); ?></a></p>
<?php if ( '' !== $setup_url ) : ?>
<p style="margin:0;"><a href="<?php echo esc_url( $setup_url ); ?>"><?php esc_html_e( 'Turn on two-step login to keep your account safe.', 'happyaccess' ); ?></a></p>
<?php endif; ?>
