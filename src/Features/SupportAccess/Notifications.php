<?php
/**
 * Emails about support access.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\ClientIp;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Mailer;
use HappyAccess\Core\Settings;
use HappyAccess\Login\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Alerts for the person who granted access, the site admin and the person
 * getting access. The bundle email is the only place a code is ever sent.
 */
final class Notifications {

	const LOCK_TRANSIENT = 'happyaccess_site_lock_alerted';

	/**
	 * Hooks the grant lifecycle.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'happyaccess_grant_ended', array( __CLASS__, 'access_ended' ), 10, 2 );
	}

	/**
	 * Tells the granting user that someone signed in.
	 *
	 * @param array  $grant  Grant.
	 * @param bool   $first  Whether this was the first login.
	 * @param string $method link or code. Leave empty when unknown.
	 * @return void
	 */
	public static function login( array $grant, $first, $method = '' ) {
		$notify = isset( $grant['notify'] ) ? $grant['notify'] : 'first';
		if ( 'every' !== $notify && ! ( 'first' === $notify && $first ) ) {
			return;
		}

		$to = self::owner_email( $grant );
		if ( '' === $to ) {
			return;
		}

		$ip = ClientIp::get();
		if ( Settings::get( 'privacy.anonymize_ip', false ) ) {
			$ip = ClientIp::anonymize( $ip );
		}

		switch ( $method ) {
			case 'link':
				$method_label = __( 'Login link', 'happyaccess' );
				break;
			case 'code':
				$method_label = __( 'Access code', 'happyaccess' );
				break;
			default:
				$method_label = __( 'Login link or access code', 'happyaccess' );
		}

		Mailer::send(
			$to,
			/* translators: %s: label of the support grant. */
			sprintf( __( 'Support access used: %s', 'happyaccess' ), $grant['label'] ),
			'login-alert',
			array(
				'label'  => $grant['label'],
				'time'   => self::format_time( Clock::now() ),
				'ip'     => $ip,
				'method' => $method_label,
			)
		);
	}

	/**
	 * Tells the granting user that access ended, with what the person did.
	 *
	 * @param array  $grant  Grant as stored after it ended.
	 * @param string $reason Why it ended.
	 * @return void
	 */
	public static function access_ended( $grant, $reason ) {
		if ( ! is_array( $grant ) || empty( $grant['id'] ) ) {
			return;
		}

		$to = self::owner_email( $grant );
		if ( '' === $to ) {
			return;
		}

		$log      = AuditLog::query(
			array(
				'feature'  => 'support',
				'token_id' => $grant['id'],
				'per_page' => 50,
			)
		);
		$activity = array();
		foreach ( $log['items'] as $item ) {
			$line = '' !== (string) $item['summary'] ? (string) $item['summary'] : self::event_name( (string) $item['event_type'] );
			if ( '' !== $line ) {
				$activity[] = $line;
			}
		}

		Mailer::send(
			$to,
			/* translators: %s: label of the support grant. */
			sprintf( __( 'Support access ended: %s', 'happyaccess' ), $grant['label'] ),
			'access-ended',
			array(
				'label'       => $grant['label'],
				'reason'      => self::reason_label( (string) $reason ),
				'login_count' => isset( $grant['login_count'] ) ? (int) $grant['login_count'] : 0,
				'activity'    => $activity,
			)
		);
	}

	/**
	 * Emails the link and the code to the person getting access.
	 *
	 * @param array  $grant    Grant.
	 * @param string $code     Plain code, as returned by Grants::create().
	 * @param string $link_key Plain link key.
	 * @return bool False when the grant has no recipient or the email was not accepted.
	 */
	public static function send_bundle( array $grant, $code, $link_key ) {
		$to = isset( $grant['recipient_email'] ) ? (string) $grant['recipient_email'] : '';
		if ( '' === $to ) {
			return false;
		}

		$sent = Mailer::send(
			$to,
			__( 'Temporary access', 'happyaccess' ),
			'access-bundle',
			array(
				'link'     => Router::url( 'link', array( 'k' => $link_key ) ),
				'code_url' => Router::url( 'code' ),
				'code'     => Codes::format_code( $code ),
				'expires'  => self::format_time( $grant['expires_at'] ),
			)
		);

		if ( $sent ) {
			AuditLog::add(
				'bundle_emailed',
				array(
					'feature'  => 'support',
					'token_id' => $grant['id'],
					/* translators: %s: label of the support grant. */
					'summary'  => sprintf( __( 'Access details emailed for %s', 'happyaccess' ), $grant['label'] ),
				)
			);
		}
		return $sent;
	}

	/**
	 * Tells the owner that a support pass made an administrator account.
	 *
	 * @param array    $grant Grant of the acting pass.
	 * @param \WP_User $user  The administrator account.
	 * @return bool Whether the email was accepted.
	 */
	public static function admin_created( array $grant, \WP_User $user ) {
		return self::admin_alert( 'created', $grant, $user, array() );
	}

