<?php
/**
 * The lines a person reads for log rows, built when they are shown.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Each writer stores a text key and its values in the row's meta (see
 * AuditLog::add()). The line is built from them when the row is read, in
 * the viewer's language, so the Activity tab, the CSV export and the
 * privacy export all read the same way whoever wrote the row.
 *
 * Values are names and numbers: a pass label, a user login, a plugin name.
 * A value that is a list is joined with commas. Words of the sentence
 * itself always come from the template, never from a value.
 */
final class LogText {

	/**
	 * Meta key of the text key.
	 */
	const META_KEY = 'summary_key';

	/**
	 * Meta key of the values.
	 */
	const META_ARGS = 'summary_args';

	/**
	 * Most placeholders a template may use. Missing values are blank, so a
	 * row with too few values still reads.
	 */
	const MAX_ARGS = 9;

	/**
	 * Every template, by text key. An entry is a string, or an array with:
	 * - text:   the template.
	 * - plural: a nooped plural, used instead of text.
	 * - count:  index of the value that picks the plural form.
	 * - empty:  index of a value to the words shown when it is empty.
	 *
	 * @return array<string,string|array>
	 */
	public static function templates() {
		return array(
			// Temporary access.
			/* translators: %s: label of the support pass. */
			'grant_created'              => __( 'Temporary access granted to %s', 'happyaccess' ),
			/* translators: %s: label of the support pass. */
			'grant_extended'             => __( 'Temporary access extended for %s', 'happyaccess' ),
			/* translators: %s: label of the support pass. */
			'grant_suspended'            => __( 'Temporary access suspended for %s', 'happyaccess' ),
			/* translators: %s: label of the support pass. */
			'grant_resumed'              => __( 'Temporary access resumed for %s', 'happyaccess' ),
			/* translators: %s: label of the support pass. */
			'grant_regenerated'          => __( 'Temporary access code and link replaced for %s', 'happyaccess' ),
			/* translators: %s: label of the support pass. */
			'grant_ended'                => __( 'Temporary access ended for %s', 'happyaccess' ),
			/* translators: %s: label of the support pass. */
			'bundle_emailed'             => __( 'Access details emailed for %s', 'happyaccess' ),
			'emergency_lock'             => array(
				/* translators: %d: number of support passes ended. */
				'plural' => _n_noop( 'Emergency lock ended %d grant.', 'Emergency lock ended %d grants.', 'happyaccess' ),
				'count'  => 0,
			),
			/* translators: %s: label of the support grant. */
			'login_success'              => __( 'Temporary access used: %s', 'happyaccess' ),
			'login_setup_failed'         => __( "Couldn't set up the support account", 'happyaccess' ),
			/* translators: %s: user login. */
			'admin_account_created'      => __( 'Made an administrator account: %s', 'happyaccess' ),
			/* translators: %s: user login. */
			'admin_account_changed'      => __( 'Changed login details for administrator: %s', 'happyaccess' ),
			/* translators: %s: role name. */
			'admin_role_default'         => __( 'Made new accounts get an admin-level role: %s', 'happyaccess' ),
			/* translators: %s: role name. */
			'admin_role_granted'         => __( 'Gave admin-level permissions to the role: %s', 'happyaccess' ),
			'wc_key_blocked'             => __( 'Blocked a WooCommerce API key request', 'happyaccess' ),
			'roles_changed_plugin_work'  => __( 'Roles changed while activating or updating a plugin', 'happyaccess' ),
			'roles_changed'              => __( 'Changed role permissions', 'happyaccess' ),
			'role_change_blocked'        => __( 'Blocked a change to role permissions outside plugin activation or update', 'happyaccess' ),

			// What a support pass did.
			/* translators: %s: plugin name. */
			'plugin_activated'           => __( 'Activated plugin: %s', 'happyaccess' ),
			/* translators: %s: plugin name. */
			'plugin_deactivated'         => __( 'Deactivated plugin: %s', 'happyaccess' ),
			/* translators: %s: plugin file. */
			'plugin_deleted'             => __( 'Deleted plugin: %s', 'happyaccess' ),
			'core_updated'               => __( 'Updated WordPress core', 'happyaccess' ),
			'translations_updated'       => __( 'Updated translations', 'happyaccess' ),
			'plugins_updated'            => array(
				/* translators: %s: comma-separated plugin names. */
				'text'  => __( 'Installed or updated plugin: %s', 'happyaccess' ),
				'empty' => array( 0 => __( '(unknown)', 'happyaccess' ) ),
			),
			'themes_updated'             => array(
				/* translators: %s: comma-separated theme names. */
				'text'  => __( 'Installed or updated theme: %s', 'happyaccess' ),
				'empty' => array( 0 => __( '(unknown)', 'happyaccess' ) ),
			),
			/* translators: %s: theme name. */
			'theme_switched'             => __( 'Switched theme to %s', 'happyaccess' ),
			/* translators: %s: theme folder name. */
			'theme_deleted'              => __( 'Deleted theme: %s', 'happyaccess' ),
			'post_created'               => array(
				/* translators: 1: content type, like "Page". 2: title. 3: id. */
				'text'  => __( 'Created %1$s: %2$s (#%3$d)', 'happyaccess' ),
				'empty' => array( 1 => __( '(no title)', 'happyaccess' ) ),
			),
			'post_updated'               => array(
				/* translators: 1: content type, like "Page". 2: title. 3: id. */
				'text'  => __( 'Updated %1$s: %2$s (#%3$d)', 'happyaccess' ),
				'empty' => array( 1 => __( '(no title)', 'happyaccess' ) ),
			),
			'post_trashed'               => array(
				/* translators: 1: content type, like "Page". 2: title. 3: id. */
				'text'  => __( 'Trashed %1$s: %2$s (#%3$d)', 'happyaccess' ),
				'empty' => array( 1 => __( '(no title)', 'happyaccess' ) ),
			),
			'post_deleted'               => array(
				/* translators: 1: content type, like "Page". 2: title. 3: id. */
				'text'  => __( 'Deleted %1$s: %2$s (#%3$d)', 'happyaccess' ),
				'empty' => array( 1 => __( '(no title)', 'happyaccess' ) ),
			),
			/* translators: 1: order number. 2: old status. 3: new status. */
			'order_status_changed'       => __( 'Order #%1$d: %2$s to %3$s', 'happyaccess' ),
			/* translators: %s: user login. */
			'user_updated'               => __( 'Updated user: %s', 'happyaccess' ),
			'user_created'               => array(
				/* translators: 1: user login, 2: comma separated role slugs. */
				'text'  => __( 'Created user: %1$s (%2$s)', 'happyaccess' ),
				'empty' => array( 1 => __( 'none', 'happyaccess' ) ),
			),
			'user_role_changed'          => array(
				/* translators: 1: user login, 2: old role slugs, 3: new role slug. */
				'text'  => __( 'Changed role for %1$s: %2$s to %3$s', 'happyaccess' ),
				'empty' => array(
					1 => __( 'none', 'happyaccess' ),
					2 => __( 'none', 'happyaccess' ),
				),
			),
			'user_role_added'            => array(
				/* translators: 1: user login, 2: role slug. */
				'text'  => __( 'Added role to %1$s: %2$s', 'happyaccess' ),
				'empty' => array( 1 => __( 'none', 'happyaccess' ) ),
			),
			/* translators: %d: privacy request id. */
			'privacy_erased'             => __( 'Ran a personal data erasure (#%d)', 'happyaccess' ),
			/* translators: %d: webhook id. */
			'wc_webhook_created'         => __( 'Created WooCommerce webhook #%d', 'happyaccess' ),
			'settings_saved'             => array(
				/* translators: %d: number of options saved. */
				'plural' => _n_noop( 'Saved settings: %d option', 'Saved settings: %d options', 'happyaccess' ),
				'count'  => 0,
			),

			// Core.
			/* translators: 1: version before the update, 2: version after it. */
			'plugin_upgraded'            => __( 'Upgraded from %1$s to %2$s', 'happyaccess' ),
			'captcha_unavailable'        => __( "The security check couldn't reach Google", 'happyaccess' ),
			'captcha_failed'             => __( 'A login failed the security check', 'happyaccess' ),

			// Passwordless login.
			'passwordless_requested'     => __( 'Login code requested', 'happyaccess' ),
			'passwordless_rate_limited'  => __( 'Login code not sent: too many requests', 'happyaccess' ),
			'passwordless_not_created'   => __( 'Login code could not be made', 'happyaccess' ),
			'passwordless_attempts'      => __( 'Login code cancelled after too many wrong tries', 'happyaccess' ),
			'passwordless_invalid_code'  => __( 'Login code did not work', 'happyaccess' ),
			'passwordless_invalid_link'  => __( 'Login link did not work', 'happyaccess' ),
			'passwordless_login'         => __( 'Logged in with a login code or link', 'happyaccess' ),
			'passwordless_site_locked'   => __( 'Login codes paused for the whole site after too many wrong codes', 'happyaccess' ),
			'passwordless_policy_paused' => __( 'Password allowed for an email code only account because login codes are not working', 'happyaccess' ),

			// Two-step login.
			'twostep_secret_unreadable'  => __( "Authenticator app secret can't be read, so email and backup codes stand in", 'happyaccess' ),
			'twostep_app_enabled'        => __( 'Two-step login turned on with an authenticator app', 'happyaccess' ),
			'twostep_app_disabled'       => __( 'Authenticator app turned off for two-step login', 'happyaccess' ),
			'twostep_email_enabled'      => __( 'Two-step login turned on with email codes', 'happyaccess' ),
			'twostep_email_disabled'     => __( 'Email codes turned off for two-step login', 'happyaccess' ),
			'twostep_reset'              => __( 'Two-step login reset', 'happyaccess' ),
			'twostep_passed'             => __( 'Two-step login passed', 'happyaccess' ),
			'twostep_failed'             => __( 'Two-step login code did not work', 'happyaccess' ),
			'twostep_locked'             => __( 'Two-step login cancelled after too many wrong codes', 'happyaccess' ),
			'twostep_account_paused'     => __( 'Two-step login paused for this account after too many wrong codes', 'happyaccess' ),
			'twostep_site_alert'         => __( 'Many wrong two-step login codes on the site in the last hour', 'happyaccess' ),
			'twostep_skipped'            => __( 'Two-step login setup put off', 'happyaccess' ),
			'twostep_backup_regenerated' => __( 'Backup codes renewed', 'happyaccess' ),
			'twostep_backup_used'        => __( 'Backup code used', 'happyaccess' ),
			/* translators: %s: browser and system, like "Chrome on macOS". */
			'new_device_login'           => __( 'Logged in from a new device: %s', 'happyaccess' ),
		);
	}

