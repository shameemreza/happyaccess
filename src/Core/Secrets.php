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
 * key also mixes in a salt from wp-config.php, so when the salts are defined
 * there, a database leak alone is not enough to reverse or forge anything.
 */
final class Secrets {

	const OPTION = 'happyaccess_secret';

	/**
	 * Raw site keys for this request, by blog ID.
	 *
	 * @var array<int, string>
	 */
	private static $cache = array();

	/**
	 * Forgets the per-request keys. For tests.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache = array();
	}

	/**
	 * Raw 32-byte site key, created on first use. A stored key is never
	 * rotated because of a failed read; only a value that exists but does
	 * not decode to 32 bytes is repaired.
	 *
	 * @return string
	 */
	public static function key() {
		$blog_id = get_current_blog_id();
		if ( isset( self::$cache[ $blog_id ] ) ) {
			return self::$cache[ $blog_id ];
		}

		$missing = new \stdClass();
		$stored  = get_option( self::OPTION, $missing );
		$raw     = self::decode_key( $stored );
		if ( null !== $raw ) {
			self::$cache[ $blog_id ] = $raw;
			return $raw;
		}

		$raw     = random_bytes( 32 );
		$encoded = base64_encode( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Stored binary key.

		if ( $stored === $missing ) {
			// Missing. INSERT IGNORE creates the row in one statement, so a request that lost the race leaves the winner's key alone.
			Installer::insert_option_once( self::OPTION, $encoded );
		} else {
			// Exists but unusable (empty, array, bad base64): repair it.
			update_option( self::OPTION, $encoded, false );
		}

		// Always re-read from the database and adopt what is stored, so a concurrent request's key wins.
		Installer::forget_option( self::OPTION );
		$current = self::decode_key( get_option( self::OPTION, '' ) );
		if ( null !== $current ) {
			$raw = $current;
		}
		// Otherwise keep the generated key in memory only: hashes will not match stored ones.
		self::$cache[ $blog_id ] = $raw;
		return $raw;
	}

	/**
	 * Decodes a stored key. Null unless it is strict base64 of 32 bytes.
	 *
	 * @param mixed $stored Stored option value.
	 * @return string|null
	 */
	private static function decode_key( $stored ) {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Stored binary key.
		return ( false !== $raw && 32 === strlen( $raw ) ) ? $raw : null;
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
	 * @throws \InvalidArgumentException For an engine other than "s1" or "o1".
	 * @throws \RuntimeException When openssl cannot encrypt.
	 */
	public static function encrypt_with( $engine, $plain ) {
		if ( 's1' !== $engine && 'o1' !== $engine ) {
			throw new \InvalidArgumentException( 'HappyAccess does not know that encryption engine.' );
		}
		$key = self::encryption_key();
		if ( 's1' === $engine ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Cipher text transport.
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			throw new \RuntimeException( 'HappyAccess could not encrypt the value.' );
		}
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
