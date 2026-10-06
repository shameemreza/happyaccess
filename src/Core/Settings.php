<?php
/**
 * Plugin settings stored in one option.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the happyaccess_settings option. Unknown keys are dropped
 * and every value is cast to the type of its default.
 */
final class Settings {

	const OPTION = 'happyaccess_settings';

	/**
	 * Numeric limits by dotted path: array( min, max ).
	 */
	const RANGES = array(
		'security.max_attempts'        => array( 1, 20 ),
		'security.attempt_window'      => array( 60, 86400 ),
		'security.lockout_duration'    => array( 60, 86400 ),
		'security.site_code_cap'       => array( 5, 100 ),
		'security.recaptcha_threshold' => array( 0, 1 ),
		'privacy.retention_days'       => array( 1, 365 ),
		'support.default_duration'     => array( 3600, 2592000 ),
	);

	/**
	 * Allowed values for string settings by dotted path.
	 */
	const CHOICES = array(
		'security.proxy_header' => array( '', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ),
	);

	/**
	 * Default settings. Later stages add groups here.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'features' => array(
				'support_access' => true,
				'passwordless'   => false,
				'two_step'       => false,
			),
			'security' => array(
				'max_attempts'        => 5,
				'attempt_window'      => 900,
				'lockout_duration'    => 1800,
				'site_code_cap'       => 30,
				'proxy_header'        => '',
				'recaptcha_enabled'   => false,
				'recaptcha_site_key'  => '',
				'recaptcha_threshold' => 0.5,
			),
			'privacy'  => array(
				'logging'             => true,
				'retention_days'      => 30,
				'anonymize_ip'        => false,
				'delete_on_uninstall' => false,
			),
			'support'  => array(
				'default_duration' => 259200,
				'consent_given_at' => '',
				'consent_user_id'  => 0,
			),
		);
	}

	/**
	 * All settings, stored values merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return self::clean( self::defaults(), is_array( $stored ) ? $stored : array(), '' );
	}

	/**
	 * One setting by dotted path, for example "security.max_attempts".
	 *
	 * @param string $path     Dotted path.
	 * @param mixed  $fallback Returned when the path doesn't exist.
	 * @return mixed
	 */
	public static function get( $path, $fallback = null ) {
		$value = self::all();
		foreach ( explode( '.', (string) $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return $fallback;
			}
			$value = $value[ $part ];
		}
		return $value;
	}

	/**
	 * Merges changes into the stored settings and saves them.
	 *
	 * @param array $changes Nested array of changes.
	 * @return array The saved settings.
	 */
	public static function update( array $changes ) {
		$merged = self::clean( self::defaults(), array_replace_recursive( self::all(), $changes ), '' );
		update_option( self::OPTION, $merged, true );
		return $merged;
	}

	/**
	 * Keeps only known keys and casts each value.
	 *
	 * @param array  $defaults Defaults for this level.
	 * @param array  $values   Incoming values for this level.
	 * @param string $prefix   Dotted path of this level.
	 * @return array
	 */
	private static function clean( array $defaults, array $values, $prefix ) {
		$out = array();
		foreach ( $defaults as $key => $default_value ) {
			$path = '' === $prefix ? $key : $prefix . '.' . $key;
			$has  = array_key_exists( $key, $values );
			if ( is_array( $default_value ) ) {
				$out[ $key ] = self::clean( $default_value, $has && is_array( $values[ $key ] ) ? $values[ $key ] : array(), $path );
				continue;
			}
			$out[ $key ] = $has ? self::cast( $path, $default_value, $values[ $key ] ) : $default_value;
		}
		return $out;
	}

	/**
	 * Casts one value to the type of its default and applies limits.
	 *
	 * @param string $path           Dotted path.
	 * @param mixed  $default_value  Default value.
	 * @param mixed  $value          Incoming value.
	 * @return mixed
	 */
	private static function cast( $path, $default_value, $value ) {
		if ( is_bool( $default_value ) ) {
			return rest_sanitize_boolean( $value );
		}
		if ( is_int( $default_value ) || is_float( $default_value ) ) {
			$number = is_float( $default_value ) ? (float) $value : (int) $value;
			if ( isset( self::RANGES[ $path ] ) ) {
				$number = max( self::RANGES[ $path ][0], min( self::RANGES[ $path ][1], $number ) );
			}
			return is_float( $default_value ) ? (float) $number : (int) $number;
		}
		$text = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		if ( isset( self::CHOICES[ $path ] ) && ! in_array( $text, self::CHOICES[ $path ], true ) ) {
			return $default_value;
		}
		return $text;
	}
}
