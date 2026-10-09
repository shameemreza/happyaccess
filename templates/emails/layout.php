<?php
/**
 * Email layout, the part before the content. The mailer joins it, the
 * content template's markup and layout-end.php, so the content is never
 * printed here.
 *
 * @package HappyAccess
 *
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