	/**
	 * Tells the owner that a support pass changed an administrator's login details.
	 *
	 * @param array    $grant  Grant of the acting pass.
	 * @param \WP_User $user   The administrator account.
	 * @param string[] $fields What changed: email, password or both.
	 * @return bool Whether the email was accepted.
	 */
	public static function admin_changed( array $grant, \WP_User $user, array $fields ) {
		return self::admin_alert( 'changed', $grant, $user, $fields );
	}

	/**
	 * Sends the administrator alert, for both variants.
	 *
	 * @param string   $variant created or changed.
	 * @param array    $grant   Grant of the acting pass.
	 * @param \WP_User $user    The administrator account.
	 * @param string[] $fields  What changed, for the changed variant.
	 * @return bool
	 */
	private static function admin_alert( $variant, array $grant, \WP_User $user, array $fields ) {
		$to = self::owner_email( $grant );
		if ( '' === $to ) {
			return false;
		}

		$labels = array();
		foreach ( $fields as $field ) {
			$labels[] = 'password' === $field ? __( 'password', 'happyaccess' ) : __( 'email address', 'happyaccess' );
		}

		return Mailer::send(
			$to,
			'changed' === $variant
				? __( "A support pass changed an administrator's login details", 'happyaccess' )
				: __( 'A support pass made an administrator account', 'happyaccess' ),
			'admin-created',
			array(
				'variant'    => $variant,
				'label'      => isset( $grant['label'] ) ? (string) $grant['label'] : '',
				'login'      => $user->user_login,
				'user_email' => $user->user_email,
				'changed'    => $labels,
				'time'       => self::format_time( Clock::now() ),
				'users_url'  => admin_url( 'users.php' ),
			)
		);
	}

	/**
	 * Alerts the site admin that code logins are locked for the whole site.
	 * Sends once per lock.
	 *
	 * @param int $retry_after Seconds until the lock ends.
	 * @return void
	 */
	public static function site_lock( $retry_after ) {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}

		$retry_after = max( 60, (int) $retry_after );
		set_transient( self::LOCK_TRANSIENT, 1, $retry_after );

		Mailer::send(
			(string) get_option( 'admin_email' ),
			__( 'Code logins are paused', 'happyaccess' ),
			'site-lock',
			array( 'minutes' => (int) ceil( $retry_after / MINUTE_IN_SECONDS ) )
		);
	}

	/**
	 * The ready-to-paste message from the admin screen and the email.
	 *
	 * @param array  $grant    Grant.
	 * @param string $code     Plain code.
	 * @param string $link_key Plain link key.
	 * @return string Plain text, one item per line.
	 */
	public static function bundle_text( array $grant, $code, $link_key ) {
		$lines = array(
			/* translators: %s: site name. */
			sprintf( __( "Here's temporary access to %s:", 'happyaccess' ), Mailer::site_name() ),
			/* translators: %s: login link. */
			sprintf( __( 'Login link: %s', 'happyaccess' ), Router::url( 'link', array( 'k' => $link_key ) ) ),
			/* translators: 1: address of the code screen, 2: access code. */
			sprintf( __( 'Or open %1$s and enter this code: %2$s', 'happyaccess' ), Router::url( 'code' ), Codes::format_code( $code ) ),
			/* translators: %s: date and time the access ends. */
			sprintf( __( 'Access ends %s.', 'happyaccess' ), self::format_time( $grant['expires_at'] ) ),
		);
		return implode( "\n", $lines );
	}

	/**
	 * Email address that gets the alerts for a grant: its owner, or the site
	 * email when there is no owner with a valid address.
	 *
	 * @param array $grant Grant.
	 * @return string
	 */
	private static function owner_email( array $grant ) {
		$owner = Grants::owner_id( $grant );
		$user  = $owner > 0 ? get_userdata( $owner ) : false;
		if ( $user && is_email( $user->user_email ) ) {
			return $user->user_email;
		}
		$site_email = (string) get_option( 'admin_email' );
		return is_email( $site_email ) ? $site_email : '';
	}

	/**
	 * Date and time in the site timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', (int) $timestamp );
	}

	/**
	 * Readable reason for the ended email.
	 *
	 * @param string $reason Reason key.
	 * @return string
	 */
	private static function reason_label( $reason ) {
		switch ( $reason ) {
			case 'revoked':
				return __( 'Revoked by an administrator', 'happyaccess' );
			case 'expired':
				return __( 'Time ran out', 'happyaccess' );
			case 'lockdown':
				return __( 'Emergency Lock', 'happyaccess' );
			default:
				return self::event_name( $reason );
		}
	}

	/**
	 * Sentence-case name from an event or reason key.
	 *
	 * @param string $key Key such as plugin_activated.
	 * @return string
	 */
	private static function event_name( $key ) {
		$name = trim( str_replace( array( '_', '-' ), ' ', $key ) );
		return '' === $name ? '' : ucfirst( $name );
	}
}
