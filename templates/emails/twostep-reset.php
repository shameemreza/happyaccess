<?php
/**
 * Email: an admin turned off the user's two-step login.
 *
 * @package HappyAccess
 *
 * @var string $site_name  Site name.
 * @var string $admin_name Display name of the admin who turned it off.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;"><?php esc_html_e( 'Two-step login was turned off', 'happyaccess' ); ?></h1>
<p style="margin:0 0 16px;">
<?php
printf(
	/* translators: %s: display name of the admin. */
	esc_html__( 'Two-step login was turned off for your account by %s.', 'happyaccess' ),
	esc_html( $admin_name )
);
?>
</p>
<p style="margin:0 0 16px;">
<?php
printf(
	/* translators: %s: site name. */
	esc_html__( 'You can now log in to %s with your password alone. Set up two-step login again from your profile.', 'happyaccess' ),
	esc_html( $site_name )
);
?>
</p>
<p style="margin:0;color:#646970;"><?php esc_html_e( "If you didn't ask for this, change your password and contact the site owner.", 'happyaccess' ); ?></p>
