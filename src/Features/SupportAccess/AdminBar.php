<?php
/**
 * Admin bar countdown, end session link and Emergency Lock.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

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

		wp_enqueue_script( 'happyaccess-admin-bar', plugins_url( 'assets/admin-bar.js', HAPPYACCESS_PLUGIN_FILE ), array(), HAPPYACCESS_VERSION, true );
		$strings = array(
			'confirm' => __( 'Lock all support access now? This ends every support session and signs support out.', 'happyaccess' ),
			/* translators: %s: time left, for example "2 hours". */
			'ends'    => __( 'Support access ends in %s', 'happyaccess' ),
			'ended'   => __( 'Support access has ended', 'happyaccess' ),
			'less'    => __( 'less than a minute', 'happyaccess' ),
			'min'     => array(
				/* translators: %d: number of minutes, singular. */
				__( '%d min', 'happyaccess' ),
				/* translators: %d: number of minutes, plural. */
				__( '%d mins', 'happyaccess' ),
			),
			'hour'    => array(
				/* translators: %d: number of hours, singular. */
				__( '%d hour', 'happyaccess' ),
				/* translators: %d: number of hours, plural. */
				__( '%d hours', 'happyaccess' ),
			),
			'day'     => array(
				/* translators: %d: number of days, singular. */
				__( '%d day', 'happyaccess' ),
				/* translators: %d: number of days, plural. */
				__( '%d days', 'happyaccess' ),
			),
		);
		wp_localize_script( 'happyaccess-admin-bar', 'happyaccessBar', $strings );
	}

	/**
	 * Revokes every current grant.
	 *
	 * @return int How many grants were revoked.
	 */
	public static function emergency_lock() {
		$count = Grants::revoke_all( 'emergency_lock' );
		AuditLog::add(
			'emergency_lock',
			array(
				'feature' => 'support',
				'summary' => sprintf(
					/* translators: %d: number of grants ended. */
					_n( 'Emergency lock ended %d grant.', 'Emergency lock ended %d grants.', $count, 'happyaccess' ),
					$count
				),
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

		$count    = self::emergency_lock();
		$referer  = wp_get_referer();
		$redirect = add_query_arg( 'happyaccess_locked', $count, $referer ? $referer : admin_url() );
		wp_safe_redirect( $redirect );
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
			? sprintf( __( 'Support access ends in %s', 'happyaccess' ), human_time_diff( $now, $expires ) )
			: __( 'Support access has ended', 'happyaccess' );

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
