<?php
/**
 * Client IP detection.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Uses REMOTE_ADDR unless the owner picked one trusted proxy header.
 */
final class ClientIp {

	/**
	 * Client IP for the current request.
	 *
	 * @return string
	 */
	public static function get() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are validated with FILTER_VALIDATE_IP in from_server().
		$ip       = self::from_server( $_SERVER );
		$filtered = apply_filters( 'happyaccess_client_ip', $ip );
		return self::valid( $filtered ) ? $filtered : $ip;
	}

	/**
	 * IP from a server array.
	 *
	 * @param array $server Usually $_SERVER.
	 * @return string
	 */
	public static function from_server( array $server ) {
		$header = (string) Settings::get( 'security.proxy_header', '' );
		if ( '' !== $header && ! empty( $server[ $header ] ) && is_string( $server[ $header ] ) ) {
			$parts = explode( ',', wp_unslash( $server[ $header ] ) );
			$first = trim( $parts[0] );
			if ( self::valid( $first ) ) {
				return $first;
			}
		}
		$remote = isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? trim( wp_unslash( $server['REMOTE_ADDR'] ) ) : '';
		return self::valid( $remote ) ? $remote : '0.0.0.0';
	}

	/**
	 * Whether a value is an IPv4 or IPv6 address.
	 *
	 * @param mixed $ip Value.
	 * @return bool
	 */
	public static function valid( $ip ) {
		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * Rate limit bucket: IPv6 by /64, IPv4 as is.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function bucket( $ip ) {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$binary = inet_pton( $ip );
			return inet_ntop( substr( $binary, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
		}
		return (string) $ip;
	}

	/**
	 * Zeroes the last IPv4 octet, or the last 80 bits of IPv6.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function anonymize( $ip ) {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return inet_ntop( substr( inet_pton( $ip ), 0, 6 ) . str_repeat( "\0", 10 ) );
		}
		return '0.0.0.0';
	}
}
