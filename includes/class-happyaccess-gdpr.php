<?php
/**
 * GDPR compliance handler for HappyAccess plugin.
 *
 * @package HappyAccess
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GDPR compliance class.
 *
 * @since 1.0.0
 */
class HappyAccess_GDPR {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Hook into WordPress privacy features.
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_data_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_data_eraser' ) );
	}

	/**
	 * Add suggested privacy policy content.
	 *
	 * @since 1.0.0
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = $this->get_privacy_policy_content();
		
		wp_add_privacy_policy_content(
			'HappyAccess',
			wp_kses_post( $content )
		);
	}

	/**
	 * Get privacy policy content.
	 *
	 * @since 1.0.0
	 * @return string Privacy policy content.
	 */
	private function get_privacy_policy_content() {
		ob_start();
		?>
		<h2><?php esc_html_e( 'Temporary Admin Access', 'happyaccess' ); ?></h2>
		<p>
			<?php esc_html_e( 'When you grant temporary admin access using HappyAccess, we collect and store the following information:', 'happyaccess' ); ?>
		</p>
		<ul>
			<li><?php esc_html_e( 'IP address of the person accessing your site', 'happyaccess' ); ?></li>
			<li><?php esc_html_e( 'Browser information (user agent)', 'happyaccess' ); ?></li>
			<li><?php esc_html_e( 'Time and date of access', 'happyaccess' ); ?></li>
			<li><?php esc_html_e( 'Actions performed during the session (audit log)', 'happyaccess' ); ?></li>
		</ul>
		<p>
			<?php esc_html_e( 'This information is stored locally on your website and is not transmitted to any third-party services. The data is automatically deleted after 30 days unless configured otherwise.', 'happyaccess' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Important:', 'happyaccess' ); ?></strong> 
			<?php esc_html_e( 'You must disclose in your Terms & Conditions that you may grant admin access to third parties (such as support engineers or developers) for maintenance and support purposes.', 'happyaccess' ); ?>
		</p>
		<?php
		return ob_get_clean();
	}

	/**
	 * Register data exporter.
	 *
	 * @since 1.0.0
	 * @param array $exporters Existing exporters.
	 * @return array Modified exporters.
	 */
	public function register_data_exporter( $exporters ) {
		$exporters['happyaccess'] = array(
			'exporter_friendly_name' => __( 'HappyAccess Data', 'happyaccess' ),
			'callback'               => array( $this, 'export_user_data' ),
		);
		return $exporters;
	}

	/**
	 * Export user data.
	 *
	 * @since 1.0.0
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Export data.
	 */
	public function export_user_data( $email_address, $page = 1 ) {
		$export_items = array();
		$user = get_user_by( 'email', $email_address );
		
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		
		global $wpdb;
		$table_logs = esc_sql( $wpdb->prefix . 'happyaccess_logs' );
		$table_tokens = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );
		
		// Get logs for this user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
				"SELECT * FROM `$table_logs` WHERE user_id = %d ORDER BY created_at DESC LIMIT 50",
				$user->ID
			),
			ARRAY_A
		);
		
		if ( $logs ) {
			$data = array();
			foreach ( $logs as $log ) {
				$data[] = array(
					'name'  => __( 'Event Type', 'happyaccess' ),
					'value' => $log['event_type'],
				);
				$data[] = array(
					'name'  => __( 'Date', 'happyaccess' ),
					'value' => $log['created_at'],
				);
				$data[] = array(
					'name'  => __( 'IP Address', 'happyaccess' ),
					'value' => $log['ip_address'],
				);
			}
			
			$export_items[] = array(
				'group_id'    => 'happyaccess_logs',
				'group_label' => __( 'HappyAccess Activity Logs', 'happyaccess' ),
				'item_id'     => 'user-' . $user->ID,
				'data'        => $data,
			);
		}
		
		// Get tokens created by this user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$tokens = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
				"SELECT * FROM `$table_tokens` WHERE created_by = %d ORDER BY created_at DESC LIMIT 50",
				$user->ID
			),
			ARRAY_A
		);
		
		if ( $tokens ) {
			$data = array();
			foreach ( $tokens as $token ) {
				$data[] = array(
					'name'  => __( 'Token Created', 'happyaccess' ),
					'value' => $token['created_at'],
				);
				$data[] = array(
					'name'  => __( 'Expires', 'happyaccess' ),
					'value' => $token['expires_at'],
				);
				$data[] = array(
					'name'  => __( 'Role', 'happyaccess' ),
					'value' => $token['role'],
				);
			}
			
			$export_items[] = array(
				'group_id'    => 'happyaccess_tokens',
				'group_label' => __( 'HappyAccess Tokens Created', 'happyaccess' ),
				'item_id'     => 'user-' . $user->ID,
				'data'        => $data,
			);
		}
		
		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Register data eraser.
	 *
	 * @since 1.0.0
	 * @param array $erasers Existing erasers.
	 * @return array Modified erasers.
	 */
	public function register_data_eraser( $erasers ) {
		$erasers['happyaccess'] = array(
			'eraser_friendly_name' => __( 'HappyAccess Data', 'happyaccess' ),
			'callback'             => array( $this, 'erase_user_data' ),
		);
		return $erasers;
	}

	/**
	 * Erase user data.
	 *
	 * @since 1.0.0
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array Erasure result.
	 */
	public function erase_user_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}
		
		global $wpdb;
		$items_removed = 0;
		
		// We don't actually delete the data, just anonymize it.
		// This preserves the audit trail while protecting privacy.
		
		$table_logs = $wpdb->prefix . 'happyaccess_logs';
		
		// Anonymize logs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
		$updated = $wpdb->update(
			$table_logs,
			array(
				'user_id'    => 0,
				'ip_address' => '0.0.0.0',
				'user_agent' => 'anonymized',
			),
			array( 'user_id' => $user->ID ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
		
		if ( $updated ) {
			$items_removed += $updated;
		}
		
		return array(
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
