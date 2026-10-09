<?php
/**
 * Email: an admin turned off the user's two-step login.
 *
 * @package HappyAccess
 *
 * @var string $site_name   Site name.
 * @var string $admin_name  Display name of the admin who turned it off.
 * @var string $setup_url   Where the user sets it up again.
 * @var string $setup_label Name of that page: your profile, or your account page.
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
	esc_html__( 'You can now log in to %s with your password alone.', 'happyaccess' ),
	esc_html( $site_name )
);
echo ' ';
printf(
	/* translators: %s: link to the user's profile or account page. */
	esc_html__( 'You can set it up again from %s.', 'happyaccess' ),
	'<a href="' . esc_url( $setup_url ) . '">' . esc_html( $setup_label ) . '</a>'
);
?>
</p>
<p style="margin:0;color:#646970;"><?php esc_html_e( "If you didn't ask for this, change your password and contact the site owner.", 'happyaccess' ); ?></p>
