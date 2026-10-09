<?php
/**
 * Plain names for setting keys, and the readable line for a settings change.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A settings_changed row stores the dotted keys that changed. The line a
 * person reads is built from them when the row is read, in the viewer's
 * language, so rows logged before this class existed read the same way.
 */
final class SettingLabels {

	/**
	 * The feature switches, in the order the Settings screen lists them.
	 */
	const FEATURE_KEYS = array( 'features.support_access', 'features.passwordless', 'features.two_step' );

	/**
	 * Keys that record who finished setup and when. They are never listed.
	 */
	const CONSENT_KEYS = array( 'support.consent_given_at', 'support.consent_user_id' );

	/**
	 * Plain name for each dotted key, in screen order. A key that ends in
	 * ".*" covers every key under it, such as one role of a role policy.
	 *
	 * @return array<string,string>
	 */
	public static function all() {
		return array(
			'features.support_access'       => __( 'Temporary access', 'happyaccess' ),
			'features.passwordless'         => __( 'Passwordless login', 'happyaccess' ),
			'features.two_step'             => __( 'Two-step login', 'happyaccess' ),
			'security.max_attempts'         => __( 'Wrong codes before a pause', 'happyaccess' ),
			'security.lockout_duration'     => __( 'Wrong codes before a pause', 'happyaccess' ),
			'security.attempt_window'       => __( 'Time window for wrong codes', 'happyaccess' ),
			'security.site_code_cap'        => __( 'Wrong access codes allowed on the whole site', 'happyaccess' ),
			'privacy.retention_days'        => __( 'Keep activity for', 'happyaccess' ),
			'security.proxy_header'         => __( 'Visitor IP comes from', 'happyaccess' ),
			'support.default_duration'      => __( 'Default pass length', 'happyaccess' ),
			'privacy.anonymize_ip'          => __( 'Shorten IP addresses in the log', 'happyaccess' ),
			'privacy.logging'               => __( 'Keep a log', 'happyaccess' ),
			'privacy.delete_on_uninstall'   => __( 'Delete all HappyAccess data when the plugin is deleted', 'happyaccess' ),
			'security.recaptcha_enabled'    => __( 'reCAPTCHA', 'happyaccess' ),
			'security.recaptcha_site_key'   => __( 'reCAPTCHA site key', 'happyaccess' ),
			'security.recaptcha_secret_key' => __( 'reCAPTCHA secret key', 'happyaccess' ),
			'security.recaptcha_threshold'  => __( 'reCAPTCHA score needed', 'happyaccess' ),
			'passwordless.code_lifetime'    => __( 'Code lifetime', 'happyaccess' ),
			'passwordless.toggle_style'     => __( 'Button style', 'happyaccess' ),
			'passwordless.show_on.*'        => __( 'Show the email code option on', 'happyaccess' ),
			'passwordless.role_policy.*'    => __( 'Passwordless login by role', 'happyaccess' ),
			'two_step.role_policy.*'        => __( 'Two-step login by role', 'happyaccess' ),
			'two_step.grace_type'           => __( 'Grace period for required roles', 'happyaccess' ),
			'two_step.grace_logins'         => __( 'Grace period for required roles', 'happyaccess' ),
			'two_step.grace_days'           => __( 'Grace period for required roles', 'happyaccess' ),
			'two_step.block_xmlrpc'         => __( 'Block XML-RPC login for accounts with two-step login', 'happyaccess' ),
			'two_step.device_alert_roles.*' => __( 'New device alerts', 'happyaccess' ),
		);
	}

	/**
	 * Plain name for one dotted key. An unknown key comes back as it is, so
	 * nothing is dropped.
	 *
	 * @param string $key Dotted key, for example "privacy.retention_days".
	 * @return string
	 */
	public static function label( $key ) {
		$entry = self::entry( (string) $key );
		return '' === $entry ? (string) $key : self::all()[ $entry ];
	}

	/**
	 * The entry of all() that covers a key: the key itself, or a ".*" entry
	 * whose stem is the key or a parent of it.
	 *
	 * @param string $key Dotted key.
	 * @return string The entry, or an empty string for an unknown key.
	 */
	private static function entry( $key ) {
		$labels = self::all();
		if ( isset( $labels[ $key ] ) && '.*' !== substr( $key, -2 ) ) {
			return $key;
		}
		foreach ( array_keys( $labels ) as $entry ) {
			if ( '.*' !== substr( $entry, -2 ) ) {
				continue;
			}
			$stem = substr( $entry, 0, -2 );
			if ( $stem === $key || 0 === strpos( $key, $stem . '.' ) ) {
				return $entry;
			}
		}
		return '';
	}

	/**
	 * The entries of all() whose plain name, in the viewer's language,
	 * contains a search term, ignoring case. The log search uses them to
	 * find settings rows by the names a person reads.
	 *
	 * @param string $term Search term.
	 * @return string[] Dotted keys, and ".*" entries for a group of keys.
	 */
	public static function entries_matching( $term ) {
		$term = (string) $term;
		if ( '' === $term ) {
			return array();
		}
		$found = array();
		foreach ( self::all() as $entry => $label ) {
			if ( self::contains( $label, $term ) ) {
				$found[] = $entry;
			}
		}
		return $found;
	}

	/**
	 * Whether a text contains a term, ignoring case.
	 *
	 * @param string $text Text.
	 * @param string $term Term.
	 * @return bool
	 */
	public static function contains( $text, $term ) {
		if ( function_exists( 'mb_stripos' ) ) {
			return false !== mb_stripos( (string) $text, (string) $term, 0, 'UTF-8' );
		}
		return false !== stripos( (string) $text, (string) $term );
	}

