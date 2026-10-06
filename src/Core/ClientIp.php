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
	 * Expects a WP-slashed array like $_SERVER. With no proxy header configured
	 * the result is REMOTE_ADDR. When the owner picked a header, the list is
	 * read from the right: the nearest public hop wins, because the client
	 * controls the left side of X-Forwarded-For and can put anything there.
	 * If no entry is public, the rightmost valid IP is used. If none is valid,
	 * REMOTE_ADDR is used. Sites behind Cloudflare should pick CF-Connecting-IP,
	 * which holds a single value set by Cloudflare.
	 *
	 * @param array $server Usually $_SERVER.
	 * @return string
	 */
	public static function from_server( array $server ) {
		$header = (string) Settings::get( 'security.proxy_header', '' );
		if ( '' !== $header && ! empty( $server[ $header ] ) && is_string( $server[ $header ] ) ) {
			$picked = self::pick_from_list( wp_unslash( $server[ $header ] ) );
			if ( '' !== $picked ) {
				return $picked;
			}
		}
		$remote = isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? trim( wp_unslash( $server['REMOTE_ADDR'] ) ) : '';
		return self::valid( $remote ) ? self::normalize( $remote ) : '0.0.0.0';
	}

	/**
	 * Nearest public IP in a comma separated list, walking from the right.
	 *
	 * @param string $header_value Header value.
	 * @return string Empty string when the list has no valid IP.
	 */
	private static function pick_from_list( $header_value ) {
		$valid = array();
		foreach ( explode( ',', $header_value ) as $entry ) {
			$entry = trim( $entry );
			if ( self::valid( $entry ) ) {
				$valid[] = self::normalize( $entry );
			}
		}
		if ( empty( $valid ) ) {
			return '';
		}
		foreach ( array_reverse( $valid ) as $ip ) {
			if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE ) ) {
				return $ip;
			}
		}
		return end( $valid );
	}

	/**
	 * Converts an IPv4-mapped IPv6 address to dotted IPv4.
	 *
	 * @param string $ip Valid IP address.
	 * @return string
	 */
	private static function normalize( $ip ) {
		$binary = inet_pton( $ip );
		if ( false !== $binary && 16 === strlen( $binary ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $binary, 0, 12 ) ) {
			return inet_ntop( substr( $binary, 12 ) );
		}
		return $ip;
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
