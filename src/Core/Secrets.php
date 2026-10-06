<?php
/**
 * Site secret, HMAC and symmetric encryption.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The site key lives in the database (autoload off). Every hash and cipher
 * key also mixes in a salt from wp-config.php, so a database leak alone is
 * not enough to reverse or forge anything.
 */
final class Secrets {

	const OPTION = 'happyaccess_secret';

	/**
	 * Raw 32-byte site key, created on first use.
	 *
	 * @return string
	 */
	public static function key() {
		$stored = get_option( self::OPTION, '' );
		$raw    = is_string( $stored ) ? base64_decode( $stored, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Stored binary key.
		if ( false === $raw || 32 !== strlen( $raw ) ) {
			$raw = random_bytes( 32 );
			delete_option( self::OPTION );
			add_option( self::OPTION, base64_encode( $raw ), '', false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Stored binary key.
		}
		return $raw;
	}

	/**
	 * Keyed SHA-256 hash as hex.
	 *
	 * @param string $value Value to hash.
	 * @return string
	 */
	public static function hmac( $value ) {
		return hash_hmac( 'sha256', (string) $value, wp_salt( 'auth' ) . self::key() );
	}

	/**
	 * Encrypts with sodium when available, openssl otherwise.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		return self::encrypt_with( function_exists( 'sodium_crypto_secretbox' ) ? 's1' : 'o1', $plain );
	}

	/**
	 * Encrypts with a named engine: "s1" (sodium secretbox) or "o1" (AES-256-GCM).
	 *
	 * @param string $engine Engine id.
	 * @param string $plain  Plain text.
	 * @return string Engine-prefixed base64 payload.
	 */
	public static function encrypt_with( $engine, $plain ) {
		$key = self::encryption_key();
		if ( 's1' === $engine ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Cipher text transport.
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return 'o1:' . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Cipher text transport.
	}

	/**
	 * Decrypts a payload from encrypt(). Returns null when it can't be opened.
	 *
	 * @param string $payload Payload.
	 * @return string|null
	 */
	public static function decrypt( $payload ) {
		if ( ! is_string( $payload ) || strlen( $payload ) < 4 || ':' !== $payload[2] ) {
			return null;
		}
		$engine = substr( $payload, 0, 2 );
		$raw    = base64_decode( substr( $payload, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Cipher text transport.
		if ( false === $raw ) {
			return null;
		}
		$key = self::encryption_key();

		if ( 's1' === $engine && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce_bytes = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			if ( strlen( $raw ) < $nonce_bytes + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
				return null;
			}
			$plain = sodium_crypto_secretbox_open( substr( $raw, $nonce_bytes ), substr( $raw, 0, $nonce_bytes ), $key );
			return false === $plain ? null : $plain;
		}

		if ( 'o1' === $engine ) {
			if ( strlen( $raw ) < 28 ) {
				return null;
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? null : $plain;
		}

		return null;
	}

	/**
	 * 32-byte cipher key derived from the site key and a wp-config salt.
	 *
	 * @return string
	 */
	private static function encryption_key() {
		return hash_hmac( 'sha256', 'happyaccess-encryption', wp_salt( 'secure_auth' ) . self::key(), true );
	}
}
