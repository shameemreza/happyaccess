<?php
/**
 * The author card on the HappyAccess page.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Admin;

use HappyAccess\Core\Clock;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Internal;
use HappyAccess\Core\OtherTwoFactor;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what the author card shows. For the first day it says hello. It
 * asks for a rating only once the plugin has done its job: after the first
 * pass that ended by itself, or a week after the install, whichever comes
 * first. Otherwise, and after a rating or "Not now", it shows tips. A user
 * who hides the tips sees no card at all from then on.
 *
 * The card shows on the HappyAccess page only and makes no outside request.
 */
final class AuthorCard {

	/**
	 * Per user choice: rated, later or hidden.
	 */
	const META = '_happyaccess_author_card';

	const CHOICES = array( 'rated', 'later', 'hidden' );

	/**
	 * Older choice names, from the 1.1.0 betas, and what they mean now.
	 */
	const ALIASES = array( 'dismissed' => 'later' );

	/**
	 * When the first pass ended by itself, as a UTC datetime. An empty string
	 * means the one-time lookup ran and found none; from then on the
	 * happyaccess_grant_ended hook fills it in.
	 */
	const EXPIRY_OPTION = 'happyaccess_first_expiry_seen';

	const INTRO_FOR = DAY_IN_SECONDS;

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
	 * What the card shows a user: intro (hello, for the first day), ask (the
	 * rating ask), tips, or none once they hid the tips.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function state( $user_id ) {
		$choice = self::choice( $user_id );
		if ( 'hidden' === $choice ) {
			return 'none';
		}
		if ( '' !== $choice ) {
			return 'tips';
		}
		if ( self::expiry_seen() ) {
			return 'ask';
		}
		$age = Clock::now() - self::installed_at();
		if ( $age < self::INTRO_FOR ) {
			return 'intro';
		}
		return $age >= self::ASK_AFTER ? 'ask' : 'tips';
	}

	/**
	 * A user's stored choice with old names mapped, or an empty string.
	 *
	 * @param int $user_id User id.
	 * @return string rated, later, hidden or empty.
	 */
	public static function choice( $user_id ) {
		return self::normalize( (string) get_user_meta( (int) $user_id, self::META, true ) );
	}

	/**
	 * Saves a user's choice. An old name is stored as its new one.
	 *
	 * @param int    $user_id User id.
	 * @param string $choice  rated, later, hidden, or dismissed for later.
	 * @return bool Whether the choice is stored.
	 */
	public static function save( $user_id, $choice ) {
		$choice = self::normalize( (string) $choice );
		if ( '' === $choice || (int) $user_id < 1 ) {
			return false;
		}
		update_user_meta( (int) $user_id, self::META, $choice );
		return get_user_meta( (int) $user_id, self::META, true ) === $choice;
	}

	/**
	 * Every choice name the route accepts, old ones included.
	 *
	 * @return string[]
	 */
	public static function accepted_choices() {
		return array_merge( self::CHOICES, array_keys( self::ALIASES ) );
	}

	/**
	 * The card's part of the page boot data. The facts behind the tips about
	 * this site are read only while the card shows.
	 *
	 * @return array{state:string,photo:string,user:int,facts:array}
	 */
	public static function boot_data() {
		$user_id = get_current_user_id();
		$state   = self::state( $user_id );

		return array(
			'state' => $state,
			'photo' => HAPPYACCESS_PLUGIN_URL . self::PHOTO,
			'user'  => (int) $user_id,
			'facts' => 'none' === $state ? array() : self::facts(),
		);
	}

	/**
	 * What the tips may say about this site, each only when it is known:
	 * administrator accounts (Support Access temp users left out), the last
	 * login with a support pass, how many administrators have two-step login,
	 * and whether new device alerts cover administrators.
	 *
	 * @return array
	 */
	private static function facts() {
		$users     = count_users();
		$roles     = isset( $users['avail_roles'] ) && is_array( $users['avail_roles'] ) ? $users['avail_roles'] : array();
		$admins    = isset( $roles['administrator'] ) ? (int) $roles['administrator'] : 0;
		$two_step  = Features::is_enabled( 'two_step' );
		$coverage  = null;
		$last_used = 0;

		if ( $admins > 0 ) {
			$admins = max( 0, $admins - self::temp_admins() );
		}
		if ( Features::is_enabled( 'support_access' ) ) {
			$last_used = self::last_pass_login();
		}
		// Another two-step plugin's users would count as not set up, so the
		// count would be wrong. On a big site it is read only when kept.
		if ( $two_step && ! OtherTwoFactor::plugin_active() ) {
			$total    = isset( $users['total_users'] ) ? (int) $users['total_users'] : 0;
			$coverage = Coverage::role_counts( 'administrator', ! Coverage::is_large( $total ) );
		}

		return array(
			'admins'        => $admins,
			'lastPassLogin' => $last_used,
			'twoStepAdmins' => $coverage,
			'deviceAlerts'  => $two_step && in_array( 'administrator', (array) Settings::get( 'two_step.device_alert_roles', array() ), true ),
			'woocommerce'   => class_exists( 'WooCommerce' ),
			'multisite'     => is_multisite(),
		);
	}

	/**
	 * Administrators that are Support Access temp users.
	 *
	 * @return int
	 */
	private static function temp_admins() {
		$query = new \WP_User_Query(
			array(
				'role'        => 'administrator',
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One count on the HappyAccess page, administrators only.
				'meta_query'  => array(
					array(
						'key'     => 'happyaccess_temp_user',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return (int) $query->get_total();
	}

	/**
	 * The last login with any support pass, current or ended, as a Unix
	 * time, or 0 when there was none.
	 *
	 * @return int
	 */
	private static function last_pass_login() {
		global $wpdb;
		if ( ! Installer::table_exists( 'tokens' ) ) {
			return 0;
		}
		$tokens = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read on the HappyAccess page; it must be current.
		$last = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(last_login_at) FROM %i WHERE last_login_at IS NOT NULL', $tokens ) );
		return max( 0, Clock::from_mysql( (string) $last ) );
	}

	/**
	 * A choice name as stored now, or an empty string when it isn't one.
	 *
	 * @param string $choice Choice name.
	 * @return string
	 */
	private static function normalize( $choice ) {
		if ( isset( self::ALIASES[ $choice ] ) ) {
			$choice = self::ALIASES[ $choice ];
		}
		return in_array( $choice, self::CHOICES, true ) ? $choice : '';
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
