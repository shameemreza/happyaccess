<?php
/**
 * reCAPTCHA v3 on the HappyAccess login steps.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Loads Google's script on the public HappyAccess login screens and checks
 * the token each of their POST steps sends. Every step has its own action
 * name, so a token made on one screen can't be spent on another.
 *
 * The two-step steps are left out: they come after a right password and
 * have their own per-account and per-IP limits, so a check there would only
 * add a way to lock people out.
 *
 * When Google can't be reached, the step is refused, as 1.0.6 did: a site
 * owner turned the check on to keep bots out, and letting every login
 * through whenever the check is down would let a bot in at the moment it
 * can block the check. The log says so, once an hour.
 */
final class Recaptcha {

	const SECRET_OPTION = 'happyaccess_recaptcha_secret_key';
	const SCRIPT_URL    = 'https://www.google.com/recaptcha/api.js';
	const VERIFY_URL    = 'https://www.google.com/recaptcha/api/siteverify';
	const HANDLE        = 'happyaccess-recaptcha';

	/**
	 * The posted field, or the JSON body key, that carries the token.
	 */
	const FIELD = 'happyaccess_recaptcha';

	/**
	 * Seconds to wait for Google.
	 */
	const TIMEOUT = 5;

	/**
	 * Action names, one per public step.
	 */
	const ACTIONS = array( 'code', 'link', 'pl_request', 'pl_verify' );

	/**
	 * Longest token sent to Google. Real ones are well under it.
	 */
	const MAX_TOKEN_BYTES = 4096;

	/**
	 * The characters a token is made of.
	 */
	const TOKEN_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';

	/**
	 * The response sent with a secret when a save checks it. Google answers
	 * it with invalid-input-response for a good secret.
	 */
	const KEY_CHECK_RESPONSE = 'happyaccess-key-check';

	/**
	 * Google's error codes for a secret it doesn't know.
	 */
	const BAD_SECRET_CODES = array( 'invalid-input-secret', 'missing-input-secret' );

	/**
	 * Milliseconds the form script waits for Google before it sends the
	 * form without a token.
	 */
	const WAIT_MS = 8000;

	/**
	 * Marks the submit script, so it is added only once.
	 */
	const FORM_SCRIPT_MARK = 'data-happyaccess-wait';

	/**
	 * Set while the log already has this hour's row.
	 */
	const UNAVAILABLE_TRANSIENT = 'happyaccess_captcha_unavailable_logged';
	const FAILED_TRANSIENT      = 'happyaccess_captcha_failed_logged';

	/**
	 * Whether the check runs: switched on, with a site key and a stored secret.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return true === Settings::get( 'security.recaptcha_enabled' ) && '' !== self::site_key() && '' !== self::secret();
	}

	/**
	 * The public site key.
	 *
	 * @return string
	 */
	public static function site_key() {
		return trim( (string) Settings::get( 'security.recaptcha_site_key', '' ) );
	}

	/**
	 * The secret. It never leaves this class except in the request to Google.
	 *
	 * @return string
	 */
	private static function secret() {
		$secret = get_option( self::SECRET_OPTION, '' );
		return is_string( $secret ) ? trim( $secret ) : '';
	}

