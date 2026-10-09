<?php
/**
 * Renders HappyAccess screens inside the wp-login.php layout.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Login;

use HappyAccess\Core\Recaptcha;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over login_header() and login_footer(), which only exist
 * while wp-login.php is running. Step handlers return a response array and
 * never call these functions themselves, so they stay testable.
 */
final class Screens {

	/**
	 * Prints a full login-styled page.
	 *
	 * @param string         $title     Page title.
	 * @param string         $body_html Body markup. The caller has already escaped it.
	 * @param \WP_Error|null $errors    Errors to show above the body.
	 * @param string         $message   Message markup shown above the body.
	 * @return void
	 */
	public static function render( $title, $body_html, $errors = null, $message = '' ) {
		login_header( $title, $message, $errors );
		echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		login_footer();
	}

	/**
	 * Sends a handler response: redirects or renders, then stops.
	 *
	 * @param array $response Either type redirect with url, or type render with title, body, errors, message and, for a screen with a form, captcha.
	 * @return void
	 */
	public static function respond( array $response ) {
		$type = isset( $response['type'] ) ? $response['type'] : '';

		if ( 'redirect' === $type ) {
			wp_safe_redirect( $response['url'] );
			exit;
		}

		self::prepare( $response );
		self::render(
			isset( $response['title'] ) ? (string) $response['title'] : '',
			isset( $response['body'] ) ? (string) $response['body'] : '',
			isset( $response['errors'] ) && $response['errors'] instanceof \WP_Error ? $response['errors'] : null,
			isset( $response['message'] ) ? (string) $response['message'] : ''
		);
		exit;
	}

	/**
	 * Loads what a rendered screen needs before the page prints: the
	 * reCAPTCHA script, for a screen with a form, named by its action.
	 *
	 * @param array $response Handler response.
	 * @return void
	 */
	public static function prepare( array $response ) {
		if ( isset( $response['type'], $response['captcha'] ) && 'render' === $response['type'] && is_string( $response['captcha'] ) ) {
			Recaptcha::enqueue( $response['captcha'] );
		}
	}
}
