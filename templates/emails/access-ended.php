<?php
/**
 * Email: support access ended.
 *
 * @package HappyAccess
 *
 * @var string $site_name   Site name.
 * @var string $label       Grant label.
 * @var string $reason      Why it ended, already readable.
 * @var int    $login_count Number of logins.
 * @var array  $activity    Activity lines, newest first.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;"><?php esc_html_e( 'Support access ended', 'happyaccess' ); ?></h1>
<p style="margin:0 0 16px;">
<?php
printf(
	/* translators: 1: grant label, 2: site name. */
	esc_html__( 'The support access "%1$s" on %2$s has ended.', 'happyaccess' ),
	esc_html( $label ),
	esc_html( $site_name )
);
?>
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Reason', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $reason ); ?></td>
</tr>
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Logins', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( (string) $login_count ); ?></td>
</tr>
</table>
<h2 style="margin:0 0 8px;font-size:16px;line-height:1.3;"><?php esc_html_e( 'Activity', 'happyaccess' ); ?></h2>
<?php if ( empty( $activity ) ) : ?>
<p style="margin:0;color:#646970;"><?php esc_html_e( 'No activity was recorded.', 'happyaccess' ); ?></p>
<?php else : ?>
<ul style="margin:0;padding:0 0 0 20px;">
	<?php foreach ( $activity as $happyaccess_line ) : ?>
<li style="margin:0 0 4px;"><?php echo esc_html( (string) $happyaccess_line ); ?></li>
	<?php endforeach; ?>
</ul>
<?php endif; ?>
