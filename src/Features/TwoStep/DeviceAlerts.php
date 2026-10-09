<?php
/**
 * Emails a user when their account logs in from a new device.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\ClientIp;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Mailer;
use HappyAccess\Core\RateLimiter;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A device is a browser that holds the happyaccess_dev cookie, a random id.
 * The user meta _happyaccess_devices keeps the hashes of the ids the user
 * logged in with and when each was last seen, never the ids themselves.
 *
 * At a login of a role in two_step.device_alert_roles, a missing cookie or
 * an id that isn't in the list is a new device, and the user gets an email,
 * at most SEND_LIMIT an hour. A missing or malformed cookie gets a fresh id.
 * A well-formed id the user doesn't know is kept, because another account
 * on the same browser may know it. A known id is sent again with a fresh
 * expiry. The first login after the feature was turned on only remembers
 * the device, since there is nothing to compare it with yet.
 *
 * The list belongs to the person, like the rest of the two-step meta, so on
 * a network the ids are hashed with the main site's key.
 */
final class DeviceAlerts {

	const COOKIE = 'happyaccess_dev';

	const META = '_happyaccess_devices';

	/**
	 * Devices kept per user. The one seen longest ago goes first.
	 */
	const MAX_DEVICES = 20;

	/**
	 * Length of a device id from Codes::link_key().
	 */
	const ID_LENGTH = 43;

	/**
	 * Rate limit of the emails: SEND_LIMIT per user inside SEND_WINDOW
	 * seconds, so a browser that blocks cookies can't fill the inbox.
	 */
	const SEND_ACTION = 'twostep_device';
	const SEND_LIMIT  = 3;
	const SEND_WINDOW = 3600;

	/**
	 * Browser and system names, by a piece of the user agent that marks
	 * them. Checked in order, so Edge and Opera come before Chrome, Chrome
	 * before Safari, and Android and iOS before Linux and macOS.
	 */
	const BROWSERS = array(
		'Edg'     => 'Edge',
		'OPR/'    => 'Opera',
		'Opera'   => 'Opera',
		'Firefox' => 'Firefox',
		'FxiOS'   => 'Firefox',
		'Chrome'  => 'Chrome',
		'CriOS'   => 'Chrome',
		'Safari'  => 'Safari',
	);
	const SYSTEMS  = array(
		'iPhone'    => 'iOS',
		'iPad'      => 'iOS',
		'Android'   => 'Android',
		'Windows'   => 'Windows',
		'Macintosh' => 'macOS',
		'Linux'     => 'Linux',
	);

	/**
	 * Emails waiting for the end of the request.
	 *
	 * @var array
	 */
	private static $queue = array();

	/**
	 * Which listener checked each user in this request: 'wp_login' or
	 * 'two_factor'. A user checked by one listener is skipped by the other,
	 * so one login never sends two alerts.
	 *
	 * @var array<int, string>
	 */
	private static $checked_by = array();

	/**
	 * Watches every login. Late, so a listener that refuses the login runs
	 * first. Safe to call twice.
	 *
	 * The Two Factor plugin stops at wp_login priority 10 to show its own
	 * step, and when that step passes it fires two_factor_user_authenticated
	 * instead of wp_login, so its logins are watched there.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), PHP_INT_MAX, 2 );
		add_action( 'two_factor_user_authenticated', array( __CLASS__, 'on_two_factor_login' ), PHP_INT_MAX, 2 );
	}

	/**
	 * The wp_login action, fired by the password login, the two-step code
	 * step and passwordless login alike.
	 *
	 * @param string        $user_login Username.
	 * @param \WP_User|null $user       The user who logged in.
	 * @return void
	 */
	public static function on_login( $user_login, $user = null ) {
		unset( $user_login );
		self::check( $user, 'wp_login' );
	}

	/**
	 * The Two Factor plugin's two_factor_user_authenticated action, fired
	 * once its step passes and the auth cookie is set.
	 *
	 * @param \WP_User|null $user     The user who logged in.
	 * @param mixed         $provider Two Factor's provider object. Unused.
	 * @return void
	 */
	public static function on_two_factor_login( $user, $provider = null ) {
		unset( $provider );
		self::check( $user, 'two_factor' );
	}

