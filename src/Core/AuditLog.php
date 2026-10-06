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
				'user_id'  => get_current_user_id(),
				'summary'  => '',
				'meta'     => array(),
			)
		);

		$ip = ClientIp::get();
		if ( Settings::get( 'privacy.anonymize_ip', false ) ) {
			$ip = ClientIp::anonymize( $ip );
		}

		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$ok = $wpdb->insert(
			Installer::table( 'logs' ),
			array(
				'token_id'   => absint( $args['token_id'] ),
				'feature'    => sanitize_key( $args['feature'] ),
				'event_type' => sanitize_key( $event ),
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
	 * Reads entries, newest first.
	 *
	 * @param array $filters feature, event, user_id, token_id, since, until, page, per_page.
	 * @return array items, total, page, per_page.
	 */
	public static function query( array $filters = array() ) {
		global $wpdb;

		$f = wp_parse_args(
			$filters,
			array(
				'feature'  => '',
				'event'    => '',
				'user_id'  => 0,
				'token_id' => 0,
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
