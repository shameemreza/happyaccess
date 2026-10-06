<?php
/**
 * HappyAccess capability and temp user checks.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * happyaccess_manage maps to manage_options for real admins and is always
 * denied for temporary users, so a temp admin can never run HappyAccess.
 */
final class Capabilities {

	const MANAGE = 'happyaccess_manage';

	/**
	 * Hooks the meta cap filter.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map' ), 10, 4 );
	}

	/**
	 * Maps happyaccess_manage to primitive caps.
	 *
	 * @param array  $caps    Primitive caps.
	 * @param string $cap     Requested cap.
	 * @param int    $user_id User id.
	 * @param array  $args    Extra args.
	 * @return array
	 */
	public static function map( $caps, $cap, $user_id, $args ) {
		unset( $args );
		if ( self::MANAGE !== $cap ) {
			return $caps;
		}
		if ( self::is_temp_user( (int) $user_id ) ) {
			return array( 'do_not_allow' );
		}
		return array( 'manage_options' );
	}

	/**
	 * Whether a user was created by Support Access.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_temp_user( $user_id ) {
		$user_id = (int) $user_id;
		return $user_id > 0 && (bool) get_user_meta( $user_id, 'happyaccess_temp_user', true );
	}

	/**
	 * Grant id of a temp user, 0 for anyone else.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function grant_id( $user_id ) {
		return self::is_temp_user( $user_id ) ? (int) get_user_meta( (int) $user_id, 'happyaccess_token_id', true ) : 0;
	}
}
