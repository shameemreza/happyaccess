<?php
/**
 * REST routes for the activity log.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Rest;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Clock;
use HappyAccess\Core\EventLabels;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Privacy;
use HappyAccess\Features\SupportAccess\Grants;

defined( 'ABSPATH' ) || exit;

/**
 * A filtered and paged list, a per-pass summary and a CSV export. Rows are
 * read through AuditLog, so IPs come back as stored.
 */
final class ActivityController {

	const FEATURES     = array( '', 'support', 'passwordless', 'two_step', 'admin', 'core' );
	const EXPORT_LIMIT = 5000;
	const MAX_IPS      = 5;

	/**
	 * Events that are logins or refused attempts, so not a change.
	 */
	const NOT_CHANGES = array( 'login_success', 'login_failed', 'logout', 'access_blocked', 'wc_key_blocked' );

	/**
	 * Registers the activity routes.
	 *
	 * @return void
	 */
	public static function routes() {
		$permission = array( Routes::class, 'can_manage' );

		register_rest_route(
			Routes::NS,
			'/activity',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_items' ),
				'permission_callback' => $permission,
				'args'                => array_merge(
					self::filter_args(),
					array(
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 25,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					)
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/activity/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'summary' ),
				'permission_callback' => $permission,
				'args'                => array(
					'token_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			Routes::NS,
			'/activity/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'export' ),
				'permission_callback' => $permission,
				'args'                => self::filter_args(),
			)
		);
	}

	/**
	 * Filter args shared by the list and the export.
	 *
	 * @return array
	 */
	private static function filter_args() {
		$date = array(
			'type'              => 'string',
			'default'           => '',
			'validate_callback' => array( __CLASS__, 'validate_date' ),
		);

		return array(
			'feature'  => array(
				'type'              => 'string',
				'default'           => '',
				'enum'              => self::FEATURES,
				'validate_callback' => 'rest_validate_request_arg',
			),
			'event'    => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'user_id'  => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'token_id' => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'since'    => $date,
			'until'    => $date,
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Accepts an empty value or a real Y-m-d date.
	 *
	 * @param mixed $value Param value.
	 * @return bool
	 */
	public static function validate_date( $value ) {
		if ( '' === $value ) {
			return true;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		$date = \DateTime::createFromFormat( '!Y-m-d', $value );
		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * GET /activity.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_items( \WP_REST_Request $request ) {
		$result = AuditLog::query(
			array_merge(
				self::filters( $request ),
				array(
					'page'     => (int) $request->get_param( 'page' ),
					'per_page' => (int) $request->get_param( 'per_page' ),
				)
			)
		);

		return rest_ensure_response(
			array(
				'items'    => self::present_rows( $result['items'] ),
				'total'    => (int) $result['total'],
				'page'     => (int) $result['page'],
				'per_page' => (int) $result['per_page'],
			)
		);
	}

	/**
	 * GET /activity/summary.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function summary( \WP_REST_Request $request ) {
		$token_id = (int) $request->get_param( 'token_id' );

		$logins  = AuditLog::query(
			array(
				'token_id' => $token_id,
				'event'    => 'login_success',
				'per_page' => 1,
			)
		);
		$changes = AuditLog::query(
			array(
				'token_id' => $token_id,
				'event_in' => array_diff( Privacy::AGENT_EVENTS, self::NOT_CHANGES ),
				'per_page' => 1,
			)
		);

		return rest_ensure_response(
			array(
				'logins'  => (int) $logins['total'],
				'changes' => (int) $changes['total'],
				'minutes' => null,
				'ips'     => self::recent_ips( $token_id ),
			)
		);
	}

	/**
	 * GET /activity/export.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function export( \WP_REST_Request $request ) {
		$filters = self::filters( $request );
		$rows    = array();
		$page    = 1;
		$wanted  = self::EXPORT_LIMIT;
		$have    = 0;
		do {
			$result = AuditLog::query(
				array_merge(
					$filters,
					array(
						'page'     => $page,
						'per_page' => 100,
					)
				)
			);
			$rows   = array_merge( $rows, $result['items'] );
			$have   = count( $rows );
			$wanted = min( self::EXPORT_LIMIT, (int) $result['total'] );
			++$page;
		} while ( $result['items'] && $have < $wanted );
		$rows = array_slice( $rows, 0, self::EXPORT_LIMIT );

		$out = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream, not the filesystem.
		fputcsv( $out, array( 'time', 'feature', 'event', 'summary', 'user', 'pass', 'ip' ), ',', '"', '' );
		foreach ( self::present_rows( $rows ) as $item ) {
			$cells = array(
				wp_date( 'Y-m-d H:i:s', $item['time'] ),
				$item['feature'],
				$item['event'],
				$item['summary'],
				$item['actor']['name'],
				$item['pass'],
				$item['ip'],
			);
			fputcsv( $out, array_map( array( __CLASS__, 'guard_cell' ), $cells ), ',', '"', '' );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory stream, not the filesystem.

		return rest_ensure_response(
			array(
				'filename' => 'happyaccess-activity-' . wp_date( 'Y-m-d', Clock::now() ) . '.csv',
				'csv'      => $csv,
			)
		);
	}

	/**
	 * Stops a spreadsheet from running a cell as a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function guard_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Turns request params into AuditLog::query() filters. Dates are the
	 * start and end of a day in the site timezone, converted to UTC.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private static function filters( \WP_REST_Request $request ) {
		$filters = array(
			'event'    => (string) $request->get_param( 'event' ),
			'user_id'  => (int) $request->get_param( 'user_id' ),
			'token_id' => (int) $request->get_param( 'token_id' ),
			'search'   => (string) $request->get_param( 'search' ),
		);

		$feature = (string) $request->get_param( 'feature' );
		if ( 'admin' === $feature ) {
			$filters['event_in'] = Privacy::ADMIN_EVENTS;
		} elseif ( '' !== $feature ) {
			$filters['feature'] = $feature;
		}

		$since = (string) $request->get_param( 'since' );
		if ( '' !== $since ) {
			$filters['since'] = get_gmt_from_date( $since . ' 00:00:00' );
		}
		$until = (string) $request->get_param( 'until' );
		if ( '' !== $until ) {
			$filters['until'] = get_gmt_from_date( $until . ' 23:59:59' );
		}

		return $filters;
	}

	/**
	 * Builds the public item for each row. Users are read in one query and
	 * each pass label is looked up once.
	 *
	 * @param array $rows Rows from AuditLog::query().
	 * @return array
	 */
	private static function present_rows( array $rows ) {
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'user_id' ) ) ) ) );
		$names    = array();
		if ( $user_ids ) {
			foreach ( get_users(
				array(
					'include' => $user_ids,
					'fields'  => array( 'ID', 'display_name' ),
				)
			) as $user ) {
				$names[ (int) $user->ID ] = (string) $user->display_name;
			}
		}

