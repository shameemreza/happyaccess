<?php
/**
 * Support grants: create and read.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\ClientIp;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Codes;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Core\Settings;

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
	 * Creates a grant.
	 *
	 * @param array $args label, email, role, duration, one_time, ips, menus, hide_admin_bar, redirect_to, block_installs, notify, created_by.
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
			throw new \InvalidArgumentException( 'A label is required.' );
		}
		$label = mb_substr( $label, 0, 190 );

		$email = '';
		if ( isset( $args['email'] ) && '' !== trim( (string) $args['email'] ) ) {
			$email = sanitize_email( (string) $args['email'] );
			if ( '' === $email ) {
				throw new \InvalidArgumentException( 'The email address is not valid.' );
			}
		}

		$role = isset( $args['role'] ) && '' !== (string) $args['role'] ? (string) $args['role'] : 'administrator';
		if ( ! array_key_exists( $role, wp_roles()->roles ) ) {
			throw new \InvalidArgumentException( 'The role does not exist.' );
		}

		$duration = isset( $args['duration'] ) ? (int) $args['duration'] : (int) Settings::get( 'support.default_duration' );
		$duration = max( self::MIN_DURATION, min( self::MAX_DURATION, $duration ) );

		$ips = array();
		if ( ! empty( $args['ips'] ) ) {
			foreach ( (array) $args['ips'] as $ip ) {
				$ip = is_string( $ip ) ? trim( $ip ) : '';
				if ( '' !== $ip && ClientIp::valid( $ip ) && ! in_array( $ip, $ips, true ) ) {
					$ips[] = $ip;
				}
			}
			if ( empty( $ips ) ) {
				throw new \InvalidArgumentException( 'None of the IP addresses is valid.' );
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

		$code      = '';
		$code_hash = '';
		for ( $attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++ ) {
			$candidate = Codes::numeric( 8 );
			$hash      = Codes::hash_code( $candidate );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$taken = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE code_hash = %s AND revoked_at IS NULL AND expires_at > %s LIMIT 1", $hash, Clock::mysql() ) );
			if ( ! $taken ) {
				$code      = $candidate;
				$code_hash = $hash;
				break;
			}
		}
		if ( '' === $code ) {
			throw new \RuntimeException( 'Could not generate an unused code.' );
		}

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
				'protection'      => empty( $args['block_installs'] ) ? 'protected' : 'protected_no_installs',
				'restrictions'    => wp_json_encode(
					array(
						'ips'            => $ips,
						'menus'          => $menus,
						'hide_admin_bar' => ! empty( $args['hide_admin_bar'] ),
					)
				),
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

		AuditLog::add(
			'grant_created',
			array(
				'feature'  => 'support',
				'token_id' => $id,
				/* translators: %s: label of the support grant. */
				'summary'  => sprintf( __( 'Support access granted to %s', 'happyaccess' ), $label ),
				'meta'     => array(
					'role'     => $role,
					'duration' => $duration,
					'one_time' => $one_time,
				),
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
	 * Clears cached grant lookups. Nothing is cached yet.
	 *
	 * @return void
	 */
	public static function flush_cache() {
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

		$grant = array(
			'id'              => (int) $row['id'],
			'label'           => (string) $row['label'],
			'recipient_email' => (string) $row['recipient_email'],
			'role'            => (string) $row['role'],
			'protection'      => (string) $row['protection'],
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
