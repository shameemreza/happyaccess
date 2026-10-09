<?php
/**
 * Turns reCAPTCHA on for a test and answers for Google.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Recaptcha;
use HappyAccess\Core\Settings;

/**
 * A token "pass:<action>" passes for that action with a high score. Any
 * other token fails. Nothing ever leaves the test.
 */
trait HappyAccess_Test_Recaptcha {

	/**
	 * Requests that would have gone to Google.
	 *
	 * @var int
	 */
	protected $captcha_calls = 0;

	/**
	 * Switches the check on with a test site key and secret.
	 *
	 * @return void
	 */
	protected function turn_on_recaptcha() {
		Settings::update(
			array(
				'security' => array(
					'recaptcha_enabled'  => true,
					'recaptcha_site_key' => 'test-site-key',
				),
			)
		);
		update_option( Recaptcha::SECRET_OPTION, 'test-secret-value', false );
		$this->watch_recaptcha();
	}

	/**
	 * Counts and answers requests to Google, without turning the check on.
	 *
	 * @return void
	 */
	protected function watch_recaptcha() {
		$this->captcha_calls = 0;
		add_filter( 'pre_http_request', array( $this, 'answer_recaptcha' ), 10, 3 );
	}

	/**
	 * The token that passes for an action.
	 *
	 * @param string $action Action name.
	 * @return array The posted field.
	 */
	protected function captcha_field( $action ) {
		return array( Recaptcha::FIELD => 'pass:' . $action );
	}

	/**
	 * Google's answer.
	 *
	 * @param false|array $preempt Earlier answer.
	 * @param array       $args    Request args.
	 * @param string      $url     Request URL.
	 * @return false|array
	 */
	public function answer_recaptcha( $preempt, $args, $url ) {
		if ( Recaptcha::VERIFY_URL !== $url ) {
			return $preempt;
		}
		++$this->captcha_calls;
		$token = isset( $args['body']['response'] ) ? (string) $args['body']['response'] : '';
		$data  = 0 === strpos( $token, 'pass:' )
			? array(
				'success' => true,
				'action'  => substr( $token, 5 ),
				'score'   => 0.9,
			)
			: array( 'success' => false );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $data ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