	/**
	 * The new on or off of each feature switch among the changed keys, for
	 * the log row. Only booleans, never another setting's value.
	 *
	 * @param string[] $keys     Changed dotted keys.
	 * @param array    $settings Settings after the change.
	 * @return array<string,bool> Feature key to whether it is now on.
	 */
	public static function feature_states( array $keys, array $settings ) {
		$states = array();
		foreach ( self::FEATURE_KEYS as $key ) {
			$feature = substr( $key, strlen( 'features.' ) );
			if ( in_array( $key, $keys, true ) && isset( $settings['features'][ $feature ] ) ) {
				$states[ $feature ] = (bool) $settings['features'][ $feature ];
			}
		}
		return $states;
	}

	/**
	 * The line a person reads for a log row. A settings_changed row with
	 * the changed keys in its meta gets a line built from them; every other
	 * row keeps its stored summary.
	 *
	 * @param string $event  Event key.
	 * @param string $stored Stored summary.
	 * @param mixed  $meta   Decoded meta of the row.
	 * @return string
	 */
	public static function summary( $event, $stored, $meta ) {
		$stored = (string) $stored;
		if ( 'settings_changed' !== $event || ! is_array( $meta ) || ! isset( $meta['keys'] ) || ! is_array( $meta['keys'] ) ) {
			return $stored;
		}
		$keys = array_values( array_filter( $meta['keys'], 'is_string' ) );
		if ( ! $keys ) {
			return $stored;
		}
		$states = isset( $meta['features'] ) && is_array( $meta['features'] ) ? $meta['features'] : array();

		$on  = array();
		$off = array();
		foreach ( self::FEATURE_KEYS as $key ) {
			$feature = substr( $key, strlen( 'features.' ) );
			if ( ! in_array( $key, $keys, true ) || ! isset( $states[ $feature ] ) || ! is_bool( $states[ $feature ] ) ) {
				continue;
			}
			if ( $states[ $feature ] ) {
				$on[] = self::label( $key );
			} else {
				$off[] = self::label( $key );
			}
			$keys = array_values( array_diff( $keys, array( $key ) ) );
		}

		$switches = self::switches( $on, $off, in_array( 'support.consent_given_at', $keys, true ) );
		$names    = self::names( array_values( array_diff( $keys, self::CONSENT_KEYS ) ) );
		$changed  = $names ? sprintf(
			/* translators: %s: comma-separated names of the settings that changed. */
			__( 'Changed settings: %s', 'happyaccess' ),
			implode( ', ', $names )
		) : '';

		if ( '' !== $switches && '' !== $changed ) {
			/* translators: 1: what was finished or turned on or off, 2: the settings that changed. */
			return sprintf( __( '%1$s. %2$s', 'happyaccess' ), $switches, $changed );
		}
		if ( '' === $switches && '' === $changed ) {
			return EventLabels::label( 'settings_changed' );
		}
		return '' !== $switches ? $switches : $changed;
	}

	/**
	 * The plain names of keys, in screen order, each once. Unknown keys
	 * come last, in the order they were given.
	 *
	 * @param string[] $keys Dotted keys.
	 * @return string[]
	 */
	private static function names( array $keys ) {
		$order = array_flip( array_keys( self::all() ) );
		$rank  = array();
		foreach ( $keys as $index => $key ) {
			$entry  = self::entry( $key );
			$rank[] = array( '' === $entry ? count( $order ) : $order[ $entry ], $index, self::label( $key ) );
		}
		sort( $rank );
		return array_values( array_unique( array_column( $rank, 2 ) ) );
	}

	/**
	 * The part of the line that says setup was finished, or which feature
	 * switches went on or off.
	 *
	 * @param string[] $on    Names of features turned on.
	 * @param string[] $off   Names of features turned off.
	 * @param bool     $setup Whether the change finished setup.
	 * @return string An empty string when there is neither.
	 */
	private static function switches( array $on, array $off, $setup ) {
		$on_list  = $on ? wp_sprintf( '%l', $on ) : '';
		$off_list = $off ? wp_sprintf( '%l', $off ) : '';

		if ( $setup ) {
			if ( $on && $off ) {
				/* translators: 1: features turned on, 2: features turned off. */
				return sprintf( __( 'Finished setup, turned on %1$s and turned off %2$s', 'happyaccess' ), $on_list, $off_list );
			}
			if ( $on ) {
				/* translators: %s: features turned on, for example "Temporary access and Passwordless login". */
				return sprintf( __( 'Finished setup and turned on %s', 'happyaccess' ), $on_list );
			}
			if ( $off ) {
				/* translators: %s: features turned off. */
				return sprintf( __( 'Finished setup and turned off %s', 'happyaccess' ), $off_list );
			}
			return __( 'Finished setup', 'happyaccess' );
		}
		if ( $on && $off ) {
			/* translators: 1: features turned on, 2: features turned off. */
			return sprintf( __( 'Turned on %1$s and turned off %2$s', 'happyaccess' ), $on_list, $off_list );
		}
		if ( $on ) {
			/* translators: %s: features turned on. */
			return sprintf( __( 'Turned on %s', 'happyaccess' ), $on_list );
		}
		if ( $off ) {
			/* translators: %s: features turned off. */
			return sprintf( __( 'Turned off %s', 'happyaccess' ), $off_list );
		}
		return '';
	}
}
