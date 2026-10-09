<?php
/**
 * Feature switches.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sets which features are on.
 */
final class Features {

	const ALL = array( 'support_access', 'passwordless', 'two_step' );

	/**
	 * Autoloaded flag, always present once set: '1' when the rewrite rules
	 * need a flush on the next init, after the My Account endpoint of
	 * two-step login is registered or gone, and '0' when idle. Being in the
	 * autoloaded options, reading it costs no query on a normal request.
	 */
	const REWRITE_FLUSH_OPTION = 'happyaccess_rewrite_flush';

	/**
	 * Whether a feature is on. Two-step login follows the main site's switch
	 * while HappyAccess is network active (see Settings::MAIN_SITE_PATHS).
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public static function is_enabled( $feature ) {
		return in_array( $feature, self::ALL, true ) && true === Settings::shared( 'features.' . $feature );
	}

	/**
	 * Turns a feature on or off.
	 *
	 * @param string $feature Feature key.
	 * @param bool   $enabled New state.
	 * @return bool False for an unknown feature.
	 */
	public static function set( $feature, $enabled ) {
		if ( ! in_array( $feature, self::ALL, true ) ) {
			return false;
		}
		Settings::update( array( 'features' => array( $feature => (bool) $enabled ) ) );
		return true;
	}

	/**
	 * Asks for one rewrite flush on the next request. Two-step login adds a
	 * My Account endpoint, and its rules only exist, or go away, once the
	 * rules are built again. A flag left from 1.1.0 betas without autoload
	 * is switched to autoload here.
	 *
	 * @return void
	 */
	public static function request_rewrite_flush() {
		Internal::run(
			static function () {
				update_option( self::REWRITE_FLUSH_OPTION, '1', true );
				wp_set_option_autoload( self::REWRITE_FLUSH_OPTION, true );
			}
		);
	}

	/**
	 * Flushes the rewrite rules once when the flag is '1', after every init
	 * callback registered its rules. Only the request whose UPDATE turns the
	 * flag back to '0' flushes, so two requests at once can't both do it.
	 * Hooked on every request, whatever the features, so turning two-step
	 * login off also takes its endpoint rule away.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrites() {
		global $wpdb;

		if ( '1' !== (string) get_option( self::REWRITE_FLUSH_OPTION, '0' ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One conditional UPDATE decides which request flushes.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = '0' WHERE option_name = %s AND option_value = '1'", self::REWRITE_FLUSH_OPTION ) );
		if ( 1 === $changed ) {
			flush_rewrite_rules( false );
		}
		// The cached '1' is stale either way.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::REWRITE_FLUSH_OPTION, 'options' );
	}
}
