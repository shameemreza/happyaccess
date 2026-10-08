<?php
/**
 * Authenticator app codes (TOTP, RFC 6238).
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

defined( 'ABSPATH' ) || exit;

/**
 * Time-based codes: HMAC-SHA1, 30-second steps and 6 digits, which every
 * authenticator app supports. Secrets are base32 text (RFC 4648, no padding).
 */
final class Totp {

	/**
	 * Seconds in one time step.
	 */
	const PERIOD = 30;

	/**
	 * Digits in a code.
	 */
	const DIGITS = 6;

	/**
	 * Random bytes in a secret.
	 */
	const SECRET_BYTES = 20;

	const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * A new secret: 20 random bytes as 32 base32 characters.
	 *
	 * @return string
	 */
	public static function new_secret() {
		return self::base32_encode( random_bytes( self::SECRET_BYTES ) );
	}

	/**
	 * The time step a Unix time falls in.
	 *
	 * @param int $time Unix time.
	 * @return int
	 */
	public static function step_for( $time ) {
		return intdiv( max( 0, (int) $time ), self::PERIOD );
	}

	/**
	 * The code for one time step (RFC 4226 section 5.3 truncation).
	 *
	 * @param string $secret Base32 secret.
	 * @param int    $step   Time step: Unix time divided by 30.
	 * @return string Six digits, or an empty string for a secret that isn't base32.
	 */
	public static function code( $secret, $step ) {
		$key = self::base32_decode( $secret );
		if ( '' === $key ) {
			return '';
		}

		$step = max( 0, (int) $step );
		// Two 32-bit halves, so the counter packs the same on any build.
		$counter = pack( 'N2', ( $step >> 32 ) & 0xFFFFFFFF, $step & 0xFFFFFFFF );
		$hash    = hash_hmac( 'sha1', $counter, $key, true );
		$offset  = ord( $hash[19] ) & 0x0F;
		$value   = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 )
			| ( ord( $hash[ $offset + 1 ] ) << 16 )
			| ( ord( $hash[ $offset + 2 ] ) << 8 )
			| ord( $hash[ $offset + 3 ] );

		return str_pad( (string) ( $value % 1000000 ), self::DIGITS, '0', STR_PAD_LEFT );
	}

	/**
	 * Checks a typed code against the steps around the current time.
	 *
	 * Accepts the step before the current one, the current one and the next
	 * one, to cover clock drift. A step at or below the last used step is
	 * refused, so a code works once.
	 *
	 * @param string $secret    Base32 secret.
	 * @param string $code      Typed code. Spaces and dashes are ignored.
	 * @param int    $now       Current Unix time.
	 * @param int    $last_step Last step that was used, 0 for none.
	 * @return int|false The matched step, or false.
	 */
	public static function match( $secret, $code, $now, $last_step ) {
		if ( ! is_string( $code ) ) {
			return false;
		}
		$typed = preg_replace( '/[\s-]+/', '', $code );
		if ( ! is_string( $typed ) || 1 !== preg_match( '/^\d{6}$/', $typed ) ) {
			return false;
		}

		$current = self::step_for( $now );
		$matched = false;
		// Every step is checked, so the time taken doesn't say which one was close.
		foreach ( array( $current - 1, $current, $current + 1 ) as $step ) {
			if ( $step < 0 ) {
				continue;
			}
			$expected = self::code( $secret, $step );
			if ( '' !== $expected && hash_equals( $expected, $typed ) && $step > (int) $last_step ) {
				$matched = $step;
			}
		}
		return $matched;
	}

	/**
	 * The otpauth address an authenticator app reads from a QR code.
	 *
	 * @param string $secret  Base32 secret.
	 * @param string $account Account name, usually the email address.
	 * @param string $issuer  Site name.
	 * @return string
	 */
	public static function uri( $secret, $account, $issuer ) {
		$query = http_build_query(
			array(
				'secret'    => $secret,
				'issuer'    => $issuer,
				'algorithm' => 'SHA1',
				'digits'    => self::DIGITS,
				'period'    => self::PERIOD,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		return 'otpauth://totp/' . rawurlencode( $issuer . ':' . $account ) . '?' . $query;
	}

	/**
	 * Base32 text for bytes, without padding.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function base32_encode( $bytes ) {
		$bits = '';
		foreach ( str_split( $bytes ) as $char ) {
			$bits .= str_pad( decbin( ord( $char ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::ALPHABET[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	/**
	 * Bytes for base32 text. Spaces and case are ignored.
	 *
	 * @param string $text Base32 text.
	 * @return string Raw bytes, or an empty string when the text isn't base32.
	 */
	private static function base32_decode( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}
		$text = strtoupper( str_replace( ' ', '', rtrim( $text, '=' ) ) );
		if ( '' === $text || 1 !== preg_match( '/^[A-Z2-7]+$/', $text ) ) {
			return '';
		}

		$bits = '';
		foreach ( str_split( $text ) as $char ) {
			$bits .= str_pad( decbin( strpos( self::ALPHABET, $char ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}
}
