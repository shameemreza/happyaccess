<?php
/**
 * Support grants: create and read.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\ClientIp;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;
use HappyAccess\Login\Session;

defined( 'ABSPATH' ) || exit;

/**
 * One row in the tokens table is one grant of support access. Only hashes of
 * the code and the link key are stored; the plain values are returned once,
 * by create().
 */
final class Grants {

	const MAX_DURATION = 2592000;
	const MIN_DURATION = 3600;

	/**
	 * Tries to find a code that no active grant uses before giving up.
	 */
	const CODE_ATTEMPTS = 5;

	/**
	 * Access levels a grant can have.
	 */
	const LEVELS = array( 'protected', 'custom', 'full' );

	/**
	 * Per-request cache of resolver results, keyed by user id.
	 *
	 * @var array
	 */
	private static $resolved = array();

	/**
	 * Login count written by the last successful record_login(), 0 when unknown.
	 *
	 * @var int
	 */
	private static $last_login_count = 0;

	/**
	 * Creates a grant.
	 *
	 * @param array $args label, email, role, duration, one_time, ips, menus, hide_admin_bar, redirect_to, allow_installs, notify, created_by, level, caps, confirm_full.
	 * @return array id, code, link_key, expires_at.
	 * @throws \InvalidArgumentException When an argument is not valid.
	 * @throws \RuntimeException         When the site key is not stored or no free code was found.
	 */
	public static function create( array $args ) {
		global $wpdb;

		if ( ! Secrets::is_persisted() ) {
			throw new \RuntimeException( 'site key not stored' );
		}

		$label = trim( sanitize_text_field( isset( $args['label'] ) ? (string) $args['label'] : '' ) );
		if ( '' === $label ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text message, escaped where it is shown.
			throw new \InvalidArgumentException( __( 'A label is required.', 'happyaccess' ) );
		}
		$label = mb_substr( $label, 0, 190 );

		$email = '';
		if ( isset( $args['email'] ) && '' !== trim( (string) $args['email'] ) ) {
			$email = sanitize_email( (string) $args['email'] );
			if ( '' === $email ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text message, escaped where it is shown.
				throw new \InvalidArgumentException( __( 'The email address is not valid.', 'happyaccess' ) );
			}
		}

		$level = isset( $args['level'] ) && '' !== (string) $args['level'] ? (string) $args['level'] : 'protected';
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text message, escaped where it is shown.
			throw new \InvalidArgumentException( __( 'The access level is not valid.', 'happyaccess' ) );
		}