	/**
	 * Whether a text key has a template.
	 *
	 * @param mixed $key Text key.
	 * @return bool
	 */
	public static function exists( $key ) {
		return is_string( $key ) && array_key_exists( $key, self::templates() );
	}

	/**
	 * The line for a text key and its values, in the current language.
	 *
	 * @param string $key  Text key.
	 * @param array  $args Values, in placeholder order.
	 * @return string An empty string for an unknown key.
	 */
	public static function render( $key, array $args = array() ) {
		$templates = self::templates();
		if ( ! is_string( $key ) || ! isset( $templates[ $key ] ) ) {
			return '';
		}
		$entry = is_array( $templates[ $key ] ) ? $templates[ $key ] : array( 'text' => $templates[ $key ] );
		$empty = isset( $entry['empty'] ) ? $entry['empty'] : array();

		$values = array();
		foreach ( array_slice( array_values( $args ), 0, self::MAX_ARGS ) as $index => $arg ) {
			$value = self::value( $arg );
			if ( '' === $value && isset( $empty[ $index ] ) ) {
				$value = $empty[ $index ];
			}
			$values[] = $value;
		}

		if ( isset( $entry['plural'] ) ) {
			$count = isset( $entry['count'], $values[ $entry['count'] ] ) ? (int) $values[ $entry['count'] ] : 0;
			$text  = translate_nooped_plural( $entry['plural'], $count, 'happyaccess' );
		} else {
			$text = $entry['text'];
		}

		// Extra values are ignored, and missing ones read as blank.
		return vsprintf( $text, array_pad( $values, self::MAX_ARGS, '' ) );
	}

