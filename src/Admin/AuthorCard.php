<?php
/**
 * The author card on the HappyAccess page.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Admin;

use HappyAccess\Core\Clock;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Internal;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what the author card shows. It asks for a rating only once the
 * plugin has done its job: after the first pass that ended by itself, or a
 * week after the install, whichever comes first. A user who dismissed the
 * card or clicked the rating link is never asked again.
 *
 * The card shows on the HappyAccess page only and makes no outside request.
 */
final class AuthorCard {

	/**
	 * Per user choice: dismissed or rated.
	 */
	const META = '_happyaccess_author_card';

	const CHOICES = array( 'dismissed', 'rated' );

	/**
	 * When the first pass ended by itself, as a UTC datetime. An empty string
	 * means the one-time lookup ran and found none; from then on the
	 * happyaccess_grant_ended hook fills it in.
	 */
	const EXPIRY_OPTION = 'happyaccess_first_expiry_seen';

	const ASK_AFTER = 7 * DAY_IN_SECONDS;

	const PHOTO = 'assets/author.jpg';

	/**
	 * Hooks the expiry listener. Passes expire from cron, so this runs on
	 * every request, not only in the admin. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'happyaccess_grant_ended', array( __CLASS__, 'note_expiry' ), 10, 2 );
	}

	/**
	 * Remembers the first pass that ended by itself.
	 *
	 * @param array  $grant  The grant as stored after it ended.
	 * @param string $reason Why it ended.
	 * @return void
	 */
	public static function note_expiry( $grant, $reason ) {
		unset( $grant );
		if ( 'expired' !== $reason || '' !== (string) get_option( self::EXPIRY_OPTION, '' ) ) {
			return;
		}
		self::store_expiry( Clock::mysql() );
	}

	/**
	 * What the card shows a user: early (credit and docs), ask (the rating
	 * ask) or credit (a small line only).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function state( $user_id ) {
		if ( in_array( get_user_meta( (int) $user_id, self::META, true ), self::CHOICES, true ) ) {
			return 'credit';
		}
		if ( self::expiry_seen() ) {
			return 'ask';
		}
		return Clock::now() - self::installed_at() >= self::ASK_AFTER ? 'ask' : 'early';
	}

	/**
	 * Saves a user's choice.
	 *
	 * @param int    $user_id User id.
	 * @param string $choice  dismissed or rated.
	 * @return bool Whether the choice is stored.
	 */
	public static function save( $user_id, $choice ) {
		if ( ! in_array( $choice, self::CHOICES, true ) || (int) $user_id < 1 ) {
			return false;
		}
		update_user_meta( (int) $user_id, self::META, $choice );
		return get_user_meta( (int) $user_id, self::META, true ) === $choice;
	}

	/**
	 * The card's part of the page boot data.
	 *
	 * @return array{state:string,photo:string}
	 */
	public static function boot_data() {
		return array(
			'state' => self::state( get_current_user_id() ),
			'photo' => HAPPYACCESS_PLUGIN_URL . self::PHOTO,
		);
	}

	/**
	 * The install time. A site that was on 1.1.0 before this option existed
	 * gets it now, so its week starts from here.
	 *
	 * @return int
	 */
	private static function installed_at() {
		$stored = Clock::from_mysql( (string) get_option( Installer::INSTALLED_OPTION, '' ) );
		if ( $stored > 0 ) {
			return $stored;
		}
		Installer::note_install_time();
		$stored = Clock::from_mysql( (string) get_option( Installer::INSTALLED_OPTION, '' ) );
		return $stored > 0 ? $stored : Clock::now();
	}

	/**
	 * Whether a pass has ended by itself. The grants and the log are read once,
	 * for expiries from before the hook existed; after that only the option is.
	 *
	 * @return bool
	 */
	private static function expiry_seen() {
		$stored = get_option( self::EXPIRY_OPTION, false );
		if ( false === $stored ) {
			$stored = self::find_first_expiry();
			self::store_expiry( $stored );
		}
		return '' !== (string) $stored;
	}

	/**
	 * The earliest pass end with reason expired, from the grants table or the
	 * log, or an empty string when there is none.
	 *
	 * @return string UTC datetime or empty.
	 */
	private static function find_first_expiry() {
		global $wpdb;
		$found = array();

		$suppress = $wpdb->suppress_errors( true );
		if ( Installer::table_exists( 'tokens' ) ) {
			$tokens = Installer::table( 'tokens' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time lookup on a plugin table; the result is kept in an option.
			$found[] = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(revoked_at) FROM %i WHERE revoked_at IS NOT NULL AND metadata LIKE %s', $tokens, '%' . $wpdb->esc_like( '"end_reason":"expired"' ) . '%' ) );
		}
		if ( Installer::table_exists( 'logs' ) ) {
			$logs = Installer::table( 'logs' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time lookup on a plugin table; the result is kept in an option.
			$found[] = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(created_at) FROM %i WHERE event_type = %s AND metadata LIKE %s', $logs, 'grant_ended', '%' . $wpdb->esc_like( '"reason":"expired"' ) . '%' ) );
		}
		$wpdb->suppress_errors( $suppress );

		$found = array_filter(
			array_map( 'strval', $found ),
			static function ( $value ) {
				return Clock::from_mysql( $value ) > 0;
			}
		);
		if ( array() === $found ) {
			return '';
		}
		sort( $found );
		return reset( $found );
	}

	/**
	 * Writes the expiry option, not autoloaded.
	 *
	 * @param string $value UTC datetime, or empty for "looked, found none".
	 * @return void
	 */
	private static function store_expiry( $value ) {
		Internal::run(
			static function () use ( $value ) {
				update_option( self::EXPIRY_OPTION, $value, false );
			}
		);
	}
}
