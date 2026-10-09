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
	 * Where a numeric code is used. Each place hashes the same digits
	 * differently, so a stored hash only ever passes where it was made.
	 */
	const PURPOSE_SUPPORT       = 'support';
	const PURPOSE_PASSWORDLESS  = 'passwordless';
	const PURPOSE_TWOSTEP_EMAIL = 'twostep_email';

	/**
	 * Option with the UTC time from which every new code hash has a purpose.
	 * A row made at or before it may still hold a hash without one, and
	 * that hash keeps passing until the row's own expiry ends it.
	 */
	const PURPOSE_SINCE_OPTION = 'happyaccess_code_purpose_since';

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
	 * Stored hash of a numeric code for one purpose. Numeric codes only:
	 * backup codes are hashed with wp_hash_password(), never with this.
	 *
	 * @param string $code    Code.
	 * @param string $purpose One of the PURPOSE_ constants.
	 * @return string
	 */
	public static function hash_code( $code, $purpose ) {
		return Secrets::hmac( 'code:' . (string) $purpose . ':' . self::normalize_code( $code ) );
	}

	/**
	 * The hash codes had before they had a purpose. Only for finding and
	 * checking rows made before PURPOSE_SINCE_OPTION; nothing new is
	 * stored this way.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function legacy_hash_code( $code ) {
		return Secrets::hmac( 'code:' . self::normalize_code( $code ) );
	}

	/**
	 * Whether a row may still hold a hash without a purpose: it was made at
	 * or before the time in PURPOSE_SINCE_OPTION. Without that time, or a
	 * row without a date, the answer is yes, so no code that is out is
	 * ever locked out.
	 *
	 * @param string|null $created_at Row creation time, UTC Y-m-d H:i:s.
	 * @return bool
	 */
	public static function accepts_legacy( $created_at ) {
		$since = get_option( self::PURPOSE_SINCE_OPTION, '' );
		if ( ! is_string( $since ) || '' === $since || ! is_string( $created_at ) || '' === $created_at ) {
			return true;
		}
		return strcmp( $created_at, $since ) <= 0;
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
	 * backup codes are checked against wp_hash_password() hashes.
	 *
	 * @param string $input       Typed code.
	 * @param string $stored_hash Stored hash.
	 * @param string $purpose     One of the PURPOSE_ constants.
	 * @param bool   $legacy      Whether the hash without a purpose passes too,
	 *                            from accepts_legacy() for the row.
	 * @return bool
	 */
	public static function verify_code( $input, $stored_hash, $purpose, $legacy = false ) {
		if ( ! is_string( $input ) ) {
			return false;
		}
		$code = self::normalize_code( $input );
		if ( '' === $code || ! is_string( $stored_hash ) || '' === $stored_hash ) {
			return false;
		}
		$match = hash_equals( $stored_hash, self::hash_code( $code, $purpose ) );
		if ( $legacy && hash_equals( $stored_hash, self::legacy_hash_code( $code ) ) ) {
			$match = true;
		}
		return $match;
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