	/**
	 * One value as text. A list is joined with commas; anything else that
	 * isn't a plain value is blank.
	 *
	 * @param mixed $arg Stored value.
	 * @return string
	 */
	private static function value( $arg ) {
		if ( is_array( $arg ) ) {
			$items = array();
			foreach ( $arg as $item ) {
				if ( is_scalar( $item ) && '' !== (string) $item ) {
					$items[] = (string) $item;
				}
			}
			return implode( ', ', $items );
		}
		return is_scalar( $arg ) ? (string) $arg : '';
	}

	/**
	 * The text key and values to store in a row's meta.
	 *
	 * @param string $key  Text key.
	 * @param array  $args Values.
	 * @return array
	 */
	public static function meta( $key, array $args = array() ) {
		$meta = array( self::META_KEY => (string) $key );
		if ( $args ) {
			$meta[ self::META_ARGS ] = array_values( $args );
		}
		return $meta;
	}

	/**
	 * The line a person reads for a log row, in the viewer's language. A row
	 * with a known text key in its meta is built from it, and a settings row
	 * from its changed keys. Any other row, such as one written before 1.1.0,
	 * keeps its stored summary.
	 *
	 * @param string $event  Event key.
	 * @param string $stored Stored summary.
	 * @param mixed  $meta   Decoded meta of the row.
	 * @return string
	 */
	public static function summary( $event, $stored, $meta ) {
		if ( is_array( $meta ) && isset( $meta[ self::META_KEY ] ) && self::exists( $meta[ self::META_KEY ] ) ) {
			$args = isset( $meta[ self::META_ARGS ] ) && is_array( $meta[ self::META_ARGS ] ) ? $meta[ self::META_ARGS ] : array();
			return self::render( $meta[ self::META_KEY ], $args );
		}
		return SettingLabels::summary( $event, $stored, $meta );
	}