		$role = isset( $args['role'] ) && '' !== (string) $args['role'] ? (string) $args['role'] : 'administrator';
		if ( 'protected' === $level ) {
			if ( ! array_key_exists( $role, wp_roles()->roles ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text message, escaped where it is shown.
				throw new \InvalidArgumentException( __( 'The role does not exist.', 'happyaccess' ) );
			}
		} else {
			$role = 'administrator';
		}

		$caps      = array();
		$confirmed = true === ( isset( $args['confirm_full'] ) ? $args['confirm_full'] : null );
		if ( 'custom' === $level ) {
			$caps = self::clean_caps( isset( $args['caps'] ) ? $args['caps'] : array() );
		}
		// A custom pass with an admin-level permission runs on trust, like a full pass.
		if ( ! $confirmed && ( 'full' === $level || Catalog::needs_trust( $caps ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text message, escaped where it is shown.
			throw new \InvalidArgumentException( __( 'Confirm that you trust this person with full access.', 'happyaccess' ) );
		}

		$protection = $level;
		if ( 'protected' === $level ) {
			$protection = empty( $args['allow_installs'] ) ? 'protected' : 'protected_allow_installs';
		}

		$duration = isset( $args['duration'] ) ? (int) $args['duration'] : (int) Settings::get( 'support.default_duration' );
		$duration = max( self::MIN_DURATION, min( self::MAX_DURATION, $duration ) );

		$ips = array();
		if ( ! empty( $args['ips'] ) ) {
			foreach ( (array) $args['ips'] as $ip ) {
				$ip = ClientIp::canonical( is_string( $ip ) ? trim( $ip ) : '' );
				if ( '' !== $ip && ! in_array( $ip, $ips, true ) ) {
					$ips[] = $ip;
				}
			}
			if ( empty( $ips ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text message, escaped where it is shown.
				throw new \InvalidArgumentException( __( 'None of the IP addresses is valid.', 'happyaccess' ) );
			}
		}

		$menus = array();
		foreach ( isset( $args['menus'] ) ? (array) $args['menus'] : array() as $menu ) {
			$menu = is_scalar( $menu ) ? sanitize_text_field( (string) $menu ) : '';
			if ( '' !== $menu && ! in_array( $menu, $menus, true ) ) {
				$menus[] = $menu;
			}
		}

		$redirect_to = '';
		if ( ! empty( $args['redirect_to'] ) && is_string( $args['redirect_to'] ) ) {
			$requested = $args['redirect_to'];
			if ( wp_validate_redirect( $requested, '' ) === $requested ) {
				$redirect_to = mb_substr( $requested, 0, 255 );
			}
		}

		$notify = isset( $args['notify'] ) ? (string) $args['notify'] : 'first';
		if ( ! in_array( $notify, array( 'first', 'every', 'off' ), true ) ) {
			$notify = 'first';
		}

		$created_by = isset( $args['created_by'] ) ? absint( $args['created_by'] ) : get_current_user_id();
		$one_time   = ! empty( $args['one_time'] );
		$now        = Clock::now();
		$table      = Installer::table( 'tokens' );

		$restrictions = array(
			'ips'            => $ips,
			'menus'          => $menus,
			'hide_admin_bar' => ! empty( $args['hide_admin_bar'] ),
		);
		if ( 'custom' === $level ) {
			$restrictions['caps'] = $caps;
		}

		list( $code, $code_hash ) = self::unused_code();

		$link_key  = Codes::link_key();
		$link_hash = Codes::hash_key( $link_key );
		$expires   = $now + $duration;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$ok = $wpdb->insert(
			$table,
			array(
				'token_hash'      => $link_hash,
				'code_hash'       => $code_hash,
				'link_hash'       => $link_hash,
				'label'           => $label,
				'recipient_email' => $email,
				'role'            => $role,
				'protection'      => $protection,
				'restrictions'    => wp_json_encode( $restrictions ),
				'redirect_to'     => $redirect_to,
				'notify'          => $notify,
				'created_by'      => $created_by,
				'created_at'      => Clock::mysql( $now ),
				'expires_at'      => Clock::mysql( $expires ),
				'max_uses'        => $one_time ? 1 : 0,
				'use_count'       => 0,
				'metadata'        => '{}',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' )
		);
		if ( ! $ok ) {
			throw new \RuntimeException( 'The grant could not be saved.' );
		}
		$id = (int) $wpdb->insert_id;

		$log_meta = array(
			'role'     => $role,
			'duration' => $duration,
			'one_time' => $one_time,
			'level'    => $level,
		);
		if ( 'custom' === $level ) {
			$log_meta['caps_count'] = count( $caps );
		}

		AuditLog::add(
			'grant_created',
			array(
				'feature'  => 'support',
				'token_id' => $id,
				/* translators: %s: label of the support grant. */
				'summary'  => sprintf( __( 'Support access granted to %s', 'happyaccess' ), $label ),
				'meta'     => $log_meta,
			)
		);

		return array(
			'id'         => $id,
			'code'       => $code,
			'link_key'   => $link_key,
			'expires_at' => $expires,
		);
	}

	/**
	 * Cleans and checks the permissions of a custom pass. "read" is dropped
	 * before the checks and added back, so it is neither required nor rejected.
	 *
	 * @param mixed $input Requested capabilities.
	 * @return array Sorted capability names, including "read".
	 * @throws \InvalidArgumentException When the list is empty or holds a capability that can't be given.
	 */
	private static function clean_caps( $input ) {
		$caps = array();
		foreach ( (array) $input as $cap ) {
			$cap = is_scalar( $cap ) ? sanitize_key( (string) $cap ) : '';
			if ( '' !== $cap && 'read' !== $cap && ! in_array( $cap, $caps, true ) ) {
				$caps[] = $cap;
			}
		}
		if ( empty( $caps ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text message, escaped where it is shown.
			throw new \InvalidArgumentException( __( 'Pick at least one permission.', 'happyaccess' ) );
		}

		// The full catalog, so a cap the creator lacks gets the creator check's message below.
		$grantable = Catalog::grantable( false );
		foreach ( $caps as $cap ) {
			if ( ! in_array( $cap, $grantable, true ) ) {
				/* translators: %s: capability name. */
				$message = sprintf( __( "This permission can't be given: %s", 'happyaccess' ), $cap );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text message, escaped where it is shown.
				throw new \InvalidArgumentException( $message );
			}
			if ( ! current_user_can( $cap ) ) {
				/* translators: %s: capability name. */
				$message = sprintf( __( "You can't give a permission you don't have: %s", 'happyaccess' ), $cap );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text message, escaped where it is shown.
				throw new \InvalidArgumentException( $message );
			}
		}

		$caps[] = 'read';
		sort( $caps );
		return $caps;
	}

	/**
	 * Reads one grant.
	 *
	 * @param int $id Grant id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return is_array( $row ) ? self::normalize( $row ) : null;
	}

	/**
	 * Who should get a grant's emails and the content of its temp user: the
	 * creator, or the lowest-ID administrator when the creator is gone, lacks
	 * manage_options, or is a temp user themselves.
	 *
	 * @param array $grant Grant with created_by.
	 * @return int User id, 0 when no administrator is left.
	 */
	public static function owner_id( array $grant ) {
		$creator = isset( $grant['created_by'] ) ? (int) $grant['created_by'] : 0;
		if ( $creator > 0 && false !== get_userdata( $creator ) && ! Capabilities::is_temp_user( $creator ) && user_can( $creator, 'manage_options' ) ) {
			return $creator;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 5,
				'fields'  => 'ID',
			)
		);
		foreach ( $admins as $admin_id ) {
			if ( ! Capabilities::is_temp_user( (int) $admin_id ) ) {
				return (int) $admin_id;
			}
		}
		return 0;
	}

	/**
	 * Status of a grant. The first matching rule wins.
	 *
	 * @param array $grant Normalized grant.
	 * @return string active, used, suspended, expired or revoked.
	 */
	public static function status( array $grant ) {
		if ( ! empty( $grant['revoked_at'] ) ) {
			return ( isset( $grant['end_reason'] ) && 'expired' === $grant['end_reason'] ) ? 'expired' : 'revoked';
		}
		if ( (int) $grant['expires_at'] <= Clock::now() ) {
			return 'expired';
		}
		if ( ! empty( $grant['suspended_at'] ) ) {
			return 'suspended';
		}
		if ( (int) $grant['max_uses'] > 0 && (int) $grant['use_count'] >= (int) $grant['max_uses'] ) {
			return 'used';
		}
		return 'active';
	}

	/**
	 * Finds a grant by its numeric code. Returns a grant of any status.
	 *
	 * @param string $code Code as typed.
	 * @return array|null
	 */
	public static function find_by_code( $code ) {
		if ( '' === Codes::normalize_code( $code ) ) {
			return null;
		}
		return self::find_by_hash( 'code_hash', Codes::hash_code( $code ), $code, array( Codes::class, 'verify_code' ) );
	}

	/**
	 * Finds a grant by its link key. Returns a grant of any status.
	 *
	 * @param string $key Key from the link.
	 * @return array|null
	 */
	public static function find_by_link( $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			return null;
		}
		return self::find_by_hash( 'link_hash', Codes::hash_key( $key ), $key, array( Codes::class, 'verify_key' ) );
	}

	/**
	 * Grants that are not revoked and not expired, newest first.
	 *
	 * @return array
	 */
	public static function list_current() {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE revoked_at IS NULL AND expires_at > %s ORDER BY id DESC", Clock::mysql() ), ARRAY_A );
		return array_map( array( __CLASS__, 'normalize' ), (array) $rows );
	}

	/**
	 * Whether any grant is not revoked and not expired.
	 *
	 * @return bool
	 */
	public static function has_current() {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE revoked_at IS NULL AND expires_at > %s LIMIT 1", Clock::mysql() ) );
	}

	/**
	 * Clears the per-request resolver cache and Session's cached grant states.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$resolved = array();
		Session::flush_cache();
		CapabilityGuard::flush_cache();
		MenuGuard::flush_cache();
	}

	/**
	 * Moves the expiry forward by a number of seconds, never further than
	 * MAX_DURATION from now.
	 *
	 * @param int $id      Grant id.
	 * @param int $seconds Seconds to add.
	 * @return bool False for an unknown, revoked or expired grant.
	 */
	public static function extend( $id, $seconds ) {
		global $wpdb;
		$grant = self::get( $id );
		if ( null === $grant || ! in_array( $grant['status'], array( 'active', 'used', 'suspended' ), true ) ) {
			return false;
		}

		$now     = Clock::now();
		$expires = min( max( $grant['expires_at'], $now ) + max( 0, (int) $seconds ), $now + self::MAX_DURATION );
		$table   = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET expires_at = %s WHERE id = %d AND revoked_at IS NULL", Clock::mysql( $expires ), $grant['id'] ) );
		self::flush_cache();
		if ( false === $result ) {
			return false;
		}

		AuditLog::add(
			'grant_extended',
			array(
				'feature'  => 'support',
				'token_id' => $grant['id'],
				/* translators: %s: label of the support grant. */
				'summary'  => sprintf( __( 'Support access extended for %s', 'happyaccess' ), $grant['label'] ),
				'meta'     => array( 'expires_at' => $expires ),
			)
		);
		return true;
	}

	/**
	 * Pauses a grant and signs its temp user out.
	 *
	 * @param int $id Grant id.
	 * @return bool False when the grant is not active or used.
	 */
	public static function suspend( $id ) {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET suspended_at = %s WHERE id = %d AND revoked_at IS NULL AND suspended_at IS NULL AND expires_at > %s", Clock::mysql(), (int) $id, Clock::mysql() ) );
		self::flush_cache();
		if ( 1 !== $changed ) {
			return false;
		}

		$grant = self::get( $id );
		if ( null !== $grant ) {
			TempUsers::destroy_sessions( $grant['user_id'] );
			/* translators: %s: label of the support grant. */
			self::log( 'grant_suspended', $grant, sprintf( __( 'Support access suspended for %s', 'happyaccess' ), $grant['label'] ) );
		}
		return true;
	}

	/**
	 * Lifts a suspension.
	 *
	 * @param int $id Grant id.
	 * @return bool False when the grant is not suspended.
	 */
	public static function resume( $id ) {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET suspended_at = NULL WHERE id = %d AND revoked_at IS NULL AND suspended_at IS NOT NULL AND expires_at > %s", (int) $id, Clock::mysql() ) );
		self::flush_cache();
		if ( 1 !== $changed ) {
			return false;
		}

		$grant = self::get( $id );
		if ( null !== $grant ) {
			/* translators: %s: label of the support grant. */
			self::log( 'grant_resumed', $grant, sprintf( __( 'Support access resumed for %s', 'happyaccess' ), $grant['label'] ) );
		}
		return true;
	}

	/**
	 * Replaces the code and the link key. The old ones stop working at once.
	 *
	 * @param int $id Grant id.
	 * @return array|null code and link_key, or null for an unknown, revoked or expired grant.
	 * @throws \RuntimeException When no free code was found.
	 */
	public static function regenerate( $id ) {
		global $wpdb;
		$grant = self::get( $id );
		if ( null === $grant || ! in_array( $grant['status'], array( 'active', 'used', 'suspended' ), true ) ) {
			return null;
		}

		list( $code, $code_hash ) = self::unused_code();
		$link_key                 = Codes::link_key();
		$link_hash                = Codes::hash_key( $link_key );
		$table                    = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET code_hash = %s, link_hash = %s, token_hash = %s, use_count = 0 WHERE id = %d AND revoked_at IS NULL", $code_hash, $link_hash, $link_hash, $grant['id'] ) );
		self::flush_cache();
		if ( 1 !== $changed ) {
			return null;
		}

		TempUsers::destroy_sessions( $grant['user_id'] );

		/* translators: %s: label of the support grant. */
		self::log( 'grant_regenerated', $grant, sprintf( __( 'Support access code and link replaced for %s', 'happyaccess' ), $grant['label'] ) );
		return array(
			'code'     => $code,
			'link_key' => $link_key,
		);
	}

	/**
	 * Ends a grant for good and removes its temp user.
	 *
	 * If the user cannot be deleted now, the grant is still revoked and the
	 * Session resolver keeps the user out.
	 *
	 * @param int    $id     Grant id.
	 * @param string $reason Why it ended, for example revoked, expired or lockdown.
	 * @return bool False when the grant is unknown or already revoked.
	 */
	public static function revoke( $id, $reason = 'revoked' ) {
		global $wpdb;
		$grant = self::get( $id );
		if ( null === $grant || 0 !== $grant['revoked_at'] ) {
			return false;
		}

		$reason = sanitize_key( $reason );
		if ( '' === $reason ) {
			$reason = 'revoked';
		}

		$table = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$raw      = $wpdb->get_var( $wpdb->prepare( "SELECT metadata FROM {$table} WHERE id = %d", $grant['id'] ) );
		$metadata = ! empty( $raw ) ? json_decode( (string) $raw, true ) : null;
		$metadata = is_array( $metadata ) ? $metadata : array();

		$metadata['end_reason'] = $reason;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET revoked_at = %s, metadata = %s WHERE id = %d AND revoked_at IS NULL", Clock::mysql(), wp_json_encode( $metadata ), $grant['id'] ) );
		self::flush_cache();
		if ( 1 !== $changed ) {
			return false;
		}

		// HappyAccess's own cleanup. The activity log must not pin it on a temp user whose request runs this.
		$deleted = ActivityTracker::quietly(
			static function () use ( $grant ) {
				if ( $grant['user_id'] > 0 && false !== get_userdata( $grant['user_id'] )
					&& ( Capabilities::is_temp_user( $grant['user_id'] ) || TempUsers::owned_by_grant( $grant['user_id'], $grant ) ) ) {
					// If the delete below fails, the account stays but can do nothing and has no API keys.
					$stripped = new \WP_User( $grant['user_id'] );
					$stripped->set_role( '' );
					$stripped->remove_all_caps();
					TempUsers::delete_wc_api_keys( $grant['user_id'] );
				}
				TempUsers::destroy_sessions( $grant['user_id'] );
				return TempUsers::delete( $grant );
			}
		);
		self::flush_cache();
		if ( $grant['user_id'] > 0 && ! $deleted ) {
			AuditLog::add(
				'temp_user_delete_failed',
				array(
					'feature'  => 'support',
					'token_id' => $grant['id'],
					'user_id'  => 0,
					'meta'     => array( 'user_id' => $grant['user_id'] ),
				)
			);
		}

		$ended = self::get( $grant['id'] );
		$ended = null === $ended ? $grant : $ended;

		AuditLog::add(
			'grant_ended',
			array(
				'feature'  => 'support',
				'token_id' => $ended['id'],
				/* translators: %s: label of the support grant. */
				'summary'  => sprintf( __( 'Support access ended for %s', 'happyaccess' ), $ended['label'] ),
				'meta'     => array( 'reason' => $reason ),
			)
		);

		/**
		 * Fires after a grant has ended.
		 *
		 * @param array  $grant  The grant as stored after it ended.
		 * @param string $reason Why it ended.
		 */
		do_action( 'happyaccess_grant_ended', $ended, $reason );
		return true;
	}

	/**
	 * Ends every grant that is not revoked yet.
	 *
	 * @param string $reason Why they ended.
	 * @return int How many were revoked.
	 */
	public static function revoke_all( $reason ) {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, no input.
		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE revoked_at IS NULL ORDER BY id ASC" );

		$count = 0;
		foreach ( (array) $ids as $id ) {
			if ( self::revoke( (int) $id, $reason ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Counts one login. A single UPDATE checks the limits and writes the
	 * counters, so two requests cannot both use the last login of a one-time
	 * grant.
	 *
	 * @param int $id Grant id.
	 * @return bool True when this login was counted.
	 */
	public static function record_login( $id ) {
		global $wpdb;
		$now   = Clock::mysql();
		$table = Installer::table( 'tokens' );
		$sql   = "UPDATE {$table} SET use_count = use_count + 1, login_count = LAST_INSERT_ID( login_count + 1 ), last_login_at = %s, used_at = COALESCE( used_at, %s )
			WHERE id = %d AND revoked_at IS NULL AND suspended_at IS NULL AND expires_at > %s AND ( max_uses = 0 OR use_count < max_uses )";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; the query is prepared here.
		$changed = $wpdb->query( $wpdb->prepare( $sql, $now, $now, (int) $id, $now ) );
		self::flush_cache();

		self::$last_login_count = 0;
		if ( 1 !== $changed ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads this connection's value set by the UPDATE above.
		self::$last_login_count = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		return true;
	}

	/**
	 * Login count that the last successful record_login() wrote. It comes from
	 * the same UPDATE, so two parallel first logins can't both see 1.
	 *
	 * @return int 0 when unknown.
	 */
	public static function last_login_count() {
		return self::$last_login_count;
	}

	/**
	 * Ends every grant that has run out.
	 *
	 * @return int How many were ended.
	 */
	public static function cleanup_expired() {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE revoked_at IS NULL AND expires_at <= %s ORDER BY id ASC", Clock::mysql() ) );

		$count = 0;
		foreach ( (array) $ids as $id ) {
			if ( self::revoke( (int) $id, 'expired' ) ) {
				++$count;
			}
		}
		self::retry_orphans();
		return $count;
	}

	/**
	 * Deletes temp users that outlived their revoked grant, for example after
	 * a failed delete in revoke().
	 *
	 * @return int How many users were deleted.
	 */
	public static function retry_orphans() {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE revoked_at IS NOT NULL AND user_id > %d ORDER BY id ASC LIMIT %d", 0, 50 ) );

		$count = 0;
		foreach ( (array) $ids as $id ) {
			$grant = self::get( (int) $id );
			if ( null === $grant ) {
				continue;
			}
			$deleted = ActivityTracker::quietly(
				static function () use ( $grant ) {
					return TempUsers::delete( $grant );
				}
			);
			if ( $deleted ) {
				++$count;
			}
		}
		self::flush_cache();
		return $count;
	}

	/**
	 * Session resolver for temp users. Session calls this from inside
	 * determine_current_user, so it must only read user meta and the grant row.
	 * Never call get_current_user_id(), wp_get_current_user() or
	 * current_user_can() here.
	 *
	 * @param int $user_id Temp user id.
	 * @return array|null state (active, revoked or suspended) and expires_at, or null when the grant is missing.
	 */
	public static function resolve_user( $user_id ) {
		$user_id = (int) $user_id;
		$key     = get_current_blog_id() . ':' . $user_id;
		if ( array_key_exists( $key, self::$resolved ) ) {
			return self::$resolved[ $key ];
		}

		$result  = null;
		$blog_id = (int) get_user_meta( $user_id, 'happyaccess_blog_id', true );
		if ( $blog_id > 0 && get_current_blog_id() !== $blog_id ) {
			// The user has no role on this site, so there is nothing to end here.
			$result = array(
				'state'      => 'active',
				'expires_at' => PHP_INT_MAX,
			);
		} else {
			$grant = self::get( (int) get_user_meta( $user_id, 'happyaccess_token_id', true ) );
			if ( null !== $grant ) {
				// A revoked grant can have status "expired" (reason expired), so check revoked_at first.
				if ( $grant['revoked_at'] > 0 ) {
					$state = 'revoked';
				} elseif ( 'suspended' === $grant['status'] ) {
					$state = 'suspended';
				} else {
					// Active, used and expired all pass; Session ends an expired one from expires_at.
					$state = 'active';
				}
				$result = array(
					'state'      => $state,
					'expires_at' => $grant['expires_at'],
				);
			}
		}

		self::$resolved[ $key ] = $result;
		return $result;
	}

	/**
	 * Finds a code that no current grant uses.
	 *
	 * @return array The code and its hash.
	 * @throws \RuntimeException When every attempt hit a used code.
	 */
	private static function unused_code() {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		for ( $attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++ ) {
			$candidate = Codes::numeric( 8 );
			$hash      = Codes::hash_code( $candidate );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$taken = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE code_hash = %s AND revoked_at IS NULL AND expires_at > %s LIMIT 1", $hash, Clock::mysql() ) );
			if ( ! $taken ) {
				return array( $candidate, $hash );
			}
		}
		throw new \RuntimeException( 'Could not generate an unused code.' );
	}

	/**
	 * Writes a grant audit entry.
	 *
	 * @param string $event   Event key.
	 * @param array  $grant   Grant with id.
	 * @param string $summary Translated summary.
	 * @return void
	 */
	private static function log( $event, array $grant, $summary ) {
		AuditLog::add(
			$event,
			array(
				'feature'  => 'support',
				'token_id' => $grant['id'],
				'summary'  => $summary,
			)
		);
	}

	/**
	 * Looks a grant up by a stored hash, then confirms it with the verifier.
	 *
	 * @param string   $column   code_hash or link_hash.
	 * @param string   $hash     Hash to look for.
	 * @param string   $input    Raw input.
	 * @param callable $verifier Constant-time check taking input and stored hash.
	 * @return array|null
	 */
	private static function find_by_hash( $column, $hash, $input, $verifier ) {
		global $wpdb;
		$table  = Installer::table( 'tokens' );
		$column = 'link_hash' === $column ? 'link_hash' : 'code_hash';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; column is one of two fixed names.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s ORDER BY id DESC LIMIT 1", $hash ), ARRAY_A );
		if ( ! is_array( $row ) || ! call_user_func( $verifier, $input, (string) $row[ $column ] ) ) {
			return null;
		}
		return self::normalize( $row );
	}

	/**
	 * Turns a table row into the grant shape, filling defaults for rows with
	 * missing or unreadable JSON.
	 *
	 * @param array $row Table row.
	 * @return array
	 */
	private static function normalize( array $row ) {
		$restrictions = ! empty( $row['restrictions'] ) ? json_decode( (string) $row['restrictions'], true ) : null;
		$restrictions = is_array( $restrictions ) ? $restrictions : array();
		$metadata     = ! empty( $row['metadata'] ) ? json_decode( (string) $row['metadata'], true ) : null;
		$metadata     = is_array( $metadata ) ? $metadata : array();

		$protection = (string) $row['protection'];
		$level      = in_array( $protection, array( 'custom', 'full' ), true ) ? $protection : 'protected';
		$caps       = array();
		if ( 'custom' === $level && isset( $restrictions['caps'] ) ) {
			foreach ( (array) $restrictions['caps'] as $cap ) {
				if ( is_string( $cap ) && '' !== $cap ) {
					$caps[] = $cap;
				}
			}
		}

		$grant = array(
			'id'              => (int) $row['id'],
			'label'           => (string) $row['label'],
			'recipient_email' => (string) $row['recipient_email'],
			'role'            => (string) $row['role'],
			'protection'      => $protection,
			'level'           => $level,
			'caps'            => $caps,
			'allow_installs'  => 'protected_allow_installs' === $protection,
			'restrictions'    => array(
				'ips'            => isset( $restrictions['ips'] ) ? array_values( (array) $restrictions['ips'] ) : array(),
				'menus'          => isset( $restrictions['menus'] ) ? array_values( (array) $restrictions['menus'] ) : array(),
				'hide_admin_bar' => ! empty( $restrictions['hide_admin_bar'] ),
			),
			'redirect_to'     => (string) $row['redirect_to'],
			'notify'          => (string) $row['notify'],
			'created_by'      => (int) $row['created_by'],
			'user_id'         => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'created_at'      => self::timestamp( $row['created_at'] ),
			'expires_at'      => self::timestamp( $row['expires_at'] ),
			'revoked_at'      => self::timestamp( $row['revoked_at'] ),
			'suspended_at'    => self::timestamp( $row['suspended_at'] ),
			'last_login_at'   => self::timestamp( $row['last_login_at'] ),
			'login_count'     => (int) $row['login_count'],
			'max_uses'        => (int) $row['max_uses'],
			'use_count'       => (int) $row['use_count'],
			'end_reason'      => isset( $metadata['end_reason'] ) && is_string( $metadata['end_reason'] ) ? $metadata['end_reason'] : '',
		);

		$grant['status'] = self::status( $grant );
		return $grant;
	}

	/**
	 * Timestamp from a stored datetime, 0 when unset.
	 *
	 * @param string|null $datetime UTC MySQL datetime.
	 * @return int
	 */
	private static function timestamp( $datetime ) {
		if ( ! is_string( $datetime ) || 0 === strpos( $datetime, '0000-00-00' ) ) {
			return 0;
		}
		return Clock::from_mysql( $datetime );
	}
}
