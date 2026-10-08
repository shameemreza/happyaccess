<?php
/**
 * Backup codes for when the app or the email isn't at hand.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Codes;

defined( 'ABSPATH' ) || exit;

/**
 * Ten single-use codes per user. Only wp_hash_password() hashes are stored,
 * in one user meta row, and a used code's hash is removed in the same write
 * that accepts it.
 */
final class BackupCodes {

	/**
	 * Codes in a set.
	 */
	const COUNT = 10;

	/**
	 * Makes a new set, which replaces any old one.
	 *
	 * @param int $user_id User id.
	 * @return string[] The plain codes. They can't be read again.
	 */
	public static function generate( $user_id ) {
		$user_id = (int) $user_id;
		$codes   = array();
		$made    = 0;
		while ( $made < self::COUNT ) {
			$code = Codes::backup_code();
			if ( ! isset( $codes[ $code ] ) ) {
				$codes[ $code ] = true;
				++$made;
			}
		}
		$codes  = array_keys( $codes );
		$hashes = array();
		foreach ( $codes as $code ) {
			$hashes[] = wp_hash_password( $code );
		}
		update_user_meta( $user_id, UserState::META_BACKUP, wp_slash( $hashes ) );

		AuditLog::add(
			'twostep_backup_regenerated',
			self::log_args( $user_id, __( 'Backup codes renewed', 'happyaccess' ) )
		);
		return $codes;
	}

	/**
	 * Uses a backup code. Case, spaces and dashes in the input are ignored.
	 *
	 * @param int    $user_id User id.
	 * @param string $input   Typed code.
	 * @return bool True once per code.
	 */
	public static function use_code( $user_id, $input ) {
		$hash = self::match_code( $user_id, $input );
		return '' !== $hash && self::remove_code( $user_id, $hash );
	}

	/**
	 * Finds the stored hash a typed code matches, without using the code up.
	 * Case, spaces and dashes in the input are ignored.
	 *
	 * @param int    $user_id User id.
	 * @param string $input   Typed code.
	 * @return string The matching hash, or an empty string.
	 */
	public static function match_code( $user_id, $input ) {
		if ( ! is_string( $input ) ) {
			return '';
		}
		$code = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $input ) );
		if ( 10 !== strlen( $code ) ) {
			return '';
		}

		$row    = UserState::read_raw( (int) $user_id, UserState::META_BACKUP );
		$hashes = null === $row ? null : maybe_unserialize( $row['raw'] );
		if ( ! is_array( $hashes ) ) {
			return '';
		}

		$matched = '';
		foreach ( $hashes as $hash ) {
			if ( is_string( $hash ) && wp_check_password( $code, $hash ) && '' === $matched ) {
				$matched = $hash;
			}
		}
		return $matched;
	}

	/**
	 * Uses up the code with this stored hash. Only a request that still finds
	 * the hash in the list it writes over removes it, so a code can't be used
	 * twice.
	 *
	 * @param int    $user_id User id.
	 * @param string $hash    Hash from match_code().
	 * @return bool True when this call removed the hash.
	 */
	public static function remove_code( $user_id, $hash ) {
		$user_id = (int) $user_id;
		if ( ! is_string( $hash ) || '' === $hash ) {
			return false;
		}

		// A write of another code between the read and the swap makes the swap miss, so it reads again.
		for ( $try = 0; $try < 3; $try++ ) {
			$row    = UserState::read_raw( $user_id, UserState::META_BACKUP );
			$hashes = null === $row ? null : maybe_unserialize( $row['raw'] );
			if ( ! is_array( $hashes ) ) {
				return false;
			}
			$index = array_search( $hash, $hashes, true );
			if ( false === $index ) {
				return false;
			}
			unset( $hashes[ $index ] );
			if ( UserState::swap_raw( $user_id, $row, array_values( $hashes ) ) ) {
				AuditLog::add(
					'twostep_backup_used',
					self::log_args( $user_id, __( 'Backup code used', 'happyaccess' ) )
				);
				return true;
			}
		}
		return false;
	}

	/**
	 * Codes still unused.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function remaining( $user_id ) {
		$hashes = get_user_meta( (int) $user_id, UserState::META_BACKUP, true );
		return is_array( $hashes ) ? count( $hashes ) : 0;
	}

	/**
	 * The arguments of a log entry. Never a code.
	 *
	 * @param int    $user_id User id.
	 * @param string $summary Summary.
	 * @return array
	 */
	private static function log_args( $user_id, $summary ) {
		return array(
			'feature' => 'two_step',
			'user_id' => (int) $user_id,
			'summary' => $summary,
			'meta'    => array( 'remaining' => self::remaining( $user_id ) ),
		);
	}
}
