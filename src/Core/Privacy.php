<?php
/**
 * Privacy policy text, personal data exporter and eraser.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks HappyAccess into the WordPress privacy tools. Data is matched by
 * user ID for the log and by user ID or email for grants. A grant recipient
 * may not be a WordPress user, so the eraser works on the email alone.
 */
final class Privacy {

	const PER_PAGE = 50;

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
			__( 'When an administrator creates support access for a person, the site also stores the label they entered and, if they entered one, the email address of the recipient. The administrator\'s user account is recorded as the creator.', 'happyaccess' ),
			sprintf(
				/* translators: %d: number of days. */
				_n(
					'Log entries are deleted after %d day. Login attempt records and expired login challenges are deleted after one day.',
					'Log entries are deleted after %d days. Login attempt records and expired login challenges are deleted after one day.',
					$days,
					'happyaccess'
				),
				$days
			),
			__( 'If reCAPTCHA is turned on for support logins, the visitor\'s IP address and browser details are sent to Google to check that the visit is from a person. Google handles that data under its own privacy policy.', 'happyaccess' ),
			__( 'Site owners can export or erase this data from the Tools menu. Erasing it removes the user link and the IP address and user agent from log entries, and clears recipient email addresses from support access records.', 'happyaccess' ),
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

		$email   = trim( (string) $email );
		$user_id = self::user_id_for( $email );
		$offset  = ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE;
		$logs    = Installer::table( 'logs' );
		$tokens  = Installer::table( 'tokens' );
		$items   = array();

		$log_rows = array();
		if ( $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$log_rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$logs} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", $user_id, self::PER_PAGE, $offset ), ARRAY_A );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$grant_rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tokens} WHERE created_by = %d OR recipient_email = %s ORDER BY id ASC LIMIT %d OFFSET %d", $user_id, $email, self::PER_PAGE, $offset ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$grant_rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tokens} WHERE recipient_email = %s ORDER BY id ASC LIMIT %d OFFSET %d", $email, self::PER_PAGE, $offset ), ARRAY_A );
		}

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
			$items[] = array(
				'group_id'          => 'happyaccess_grants',
				'group_label'       => __( 'HappyAccess support access', 'happyaccess' ),
				'group_description' => __( 'Support access records that name this user or email address.', 'happyaccess' ),
				'item_id'           => 'happyaccess-grant-' . (int) $row['id'],
				'data'              => array(
					array(
						'name'  => __( 'Label', 'happyaccess' ),
						'value' => (string) $row['label'],
					),
					array(
						'name'  => __( 'Recipient email', 'happyaccess' ),
						'value' => (string) $row['recipient_email'],
					),
					array(
						'name'  => __( 'Created by user ID', 'happyaccess' ),
						'value' => (string) (int) $row['created_by'],
					),
					array(
						'name'  => __( 'Created (UTC)', 'happyaccess' ),
						'value' => (string) $row['created_at'],
					),
					array(
						'name'  => __( 'Expires (UTC)', 'happyaccess' ),
						'value' => (string) $row['expires_at'],
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $log_rows ) < self::PER_PAGE && count( $grant_rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Anonymizes log entries and clears grant fields tied to an email
	 * address. Works when the email belongs to no user.
	 *
	 * Every pass takes the first matching rows. A processed row stops
	 * matching, so the page number is not used as an offset.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, starting at 1. Unused.
	 * @return array items_removed, items_retained, messages and done.
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;

		unset( $page );
		$email   = trim( (string) $email );
		$user_id = self::user_id_for( $email );
		$logs    = Installer::table( 'logs' );
		$tokens  = Installer::table( 'tokens' );

		$log_count     = 0;
		$created_count = 0;
		$email_count   = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables.
		if ( $user_id ) {
			$log_count     = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$logs} SET user_id = 0, ip_address = %s, user_agent = %s WHERE user_id = %d LIMIT %d", '0.0.0.0', '', $user_id, self::PER_PAGE ) );
			$created_count = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$tokens} SET created_by = 0 WHERE created_by = %d LIMIT %d", $user_id, self::PER_PAGE ) );
		}
		if ( '' !== $email ) {
			$email_count = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$tokens} SET recipient_email = %s WHERE recipient_email = %s LIMIT %d", '', $email, self::PER_PAGE ) );
		}
		// phpcs:enable

		return array(
			'items_removed'  => ( $log_count + $created_count + $email_count ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $log_count < self::PER_PAGE && $created_count < self::PER_PAGE && $email_count < self::PER_PAGE,
		);
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
