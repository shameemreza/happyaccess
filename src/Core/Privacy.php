<?php
/**
 * Privacy policy text, personal data exporter and eraser.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

use HappyAccess\Features\SupportAccess\Grants;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks HappyAccess into the WordPress privacy tools. Log entries are matched
 * by user ID or by the grants addressed to the email. Grants are matched by
 * recipient email, or by creator for a WordPress user. A grant recipient may
 * not be a WordPress user, so everything also works on the email alone.
 */
final class Privacy {

	const PER_PAGE = 50;

	/**
	 * Log events written because the site owner acted, not the support agent.
	 * Rows with these events stay out of an agent's export and erase, even
	 * when they carry the grant's token id.
	 */
	const ADMIN_EVENTS = array(
		'grant_created',
		'grant_extended',
		'grant_suspended',
		'grant_resumed',
		'grant_regenerated',
		'grant_ended',
		'temp_user_deleted',
		'temp_user_delete_failed',
		'bundle_emailed',
		'emergency_lock',
	);

	/**
	 * Log events written by a temp user's own requests, or by the login steps
	 * on behalf of a grant. Every one carries the grant's token id.
	 */
	const AGENT_EVENTS = array(
		'login_success',
		'login_failed',
		'access_blocked',
		'plugin_activated',
		'plugin_deactivated',
		'plugin_deleted',
		'upgrader_ran',
		'theme_switched',
		'theme_deleted',
		'post_created',
		'post_updated',
		'post_trashed',
		'post_deleted',
		'settings_saved',
		'order_status_changed',
		'user_updated',
		'user_created',
		'user_role_changed',
		'user_role_added',
		'roles_changed',
		'role_change_blocked',
		'privacy_erased',
		'wc_webhook_created',
		'wc_key_blocked',
		'admin_account_created',
		'admin_account_changed',
		'admin_role_granted',
	);

	/**
	 * Log events with no grant token: written by the plugin itself. Emergency
	 * lock is also one of these, but it is listed once, under ADMIN_EVENTS.
	 */
	const CORE_EVENTS = array(
		'plugin_upgraded',
	);

