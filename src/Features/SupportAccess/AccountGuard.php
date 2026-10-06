<?php
/**
 * Keeps temporary support accounts away from credentials and API keys.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A temp admin may edit customer profiles but never change a password or
 * email, start a password reset, or mint WooCommerce API keys.
 */
final class AccountGuard {

	/**
	 * Hooks everything the guard needs.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'freeze_credentials' ), PHP_INT_MAX, 4 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'block_reset' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_save_account_details_errors', array( __CLASS__, 'block_wc_account' ), 10, 2 );
		add_action( 'wp_ajax_woocommerce_update_api_key', array( __CLASS__, 'block_wc_api_key' ), 0 );
		add_action( 'parse_request', array( __CLASS__, 'block_wc_auth' ), 0 );
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
	 * Restores the stored password hash and email when a temp user updates an existing user.
	 *
	 * @param array    $data     Data about to be saved, with the password already hashed.
	 * @param bool     $update   Whether this is an update.
	 * @param int|null $user_id  User id, null on create.
	 * @param array    $userdata Raw data passed to wp_insert_user().
	 * @return array
	 */
	public static function freeze_credentials( $data, $update, $user_id = null, $userdata = array() ) {
		unset( $userdata );
		if ( ! $update || ! is_array( $data ) || ! self::is_blocked_request() ) {
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
	 * Stops password resets for temp users, and any reset started by a temp user.
	 *
	 * @param bool $allow   Whether the reset is allowed.
	 * @param int  $user_id User the reset is for.
	 * @return bool
	 */
	public static function block_reset( $allow, $user_id = 0 ) {
		if ( Capabilities::is_temp_user( (int) $user_id ) || self::is_blocked_request() ) {
			return false;
		}
		return $allow;
	}

	/**
	 * Adds an error to the WooCommerce account details form for temp users.
	 *
	 * @param \WP_Error $errors Errors so far.
	 * @param mixed     $user   User being saved.
	 * @return \WP_Error
	 */
	public static function block_wc_account( $errors, $user = null ) {
		unset( $user );
		if ( $errors instanceof \WP_Error && self::is_blocked_request() ) {
			$errors->add( 'happyaccess_temp', __( "Temporary support accounts can't change account details.", 'happyaccess' ) );
		}
		return $errors;
	}

	/**
	 * Stops temp users creating or editing WooCommerce API keys.
	 *
	 * @return void
	 */
	public static function block_wc_api_key() {
		if ( ! self::is_blocked_request() ) {
			return;
		}
		wp_send_json_error( array( 'message' => __( "Temporary support accounts can't manage API keys.", 'happyaccess' ) ), 403 );
	}

	/**
	 * Stops temp users approving WooCommerce REST API authorization requests.
	 *
	 * @param \WP $wp Request object.
	 * @return void
	 */
	public static function block_wc_auth( $wp ) {
		if ( ! $wp instanceof \WP || empty( $wp->query_vars['wc-auth-version'] ) || ! self::is_blocked_request() ) {
			return;
		}
		wp_die( esc_html__( "Temporary support accounts can't authorize API access.", 'happyaccess' ), '', array( 'response' => 403 ) );
	}
}
