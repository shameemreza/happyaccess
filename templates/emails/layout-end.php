<?php
/**
 * Email layout, the part after the content.
 *
 * @package HappyAccess
 *
 * @var string $site_name Site name.
 */

defined( 'ABSPATH' ) || exit;
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