	/**
	 * Remembers the browser and alerts when it is new.
	 *
	 * @param mixed  $user   The user who logged in.
	 * @param string $source The listener that called: 'wp_login' or 'two_factor'.
	 * @return void
	 */
	private static function check( $user, $source ) {
		if ( ! $user instanceof \WP_User || ! self::watches( $user ) || Challenge::is_side_login( $user ) ) {
			return;
		}
		if ( isset( self::$checked_by[ $user->ID ] ) && $source !== self::$checked_by[ $user->ID ] ) {
			return;
		}
		self::$checked_by[ $user->ID ] = $source;
		// Without the tables or a stored key, nothing could be logged and no hash would match later.
		if ( ! Challenge::db_ready() || ! Secrets::is_network_persisted() ) {
			return;
		}

		$first   = ! metadata_exists( 'user', $user->ID, self::META );
		$devices = self::clean_list( get_user_meta( $user->ID, self::META, true ) );
		$now     = Clock::now();

		$id = self::cookie_id();
		if ( '' !== $id ) {
			$hash = self::hash( $id );
			foreach ( $devices as $index => $device ) {
				if ( hash_equals( $device['hash'], $hash ) ) {
					$devices[ $index ]['seen'] = $now;
					update_user_meta( $user->ID, self::META, $devices );
					// The same id again with a fresh expiry, so a browser in daily use never ages out.
					Challenge::send_cookie( self::COOKIE, $id, $now + YEAR_IN_SECONDS );
					return;
				}
			}
		} else {
			// Only a missing or malformed cookie gets a new id. Replacing an id another account set would make two accounts on one browser alert at every login.
			$id   = Codes::link_key();
			$hash = self::hash( $id );
			Challenge::send_cookie( self::COOKIE, $id, $now + YEAR_IN_SECONDS );
		}

		$devices[] = array(
			'hash' => $hash,
			'seen' => $now,
		);
		update_user_meta( $user->ID, self::META, self::newest( $devices ) );
		if ( $first ) {
			return;
		}

		$browser = self::browser_name( self::user_agent() );
		$emailed = 0 === RateLimiter::attempt( self::SEND_ACTION, 'account', 'u:' . $user->ID, self::SEND_LIMIT, self::SEND_WINDOW, self::SEND_WINDOW );
		AuditLog::add(
			'new_device_login',
			array(
				'feature'      => 'two_step',
				'user_id'      => (int) $user->ID,
				'summary_key'  => 'new_device_login',
				'summary_args' => array( $browser ),
				'meta'         => array(
					'browser' => $browser,
					'emailed' => $emailed,
				),
			)
		);
		if ( $emailed ) {
			self::queue( $user, $browser, $now );
		}
	}

	/**
	 * Whether the user gets alerts: not a Support Access temp user, and one
	 * of their roles is in the list. A super admin counts as an
	 * administrator on a network.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function watches( \WP_User $user ) {
		if ( $user->ID < 1 || Capabilities::is_temp_user( $user->ID ) ) {
			return false;
		}
		$roles = (array) $user->roles;
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			$roles[] = 'administrator';
		}
		return array() !== array_intersect( $roles, (array) Settings::get( 'two_step.device_alert_roles', array() ) );
	}

	/**
	 * A short browser and system name from a user agent, like "Chrome on
	 * macOS". "Unknown browser" when no browser is found.
	 *
	 * @param string $agent User agent.
	 * @return string
	 */
	public static function browser_name( $agent ) {
		$agent   = (string) $agent;
		$browser = self::first_match( $agent, self::BROWSERS );
		if ( '' === $browser ) {
			return __( 'Unknown browser', 'happyaccess' );
		}
		$system = self::first_match( $agent, self::SYSTEMS );
		if ( '' === $system ) {
			return $browser;
		}
		/* translators: 1: browser name, like "Chrome", 2: operating system, like "macOS". */
		return sprintf( __( '%1$s on %2$s', 'happyaccess' ), $browser, $system );
	}

