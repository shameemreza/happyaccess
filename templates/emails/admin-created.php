<?php
/**
 * Email: a support pass made or changed an administrator account, or gave
 * a role admin-level permissions.
 *
 * @package HappyAccess
 *
 * @var string   $site_name  Site name.
 * @var string   $variant    created, changed or role.
 * @var string   $label      Grant label.
 * @var string   $login      Login of the administrator account.
 * @var string   $user_email Email address of the administrator account.
 * @var string[] $changed    What changed, for the changed variant.
 * @var string   $role       Readable role name, for the role variant.
 * @var string   $change     role or default_role, for the role variant.
 * @var string[] $caps       Admin-level permissions involved, for the role variant.
 * @var string   $time       Time in the site timezone.
 * @var string   $users_url  Address of the Users screen.
 * @var bool     $more       Whether this is the last email before the cap.
 * @var string   $log_url    Address of the activity log.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;">
<?php
if ( 'role' === $variant ) {
	esc_html_e( 'A support pass gave a role admin-level permissions', 'happyaccess' );
} elseif ( 'changed' === $variant ) {
	esc_html_e( "A support pass changed an administrator's login details", 'happyaccess' );
} else {
	esc_html_e( 'A support pass made an administrator account', 'happyaccess' );
}
?>
</h1>
<p style="margin:0 0 16px;">
<?php
if ( 'role' === $variant && 'default_role' === $change ) {
	/* translators: 1: grant label, 2: site name. */
	printf( esc_html__( 'The support access "%1$s" made new accounts on %2$s get a role with admin-level permissions.', 'happyaccess' ), esc_html( $label ), esc_html( $site_name ) );
} elseif ( 'role' === $variant ) {
	/* translators: 1: grant label, 2: site name. */
	printf( esc_html__( 'The support access "%1$s" gave a role on %2$s admin-level permissions. Everyone with that role now has them.', 'happyaccess' ), esc_html( $label ), esc_html( $site_name ) );
} elseif ( 'changed' === $variant ) {
	/* translators: 1: grant label, 2: site name. */
	printf( esc_html__( 'The support access "%1$s" changed the login details of an administrator account on %2$s.', 'happyaccess' ), esc_html( $label ), esc_html( $site_name ) );
} else {
	/* translators: 1: grant label, 2: site name. */
	printf( esc_html__( 'The support access "%1$s" made an administrator account on %2$s.', 'happyaccess' ), esc_html( $label ), esc_html( $site_name ) );
}
?>
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
<?php if ( 'role' === $variant ) : ?>
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Role', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $role ); ?></td>
</tr>
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Permissions', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( implode( ', ', array_map( 'strval', $caps ) ) ); ?></td>
</tr>
<?php else : ?>
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Login', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $login ); ?></td>
</tr>
<tr>
<td style="padding:4px 16px 4px 0;color:#646970;"><?php esc_html_e( 'Email', 'happyaccess' ); ?></td>
<td style="padding:4px 0;"><?php echo esc_html( $user_email ); ?></td>
</tr>
<?php endif; ?>
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
<?php if ( ! empty( $more ) ) : ?>
<p style="margin:0 0 16px;"><?php esc_html_e( 'More changes like this may follow. See the activity log for the full list.', 'happyaccess' ); ?> <a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'Open the activity log', 'happyaccess' ); ?></a></p>
<?php endif; ?>
<p style="margin:0;color:#646970;"><?php esc_html_e( 'Full and custom support access can do this by design. If you did not expect it, revoke the access from the HappyAccess screen.', 'happyaccess' ); ?></p>
