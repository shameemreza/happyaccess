<?php
/**
 * Codes, link keys and their hashes.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and checks every code and key HappyAccess hands out.
 * Only hashes are stored; comparisons are constant time.
 */
final class Codes {

	/**
	 * Base32-style alphabet without 0, 1, I and O.
	 */
	const BACKUP_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * Random numeric code.
	 *
	 * @param int $digits Length, 4 to 12.
	 * @return string
	 */
	public static function numeric( $digits ) {
		$digits = max( 4, min( 12, (int) $digits ) );
		$code   = '';
		for ( $i = 0; $i < $digits; $i++ ) {
			$code .= (string) random_int( 0, 9 );
		}
		return $code;
	}

	/**
	 * 256-bit URL-safe key (43 characters).
	 *
	 * @return string
	 */
	public static function link_key() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe random key.
	}

	/**
	 * 10-character backup code.
	 *
	 * @return string
	 */
	public static function backup_code() {
		$code = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$code .= self::BACKUP_ALPHABET[ random_int( 0, strlen( self::BACKUP_ALPHABET ) - 1 ) ];
		}
		return $code;
	}

	/**
	 * Digits only, so "4829 1375" and "4829-1375" match "48291375".
	 * For numeric codes only; backup codes are never normalized this way.
	 *
	 * @param string $input Raw input.
	 * @return string
	 */
	public static function normalize_code( $input ) {
		return (string) preg_replace( '/\D+/', '', (string) $input );
	}

	/**
	 * Stored hash of a numeric code. Numeric codes only: backup codes are
	 * hashed with wp_hash_password() in Stage 4, never with hash_code().
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function hash_code( $code ) {
		return Secrets::hmac( 'code:' . self::normalize_code( $code ) );
	}

	/**
	 * Stored hash of a link key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function hash_key( $key ) {
		return Secrets::hmac( 'key:' . (string) $key );
	}

	/**
	 * Checks a typed numeric code against a stored hash. Numeric codes only:
	 * backup codes are checked against wp_hash_password() hashes in Stage 4.
	 *
	 * @param string $input       Typed code.
	 * @param string $stored_hash Stored hash.
	 * @return bool
	 */
	public static function verify_code( $input, $stored_hash ) {
		if ( ! is_string( $input ) ) {
			return false;
		}
		$code = self::normalize_code( $input );
		if ( '' === $code || ! is_string( $stored_hash ) || '' === $stored_hash ) {
			return false;
		}
		return hash_equals( $stored_hash, self::hash_code( $code ) );
	}

	/**
	 * Checks a link key against a stored hash.
	 *
	 * @param string $input       Key from the URL or form.
	 * @param string $stored_hash Stored hash.
	 * @return bool
	 */
	public static function verify_key( $input, $stored_hash ) {
		if ( ! is_string( $input ) || '' === $input || ! is_string( $stored_hash ) || '' === $stored_hash ) {
			return false;
		}
		return hash_equals( $stored_hash, self::hash_key( $input ) );
	}

	/**
	 * Display form: "4829 1375" for 8 digits, "482 913" for 6.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function format_code( $code ) {
		$digits = self::normalize_code( $code );
		$group  = ( 8 === strlen( $digits ) ) ? 4 : 3;
		return implode( ' ', str_split( $digits, $group ) );
	}
}
