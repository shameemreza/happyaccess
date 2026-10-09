<?php
/**
 * reCAPTCHA v3 on the HappyAccess login steps.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Loads Google's script on the HappyAccess login screens and checks the
 * token each POST step sends. Every step has its own action name, so a token
 * made on one screen can't be spent on another.
 *
 * When Google can't be reached, the public steps are refused, as 1.0.6 did:
 * a site owner turned the check on to keep bots out, and letting every login
 * through whenever the check is down would let a bot in at the moment it
 * can block the check. The two-step steps (code, email send, setup) run only
 * after a right password, so they go on instead: refusing there would lock
 * out every user with two-step login, admins included, and the only way back
 * is a login. A reachable Google that says no still refuses every step.
 * The log says what happened, once an hour.
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
	 * Action names, one per step.
	 */
	const ACTIONS = array( 'code', 'link', 'pl_request', 'pl_verify', 'twostep', 'twostep_setup' );

	/**
	 * Steps that go on when Google can't be reached. They come after a right
	 * password, so a bot has already failed to get here.
	 */
	const OPEN_WHEN_UNREACHABLE = array( 'twostep', 'twostep_setup' );

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
		if ( ! in_array( $action, self::ACTIONS, true ) || wp_script_is( self::HANDLE, 'enqueued' ) || ! self::load_script() ) {
			return;
		}
		wp_add_inline_script( self::HANDLE, self::form_script( $action ), 'after' );
	}

	/**
	 * The script that fills the hidden field on submit. Without Google's
	 * script, or when it fails, the form goes as it is and the step answers
	 * with the reload message.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	private static function form_script( $action ) {
		return sprintf(
			'(function(){var key=%1$s,action=%2$s,field=%3$s;' .
			'document.addEventListener("submit",function(e){var form=e.target,by=e.submitter,input;' .
			'if(!form.matches||!form.matches("form[name^=\"happyaccess\"],form[id^=\"happyaccess\"]")||!window.grecaptcha){return;}' .
			'input=form.querySelector("input[name=\""+field+"\"]");if(input&&input.value){return;}' .
			'e.preventDefault();if(form.hasAttribute("data-happyaccess-wait")){return;}form.setAttribute("data-happyaccess-wait","1");' .
			'if(!input){input=document.createElement("input");input.type="hidden";input.name=field;form.appendChild(input);}' .
			'function send(){form.removeAttribute("data-happyaccess-wait");if(!input.value||!form.requestSubmit){form.submit();}else if(by&&by.form===form){form.requestSubmit(by);}else{form.requestSubmit();}}' .
			'window.grecaptcha.ready(function(){window.grecaptcha.execute(key,{action:action}).then(function(t){input.value=t;send();},send);});' .
			'},true);})();',
			wp_json_encode( self::site_key() ),
			wp_json_encode( (string) $action ),
			wp_json_encode( self::FIELD )
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
		$open   = 'unavailable' === $reason && in_array( $action, self::OPEN_WHEN_UNREACHABLE, true );

		/**
		 * Overrides the reCAPTCHA result of a login step.
		 *
		 * @param bool   $passed Whether the check passed.
		 * @param string $action The step: code, link, pl_request, pl_verify, twostep or twostep_setup.
		 */
		if ( (bool) apply_filters( 'happyaccess_verify_captcha', '' === $reason || $open, $action ) ) {
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
		if ( '' === $token ) {
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

		$data = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			self::log_unavailable( $action, in_array( $action, self::OPEN_WHEN_UNREACHABLE, true ) );
			return 'unavailable';
		}

		if ( empty( $data['success'] ) ) {
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
	 * @param bool   $open   Whether the step went on.
	 * @return void
	 */
	private static function log_unavailable( $action, $open ) {
		if ( ! self::first_this_hour( self::UNAVAILABLE_TRANSIENT ) ) {
			return;
		}
		AuditLog::add(
			'captcha_unavailable',
			array(
				'feature' => 'core',
				'user_id' => 0,
				'summary' => __( "The security check couldn't reach Google", 'happyaccess' ),
				'meta'    => array(
					'step'    => $action,
					'outcome' => $open ? 'allowed' : 'refused',
				),
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
				'feature' => 'core',
				'user_id' => 0,
				'summary' => __( 'A login failed the security check', 'happyaccess' ),
				'meta'    => array(
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
