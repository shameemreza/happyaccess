<?php
/**
 * Shared audit log.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * One log for every feature. Stores names and summaries, never secrets or
 * option values.
 */
final class AuditLog {

	/**
	 * Adds an entry.
	 *
	 * @param string $event Event key, for example "login".
	 * @param array  $args  feature, token_id, user_id, summary, meta.
	 * @return int New row id, or 0 when logging is off or the insert failed.
	 */
	public static function add( $event, array $args = array() ) {
		global $wpdb;

		if ( ! Settings::get( 'privacy.logging', true ) ) {
			return 0;
		}

		$args = wp_parse_args(
			$args,
			array(
				'feature'  => 'support',
				'token_id' => 0,
				'summary'  => '',
				'meta'     => array(),
			)
		);
		if ( ! array_key_exists( 'user_id', $args ) ) {
			$args['user_id'] = get_current_user_id();
		}

		$ip = ClientIp::get();
		if ( Settings::get( 'privacy.anonymize_ip', false ) ) {
			$ip = ClientIp::anonymize( $ip );
		}

		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? self::clip_bytes( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 255 ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$ok = $wpdb->insert(
			Installer::table( 'logs' ),
			array(
				'token_id'   => absint( $args['token_id'] ),
				'feature'    => substr( sanitize_key( $args['feature'] ), 0, 20 ),
				'event_type' => substr( sanitize_key( $event ), 0, 50 ),
				'user_id'    => absint( $args['user_id'] ),
				'ip_address' => $ip,
				'user_agent' => $agent,
				'summary'    => sanitize_text_field( (string) $args['summary'] ),
				'metadata'   => empty( $args['meta'] ) ? null : wp_json_encode( $args['meta'] ),
				'created_at' => Clock::mysql(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Cuts a string to a byte length without splitting a multibyte character,
	 * because $wpdb rejects the whole row when a value ends in a broken one.
	 *
	 * @param string $value Text.
	 * @param int    $bytes Maximum bytes.
	 * @return string
	 */
	private static function clip_bytes( $value, $bytes ) {
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, $bytes, 'UTF-8' );
		}
		return wp_check_invalid_utf8( substr( $value, 0, $bytes ), true );
	}

	/**
	 * Cuts a string to a number of characters.
	 *
	 * @param string $value Text.
	 * @param int    $chars Maximum characters.
	 * @return string
	 */
	private static function clip_chars( $value, $chars ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $chars, 'UTF-8' ) : substr( $value, 0, $chars );
	}

	/**
	 * Reads entries, newest first.
	 *
	 * Pass `features` as a list of feature keys. An empty list means no filter;
	 * a non-empty list with no valid key matches nothing.
	 *
	 * @param array $filters feature, features, event, user_id, token_id, search, since, until, page, per_page.
	 * @return array items, total, page, per_page.
	 */
	public static function query( array $filters = array() ) {
		global $wpdb;

		$f = wp_parse_args(
			$filters,
			array(
				'feature'  => '',
				'features' => array(),
				'event'    => '',
				'user_id'  => 0,
				'token_id' => 0,
				'search'   => '',
				'since'    => '',
				'until'    => '',
				'page'     => 1,
				'per_page' => 25,
			)
		);

		$where  = array( 'id > %d' );
		$params = array( 0 );

		if ( '' !== $f['feature'] ) {
			$where[]  = 'feature = %s';
			$params[] = sanitize_key( $f['feature'] );
		}
		$requested = (array) $f['features'];
		if ( $requested ) {
			$features = array_values( array_filter( array_map( 'sanitize_key', $requested ) ) );
			if ( $features ) {
				$where[] = 'feature IN ( ' . implode( ', ', array_fill( 0, count( $features ), '%s' ) ) . ' )';
				$params  = array_merge( $params, $features );
			} else {
				$where[] = '1 = 0';
			}
		}
		if ( '' !== $f['event'] ) {
			$where[]  = 'event_type = %s';
			$params[] = sanitize_key( $f['event'] );
		}
		if ( $f['user_id'] ) {
			$where[]  = 'user_id = %d';
			$params[] = absint( $f['user_id'] );
		}
		if ( $f['token_id'] ) {
			$where[]  = 'token_id = %d';
			$params[] = absint( $f['token_id'] );
		}
		$search = self::clip_chars( sanitize_text_field( (string) $f['search'] ), 100 );
		if ( '' !== $search ) {
			$where[]  = 'summary LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		if ( '' !== $f['since'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = sanitize_text_field( $f['since'] );
		}
		if ( '' !== $f['until'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = sanitize_text_field( $f['until'] );
		}

		$per_page  = max( 1, min( 100, (int) $f['per_page'] ) );
		$page      = max( 1, (int) $f['page'] );
		$table     = Installer::table( 'logs' );
		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; WHERE parts are fixed strings with placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();
		foreach ( (array) $rows as $row ) {
			$row['meta'] = empty( $row['metadata'] ) ? array() : (array) json_decode( $row['metadata'], true );
			unset( $row['metadata'] );
			$items[] = $row;
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Deletes entries older than a number of days.
	 *
	 * @param int $days Days to keep.
	 * @return int Rows deleted.
	 */
	public static function purge( $days ) {
		global $wpdb;
		$table  = Installer::table( 'logs' );
		$cutoff = Clock::mysql( Clock::now() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
