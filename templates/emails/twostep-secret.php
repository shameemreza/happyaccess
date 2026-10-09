<?php
/**
 * Email: stored authenticator app secrets can't be read any more.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 */

defined( 'ABSPATH' ) || exit;
?>
<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;"><?php esc_html_e( "Authenticator app codes can't be checked", 'happyaccess' ); ?></h1>
<p style="margin:0 0 16px;">
<?php
printf(
	/* translators: %s: site name. */
	esc_html__( "A user logged in to %s with an authenticator app secret that can't be read any more. This happens when the security keys and salts in wp-config.php change, or when the HappyAccess site key is lost in a move or a cleanup.", 'happyaccess' ),
	esc_html( $site_name )
);
?>
</p>
<p style="margin:0 0 16px;"><?php esc_html_e( 'Two-step login stays on for these users. They log in with an email code or a backup code, and they need to set up their authenticator app again from their profile or account page.', 'happyaccess' ); ?></p>
<p style="margin:0;color:#646970;"><?php esc_html_e( 'The HappyAccess activity log lists each account. This email is sent at most once a week.', 'happyaccess' ); ?></p>