	/**
	 * Loads Google's script while the check is on. The inline forms use it
	 * through assets/login.js.
	 *
	 * @return bool Whether the script is loaded.
	 */
	public static function load_script() {
		if ( ! self::enabled() ) {
			return false;
		}
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			wp_enqueue_script( self::HANDLE, add_query_arg( 'render', rawurlencode( self::site_key() ), self::SCRIPT_URL ), array(), '3', true );
		}
		return true;
	}

	/**
	 * Loads the script on a login screen with a form, plus a small script
	 * that adds the token to every HappyAccess form on the page when it is
	 * sent. One action per screen.
	 *
	 * @param string $action One of ACTIONS.
	 * @return void
	 */
	public static function enqueue( $action ) {
		if ( ! in_array( $action, self::ACTIONS, true ) || ! self::load_script() ) {
			return;
		}
		// The inline forms may have loaded Google's script already; the
		// submit script is still needed, once.
		foreach ( (array) wp_scripts()->get_data( self::HANDLE, 'after' ) as $script ) {
			if ( is_string( $script ) && false !== strpos( $script, self::FORM_SCRIPT_MARK ) ) {
				return;
			}
		}
		wp_add_inline_script( self::HANDLE, self::form_script( $action ), 'after' );
	}

	/**
	 * The script that fills the hidden field on submit. Without Google's
	 * script, when it fails, or when it hasn't answered after WAIT_MS, the
	 * form goes without a token and the step answers with the reload
	 * message. form.submit() skips the pressed button, so its name and
	 * value go in a hidden field.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	private static function form_script( $action ) {
		return sprintf(
			'(function(){var key=%1$s,action=%2$s,field=%3$s,wait=%4$d;' .
			'document.addEventListener("submit",function(e){var form=e.target,by=e.submitter,input,timer,done=false;' .
			'if(!form.matches||!form.matches("form[name^=\"happyaccess\"],form[id^=\"happyaccess\"]")||!window.grecaptcha){return;}' .
			'input=form.querySelector("input[name=\""+field+"\"]");if(input&&input.value){return;}' .
			'e.preventDefault();if(form.hasAttribute("data-happyaccess-wait")){return;}form.setAttribute("data-happyaccess-wait","1");' .
			'if(!input){input=document.createElement("input");input.type="hidden";input.name=field;form.appendChild(input);}' .
			'function send(){var carry;if(done){return;}done=true;window.clearTimeout(timer);form.removeAttribute("data-happyaccess-wait");' .
			'if(input.value&&form.requestSubmit){if(by&&by.form===form){form.requestSubmit(by);}else{form.requestSubmit();}return;}' .
			'if(by&&by.form===form&&by.name){carry=document.createElement("input");carry.type="hidden";carry.name=by.name;carry.value=by.value;form.appendChild(carry);}' .
			'form.submit();}' .
			'timer=window.setTimeout(send,wait);' .
			'window.grecaptcha.ready(function(){window.grecaptcha.execute(key,{action:action}).then(function(t){if(!done){input.value=t;}send();},send);});' .
			'},true);})();',
			wp_json_encode( self::site_key() ),
			wp_json_encode( (string) $action ),
			wp_json_encode( self::FIELD ),
			self::WAIT_MS
		);
	}

	/**
	 * The check a step runs after its nonce and before its rate limit.
	 * Passes without a word while the check is off.
	 *
	 * @param mixed  $token  Token from the request.
	 * @param string $action The step's action name.
	 * @return true|\WP_Error The error message is plain text.
	 */
	public static function check( $token, $action ) {
		if ( ! self::enabled() ) {
			return true;
		}
		return self::verify( $token, $action );
	}

	/**
	 * Asks Google about a token: it must pass, be made for this step and
	 * score at least the threshold. The happyaccess_verify_captcha filter
	 * has the final word.
	 *
	 * @param mixed  $token  Token from the request.
	 * @param string $action The step's action name.
	 * @return true|\WP_Error The error message is plain text.
	 */
	public static function verify( $token, $action ) {
		$action = (string) $action;
		$reason = self::reason( is_string( $token ) ? trim( $token ) : '', $action );

		/**
		 * Overrides the reCAPTCHA result of a login step.
		 *
		 * @param bool   $passed Whether the check passed.
		 * @param string $action The step: code, link, pl_request or pl_verify.
		 */
		if ( (bool) apply_filters( 'happyaccess_verify_captcha', '' === $reason, $action ) ) {
			return true;
		}

		if ( '' !== $reason && 'unavailable' !== $reason ) {
			self::log_failed( $reason, $action );
		}
		return self::error();
	}

	/**
	 * Why a token fails, or an empty string when it passes.
	 *
	 * @param string $token  Token, trimmed.
	 * @param string $action Action name.
	 * @return string missing, unavailable, rejected, action, score or empty.
	 */
	private static function reason( $token, $action ) {
		if ( ! self::well_formed( $token ) ) {
			return 'missing';
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'secret'   => self::secret(),
					'response' => $token,
					'remoteip' => ClientIp::get(),
				),
			)
		);

		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 0 === $status || $status >= 500 ) {
			self::log_unavailable( $action );
			return 'unavailable';
		}

		$data = 200 === $status ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return 'rejected';
		}
		if ( isset( $data['hostname'] ) && ! self::own_host( $data['hostname'] ) ) {
			return 'rejected';
		}
		if ( ! isset( $data['action'] ) || $action !== $data['action'] ) {
			return 'action';
		}
		$score = isset( $data['score'] ) && is_numeric( $data['score'] ) ? (float) $data['score'] : -1.0;
		if ( $score < (float) Settings::get( 'security.recaptcha_threshold', 0.5 ) ) {
			return 'score';
		}
		return '';
	}

	/**
	 * Whether a token is worth sending to Google: not empty, not too long,
	 * and only the characters tokens are made of.
	 *
	 * @param string $token Token, trimmed.
	 * @return bool
	 */
	private static function well_formed( $token ) {
		$length = strlen( $token );
		return $length > 0 && $length <= self::MAX_TOKEN_BYTES && strspn( $token, self::TOKEN_CHARS ) === $length;
	}

	/**
	 * Whether the host Google saw the token made on is this site's.
	 *
	 * @param mixed $hostname Host from Google's answer.
	 * @return bool
	 */
	private static function own_host( $hostname ) {
		if ( ! is_string( $hostname ) || '' === $hostname ) {
			return false;
		}
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}
		return in_array( strtolower( $hostname ), $hosts, true );
	}

	/**
	 * Asks Google once whether it knows a secret, before a save stores it
	 * or turns the check on with it. Only an answer that names the secret
	 * as bad counts; a network error or anything else lets the save go on,
	 * so an outage never blocks the settings. Nothing is logged.
	 *
	 * @param string $secret Secret to check.
	 * @return bool Whether Google said the secret is wrong.
	 */
	public static function secret_rejected( $secret ) {
		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'secret'   => (string) $secret,
					'response' => self::KEY_CHECK_RESPONSE,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['error-codes'] ) || ! is_array( $data['error-codes'] ) ) {
			return false;
		}
		return array() !== array_intersect( self::BAD_SECRET_CODES, $data['error-codes'] );
	}

	/**
	 * The one error every step shows, so it tells nothing about why.
	 *
	 * @return \WP_Error
	 */
	public static function error() {
		return new \WP_Error( 'happyaccess_captcha', __( "The security check didn't pass. Reload the page and try again.", 'happyaccess' ) );
	}

	/**
	 * Logs that Google couldn't be reached, once an hour.
	 *
	 * @param string $action Action name.
	 * @return void
	 */
	private static function log_unavailable( $action ) {
		if ( ! self::first_this_hour( self::UNAVAILABLE_TRANSIENT ) ) {
			return;
		}
		AuditLog::add(
			'captcha_unavailable',
			array(
				'feature'     => 'core',
				'user_id'     => 0,
				'summary_key' => 'captcha_unavailable',
				'meta'        => array( 'step' => $action ),
			)
		);
	}

	/**
	 * Logs a failed check, once an hour, so a bot can't fill the log.
	 *
	 * @param string $reason Why it failed.
	 * @param string $action Action name.
	 * @return void
	 */
	private static function log_failed( $reason, $action ) {
		if ( ! self::first_this_hour( self::FAILED_TRANSIENT ) ) {
			return;
		}
		AuditLog::add(
			'captcha_failed',
			array(
				'feature'     => 'core',
				'user_id'     => 0,
				'summary_key' => 'captcha_failed',
				'meta'        => array(
					'step'   => $action,
					'reason' => $reason,
				),
			)
		);
	}

	/**
	 * Whether this is the first time this hour, and marks it.
	 *
	 * @param string $name Transient name.
	 * @return bool
	 */
	private static function first_this_hour( $name ) {
		// Transient calls can add or delete an option, so they run inside the bypass.
		$seen = Internal::run(
			static function () use ( $name ) {
				return get_transient( $name );
			}
		);
		if ( false !== $seen ) {
			return false;
		}
		Internal::run(
			static function () use ( $name ) {
				set_transient( $name, 1, HOUR_IN_SECONDS );
			}
		);
		return true;
	}
}
