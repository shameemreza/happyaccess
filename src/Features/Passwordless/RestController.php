<?php
/**
 * Public REST routes behind the inline passwordless forms.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\Passwordless;

use HappyAccess\Core\Recaptcha;
use HappyAccess\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * The inline forms sit on pages that are often cached, so they can't carry
 * a nonce. Each route asks for a custom header instead. A custom header makes
 * the browser send a CORS preflight first, and WordPress doesn't allow this
 * header across origins, so another site's page can't post here. The verify
 * route also needs the request cookie, which is SameSite Lax and so is never
 * sent with a cross-site fetch. As a second layer, a request that names an
 * Origin (or, without one, a Referer) must name this site.
 *
 * Both routes run the same rules as the login screens through
 * LoginSteps::request_flow() and LoginSteps::verify_flow(), after the same
 * reCAPTCHA check, with the token in the JSON body.
 */
final class RestController {

	const HEADER = 'X-HappyAccess-Login';
	const BASE   = '/passwordless/';

	/**
	 * Hooks the routes and the no-store header. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store' ), 10, 3 );
	}

	/**
	 * Registers the request and verify routes.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			Routes::NS,
			self::BASE . 'request',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'request' ),
				'permission_callback' => array( __CLASS__, 'has_header' ),
				'args'                => array(
					'login'          => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					Recaptcha::FIELD => self::captcha_arg(),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			self::BASE . 'verify',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'verify' ),
				'permission_callback' => array( __CLASS__, 'has_header' ),
				'args'                => array(
					'code'           => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'remember'       => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'redirect_to'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( LoginSteps::class, 'valid_redirect' ),
					),
					Recaptcha::FIELD => self::captcha_arg(),
				),
			)
		);
	}

	/**
	 * The reCAPTCHA token argument of both routes.
	 *
	 * @return array
	 */
	private static function captcha_arg() {
		return array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	/**
	 * Lets in only requests that carry the custom header and that don't
	 * come from another site. Logged-out visitors are welcome: that is who
	 * these routes are for.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function has_header( \WP_REST_Request $request ) {
		if ( '1' !== $request->get_header( self::HEADER ) ) {
			return new \WP_Error( 'happyaccess_missing_header', __( 'This request is not allowed.', 'happyaccess' ), array( 'status' => 403 ) );
		}
		if ( ! self::from_this_site( $request ) ) {
			return new \WP_Error( 'happyaccess_cross_origin', __( 'This request is not allowed.', 'happyaccess' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Whether the Origin header, or the Referer when there is no Origin,
	 * names this site. A request with neither passes, because some browsers
	 * and privacy tools strip both and the custom header still applies. The
	 * scheme is ignored, because a proxy may change it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private static function from_this_site( \WP_REST_Request $request ) {
		$sent = trim( (string) $request->get_header( 'origin' ) );
		if ( '' === $sent ) {
			$sent = trim( (string) $request->get_header( 'referer' ) );
		}
		if ( '' === $sent ) {
			return true;
		}

		$theirs = self::authority( $sent );
		return '' !== $theirs && ( self::authority( home_url() ) === $theirs || self::authority( site_url() ) === $theirs );
	}

	/**
	 * Host and port of a URL, in lower case. Empty when there is no host.
	 *
	 * @param string $url URL, or an Origin value.
	 * @return string
	 */
	private static function authority( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * POST passwordless/request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function request( \WP_REST_Request $request ) {
		$captcha = Recaptcha::check( $request->get_param( Recaptcha::FIELD ), 'pl_request' );
		if ( is_wp_error( $captcha ) ) {
			return self::error( $captcha );
		}

		$result = LoginSteps::request_flow( (string) $request->get_param( 'login' ) );
		if ( is_wp_error( $result ) ) {
			return self::error( $result );
		}

		return new \WP_REST_Response(
			array(
				'message'    => $result['message'],
				'expires_in' => (int) $result['expires_in'],
			),
			200
		);
	}

	/**
	 * POST passwordless/verify.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function verify( \WP_REST_Request $request ) {
		$captcha = Recaptcha::check( $request->get_param( Recaptcha::FIELD ), 'pl_verify' );
		if ( is_wp_error( $captcha ) ) {
			return self::error( $captcha );
		}

		$result = LoginSteps::verify_flow(
			LoginSteps::request_key( wp_unslash( $_COOKIE ) ),
			(string) $request->get_param( 'code' ),
			(bool) $request->get_param( 'remember' ),
			(string) $request->get_param( 'redirect_to' )
		);
		if ( is_wp_error( $result ) ) {
			return self::error( $result );
		}

		return new \WP_REST_Response( array( 'redirect' => $result['redirect'] ), 200 );
	}

	/**
	 * Adds Cache-Control: no-store to every response of these routes,
	 * including the refusals WordPress makes before a callback runs.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public static function no_store( $response, $server, $request ) {
		unset( $server );
		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 0 === strpos( (string) $request->get_route(), '/' . Routes::NS . self::BASE ) ) {
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	/**
	 * A flow error as a REST error with its status.
	 *
	 * @param \WP_Error $error Error from a LoginSteps flow.
	 * @return \WP_Error
	 */
	private static function error( \WP_Error $error ) {
		$code     = (string) $error->get_error_code();
		$statuses = array(
			'locked'   => 429,
			'updating' => 503,
		);
		$status   = isset( $statuses[ $code ] ) ? $statuses[ $code ] : 400;
		if ( 0 !== strpos( $code, 'happyaccess_' ) ) {
			$code = 'happyaccess_' . $code;
		}
		return new \WP_Error( $code, $error->get_error_message(), array( 'status' => $status ) );
	}
}