		$labels = array();
		$items  = array();
		foreach ( $rows as $row ) {
			$user_id  = (int) $row['user_id'];
			$token_id = (int) $row['token_id'];

			if ( isset( $names[ $user_id ] ) ) {
				$name = $names[ $user_id ];
			} else {
				$name = isset( $row['meta']['user_login'] ) && is_string( $row['meta']['user_login'] ) ? $row['meta']['user_login'] : '';
			}

			if ( $token_id > 0 && ! isset( $labels[ $token_id ] ) ) {
				$grant               = Grants::get( $token_id );
				$labels[ $token_id ] = null === $grant ? '' : (string) $grant['label'];
			}

			$items[] = array(
				'id'          => (int) $row['id'],
				'time'        => Clock::from_mysql( $row['created_at'] ),
				'feature'     => (string) $row['feature'],
				'event'       => (string) $row['event_type'],
				'event_label' => EventLabels::label( $row['event_type'] ),
				'summary'     => (string) $row['summary'],
				'actor'       => array(
					'id'   => $user_id,
					'name' => $name,
				),
				'ip'          => (string) $row['ip_address'],
				'token_id'    => $token_id,
				'pass'        => $token_id > 0 ? $labels[ $token_id ] : '',
			);
		}
		return $items;
	}

	/**
	 * The most recent distinct IPs of a pass's agent activity.
	 *
	 * @param int $token_id Grant id.
	 * @return string[]
	 */
	private static function recent_ips( $token_id ) {
		global $wpdb;

		$table = Installer::table( 'logs' );
		$marks = implode( ', ', array_fill( 0, count( Privacy::AGENT_EVENTS ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; the IN list is placeholders only.
		$ips = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ip_address FROM {$table} WHERE token_id = %d AND ip_address <> '' AND event_type IN ( {$marks} ) GROUP BY ip_address ORDER BY MAX( id ) DESC LIMIT %d",
				array_merge( array( $token_id ), Privacy::AGENT_EVENTS, array( self::MAX_IPS ) )
			)
		);
		// phpcs:enable

		return array_map( 'strval', (array) $ips );
	}
}
