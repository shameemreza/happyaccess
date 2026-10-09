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
	 * Longest summary, in characters.
	 */
	const SUMMARY_MAX = 500;

	/**
	 * Meta keys whose values are replaced before they are stored, matched
	 * whole and without regard to case. A key such as "keys", which lists
	 * setting names, is not on the list and keeps its value.
	 */
	const SECRET_META_KEYS = array( 'code', 'key', 'token', 'secret' );

	/**
	 * What a redacted value becomes.
	 */
	const REDACTED = '[redacted]';

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

		$meta = is_array( $args['meta'] ) ? self::redact( $args['meta'] ) : array();

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
				'summary'    => self::clip_chars( sanitize_text_field( (string) $args['summary'] ), self::SUMMARY_MAX ),
				'metadata'   => empty( $meta ) ? null : wp_json_encode( $meta ),
				'created_at' => Clock::mysql(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Replaces the value of every secret-looking meta key, at any depth.
	 *
	 * @param array $meta Meta array.
	 * @return array
	 */
	private static function redact( array $meta ) {
		foreach ( $meta as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), self::SECRET_META_KEYS, true ) ) {
				$meta[ $key ] = self::REDACTED;
			} elseif ( is_array( $value ) ) {
				$meta[ $key ] = self::redact( $value );
			}
		}
		return $meta;
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
	 * Pass `features` as a list of feature keys, or `event_in` as a list of
	 * event keys. An empty list means no filter; a non-empty list with no valid
	 * key matches nothing.
	 *
	 * A user_id or token_id of 0 filters for rows with no user or no pass when
	 * the key is given; leave the key out, or pass null, for no filter. The
	 * since and until bounds are UTC datetimes as "Y-m-d H:i:s"; a bound in
	 * any other form matches nothing.
	 *
	 * @param array $filters feature, features, event, event_in, user_id, token_id, search, since, until, page, per_page.
	 * @return array items, total, page, per_page.
	 */
	public static function query( array $filters = array() ) {
		global $wpdb;

		$f = wp_parse_args(
			$filters,
			array(
				'page'     => 1,
				'per_page' => 25,
			)
		);

		list( $where_sql, $params ) = self::where( $f );

		$per_page = max( 1, min( 100, (int) $f['per_page'] ) );
		$page     = max( 1, (int) $f['page'] );
		$table    = Installer::table( 'logs' );

		// $where_sql is joined only from the fixed strings in where(), and every value in it is a placeholder.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; WHERE parts are fixed strings with placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where_sql}", array_merge( array( $table ), $params ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( array( $table ), $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items'    => self::decode_rows( (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Reads up to a number of entries, newest first, in one query with no
	 * count. Takes the same filters as query(), without the paging.
	 *
	 * @param array $filters Filters, as for query().
	 * @param int   $limit   Most rows to return, 1 to 5000.
	 * @return array Rows with decoded meta.
	 */
	public static function rows( array $filters, $limit ) {
		global $wpdb;

		list( $where_sql, $params ) = self::where( $filters );

		$limit = max( 1, min( 5000, (int) $limit ) );
		$table = Installer::table( 'logs' );

		// $where_sql is joined only from the fixed strings in where(), and every value in it is a placeholder.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; WHERE parts are fixed strings with placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d",
				array_merge( array( $table ), $params, array( $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return self::decode_rows( (array) $rows );
	}

	/**
	 * The WHERE clause and its values for a set of filters.
	 *
	 * @param array $filters feature, features, event, event_in, user_id, token_id, search, since, until.
	 * @return array The clause with placeholders, then the values.
	 */
	private static function where( array $filters ) {
		global $wpdb;

		$f = wp_parse_args(
			$filters,
			array(
				'feature'  => '',
				'features' => array(),
				'event'    => '',
				'event_in' => array(),
				'user_id'  => null,
				'token_id' => null,
				'search'   => '',
				'since'    => '',
				'until'    => '',
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
		$requested_events = (array) $f['event_in'];
		if ( $requested_events ) {
			$events = array_values( array_filter( array_map( 'sanitize_key', $requested_events ) ) );
			if ( $events ) {
				$where[] = 'event_type IN ( ' . implode( ', ', array_fill( 0, count( $events ), '%s' ) ) . ' )';
				$params  = array_merge( $params, $events );
			} else {
				$where[] = '1 = 0';
			}
		}
		if ( null !== $f['user_id'] ) {
			$where[]  = 'user_id = %d';
			$params[] = absint( $f['user_id'] );
		}
		if ( null !== $f['token_id'] ) {
			$where[]  = 'token_id = %d';
			$params[] = absint( $f['token_id'] );
		}
		$search = self::clip_chars( sanitize_text_field( (string) $f['search'] ), 100 );
		if ( '' !== $search ) {
			$where[]  = 'summary LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		foreach ( array(
			'since' => '>=',
			'until' => '<=',
		) as $bound => $operator ) {
			if ( '' === $f[ $bound ] || null === $f[ $bound ] ) {
				continue;
			}
			if ( self::is_utc_datetime( $f[ $bound ] ) ) {
				$where[]  = 'created_at ' . $operator . ' %s';
				$params[] = $f[ $bound ];
			} else {
				$where[] = '1 = 0';
			}
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Whether a value is a real UTC datetime written as "Y-m-d H:i:s".
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_utc_datetime( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	/**
	 * Turns each row's stored metadata into a meta array.
	 *
	 * @param array $rows Table rows.
	 * @return array
	 */
	private static function decode_rows( array $rows ) {
		$items = array();
		foreach ( $rows as $row ) {
			$row['meta'] = empty( $row['metadata'] ) ? array() : (array) json_decode( $row['metadata'], true );
			unset( $row['metadata'] );
			$items[] = $row;
		}
		return $items;
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
