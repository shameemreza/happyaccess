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
		// Only REMOTE_ADDR and the proxy header the owner picked, each cleaned as it is read.
		$server = array();
		foreach ( array( 'REMOTE_ADDR', (string) Settings::get( 'security.proxy_header', '' ) ) as $key ) {
			if ( '' !== $key && isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
				$server[ $key ] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}
		$ip       = self::from_server( $server );
		$filtered = apply_filters( 'happyaccess_client_ip', $ip );
		return self::valid( $filtered ) ? self::normalize( $filtered ) : $ip;
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
	 * Stops at the first hop that is not an IP, so entries further left,
	 * which the client controls, are never reached past a broken hop.
	 *
	 * @param string $header_value Header value.
	 * @return string Empty string when the walk hits an unparseable hop or the list is empty.
	 */
	private static function pick_from_list( $header_value ) {
		$rightmost = '';
		foreach ( array_reverse( explode( ',', $header_value ) ) as $entry ) {
			$entry = self::strip_port( trim( $entry ) );
			if ( ! self::valid( $entry ) ) {
				return '';
			}
			$ip = self::normalize( $entry );
			if ( '' === $rightmost ) {
				$rightmost = $ip;
			}
			if ( self::is_public( $ip ) ) {
				return $ip;
			}
		}
		return $rightmost;
	}

	/**
	 * Whether an IP can belong to one client on the public internet. Private,
	 * reserved and carrier-grade NAT (100.64.0.0/10) addresses are not.
	 * PHP 8.2 and later also know the other special-purpose ranges.
	 *
	 * @param string $ip A valid, normalized IP.
	 * @return bool
	 */
	private static function is_public( $ip ) {
		$flags = defined( 'FILTER_FLAG_GLOBAL_RANGE' ) ? constant( 'FILTER_FLAG_GLOBAL_RANGE' ) : FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, $flags ) ) {
			return false;
		}
		$long = false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? false : ip2long( $ip );
		// 100.64.0.0/10: the top 10 bits are 0110 0100 01.
		return false === $long || ( $long & 0xFFC00000 ) !== 0x64400000;
	}

	/**
	 * Removes a port from "[v6]:port" or "a.b.c.d:port".
	 *
	 * @param string $entry One header entry.
	 * @return string
	 */
	private static function strip_port( $entry ) {
		if ( '[' === substr( $entry, 0, 1 ) ) {
			$end = strpos( $entry, ']' );
			return false === $end ? $entry : substr( $entry, 1, $end - 1 );
		}
		if ( 1 === substr_count( $entry, ':' ) ) {
			$host = substr( $entry, 0, strpos( $entry, ':' ) );
			if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $host;
			}
		}
		return $entry;
	}

	/**
	 * Converts an IPv4-mapped IPv6 address to dotted IPv4.
	 *
	 * @param string $ip IP address. Other values are returned unchanged.
	 * @return string
	 */
	private static function normalize( $ip ) {
		if ( ! self::valid( $ip ) ) {
			return $ip;
		}
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
	 * Canonical text form of an IP, for storing and comparing allowlists:
	 * lower-case compressed IPv6, and IPv4-mapped IPv6 unwrapped to IPv4.
	 *
	 * @param string $ip IP address.
	 * @return string Empty string when the value is not a valid IP.
	 */
	public static function canonical( $ip ) {
		if ( ! self::valid( $ip ) ) {
			return '';
		}
		$binary = inet_pton( self::normalize( $ip ) );
		if ( false === $binary ) {
			return '';
		}
		$text = inet_ntop( $binary );
		return false === $text ? '' : $text;
	}

	/**
	 * Rate limit bucket: IPv6 by /64, IPv4 as is.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function bucket( $ip ) {
		$ip = self::normalize( $ip );
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
		$ip = self::normalize( $ip );
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
