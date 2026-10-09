<?php
/**
 * Inline passwordless form for WooCommerce login forms, the shortcode and the block.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\Passwordless;

use HappyAccess\Core\Recaptcha;
use HappyAccess\Core\Settings;
use HappyAccess\Login\Router;
use HappyAccess\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * The form holds no form element of its own, because WooCommerce prints it
 * inside its login form and a nested form would break both. The script
 * sends the fields to the REST routes instead. Without JavaScript, a link
 * leads to the wp-login.php request screen.
 *
 * Two forms on one page share one request cookie (the cookie name is fixed),
 * so a code asked for in one form is only good for the browser's latest
 * request, whichever form sent it. Each form still has its own ids and state.
 */
final class Forms {

	const HANDLE = 'happyaccess-login';

	/**
	 * Places a form can show up in.
	 */
	const CONTEXTS = array( 'woo_account', 'woo_checkout', 'shortcode', 'block' );

	/**
	 * Looks of the toggle.
	 */
	const STYLES = array( 'link', 'button' );

	/**
	 * Forms printed in this request, for unique ids.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Hooks the form into the WooCommerce login forms. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'woocommerce_login_form_end', array( __CLASS__, 'print_woo_form' ) );
	}

	/**
	 * Prints the form at the end of a WooCommerce login form. The classic
	 * checkout prints the same form through woocommerce_login_form(), so
	 * is_checkout() tells the two apart.
	 *
	 * @return void
	 */
	public static function print_woo_form() {
		if ( is_user_logged_in() ) {
			return;
		}

		$checkout = function_exists( 'is_checkout' ) && is_checkout();
		$context  = $checkout ? 'woo_checkout' : 'woo_account';
		if ( ! Settings::get( 'passwordless.show_on.' . $context ) ) {
			return;
		}

		if ( $checkout && self::on_order_page() ) {
			// Order-pay and order-received belong to one order, so a login there goes back to the same page.
			$redirect = self::current_url();
		} elseif ( $checkout ) {
			$redirect = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
		} else {
			// The checkout block's Log in link sends shoppers to My Account with a redirect_to back to checkout.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the value is validated before it is used.
			$redirect = LoginSteps::valid_redirect( isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '' );
			if ( '' === $redirect && function_exists( 'wc_get_page_permalink' ) ) {
				$redirect = (string) wc_get_page_permalink( 'myaccount' );
			}
		}

		$html = self::render(
			array(
				'redirect_to' => $redirect,
				'context'     => $context,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes every value.
	}

	/**
	 * Whether this is the pay page or the thank-you page of an order.
	 *
	 * @return bool
	 */
	private static function on_order_page() {
		return function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) );
	}

	/**
	 * The address of this request on this site. The scheme and host come
	 * from the home URL, so the request can't point it elsewhere.
	 *
	 * @return string
	 */
	private static function current_url() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$home = wp_parse_url( home_url() );
		if ( '' === $uri || ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}
		$origin = ( isset( $home['scheme'] ) ? $home['scheme'] : 'http' ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return esc_url_raw( $origin . $uri );
	}

	/**
	 * The form markup. Loads the script and stylesheet when it returns any.
	 *
	 * @param array $args redirect_to (string), context (woo_account, woo_checkout, shortcode or block)
	 *                    and style (link or button; anything else, or empty, means the site setting).
	 * @return string
	 */
	public static function render( array $args ) {
		if ( ! LoginSteps::db_ready() ) {
			return '';
		}

		$context  = isset( $args['context'] ) && in_array( $args['context'], self::CONTEXTS, true ) ? $args['context'] : 'shortcode';
		$redirect = LoginSteps::valid_redirect( isset( $args['redirect_to'] ) ? $args['redirect_to'] : '' );

		$file = HAPPYACCESS_PLUGIN_DIR . 'templates/login/passwordless-form.php';
		if ( ! is_readable( $file ) ) {
			return '';
		}

		$style = isset( $args['style'] ) && is_string( $args['style'] ) && in_array( $args['style'], self::STYLES, true ) ? $args['style'] : (string) Settings::get( 'passwordless.toggle_style' );

		++self::$count;
		$woo  = 0 === strpos( $context, 'woo_' );
		$vars = array(
			'form_id'      => 'happyaccess-pl-' . self::$count,
			'context'      => $context,
			'redirect'     => $redirect,
			'button_class' => self::button_class(),
			'toggle_class' => self::toggle_class( $style ),
			'input_class'  => $woo ? 'input-text' : 'input',
			'row_class'    => $woo ? 'form-row form-row-wide' : '',
			'fallback_url' => Router::url( 'request', '' !== $redirect ? array( 'redirect_to' => $redirect ) : array() ),
		);

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template scope only; existing names are never overwritten.
		extract( $vars, EXTR_SKIP );
		ob_start();
		include $file;
		$html = (string) ob_get_clean();

		self::enqueue();
		return $html;
	}

	/**
	 * Loads the script and stylesheet for this request, with the REST URL
	 * and the strings the script needs. Adds the settings only once. While
	 * reCAPTCHA is on, Google's script loads too, and the script sends a
	 * token with each request.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/login.css', HAPPYACCESS_PLUGIN_FILE ), array(), self::asset_version( 'assets/login.css' ) );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/login.js', HAPPYACCESS_PLUGIN_FILE ), array(), self::asset_version( 'assets/login.js' ), true );

		$settings = array(
			'url'    => rest_url( Routes::NS . RestController::BASE ),
			'header' => RestController::HEADER,
			'i18n'   => array(
				'empty'  => __( 'Enter your email or username.', 'happyaccess' ),
				'noCode' => __( 'Enter the login code from your email.', 'happyaccess' ),
				'error'  => __( 'Something went wrong. Try again.', 'happyaccess' ),
			),
		);

		$inline = 'var happyaccessLogin = ' . wp_json_encode( $settings ) . ';';
		if ( Recaptcha::load_script() ) {
			$inline .= ' var happyaccessRecaptcha = ' . wp_json_encode(
				array(
					'key'   => Recaptcha::site_key(),
					'field' => Recaptcha::FIELD,
				)
			) . ';';
		}
		wp_add_inline_script( self::HANDLE, $inline, 'before' );
	}

	/**
	 * The plugin version, plus the file's change time while WP_DEBUG is on,
	 * so a development site doesn't keep serving a script cached under the
	 * same version.
	 *
	 * @param string $path Path inside the plugin folder.
	 * @return string
	 */
	private static function asset_version( $path ) {
		$file = HAPPYACCESS_PLUGIN_DIR . $path;
		return HAPPYACCESS_VERSION . ( WP_DEBUG && is_readable( $file ) ? '.' . filemtime( $file ) : '' );
	}

	/**
	 * Classes for the toggle. The link style is a button drawn like a text
	 * link; the button style takes the theme button look.
	 *
	 * @param string $style link or button.
	 * @return string
	 */
	private static function toggle_class( $style ) {
		if ( 'button' === $style ) {
			return 'happyaccess-pl__toggle happyaccess-pl__toggle--button ' . self::button_class();
		}
		return 'happyaccess-pl__toggle happyaccess-pl__toggle--link';
	}

	/**
	 * Button classes: the classic button class, plus the block theme
	 * button class when the theme styles buttons through theme.json.
	 *
	 * @return string
	 */
	private static function button_class() {
		$classes = array( 'button' );
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && function_exists( 'wp_theme_get_element_class_name' ) ) {
			$classes[] = wp_theme_get_element_class_name( 'button' );
		}
		return trim( implode( ' ', array_filter( $classes ) ) );
	}
}
