<?php
/**
 * HTML email sender.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a template from templates/emails/ inside the shared layout and
 * sends it with wp_mail().
 */
final class Mailer {

	/**
	 * Sends one email.
	 *
	 * @param string $to       Recipient address.
	 * @param string $subject  Subject without the site name prefix.
	 * @param string $template Template name, for example "login-alert".
	 * @param array  $vars     Variables for the template.
	 * @return bool Whether wp_mail() accepted the message. False for an invalid address or a missing template.
	 */
	public static function send( $to, $subject, $template, array $vars ) {
		$to = is_string( $to ) ? trim( $to ) : '';
		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}

		$site_name = self::site_name();
		$subject   = sanitize_text_field( '[' . $site_name . '] ' . $subject );

		$vars['site_name'] = $site_name;
		$content           = self::render( sanitize_key( $template ), $vars );
		if ( null === $content ) {
			return false;
		}

		$body = self::render(
			'layout',
			array(
				'content'   => $content,
				'site_name' => $site_name,
				'subject'   => $subject,
			)
		);
		if ( null === $body ) {
			return false;
		}

		return (bool) wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Site name as plain text.
	 *
	 * @return string
	 */
	public static function site_name() {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Renders one template file with its variables.
	 *
	 * @param string $template Template name.
	 * @param array  $vars     Variables.
	 * @return string|null Markup, or null when the template file is missing.
	 */
	private static function render( $template, array $vars ) {
		$file = HAPPYACCESS_PLUGIN_DIR . 'templates/emails/' . $template . '.php';
		if ( '' === $template || ! is_readable( $file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template scope only; existing names are never overwritten.
		extract( $vars, EXTR_SKIP );
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}
}
