<?php
/**
 * Keeps temporary support accounts away from credentials and API keys.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * On the protected level a temp admin may edit customer profiles but never
 * change a password or email, start a password reset, or mint WooCommerce
 * API keys. Custom and full passes are kept away from the creator's
 * account and their own login details.
 */
final class AccountGuard {

	/**
	 * Hooks everything the guard needs.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'freeze_credentials' ), PHP_INT_MAX, 4 );
		add_filter( 'send_password_change_email', array( __CLASS__, 'skip_change_email' ), PHP_INT_MAX, 3 );
		add_filter( 'send_email_change_email', array( __CLASS__, 'skip_change_email' ), PHP_INT_MAX, 3 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'block_reset' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_save_account_details_errors', array( __CLASS__, 'block_wc_account' ), 10, 2 );
		add_action( 'wp_ajax_woocommerce_update_api_key', array( __CLASS__, 'block_wc_api_key' ), 0 );
		// WooCommerce hooks wc-auth on parse_request at priority 0 when it loads, before this runs, so go first.
		add_action( 'parse_request', array( __CLASS__, 'block_wc_auth' ), PHP_INT_MIN );
	}

	/**
	 * Whether the current request runs as a temp user.
	 *
	 * @return bool
	 */
	public static function is_blocked_request() {
		return Capabilities::is_temp_user( get_current_user_id() );
	}

	/**
	 * Whether the current request runs as a temp user on the protected level.
	 *
	 * @return bool
	 */
	private static function is_protected_request() {
		return self::is_blocked_request() && 'protected' === CapabilityGuard::level_for( get_current_user_id() );
	}

	/**
	 * Whether the current temp user's rules cover changes to this account.
	 * On the protected level that is every account. On custom and full it
	 * is the grant's creator and the temp user's own account.
	 *
	 * @param int $user_id Account being changed.
	 * @return bool
	 */
	private static function guards_account( $user_id ) {
		if ( ! self::is_blocked_request() ) {
			return false;
		}
		$current = get_current_user_id();
		if ( 'protected' === CapabilityGuard::level_for( $current ) ) {
			return true;
		}
		$user_id = (int) $user_id;
		if ( $user_id === $current ) {
			return true;
		}
		$creator = CapabilityGuard::creator_for( $current );
		return $creator > 0 && $user_id === $creator;
	}

	/**
	 * Restores the stored password hash and email when a temp user updates an existing user.
	 * On custom and full this applies to the grant's creator and the temp user.
	 *
	 * @param array    $data     Data about to be saved, with the password already hashed.
	 * @param bool     $update   Whether this is an update.
	 * @param int|null $user_id  User id, null on create.
	 * @param array    $userdata Raw data passed to wp_insert_user().
	 * @return array
	 */
	public static function freeze_credentials( $data, $update, $user_id = null, $userdata = array() ) {
		unset( $userdata );
		if ( ! $update || ! is_array( $data ) || ! self::guards_account( (int) $user_id ) ) {
			return $data;
		}
		$stored = get_userdata( (int) $user_id );
		if ( ! $stored instanceof \WP_User ) {
			return $data;
		}
		$data['user_pass']  = $stored->user_pass;
		$data['user_email'] = $stored->user_email;
		return $data;
	}

	/**
	 * Skips the "password changed" and "email changed" notices when a temp user
	 * saves a profile. The change is frozen, so the customer would get a false alarm.
	 * On custom and full this applies to the grant's creator and the temp user.
	 *
	 * @param bool  $send     Whether to send the email.
	 * @param array $user     User data before the update.
	 * @param array $userdata Data passed to the update.
	 * @return bool
	 */
	public static function skip_change_email( $send, $user = array(), $userdata = array() ) {
		unset( $userdata );
		$user_id = is_array( $user ) && isset( $user['ID'] ) ? (int) $user['ID'] : 0;
		return self::guards_account( $user_id ) ? false : $send;
	}

	/**
	 * Stops password resets for temp users, and any reset started by a temp
	 * user. On custom and full only resets of the creator's account and the
	 * temp user's own account are stopped.
	 *
	 * @param bool $allow   Whether the reset is allowed.
	 * @param int  $user_id User the reset is for.
	 * @return bool
	 */
	public static function block_reset( $allow, $user_id = 0 ) {
		if ( Capabilities::is_temp_user( (int) $user_id ) || self::guards_account( (int) $user_id ) ) {
			return false;
		}
		return $allow;
	}

	/**
	 * Adds an error to the WooCommerce account details form for protected temp users.
	 *
	 * @param \WP_Error $errors Errors so far.
	 * @param mixed     $user   User being saved.
	 * @return \WP_Error
	 */
	public static function block_wc_account( $errors, $user = null ) {
		unset( $user );
		if ( $errors instanceof \WP_Error && self::is_protected_request() ) {
			$errors->add( 'happyaccess_temp', __( "Temporary support accounts can't change account details.", 'happyaccess' ) );
		}
		return $errors;
	}

	/**
	 * Stops protected temp users creating or editing WooCommerce API keys.
	 *
	 * @return void
	 */
	public static function block_wc_api_key() {
		if ( ! self::is_protected_request() ) {
			return;
		}
		self::log_blocked( 'ajax' );
		wp_send_json_error( array( 'message' => __( "Temporary support accounts can't manage API keys.", 'happyaccess' ) ), 403 );
	}

	/**
	 * Stops protected temp users approving WooCommerce REST API authorization requests.
	 *
	 * @param \WP $wp Request object.
	 * @return void
	 */
	public static function block_wc_auth( $wp ) {
		if ( ! $wp instanceof \WP || empty( $wp->query_vars['wc-auth-version'] ) || ! self::is_protected_request() ) {
			return;
		}
		self::log_blocked( 'wc-auth' );
		wp_die( esc_html__( "Temporary support accounts can't authorize API access.", 'happyaccess' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Logs a blocked API key request under the current temp user's grant.
	 * WooCommerce has no action for a new key, so the attempt is what gets logged.
	 *
	 * @param string $source ajax or wc-auth.
	 * @return void
	 */
	private static function log_blocked( $source ) {
		$user_id = get_current_user_id();
		AuditLog::add(
			'wc_key_blocked',
			array(
				'feature'  => 'support',
				'token_id' => Capabilities::grant_id( $user_id ),
				'user_id'  => $user_id,
				'summary'  => __( 'Blocked a WooCommerce API key request', 'happyaccess' ),
				'meta'     => array( 'source' => $source ),
			)
		);
	}
}
