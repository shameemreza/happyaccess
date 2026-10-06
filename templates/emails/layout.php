<?php
/**
 * Email layout.
 *
 * @package HappyAccess
 *
 * @var string $content   Rendered template markup.
 * @var string $site_name Site name.
 * @var string $subject   Subject line.
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:24px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #dcdcde;border-radius:4px;">
<tr>
<td style="padding:24px;">
<?php
// Markup built by the content templates, which escape every value.
echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
</td>
</tr>
<tr>
<td style="padding:12px 24px;border-top:1px solid #dcdcde;font-size:12px;color:#646970;">
<?php
printf(
	/* translators: %s: site name. */
	esc_html__( 'Sent by HappyAccess on %s.', 'happyaccess' ),
	esc_html( $site_name )
);
?>
</td>
</tr>
</table>
</body>
</html>