	/**
	 * Adds the policy text, the exporter and the eraser.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( self::class, 'add_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	/**
	 * Adds the suggested text to the privacy policy guide.
	 *
	 * @return void
	 */
	public static function add_policy_content() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'HappyAccess', self::policy_text() );
		}
	}

	/**
	 * Suggested privacy policy text.
	 *
	 * @return string HTML paragraphs.
	 */
	public static function policy_text() {
		$days = (int) Settings::get( 'privacy.retention_days', 30 );

		$paragraphs = array(
			__( 'HappyAccess gives support staff temporary access to this site. When someone uses a support access link or code, the site stores the IP address and browser user agent of that visit, the login times, and a list of the actions taken while the access was active. The list holds names and titles of what changed, never the values of settings or passwords.', 'happyaccess' ),
			__( 'When an administrator creates support access for a person, the site also stores the label they entered, the IP allowlist, and, if they entered one, the email address of the recipient. The administrator\'s user account is recorded as the creator.', 'happyaccess' ),
			sprintf(
				/* translators: %d: number of days. */
				_n(
					'Log entries are deleted after %d day. Support access records (label, recipient email and allowlist) are kept until the access ends plus the same number of days, then deleted. Login attempt records and expired login challenges are deleted after one day.',
					'Log entries are deleted after %d days. Support access records (label, recipient email and allowlist) are kept until the access ends plus the same number of days, then deleted. Login attempt records and expired login challenges are deleted after one day.',
					$days,
					'happyaccess'
				),
				$days
			),
			__( 'If reCAPTCHA is turned on for support logins, the visitor\'s IP address and browser details are sent to Google to check that the visit is from a person. Google handles that data under its own privacy policy.', 'happyaccess' ),
			__( 'Site owners can export or erase this data from the Tools menu. Erasing an email address ends any active support access for it, removes the IP address and user agent from the related log entries, and clears the recipient email, label and allowlist from the support access records.', 'happyaccess' ),
		);

		$html = '';
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>';
		}
		return $html;
	}

	/**
	 * Adds the exporter to the core list.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['happyaccess'] = array(
			'exporter_friendly_name' => __( 'HappyAccess', 'happyaccess' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds the eraser to the core list.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['happyaccess'] = array(
			'eraser_friendly_name' => __( 'HappyAccess', 'happyaccess' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports log entries and grants tied to an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, starting at 1.
	 * @return array data and done.
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;

		$email = trim( (string) $email );
		if ( '' === $email || Capabilities::is_temp_user( get_current_user_id() ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user_id = self::user_id_for( $email );
		$offset  = ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE;
		$logs    = Installer::table( 'logs' );
		$tokens  = Installer::table( 'tokens' );
		$items   = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom tables; the IN list has one placeholder per ADMIN_EVENTS value.
		$events     = self::admin_event_placeholders();
		$log_rows   = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$logs} WHERE ( ( %d > 0 AND user_id = %d ) OR ( token_id IN ( SELECT id FROM {$tokens} WHERE recipient_email = %s ) AND event_type NOT IN ( {$events} ) ) ) ORDER BY id ASC LIMIT %d OFFSET %d",
				array_merge( array( $user_id, $user_id, $email ), self::ADMIN_EVENTS, array( self::PER_PAGE, $offset ) )
			),
			ARRAY_A
		);
		$grant_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tokens} WHERE ( ( %d > 0 AND created_by = %d ) OR recipient_email = %s ) ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$user_id,
				$email,
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		foreach ( $log_rows as $row ) {
			$items[] = array(
				'group_id'          => 'happyaccess_logs',
				'group_label'       => __( 'HappyAccess activity log', 'happyaccess' ),
				'group_description' => __( 'Events recorded for this user by HappyAccess.', 'happyaccess' ),
				'item_id'           => 'happyaccess-log-' . (int) $row['id'],
				'data'              => array(
					array(
						'name'  => __( 'Event', 'happyaccess' ),
						'value' => (string) $row['event_type'],
					),
					array(
						'name'  => __( 'Date (UTC)', 'happyaccess' ),
						'value' => (string) $row['created_at'],
					),
					array(
						'name'  => __( 'IP address', 'happyaccess' ),
						'value' => (string) $row['ip_address'],
					),
					array(
						'name'  => __( 'User agent', 'happyaccess' ),
						'value' => (string) $row['user_agent'],
					),
					array(
						'name'  => __( 'Summary', 'happyaccess' ),
						'value' => (string) $row['summary'],
					),
				),
			);
		}

		foreach ( $grant_rows as $row ) {
			$data = array(
				array(
					'name'  => __( 'Created (UTC)', 'happyaccess' ),
					'value' => (string) $row['created_at'],
				),
				array(
					'name'  => __( 'Expires (UTC)', 'happyaccess' ),
					'value' => (string) $row['expires_at'],
				),
				array(
					'name'  => __( 'Role', 'happyaccess' ),
					'value' => (string) $row['role'],
				),
			);

			// The label, recipient email and allowlist belong to the recipient. A creator only gets the rest.
			if ( 0 === strcasecmp( (string) $row['recipient_email'], $email ) ) {
				$restrictions = ! empty( $row['restrictions'] ) ? json_decode( (string) $row['restrictions'], true ) : null;
				$ips          = is_array( $restrictions ) && isset( $restrictions['ips'] ) ? array_filter( array_map( 'strval', (array) $restrictions['ips'] ) ) : array();
				$data         = array_merge(
					array(
						array(
							'name'  => __( 'Label', 'happyaccess' ),
							'value' => (string) $row['label'],
						),
						array(
							'name'  => __( 'Recipient email', 'happyaccess' ),
							'value' => (string) $row['recipient_email'],
						),
					),
					$data,
					array(
						array(
							'name'  => __( 'IP allowlist', 'happyaccess' ),
							'value' => implode( ', ', $ips ),
						),
					)
				);
			}

			$items[] = array(
				'group_id'          => 'happyaccess_grants',
				'group_label'       => __( 'HappyAccess support access', 'happyaccess' ),
				'group_description' => __( 'Support access records that name this user or email address.', 'happyaccess' ),
				'item_id'           => 'happyaccess-grant-' . (int) $row['id'],
				'data'              => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => count( $log_rows ) < self::PER_PAGE && count( $grant_rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Erases what HappyAccess holds for an email address. Works when the email
	 * belongs to no user, because a grant recipient can be an outside agent.
	 *
	 * Each pass runs in this order:
	 * 1. End the grants addressed to the email that are still current, and
	 *    swap their labels inside the log summaries.
	 * 2. Anonymize up to 50 log entries: those of the user, and those of the
	 *    grants addressed to the email.
	 * 3. Only when no log entries are left, clear the grant fields. The log
	 *    match depends on recipient_email, so grants must stay matched until
	 *    their logs are done.
	 *
	 * A processed row stops matching, so the page number is not used as an
	 * offset.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, starting at 1. Unused.
	 * @return array items_removed, items_retained, messages and done.
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;

		unset( $page );
		$email = trim( (string) $email );
		if ( '' === $email || Capabilities::is_temp_user( get_current_user_id() ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id = self::user_id_for( $email );
		$logs    = Installer::table( 'logs' );
		$tokens  = Installer::table( 'tokens' );

		$scrubbed = self::end_and_scrub_grants( $email );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom tables; the IN list has one placeholder per ADMIN_EVENTS value.
		$events    = self::admin_event_placeholders();
		$log_count = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$logs} SET user_id = IF( user_id = %d, 0, user_id ), ip_address = %s, user_agent = %s WHERE ( ( %d > 0 AND user_id = %d ) OR ( token_id IN ( SELECT id FROM {$tokens} WHERE recipient_email = %s ) AND event_type NOT IN ( {$events} ) AND COALESCE( ip_address, '' ) <> %s ) ) LIMIT %d",
				array_merge( array( $user_id, '0.0.0.0', '', $user_id, $user_id, $email ), self::ADMIN_EVENTS, array( '0.0.0.0', self::PER_PAGE ) )
			)
		);
		// phpcs:enable

		$grant_count   = 0;
		$created_count = 0;
		if ( $log_count < self::PER_PAGE ) {
			$grant_count = self::clear_grants( $email );
			if ( $user_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
				$created_count = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$tokens} SET created_by = 0 WHERE created_by = %d LIMIT %d", $user_id, self::PER_PAGE ) );
			}
			Grants::flush_cache();
		}

		$messages = array();
		if ( $log_count > 0 ) {
			$messages[] = __( 'Log entries were kept without IP address or browser details until the retention period ends.', 'happyaccess' );
		}

		if ( ! $scrubbed ) {
			$messages[] = __( 'Some log summaries may still contain the grant label.', 'happyaccess' );
		}

		return array(
			'items_removed'  => ( $log_count + $grant_count + $created_count ) > 0,
			'items_retained' => $log_count > 0,
			'messages'       => $messages,
			'done'           => $log_count < self::PER_PAGE && $grant_count < self::PER_PAGE && $created_count < self::PER_PAGE,
		);
	}

	/**
	 * Ends the grants addressed to an email that are still current, and swaps
	 * their labels inside the log summaries for a placeholder. Running it again
	 * is harmless.
	 *
	 * @param string $email Recipient email.
	 * @return bool False when a label may still appear in a log summary.
	 */
	private static function end_and_scrub_grants( $email ) {
		global $wpdb;

		$tokens = Installer::table( 'tokens' );
		$logs   = Installer::table( 'logs' );
		// Grants with an empty label that already ended have nothing left to do.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tokens} WHERE recipient_email = %s AND ( revoked_at IS NULL OR label <> %s ) ORDER BY id ASC LIMIT %d", $email, '', self::PER_PAGE ) );

		$complete = true;
		foreach ( $ids as $id ) {
			$grant = Grants::get( (int) $id );
			if ( null === $grant ) {
				continue;
			}
			if ( 0 === $grant['revoked_at'] && $grant['expires_at'] > Clock::now() ) {
				Grants::revoke( $grant['id'], 'privacy_erased' );
			}

			// Summaries end with the label ("Support access granted to Acme"). Only that trailing part is replaced, and short labels are skipped because they could match other text.
			if ( mb_strlen( $grant['label'] ) >= 3 ) {
				$suffix = ' ' . $grant['label'];
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
				$wpdb->query( $wpdb->prepare( "UPDATE {$logs} SET summary = CONCAT( LEFT( summary, CHAR_LENGTH( summary ) - CHAR_LENGTH( %s ) ), %s ) WHERE token_id = %d AND RIGHT( summary, CHAR_LENGTH( %s ) ) = %s", $suffix, ' [removed]', $grant['id'], $suffix, $suffix ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
				$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE token_id = %d AND summary LIKE %s", $grant['id'], '%' . $wpdb->esc_like( $grant['label'] ) . '%' ) );
				if ( $left > 0 ) {
					$complete = false;
				}
			} elseif ( '' !== $grant['label'] ) {
				$complete = false;
			}
		}
		return $complete;
	}

	/**
	 * Placeholders for the admin events, to sit inside an IN list. A token
	 * matched log row is the agent's data when its event is not one of these.
	 * The caller passes self::ADMIN_EVENTS as the values.
	 *
	 * @return string One %s per admin event, comma separated.
	 */
	private static function admin_event_placeholders() {
		return implode( ', ', array_fill( 0, count( self::ADMIN_EVENTS ), '%s' ) );
	}

	/**
	 * Clears recipient email, label and the IP allowlist of up to 50 grants
	 * addressed to an email. Menus and the admin bar flag stay.
	 *
	 * @param string $email Recipient email.
	 * @return int Grants cleared.
	 */
	private static function clear_grants( $email ) {
		global $wpdb;

		$tokens = Installer::table( 'tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, restrictions FROM {$tokens} WHERE recipient_email = %s ORDER BY id ASC LIMIT %d", $email, self::PER_PAGE ), ARRAY_A );

		$count = 0;
		foreach ( $rows as $row ) {
			$data = array(
				'recipient_email' => '',
				'label'           => '',
				'ip_restrictions' => null,
			);

			$restrictions = ! empty( $row['restrictions'] ) ? json_decode( (string) $row['restrictions'], true ) : null;
			if ( is_array( $restrictions ) ) {
				$restrictions['ips']  = array();
				$data['restrictions'] = wp_json_encode( $restrictions );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			if ( false !== $wpdb->update( $tokens, $data, array( 'id' => (int) $row['id'] ) ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * User ID for an email address.
	 *
	 * @param string $email Email address.
	 * @return int 0 when no user has this email.
	 */
	private static function user_id_for( $email ) {
		if ( '' === $email ) {
			return 0;
		}
		$user = get_user_by( 'email', $email );
		return $user ? (int) $user->ID : 0;
	}
}
