<?php
/**
 * The [happyaccess_login] shortcode.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\Passwordless;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the inline passwordless form for logged-out visitors, and nothing
 * for logged-in ones.
 */
final class Shortcode {

	const TAG = 'happyaccess_login';

	/**
	 * Adds the shortcode. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Attributes: redirect_to.
	 * @return string
	 */
	public static function render( $atts ) {
		if ( is_user_logged_in() ) {
			return '';
		}

		$atts = shortcode_atts( array( 'redirect_to' => '' ), is_array( $atts ) ? $atts : array(), self::TAG );

		return Forms::render(
			array(
				'redirect_to' => (string) $atts['redirect_to'],
				'context'     => 'shortcode',
			)
		);
	}
}