	/**
	 * Sends the queued emails. Runs on shutdown, after the response has gone
	 * to the visitor where the server allows it, so the login doesn't wait
	 * for wp_mail().
	 *
	 * @return void
	 */
	public static function flush_queue() {
		if ( empty( self::$queue ) ) {
			return;
		}

		$queue       = self::$queue;
		self::$queue = array();

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		foreach ( $queue as $item ) {
			Mailer::send( $item['to'], __( 'New login to your account', 'happyaccess' ), 'new-device', $item['vars'] );
		}
	}

	/**
	 * Drops the queued emails and the per-request checks. For tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$queue      = array();
		self::$checked_by = array();
	}

	/**
	 * Queues the email for the end of the request. The links are worked out
	 * now, while the user is the current user. The tip to turn on two-step
	 * login shows only when their role offers it and nothing exempts them.
	 *
	 * @param \WP_User $user    The user.
	 * @param string   $browser Browser and system name.
	 * @param int      $now     Login time.
	 * @return void
	 */
	private static function queue( \WP_User $user, $browser, $now ) {
		$ip = ClientIp::get();
		if ( Settings::get( 'privacy.anonymize_ip', false ) ) {
			$ip = ClientIp::anonymize( $ip );
		}
		$tip = ! UserState::is_enabled( $user->ID ) && ! Challenge::is_exempt( $user ) && RestController::is_offered( $user );

		self::$queue[] = array(
			'to'   => $user->user_email,
			'vars' => array(
				'time'      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $now ),
				'device'    => $browser,
				'ip'        => $ip,
				'reset_url' => wp_lostpassword_url(),
				'setup_url' => $tip ? Profile::url_for( $user ) : '',
			),
		);
		add_action( 'shutdown', array( __CLASS__, 'flush_queue' ) );
	}

	/**
	 * The device id from the cookie, or an empty string when it is missing
	 * or not shaped like one.
	 *
	 * @return string
	 */
	private static function cookie_id() {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) {
			return '';
		}
		$id = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		$ok = self::ID_LENGTH === strlen( $id ) && self::ID_LENGTH === strspn( $id, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_' );
		return $ok ? $id : '';
	}

	/**
	 * The hash of a device id, with the main site's key on a network.
	 *
	 * @param string $id Device id.
	 * @return string
	 */
	private static function hash( $id ) {
		if ( ! is_multisite() || get_current_blog_id() === get_main_site_id() ) {
			return Codes::hash_key( $id );
		}
		switch_to_blog( get_main_site_id() );
		try {
			return Codes::hash_key( $id );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * The stored list with only well-formed entries.
	 *
	 * @param mixed $stored Stored meta value.
	 * @return array
	 */
	private static function clean_list( $stored ) {
		$out = array();
		foreach ( is_array( $stored ) ? $stored : array() as $device ) {
			if ( is_array( $device ) && isset( $device['hash'], $device['seen'] ) && is_string( $device['hash'] ) ) {
				$out[] = array(
					'hash' => $device['hash'],
					'seen' => (int) $device['seen'],
				);
			}
		}
		return $out;
	}

	/**
	 * The MAX_DEVICES devices seen most recently, oldest first.
	 *
	 * @param array $devices Devices.
	 * @return array
	 */
	private static function newest( array $devices ) {
		usort(
			$devices,
			static function ( $a, $b ) {
				return $a['seen'] - $b['seen'];
			}
		);
		return array_slice( $devices, -self::MAX_DEVICES );
	}

	/**
	 * The value of the first key found in the text.
	 *
	 * @param string $text Text to look in.
	 * @param array  $map  Piece of text to name.
	 * @return string
	 */
	private static function first_match( $text, array $map ) {
		foreach ( $map as $needle => $name ) {
			if ( false !== strpos( $text, $needle ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * The request's user agent.
	 *
	 * @return string
	 */
	private static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}
}
