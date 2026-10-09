<?php
/**
 * Admin bar countdown, end session link and Emergency Lock.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Admin\Page;
use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
use HappyAccess\Login\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Temp users see how long their access lasts and can end it. Owners see an
 * Emergency Lock link while any grant is current.
 */
final class AdminBar {

	const LOCK_ACTION = 'happyaccess_emergency_lock';

	/**
	 * Hooks the admin bar nodes, the script and the lock handler.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_nodes' ), 100 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::LOCK_ACTION, array( __CLASS__, 'handle_emergency_lock' ) );
	}

	/**
	 * Adds the timer and end session nodes for a temp user, or the lock node
	 * for an owner while a grant is current.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public static function add_nodes( $bar ) {
		$user_id = get_current_user_id();

		if ( Capabilities::is_temp_user( $user_id ) ) {
			self::add_temp_user_nodes( $bar, $user_id );
			return;
		}

		if ( self::shows_lock( $user_id ) ) {
			$bar->add_node(
				array(
					'id'     => 'happyaccess-lock',
					'parent' => 'top-secondary',
					'title'  => esc_html__( 'Emergency lock', 'happyaccess' ),
					'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=' . self::LOCK_ACTION ), self::LOCK_ACTION ),
					'meta'   => array( 'class' => 'happyaccess-lock' ),
				)
			);
		}
	}

	/**
	 * Loads the admin bar script, only when the bar shows one of our nodes.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! Capabilities::is_temp_user( $user_id ) && ! self::shows_lock( $user_id ) ) {
			return;
		}

		wp_enqueue_script( 'happyaccess-admin-bar', plugins_url( 'assets/admin-bar.js', HAPPYACCESS_PLUGIN_FILE ), array( 'wp-i18n' ), HAPPYACCESS_VERSION, true );
		// The countdown builds "5 mins" or "2 hours" with _n() from wp-i18n, so each language gets its own plural rules.
		wp_set_script_translations( 'happyaccess-admin-bar', 'happyaccess', HAPPYACCESS_PLUGIN_DIR . 'languages' );
		$strings = array(
			'confirm' => __( 'Lock all temporary access now? This ends every support session and signs support out.', 'happyaccess' ),
			/* translators: %s: time left, for example "2 hours". */
			'ends'    => __( 'Temporary access ends in %s', 'happyaccess' ),
			'ended'   => __( 'Temporary access has ended', 'happyaccess' ),
			'less'    => __( 'less than a minute', 'happyaccess' ),
		);
		wp_localize_script( 'happyaccess-admin-bar', 'happyaccessBar', $strings );
	}

	/**
	 * Time left as the admin bar shows it. Under three days or three hours it
	 * gives two units ("2 days 23 hours"), so the bar never shows a day less
	 * than is left. From three on it rounds to the nearest whole unit.
	 * assets/admin-bar.js keeps the same rules for the live countdown.
	 *
	 * @param int $seconds Seconds left.
	 * @return string
	 */
	public static function time_left( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		if ( $seconds < MINUTE_IN_SECONDS ) {
			return __( 'less than a minute', 'happyaccess' );
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			return self::minutes( (int) floor( $seconds / MINUTE_IN_SECONDS ) );
		}
		if ( $seconds < DAY_IN_SECONDS ) {
			$hours = (int) floor( $seconds / HOUR_IN_SECONDS );
			if ( $hours < 3 ) {
				$mins = (int) floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
				return $mins > 0 ? self::hours( $hours ) . ' ' . self::minutes( $mins ) : self::hours( $hours );
			}
			$hours = (int) round( $seconds / HOUR_IN_SECONDS );
			return $hours >= 24 ? self::days( 1 ) : self::hours( $hours );
		}
		$days = (int) floor( $seconds / DAY_IN_SECONDS );
		if ( $days < 3 ) {
			$hours = (int) floor( ( $seconds % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
			return $hours > 0 ? self::days( $days ) . ' ' . self::hours( $hours ) : self::days( $days );
		}
		return self::days( (int) round( $seconds / DAY_IN_SECONDS ) );
	}

	/**
	 * A number of minutes, like "5 mins".
	 *
	 * @param int $count Minutes.
	 * @return string
	 */
	private static function minutes( $count ) {
		/* translators: %d: number of minutes. */
		return sprintf( _n( '%d min', '%d mins', $count, 'happyaccess' ), $count );
	}

	/**
	 * A number of hours, like "3 hours".
	 *
	 * @param int $count Hours.
	 * @return string
	 */
	private static function hours( $count ) {
		/* translators: %d: number of hours. */
		return sprintf( _n( '%d hour', '%d hours', $count, 'happyaccess' ), $count );
	}

	/**
	 * A number of days, like "2 days".
	 *
	 * @param int $count Days.
	 * @return string
	 */
	private static function days( $count ) {
		/* translators: %d: number of days. */
		return sprintf( _n( '%d day', '%d days', $count, 'happyaccess' ), $count );
	}

	/**
	 * Revokes every grant that is not revoked yet. The count, which the
	 * notice and the log show, is the passes that were current, so rows that
	 * had expired and were waiting for cleanup don't count as ended now.
	 *
	 * @return int How many current passes were ended.
	 */
	public static function emergency_lock() {
		$count = count( Grants::list_current() );
		Grants::revoke_all( 'emergency_lock' );
		AuditLog::add(
			'emergency_lock',
			array(
				'feature'      => 'support',
				'summary_key'  => 'emergency_lock',
				'summary_args' => array( $count ),
			)
		);
		return $count;
	}

	/**
	 * Admin-post handler for the lock link.
	 *
	 * @return void
	 */
	public static function handle_emergency_lock() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'happyaccess' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::LOCK_ACTION );

		Page::remember_lock( self::emergency_lock() );
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Whether this viewer gets the lock node.
	 *
	 * @param int $user_id Viewer id.
	 * @return bool
	 */
	private static function shows_lock( $user_id ) {
		return $user_id > 0 && current_user_can( Capabilities::MANAGE ) && Grants::has_current();
	}

	/**
	 * Adds the countdown and end session nodes.
	 *
	 * @param \WP_Admin_Bar $bar     Admin bar.
	 * @param int           $user_id Temp user id.
	 * @return void
	 */
	private static function add_temp_user_nodes( $bar, $user_id ) {
		$grant = Grants::get( Capabilities::grant_id( $user_id ) );
		if ( ! $grant || empty( $grant['expires_at'] ) ) {
			return;
		}
		$expires = (int) $grant['expires_at'];
		$now     = Clock::now();

		$text = $expires > $now
			/* translators: %s: time left, for example "2 hours". */
			? sprintf( __( 'Temporary access ends in %s', 'happyaccess' ), self::time_left( $expires - $now ) )
			: __( 'Temporary access has ended', 'happyaccess' );

		$bar->add_node(
			array(
				'id'     => 'happyaccess-timer',
				'parent' => 'top-secondary',
				'title'  => '<span class="happyaccess-timer-text" data-expires="' . esc_attr( (string) $expires ) . '">' . esc_html( $text ) . '</span>',
			)
		);
		$bar->add_node(
			array(
				'id'     => 'happyaccess-end',
				'parent' => 'happyaccess-timer',
				'title'  => esc_html__( 'End session', 'happyaccess' ),
				'href'   => wp_logout_url( Router::url( 'ended', array( 'reason' => 'ended' ) ) ),
			)
		);
	}
}
