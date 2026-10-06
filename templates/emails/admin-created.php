<?php
/**
 * Email: a support pass made or changed an administrator account.
 *
 * @package HappyAccess
 *
 * @var string   $site_name  Site name.
 * @var string   $variant    created or changed.
 * @var string   $label      Grant label.
 * @var string   $login      Login of the administrator account.
 * @var string   $user_email Email address of the administrator account.
 * @var string[] $changed    What changed, for the changed variant.
 * @var string   $time       Time in the site timezone.
 * @var string   $users_url  Address of the Users screen.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;">
<?php
echo 'changed' === $variant
	? esc_html__( "A support pass changed an administrator's login details", 'happyaccess' )
	: esc_html__( 'A support pass made an administrator account', 'happyaccess' );
?>
</h1>
<p style="margin:0 0 16px;">
<?php
printf(
	'changed' === $variant
		/* translators: 1: grant label, 2: site name. */
		? esc_html__( 'The support access "%1$s" changed the login details of an administrator account on %2$s.', 'happyaccess' )
		/* translators: 1: grant label, 2: site name. */
		: esc_html__( 'The support access "%1$s" made an administrator account on %2$s.', 'happyaccess' ),
	esc_html( $label ),
	esc_html( $site_name )
);
?>
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Login', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $login ); ?></td>
</tr>
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Email', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $user_email ); ?></td>
</tr>
<?php if ( 'changed' === $variant && ! empty( $changed ) ) : ?>
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Changed', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( implode( ', ', array_map( 'strval', $changed ) ) ); ?></td>
</tr>
<?php endif; ?>
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Time', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $time ); ?></td>
</tr>
</table>
<p style="margin:0 0 16px;"><a href="<?php echo esc_url( $users_url ); ?>"><?php esc_html_e( 'Review the users on your site', 'happyaccess' ); ?></a></p>
<p style="margin:0;color:#646970;"><?php esc_html_e( 'Full and custom support access can do this by design. If you did not expect it, revoke the access from the HappyAccess screen.', 'happyaccess' ); ?></p>