	/**
	 * The text keys whose words, in the viewer's language, contain a search
	 * term, ignoring case. Placeholders are left out, so a term only matches
	 * the words of the sentence; the values are found in the stored summary.
	 *
	 * @param string $term Search term.
	 * @return string[]
	 */
	public static function keys_matching( $term ) {
		$term = (string) $term;
		if ( '' === $term ) {
			return array();
		}
		$found = array();
		foreach ( self::templates() as $key => $entry ) {
			$texts = array();
			if ( is_array( $entry ) && isset( $entry['plural'] ) ) {
				$texts[] = translate_nooped_plural( $entry['plural'], 1, 'happyaccess' );
				$texts[] = translate_nooped_plural( $entry['plural'], 2, 'happyaccess' );
			} else {
				$texts[] = is_array( $entry ) ? $entry['text'] : $entry;
			}
			foreach ( $texts as $text ) {
				if ( SettingLabels::contains( self::words( $text ), $term ) ) {
					$found[] = $key;
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * A template without its placeholders, such as "%s", "%d" or "%2$s".
	 *
	 * @param string $text Template.
	 * @return string
	 */
	private static function words( $text ) {
		$out    = '';
		$length = strlen( $text );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( '%' !== $text[ $i ] ) {
				$out .= $text[ $i ];
				continue;
			}
			$next = $i + 1;
			if ( $next < $length && '%' === $text[ $next ] ) {
				$out .= '%';
				$i    = $next;
				continue;
			}
			while ( $next < $length && ( ctype_digit( $text[ $next ] ) || '$' === $text[ $next ] ) ) {
				++$next;
			}
			// Skip the conversion letter too.
			$i = $next;
		}
		return $out;
	}
}
