<?php
/**
 * Admin functionality for HappyAccess.
 *
 * @package HappyAccess
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class.
 *
 * @since 1.0.0
 */
class HappyAccess_Admin {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_happyaccess_generate_token', array( $this, 'ajax_generate_token' ) );
		add_action( 'wp_ajax_happyaccess_revoke_token', array( $this, 'ajax_revoke_token' ) );
		add_action( 'wp_ajax_happyaccess_logout_sessions', array( $this, 'ajax_logout_sessions' ) );
		add_action( 'wp_ajax_happyaccess_clear_logs', array( $this, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_happyaccess_generate_magic_link', array( $this, 'ajax_generate_magic_link' ) );
		add_action( 'wp_ajax_happyaccess_generate_share_link', array( $this, 'ajax_generate_share_link' ) );
		add_action( 'wp_ajax_happyaccess_email_magic_link', array( $this, 'ajax_email_magic_link' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_init', array( $this, 'handle_settings' ) );
	}

	/**
	 * Add admin menu page.
	 *
	 * @since 1.0.0
	 */
	public function add_menu_page() {
		add_submenu_page(
			'users.php',
			__( 'HappyAccess', 'happyaccess' ),
			__( 'HappyAccess', 'happyaccess' ),
			'list_users',
			'happyaccess',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @since 1.0.0
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'users_page_happyaccess' !== $hook ) {
			return;
		}

		// Enqueue Thickbox for modals.
		add_thickbox();

		wp_enqueue_script(
			'happyaccess-admin',
			HAPPYACCESS_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			HAPPYACCESS_VERSION,
			true
		);

		wp_localize_script( 'happyaccess-admin', 'happyaccess_ajax', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'happyaccess_ajax' ),
			'settings' => array(
				'magic_link_expiry' => absint( get_option( 'happyaccess_magic_link_expiry', 300 ) ),
				'share_link_expiry' => absint( get_option( 'happyaccess_share_link_expiry', 300 ) ),
			),
			'strings'  => array(
				'copied'                  => __( 'Copied!', 'happyaccess' ),
				'copy_failed'             => __( 'Copy failed. Please copy manually.', 'happyaccess' ),
				'confirm_revoke'          => __( 'Are you sure you want to revoke this token?', 'happyaccess' ),
				'confirm_logout_sessions' => __( 'This will log out all temporary users but keep their tokens active. They can log in again with the same code. Continue?', 'happyaccess' ),
				'logging_out'             => __( 'Logging out...', 'happyaccess' ),
				'logout_sessions'         => __( 'Logout All Temp Sessions', 'happyaccess' ),
				'gdpr_required'           => __( 'Please confirm that third-party access is disclosed in your Privacy Policy or Terms of Service.', 'happyaccess' ),
				'confirm_clear_logs'      => __( 'WARNING: This will permanently delete ALL audit logs. This action cannot be undone. Are you sure you want to continue?', 'happyaccess' ),
				'clearing_logs'           => __( 'Clearing...', 'happyaccess' ),
				'clear_all_logs'          => __( 'Clear All Logs', 'happyaccess' ),
				'magic_link_success'      => __( 'Magic link generated and copied to clipboard!', 'happyaccess' ),
				'copied_to_clipboard'     => __( 'Link copied to clipboard!', 'happyaccess' ),
				'generating'              => __( 'Generating...', 'happyaccess' ),
				'magic_link_expires_at'   => __( 'Expires:', 'happyaccess' ),
				'share_link_single_view'  => __( 'Single view - expires after viewing', 'happyaccess' ),
				'share_link_expires'      => __( 'Expires:', 'happyaccess' ),
				'email_sent'              => __( 'Email sent!', 'happyaccess' ),
				'sending'                 => __( 'Sending...', 'happyaccess' ),
				'email_error'             => __( 'Please enter a valid email address.', 'happyaccess' ),
				'send_email'              => __( 'Send Email', 'happyaccess' ),
				'cancel'                  => __( 'Cancel', 'happyaccess' ),
			),
		) );
		
		// Minimal styling - relies on WordPress native styles.
		$custom_css = '
			.form-table th {
				font-weight: 600;
			}
		';
		wp_add_inline_style( 'wp-admin', $custom_css );
	}

	/**
	 * Render admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'generate';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HappyAccess', 'happyaccess' ); ?></h1>
			
			<h2 class="nav-tab-wrapper">
				<a href="?page=happyaccess&tab=generate" class="nav-tab <?php echo 'generate' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Generate Access', 'happyaccess' ); ?>
				</a>
				<a href="?page=happyaccess&tab=active" class="nav-tab <?php echo 'active' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Active Tokens', 'happyaccess' ); ?>
				</a>
				<a href="?page=happyaccess&tab=logs" class="nav-tab <?php echo 'logs' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Audit Logs', 'happyaccess' ); ?>
				</a>
				<a href="?page=happyaccess&tab=settings" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'happyaccess' ); ?>
				</a>
			</h2>

			<?php
			if ( 'settings' === $tab ) {
				$this->render_settings();
			} elseif ( 'active' === $tab ) {
				$this->render_active_tokens();
			} elseif ( 'logs' === $tab ) {
				$this->render_audit_logs();
			} else {
				$this->render_generate();
			}
			
			// Email modal (hidden by default).
			?>
			<div id="happyaccess-email-modal" style="display:none;">
				<div style="padding: 15px;">
					<p style="margin-bottom: 15px;"><?php esc_html_e( 'Enter the email address to send the magic link to:', 'happyaccess' ); ?></p>
					<p>
						<label for="happyaccess-email-recipient" class="screen-reader-text"><?php esc_html_e( 'Email Address', 'happyaccess' ); ?></label>
						<input type="email" id="happyaccess-email-recipient" class="regular-text" style="width: 100%;" placeholder="<?php esc_attr_e( 'email@example.com', 'happyaccess' ); ?>" />
					</p>
					<p class="description" style="margin-bottom: 15px;"><?php esc_html_e( 'The recipient will receive a one-click login link.', 'happyaccess' ); ?></p>
					<p style="text-align: right;">
						<button type="button" class="button" onclick="tb_remove();"><?php esc_html_e( 'Cancel', 'happyaccess' ); ?></button>
						<button type="button" class="button button-primary" id="happyaccess-send-email-btn"><?php esc_html_e( 'Send Email', 'happyaccess' ); ?></button>
					</p>
				</div>
			</div>
			
			<div id="happyaccess-link-modal" style="display:none;">
				<div style="padding: 15px;">
					<p style="margin-bottom: 10px;"><strong><?php esc_html_e( 'Link Generated Successfully!', 'happyaccess' ); ?></strong></p>
					<p>
						<input type="text" id="happyaccess-modal-link" class="large-text code" readonly style="font-size: 12px;" />
					</p>
					<p class="description" id="happyaccess-modal-expires"></p>
					<p style="text-align: right; margin-top: 15px;">
						<button type="button" class="button" onclick="tb_remove();"><?php esc_html_e( 'Close', 'happyaccess' ); ?></button>
						<button type="button" class="button" id="happyaccess-modal-email-btn"><?php esc_html_e( 'Email Link', 'happyaccess' ); ?></button>
						<button type="button" class="button button-primary" id="happyaccess-modal-copy-btn"><?php esc_html_e( 'Copy Link', 'happyaccess' ); ?></button>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render generate tab.
	 *
	 * @since 1.0.0
	 */
	private function render_generate() {
		$default_duration = get_option( 'happyaccess_token_expiry', 604800 ); // Default to 7 days
		?>
		<div class="notice notice-info" style="margin: 5px 0 20px 0;">
			<p><?php esc_html_e( 'Generate temporary access codes for support engineers to help with your site. No passwords are shared, and access automatically expires.', 'happyaccess' ); ?></p>
		</div>
		
		<form id="happyaccess-generate-form" method="post">
			<?php wp_nonce_field( 'happyaccess_generate', 'happyaccess_nonce' ); ?>
			
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="duration"><?php esc_html_e( 'Access Duration', 'happyaccess' ); ?></label>
					</th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'How long the access code will remain valid. After this time, the code expires and the temporary user is automatically deleted.', 'happyaccess' ); ?>"></span>
						<select name="duration" id="duration">
							<option value="3600" <?php selected( $default_duration, 3600 ); ?>><?php esc_html_e( '1 Hour', 'happyaccess' ); ?></option>
							<option value="14400" <?php selected( $default_duration, 14400 ); ?>><?php esc_html_e( '4 Hours', 'happyaccess' ); ?></option>
							<option value="28800" <?php selected( $default_duration, 28800 ); ?>><?php esc_html_e( '8 Hours', 'happyaccess' ); ?></option>
							<option value="86400" <?php selected( $default_duration, 86400 ); ?>><?php esc_html_e( '24 Hours', 'happyaccess' ); ?></option>
							<option value="259200" <?php selected( $default_duration, 259200 ); ?>><?php esc_html_e( '3 Days', 'happyaccess' ); ?></option>
							<option value="604800" <?php selected( $default_duration, 604800 ); ?>><?php esc_html_e( '7 Days', 'happyaccess' ); ?></option>
							<option value="1209600" <?php selected( $default_duration, 1209600 ); ?>><?php esc_html_e( '14 Days', 'happyaccess' ); ?></option>
							<option value="2592000" <?php selected( $default_duration, 2592000 ); ?>><?php esc_html_e( '30 Days', 'happyaccess' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="role"><?php esc_html_e( 'User Role', 'happyaccess' ); ?></label>
					</th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'The permission level for the temporary user. Administrator has full access, Editor can manage content, Shop Manager handles WooCommerce orders.', 'happyaccess' ); ?>"></span>
						<select name="role" id="role">
							<?php wp_dropdown_roles( 'administrator' ); ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="note"><?php esc_html_e( 'Reference Note', 'happyaccess' ); ?></label>
					</th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'Optional note to identify this access request. Example: Ticket #12345 or Payment issue investigation.', 'happyaccess' ); ?>"></span>
						<input type="text" name="note" id="note" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Ticket #12345', 'happyaccess' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="ip_restrictions"><?php esc_html_e( 'IP Allowlist', 'happyaccess' ); ?></label>
					</th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'Optional: Restrict access to specific IP addresses. Leave empty to allow access from any IP.', 'happyaccess' ); ?>"></span>
						<input type="text" name="ip_restrictions" id="ip_restrictions" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., 192.168.1.100, 10.0.0.50', 'happyaccess' ); ?>" />
						<p class="description"><?php esc_html_e( 'Comma-separated IP addresses. Only these IPs can use this access code. Leave empty for no restriction.', 'happyaccess' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'One-Time Use', 'happyaccess' ); ?></th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'SECURITY: When enabled, the access code will automatically revoke after the first successful login. The support engineer will not be able to log in again with the same code. Use this for maximum security when you only need a single session.', 'happyaccess' ); ?>"></span>
						<label>
							<input type="checkbox" name="single_use" id="happyaccess-single-use" value="1" />
							<?php esc_html_e( 'Revoke access code after first use (single session only)', 'happyaccess' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, the code becomes invalid immediately after the support engineer logs in. They cannot log out and log back in - they get only one session.', 'happyaccess' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Generate Magic Link', 'happyaccess' ); ?></th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'A magic link is a one-click login URL that works without entering the OTP code. It has a very short expiration (1-10 minutes) and is single-use.', 'happyaccess' ); ?>"></span>
						<label>
							<input type="checkbox" name="generate_magic_link" id="happyaccess-generate-magic-link" value="1" />
							<?php esc_html_e( 'Also generate a magic link for one-click login', 'happyaccess' ); ?>
						</label>
						<div id="happyaccess-magic-link-options" style="margin-top: 10px; display: none;">
							<label for="magic_link_expiry"><?php esc_html_e( 'Magic Link Expires In:', 'happyaccess' ); ?></label>
							<select name="magic_link_expiry" id="magic_link_expiry">
								<option value="60"><?php esc_html_e( '1 Minute', 'happyaccess' ); ?></option>
								<option value="120"><?php esc_html_e( '2 Minutes', 'happyaccess' ); ?></option>
								<option value="300" selected><?php esc_html_e( '5 Minutes', 'happyaccess' ); ?></option>
								<option value="600"><?php esc_html_e( '10 Minutes', 'happyaccess' ); ?></option>
							</select>
						</div>
						<p class="description"><?php esc_html_e( 'Magic links are one-click login URLs. They expire quickly (1-10 min) and work only once.', 'happyaccess' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email Confirmation', 'happyaccess' ); ?></th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'Receive a copy of the access code via email for your records and secure sharing.', 'happyaccess' ); ?>"></span>
						<label>
							<input type="checkbox" name="email_admin" id="happyaccess-email-admin" value="1" />
							<?php 
							printf(
								/* translators: %s: admin email address */
								esc_html__( 'Send access code to %s', 'happyaccess' ),
								'<strong>' . esc_html( get_option( 'admin_email' ) ) . '</strong>'
							);
							?>
						</label>
						<p class="description"><?php esc_html_e( 'You\'ll receive the access code and instructions via email for secure sharing with your support team.', 'happyaccess' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'GDPR Compliance', 'happyaccess' ); ?></th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'GDPR requires you to disclose when sharing site access with third parties. Your Privacy Policy should mention that you may grant temporary admin access for technical support purposes.', 'happyaccess' ); ?>"></span>
						<label>
							<input type="checkbox" name="gdpr_consent" id="happyaccess-gdpr-consent" value="1" required />
							<?php esc_html_e( 'I confirm that granting temporary admin access to third parties (e.g., support engineers) is disclosed in my Privacy Policy or Terms of Service.', 'happyaccess' ); ?>
						</label>
						<p class="description">
							<a href="https://woocommerce.com/posts/the-gdpr-and-you-the-woocommerce-store-owner/" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Learn more about GDPR responsibilities for store owners →', 'happyaccess' ); ?>
							</a>
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" id="happyaccess-generate-btn" class="button button-primary">
					<?php esc_html_e( 'Generate Access Code', 'happyaccess' ); ?>
				</button>
			</p>
		</form>

		<div id="happyaccess-otp-display" style="display:none;">
			<hr />
			<h2><?php esc_html_e( 'Access Code Generated Successfully', 'happyaccess' ); ?></h2>
			<div class="notice notice-info inline">
				<p><strong><?php esc_html_e( 'Instructions for Support Access:', 'happyaccess' ); ?></strong></p>
				<ol style="margin: 0.5em 0 0.5em 2em;">
					<li><?php esc_html_e( 'Share the access code below with your support engineer', 'happyaccess' ); ?></li>
					<li><?php 
						printf( 
							/* translators: %s: login URL */
							esc_html__( 'They should visit your login page: %s', 'happyaccess' ),
							'<code>' . esc_html( wp_login_url() ) . '</code>'
						);
					?></li>
					<li><?php esc_html_e( 'They enter the code in the "Temporary Support Access" field (no username/password needed)', 'happyaccess' ); ?></li>
					<li><?php esc_html_e( 'Access automatically expires after the set duration', 'happyaccess' ); ?></li>
				</ol>
			</div>
			
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Access Code', 'happyaccess' ); ?></th>
					<td>
						<strong><code id="happyaccess-otp-code" style="font-size: 18px; padding: 4px 8px;"></code></strong>
						<button type="button" id="happyaccess-copy-otp" class="button">
							<?php esc_html_e( 'Copy', 'happyaccess' ); ?>
						</button>
						<button type="button" id="happyaccess-share-otp" class="button" title="<?php esc_attr_e( 'Generate a secure link to view this OTP code', 'happyaccess' ); ?>">
							<?php esc_html_e( 'Share Link', 'happyaccess' ); ?>
						</button>
					</td>
				</tr>
				<tr id="happyaccess-share-link-row" style="display:none;">
					<th><?php esc_html_e( 'OTP Share Link', 'happyaccess' ); ?></th>
					<td>
						<input type="text" id="happyaccess-share-link-url" class="large-text" readonly style="font-family: monospace; font-size: 12px;" />
						<button type="button" id="happyaccess-copy-share-link" class="button" style="margin-top: 5px;">
							<?php esc_html_e( 'Copy Share Link', 'happyaccess' ); ?>
						</button>
						<p class="description" id="happyaccess-share-link-info"></p>
					</td>
				</tr>
				<tr id="happyaccess-single-use-row" style="display:none;">
					<th><?php esc_html_e( 'Usage Type', 'happyaccess' ); ?></th>
					<td id="happyaccess-single-use-display"></td>
				</tr>
				<tr id="happyaccess-magic-link-row" style="display:none;">
					<th><?php esc_html_e( 'Magic Link', 'happyaccess' ); ?></th>
					<td>
						<input type="text" id="happyaccess-magic-link-url" class="large-text" readonly style="font-family: monospace; font-size: 12px;" />
						<div style="margin-top: 5px;">
							<button type="button" id="happyaccess-copy-magic-link" class="button">
								<?php esc_html_e( 'Copy', 'happyaccess' ); ?>
							</button>
							<button type="button" id="happyaccess-email-magic-link" class="button">
								<?php esc_html_e( 'Email Link', 'happyaccess' ); ?>
							</button>
						</div>
						<p class="description" id="happyaccess-magic-link-expires"></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Expires', 'happyaccess' ); ?></th>
					<td id="happyaccess-expires-display"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Role', 'happyaccess' ); ?></th>
					<td id="happyaccess-role-display"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Note', 'happyaccess' ); ?></th>
					<td id="happyaccess-note-display"></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Render active tokens page.
	 *
	 * @since 1.0.0
	 */
	private function render_active_tokens() {
		$token_manager = new HappyAccess_Token_Manager();
		$active_tokens = $token_manager->get_active_tokens();
		
		// Check if any tokens have active sessions (users).
		$has_active_sessions = false;
		foreach ( $active_tokens as $token ) {
			if ( ! empty( $token['user_id'] ) && get_user_by( 'ID', $token['user_id'] ) ) {
				$has_active_sessions = true;
				break;
			}
		}
		?>
		<h2 class="wp-heading-inline"><?php esc_html_e( 'Active Access Tokens', 'happyaccess' ); ?></h2>
		<?php if ( $has_active_sessions ) : ?>
			<button type="button" id="happyaccess-logout-sessions" class="page-title-action" style="color:#d63638;">
				<?php esc_html_e( 'Logout All Temp Sessions', 'happyaccess' ); ?>
			</button>
		<?php endif; ?>
		<hr class="wp-header-end">
		
		<?php if ( empty( $active_tokens ) ) : ?>
			<p><?php esc_html_e( 'No active tokens found.', 'happyaccess' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped" role="table" aria-label="<?php esc_attr_e( 'Active access tokens', 'happyaccess' ); ?>">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Access Code', 'happyaccess' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Username', 'happyaccess' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Role', 'happyaccess' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Created', 'happyaccess' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Expires', 'happyaccess' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Note', 'happyaccess' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'happyaccess' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'happyaccess' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $active_tokens as $token ) : 
						$expires_timestamp = strtotime( $token['expires_at'] );
						$is_expired = $expires_timestamp < time();
						$temp_user = get_user_by( 'ID', $token['user_id'] );
						// Parse metadata to get the note.
						$token_metadata = ! empty( $token['metadata'] ) ? json_decode( $token['metadata'], true ) : array();
						$token_note = isset( $token_metadata['note'] ) ? $token_metadata['note'] : '';
						?>
						<tr>
							<td><code><?php echo esc_html( $token['otp_code'] ); ?></code></td>
							<td>
								<?php if ( $temp_user ) : ?>
									<a href="<?php echo esc_url( get_edit_user_link( $token['user_id'] ) ); ?>">
										<?php echo esc_html( $temp_user->user_login ); ?>
									</a>
								<?php else : ?>
									<em><?php esc_html_e( 'Not created yet', 'happyaccess' ); ?></em>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $token['role'] ); ?></td>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $token['created_at'] ) ) ); ?></td>
							<td>
								<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires_timestamp ) ); ?>
								<?php if ( $is_expired ) : ?>
									<strong><?php esc_html_e( '(Expired)', 'happyaccess' ); ?></strong>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $token_note ? $token_note : '-' ); ?></td>
							<td>
								<?php 
								// Determine status with single-use support.
								// Note: Revoked tokens are not shown in this list (visible in Audit Logs).
								$is_single_use = ( 1 === (int) $token['max_uses'] );
								
								if ( $is_expired ) {
									esc_html_e( 'Expired', 'happyaccess' );
								} elseif ( $temp_user ) {
									if ( $is_single_use ) {
										echo '<span style="color: #00a32a;">' . esc_html__( 'Active (One-Time)', 'happyaccess' ) . '</span>';
									} else {
										esc_html_e( 'Active', 'happyaccess' );
									}
								} else {
									if ( $is_single_use ) {
										echo '<span>' . esc_html__( 'Unused (One-Time)', 'happyaccess' ) . '</span>';
									} else {
										esc_html_e( 'Unused', 'happyaccess' );
									}
								}
								?>
							</td>
							<td>
								<?php if ( ! $is_expired && ! $token['revoked_at'] ) : ?>
									<button type="button" class="button button-small happyaccess-magic-link" 
											data-token-id="<?php echo esc_attr( $token['id'] ); ?>"
											title="<?php esc_attr_e( 'Generate one-click login link (expires in minutes)', 'happyaccess' ); ?>">
										<?php esc_html_e( 'Magic Link', 'happyaccess' ); ?>
									</button>
									<button type="button" class="button button-small happyaccess-revoke-token" 
											data-token-id="<?php echo esc_attr( $token['id'] ); ?>">
										<?php esc_html_e( 'Revoke', 'happyaccess' ); ?>
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render audit logs page.
	 *
	 * @since 1.0.0
	 */
	private function render_audit_logs() {
		// Check if export is requested.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Export action.
		if ( isset( $_GET['export'] ) && 'csv' === $_GET['export'] ) {
			$this->export_logs_csv();
			return;
		}
		
		// Get filter parameters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View-only filtering.
		$event_type = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View-only filtering.
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View-only filtering.
		$date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		
		// Build filters array.
		$filters = array();
		if ( $event_type ) {
			$filters['event_type'] = $event_type;
		}
		if ( $date_from ) {
			$filters['date_from'] = $date_from;
		}
		if ( $date_to ) {
			$filters['date_to'] = $date_to;
		}
		
		// Get logs with filters.
		$logs = HappyAccess_Logger::get_logs( $filters );
		
		// Get unique event types for filter dropdown.
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'happyaccess_logs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$event_types = $wpdb->get_col( 
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe, no user input.
			"SELECT DISTINCT event_type FROM `$table` ORDER BY event_type"
		);
		?>
		<h2 class="wp-heading-inline"><?php esc_html_e( 'Audit Logs', 'happyaccess' ); ?></h2>
		<?php
		$export_url = add_query_arg( array(
			'page' => 'happyaccess',
			'tab' => 'logs',
			'export' => 'csv',
			'event_type' => $event_type,
			'date_from' => $date_from,
			'date_to' => $date_to,
		), admin_url( 'users.php' ) );
		?>
		<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'happyaccess' ); ?></a>
		<?php if ( ! empty( $logs ) ) : ?>
			<button type="button" id="happyaccess-clear-logs" class="page-title-action" style="color:#d63638;">
				<?php esc_html_e( 'Clear All Logs', 'happyaccess' ); ?>
			</button>
		<?php endif; ?>
		<hr class="wp-header-end">
		
		<!-- Filters -->
		<div class="tablenav top">
			<form method="get" action="" class="alignleft actions">
				<input type="hidden" name="page" value="happyaccess">
				<input type="hidden" name="tab" value="logs">
				
				<select name="event_type">
					<option value=""><?php esc_html_e( 'All Events', 'happyaccess' ); ?></option>
					<?php foreach ( $event_types as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $event_type, $type ); ?>>
							<?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				
				<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" placeholder="<?php esc_attr_e( 'From date', 'happyaccess' ); ?>" />
				<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" placeholder="<?php esc_attr_e( 'To date', 'happyaccess' ); ?>" />
				
				<input type="submit" class="button action" value="<?php esc_attr_e( 'Filter', 'happyaccess' ); ?>" />
				<?php if ( $event_type || $date_from || $date_to ) : ?>
					<a href="?page=happyaccess&tab=logs" class="button"><?php esc_html_e( 'Clear', 'happyaccess' ); ?></a>
				<?php endif; ?>
			</form>
			<br class="clear">
		</div>
		
		<?php if ( empty( $logs ) ) : ?>
			<p><?php esc_html_e( 'No logs found.', 'happyaccess' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped" role="table" aria-label="<?php esc_attr_e( 'Audit logs', 'happyaccess' ); ?>">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date/Time', 'happyaccess' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Event', 'happyaccess' ); ?></th>
						<th scope="col"><?php esc_html_e( 'User', 'happyaccess' ); ?></th>
						<th scope="col"><?php esc_html_e( 'IP Address', 'happyaccess' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Details', 'happyaccess' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $logs as $log ) : 
						$user = $log['user_id'] ? get_user_by( 'ID', $log['user_id'] ) : null;
						$details = ! empty( $log['metadata'] ) ? json_decode( $log['metadata'], true ) : array();
						?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log['created_at'] ) ) ); ?></td>
							<td>
								<?php 
								$event_type = $log['event_type'];
								$event_label = ucwords( str_replace( '_', ' ', $event_type ) );
								$masked_otp = ! empty( $details['otp'] ) ? $details['otp'] : '';
								
								// Format special event types with masked OTP where applicable.
								if ( 'token_created' === $event_type && $masked_otp ) {
									/* translators: %s: masked OTP code */
									$event_label = sprintf( __( 'Token Created (%s)', 'happyaccess' ), $masked_otp );
								} elseif ( 'otp_verified_relogin' === $event_type ) {
									$login_count = isset( $details['login_count'] ) ? (int) $details['login_count'] : 0;
									if ( $masked_otp ) {
										/* translators: 1: masked OTP code, 2: login count */
										$event_label = sprintf( __( 'OTP Verified (%1$s) - Login #%2$d', 'happyaccess' ), $masked_otp, $login_count );
									} else {
										/* translators: %d: login count */
										$event_label = sprintf( __( 'OTP Verified (Login #%d)', 'happyaccess' ), $login_count );
									}
								} elseif ( 'otp_verified' === $event_type ) {
									if ( $masked_otp ) {
										/* translators: %s: masked OTP code */
										$event_label = sprintf( __( 'OTP Verified (%s) - First Login', 'happyaccess' ), $masked_otp );
									} else {
										$event_label = __( 'OTP Verified (First Login)', 'happyaccess' );
									}
								} elseif ( 'login_success' === $event_type && $masked_otp ) {
									/* translators: %s: masked OTP code */
									$event_label = sprintf( __( 'Login Success (%s)', 'happyaccess' ), $masked_otp );
								} elseif ( 'login_failed' === $event_type && ! empty( $details['attempted_code'] ) ) {
									/* translators: %s: masked OTP code */
									$event_label = sprintf( __( 'Login Failed (%s)', 'happyaccess' ), $details['attempted_code'] );
								} elseif ( 'temp_user_logout' === $event_type ) {
									$event_label = __( 'Temp User Logout', 'happyaccess' );
								}
								
								if ( strpos( $event_type, 'failed' ) !== false ) {
									echo '<strong>' . esc_html( $event_label ) . '</strong>';
								} else {
									echo esc_html( $event_label );
								}
								?>
							</td>
							<td>
								<?php if ( $user ) : ?>
									<a href="<?php echo esc_url( get_edit_user_link( $log['user_id'] ) ); ?>">
										<?php echo esc_html( $user->user_login ); ?>
									</a>
								<?php elseif ( ! empty( $details['username'] ) ) : ?>
									<?php echo esc_html( $details['username'] ); ?>
								<?php elseif ( ! empty( $details['temp_username'] ) ) : ?>
									<em><?php echo esc_html( $details['temp_username'] ); ?></em>
								<?php elseif ( 'login_failed' === $log['event_type'] ) : ?>
									<em><?php esc_html_e( '(unknown)', 'happyaccess' ); ?></em>
								<?php else : ?>
									-
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $log['ip_address'] ?: '-' ); ?></td>
							<td>
								<?php 
								if ( is_array( $details ) ) {
									$detail_parts = array();
									foreach ( $details as $key => $value ) {
										// Exclude fields that are shown in other columns.
										if ( ! in_array( $key, array( 'username', 'user_id', 'temp_username', 'attempted_code', 'ip', 'otp', 'login_count' ), true ) ) {
											// Format duration if it's a raw number (legacy logs).
											if ( 'duration' === $key && is_numeric( $value ) ) {
												$value = HappyAccess_Token_Manager::format_duration( (int) $value );
											}
											$detail_parts[] = ucwords( str_replace( '_', ' ', $key ) ) . ': ' . ( is_array( $value ) ? wp_json_encode( $value ) : $value );
										}
									}
									echo esc_html( implode( ', ', $detail_parts ) ?: '-' );
								} else {
									echo '-';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			
			<div class="tablenav bottom">
				<div class="alignleft actions">
					<p class="description">
						<?php 
						printf(
							/* translators: %d: number of log entries */
							esc_html( _n( 'Showing %d log entry', 'Showing %d log entries', count( $logs ), 'happyaccess' ) ),
							count( $logs )
						);
						?>
					</p>
				</div>
				<br class="clear">
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Export logs to CSV.
	 *
	 * @since 1.0.0
	 */
	private function export_logs_csv() {
		// Check permissions.
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'happyaccess' ) );
		}
		
		// Get filter parameters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Export parameters.
		$event_type = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Export parameters.
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Export parameters.
		$date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		
		// Build filters array.
		$filters = array();
		if ( $event_type ) {
			$filters['event_type'] = $event_type;
		}
		if ( $date_from ) {
			$filters['date_from'] = $date_from;
		}
		if ( $date_to ) {
			$filters['date_to'] = $date_to;
		}
		
		// Get logs.
		$logs = HappyAccess_Logger::get_logs( $filters );
		
		// Set headers for CSV download.
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=happyaccess-audit-logs-' . gmdate( 'Y-m-d' ) . '.csv' );
		
		// Create output stream.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Output stream for CSV download.
		$output = fopen( 'php://output', 'w' );
		
		// Add UTF-8 BOM for Excel compatibility.
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		
		// Add CSV headers.
		fputcsv( $output, array(
			'Date/Time',
			'Event Type',
			'User',
			'IP Address',
			'Details',
		) );
		
		// Add data rows.
		foreach ( $logs as $log ) {
			$user = $log['user_id'] ? get_user_by( 'ID', $log['user_id'] ) : null;
			$details = ! empty( $log['metadata'] ) ? json_decode( $log['metadata'], true ) : array();
			
			// Determine user display.
			$user_display = '-';
			if ( $user ) {
				$user_display = $user->user_login;
			} elseif ( ! empty( $details['username'] ) ) {
				$user_display = $details['username'];
			} elseif ( ! empty( $details['temp_username'] ) ) {
				$user_display = $details['temp_username'];
			} elseif ( 'token_created' === $log['event_type'] && ! empty( $details['otp'] ) ) {
				$user_display = 'Code: ' . $details['otp'];
			} elseif ( 'login_failed' === $log['event_type'] && ! empty( $details['attempted_code'] ) ) {
				$user_display = 'Code: ' . $details['attempted_code'];
			}
			
			// Format event type.
			$event_type = $log['event_type'];
			if ( 'otp_verified_relogin' === $event_type ) {
				$login_count = isset( $details['login_count'] ) ? (int) $details['login_count'] : 0;
				$event_label = sprintf( 'OTP Verified (Login #%d)', $login_count );
			} elseif ( 'otp_verified' === $event_type && isset( $details['login_count'] ) ) {
				$event_label = 'OTP Verified (First Login)';
			} else {
				$event_label = ucwords( str_replace( '_', ' ', $event_type ) );
			}
			
			// Format details.
			$detail_parts = array();
			if ( is_array( $details ) ) {
				foreach ( $details as $key => $value ) {
					if ( ! in_array( $key, array( 'username', 'user_id', 'temp_username', 'attempted_code', 'ip', 'otp', 'login_count' ), true ) ) {
						// Format duration if it's a raw number (legacy logs).
						if ( 'duration' === $key && is_numeric( $value ) ) {
							$value = HappyAccess_Token_Manager::format_duration( (int) $value );
						}
						$detail_parts[] = ucwords( str_replace( '_', ' ', $key ) ) . ': ' . ( is_array( $value ) ? wp_json_encode( $value ) : $value );
					}
				}
			}
			
			fputcsv( $output, array(
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log['created_at'] ) ),
				$event_label,
				$user_display,
				$log['ip_address'] ?: '-',
				implode( ', ', $detail_parts ) ?: '-',
			) );
		}
		
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Output stream, not filesystem.
		fclose( $output );
		exit;
	}

	/**
	 * Render settings tab.
	 *
	 * @since 1.0.0
	 */
	private function render_settings() {
		// Show success notice if settings were just saved.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for settings update.
		if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Settings saved successfully.', 'happyaccess' ); ?></p>
			</div>
			<?php
		}
		
		$max_attempts = get_option( 'happyaccess_max_attempts', 5 );
		$lockout_duration = get_option( 'happyaccess_lockout_duration', 900 );
		$token_expiry = get_option( 'happyaccess_token_expiry', 604800 ); // Default to 7 days
		$cleanup_days = get_option( 'happyaccess_cleanup_days', 30 );
		$enable_logging = get_option( 'happyaccess_enable_logging', true );
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'happyaccess_settings' );
			?>
			
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="happyaccess_max_attempts"><?php esc_html_e( 'Max Login Attempts', 'happyaccess' ); ?></label>
					</th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'Maximum number of failed OTP attempts before temporarily blocking the IP address. Helps prevent brute-force attacks.', 'happyaccess' ); ?>"></span>
						<input type="number" name="happyaccess_max_attempts" id="happyaccess_max_attempts" value="<?php echo esc_attr( $max_attempts ); ?>" min="1" max="20" class="small-text" />
						<p class="description"><?php esc_html_e( 'Number of failed attempts before lockout.', 'happyaccess' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="happyaccess_lockout_duration"><?php esc_html_e( 'Lockout Duration', 'happyaccess' ); ?></label>
					</th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'How long to block an IP address after exceeding max attempts. Default: 900 seconds (15 minutes).', 'happyaccess' ); ?>"></span>
						<input type="number" name="happyaccess_lockout_duration" id="happyaccess_lockout_duration" value="<?php echo esc_attr( $lockout_duration ); ?>" min="60" max="86400" class="small-text" />
						<p class="description"><?php esc_html_e( 'Time in seconds before allowing retry (default: 900 = 15 minutes).', 'happyaccess' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="happyaccess_token_expiry"><?php esc_html_e( 'Default Token Expiry', 'happyaccess' ); ?></label>
					</th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'Default duration for new access codes. Support engineers can use the same code multiple times within this period. Individual codes can override this setting.', 'happyaccess' ); ?>"></span>
						<select name="happyaccess_token_expiry" id="happyaccess_token_expiry">
							<option value="3600" <?php selected( $token_expiry, 3600 ); ?>><?php esc_html_e( '1 Hour', 'happyaccess' ); ?></option>
							<option value="14400" <?php selected( $token_expiry, 14400 ); ?>><?php esc_html_e( '4 Hours', 'happyaccess' ); ?></option>
							<option value="28800" <?php selected( $token_expiry, 28800 ); ?>><?php esc_html_e( '8 Hours', 'happyaccess' ); ?></option>
							<option value="86400" <?php selected( $token_expiry, 86400 ); ?>><?php esc_html_e( '24 Hours', 'happyaccess' ); ?></option>
							<option value="259200" <?php selected( $token_expiry, 259200 ); ?>><?php esc_html_e( '3 Days', 'happyaccess' ); ?></option>
							<option value="604800" <?php selected( $token_expiry, 604800 ); ?>><?php esc_html_e( '7 Days', 'happyaccess' ); ?></option>
							<option value="1209600" <?php selected( $token_expiry, 1209600 ); ?>><?php esc_html_e( '14 Days', 'happyaccess' ); ?></option>
							<option value="2592000" <?php selected( $token_expiry, 2592000 ); ?>><?php esc_html_e( '30 Days', 'happyaccess' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'How long the access code remains valid. Support engineers can log in multiple times during this period using the same code.', 'happyaccess' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="happyaccess_cleanup_days"><?php esc_html_e( 'Log Retention', 'happyaccess' ); ?></label>
					</th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'Automatically delete audit logs older than this many days. Helps maintain database performance and comply with data retention policies.', 'happyaccess' ); ?>"></span>
						<input type="number" name="happyaccess_cleanup_days" id="happyaccess_cleanup_days" value="<?php echo esc_attr( $cleanup_days ); ?>" min="1" max="365" class="small-text" />
						<p class="description"><?php esc_html_e( 'Days to keep audit logs (default: 30 days).', 'happyaccess' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Activity Logging', 'happyaccess' ); ?></th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'Track all HappyAccess events including logins, token creation, and revocations. Essential for security audits and compliance.', 'happyaccess' ); ?>"></span>
						<label>
							<input type="checkbox" name="happyaccess_enable_logging" id="happyaccess_enable_logging" value="1" <?php checked( $enable_logging ); ?> />
							<?php esc_html_e( 'Enable audit logging for all access events', 'happyaccess' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Recommended for security and compliance tracking.', 'happyaccess' ); ?></p>
					</td>
				</tr>
			</table>
			
			<h2 class="title"><?php esc_html_e( 'Link Sharing Settings', 'happyaccess' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Configure default expiration times for magic links and share links. These are used when generating links quickly without prompts.', 'happyaccess' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="happyaccess_magic_link_expiry"><?php esc_html_e( 'Magic Link Expiry', 'happyaccess' ); ?></label>
					</th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'Magic links provide one-click login without entering an OTP code. They are very short-lived for security.', 'happyaccess' ); ?>"></span>
						<select name="happyaccess_magic_link_expiry" id="happyaccess_magic_link_expiry">
							<option value="60" <?php selected( get_option( 'happyaccess_magic_link_expiry', 300 ), 60 ); ?>><?php esc_html_e( '1 Minute', 'happyaccess' ); ?></option>
							<option value="120" <?php selected( get_option( 'happyaccess_magic_link_expiry', 300 ), 120 ); ?>><?php esc_html_e( '2 Minutes', 'happyaccess' ); ?></option>
							<option value="300" <?php selected( get_option( 'happyaccess_magic_link_expiry', 300 ), 300 ); ?>><?php esc_html_e( '5 Minutes', 'happyaccess' ); ?></option>
							<option value="600" <?php selected( get_option( 'happyaccess_magic_link_expiry', 300 ), 600 ); ?>><?php esc_html_e( '10 Minutes', 'happyaccess' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default expiry for one-click login links.', 'happyaccess' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="happyaccess_share_link_expiry"><?php esc_html_e( 'Share Link Expiry', 'happyaccess' ); ?></label>
					</th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'Share links allow you to send a secure link that reveals the OTP code when opened. They expire after first view.', 'happyaccess' ); ?>"></span>
						<select name="happyaccess_share_link_expiry" id="happyaccess_share_link_expiry">
							<option value="60" <?php selected( get_option( 'happyaccess_share_link_expiry', 300 ), 60 ); ?>><?php esc_html_e( '1 Minute', 'happyaccess' ); ?></option>
							<option value="120" <?php selected( get_option( 'happyaccess_share_link_expiry', 300 ), 120 ); ?>><?php esc_html_e( '2 Minutes', 'happyaccess' ); ?></option>
							<option value="300" <?php selected( get_option( 'happyaccess_share_link_expiry', 300 ), 300 ); ?>><?php esc_html_e( '5 Minutes', 'happyaccess' ); ?></option>
							<option value="600" <?php selected( get_option( 'happyaccess_share_link_expiry', 300 ), 600 ); ?>><?php esc_html_e( '10 Minutes', 'happyaccess' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default expiry for OTP share links (single-view).', 'happyaccess' ); ?></p>
					</td>
				</tr>
			</table>
			
			<h2 class="title"><?php esc_html_e( 'reCAPTCHA v3 Protection', 'happyaccess' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Optional: Add invisible bot protection to HappyAccess OTP login.', 'happyaccess' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable reCAPTCHA', 'happyaccess' ); ?></th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'reCAPTCHA v3 runs invisibly in the background. It scores visitors and blocks automated bots without interrupting real users.', 'happyaccess' ); ?>"></span>
						<label>
							<input type="checkbox" name="happyaccess_recaptcha_enabled" id="happyaccess_recaptcha_enabled" value="1" <?php checked( get_option( 'happyaccess_recaptcha_enabled', false ) ); ?> />
							<?php esc_html_e( 'Enable reCAPTCHA v3 on OTP login', 'happyaccess' ); ?>
						</label>
						<p class="description">
							<?php 
							printf(
								/* translators: %s: Google reCAPTCHA admin URL */
								esc_html__( 'Get your keys from %s', 'happyaccess' ),
								'<a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer">Google reCAPTCHA Admin</a>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="happyaccess_recaptcha_site_key"><?php esc_html_e( 'Site Key', 'happyaccess' ); ?></label>
					</th>
					<td>
						<input type="text" name="happyaccess_recaptcha_site_key" id="happyaccess_recaptcha_site_key" 
							   value="<?php echo esc_attr( get_option( 'happyaccess_recaptcha_site_key', '' ) ); ?>" 
							   class="regular-text" 
							   placeholder="<?php esc_attr_e( '6Lc...', 'happyaccess' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="happyaccess_recaptcha_secret_key"><?php esc_html_e( 'Secret Key', 'happyaccess' ); ?></label>
					</th>
					<td>
						<input type="password" name="happyaccess_recaptcha_secret_key" id="happyaccess_recaptcha_secret_key" 
							   value="<?php echo esc_attr( get_option( 'happyaccess_recaptcha_secret_key', '' ) ); ?>" 
							   class="regular-text" 
							   placeholder="<?php esc_attr_e( '6Lc...', 'happyaccess' ); ?>" />
						<p class="description"><?php esc_html_e( 'Keep this secret. Never share it publicly.', 'happyaccess' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="happyaccess_recaptcha_threshold"><?php esc_html_e( 'Score Threshold', 'happyaccess' ); ?></label>
					</th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'reCAPTCHA v3 scores users from 0.0 (likely bot) to 1.0 (likely human). Users below this threshold are blocked.', 'happyaccess' ); ?>"></span>
						<select name="happyaccess_recaptcha_threshold" id="happyaccess_recaptcha_threshold">
							<option value="0.3" <?php selected( get_option( 'happyaccess_recaptcha_threshold', 0.5 ), 0.3 ); ?>><?php esc_html_e( '0.3 (Lenient)', 'happyaccess' ); ?></option>
							<option value="0.5" <?php selected( get_option( 'happyaccess_recaptcha_threshold', 0.5 ), 0.5 ); ?>><?php esc_html_e( '0.5 (Balanced - Recommended)', 'happyaccess' ); ?></option>
							<option value="0.7" <?php selected( get_option( 'happyaccess_recaptcha_threshold', 0.5 ), 0.7 ); ?>><?php esc_html_e( '0.7 (Strict)', 'happyaccess' ); ?></option>
							<option value="0.9" <?php selected( get_option( 'happyaccess_recaptcha_threshold', 0.5 ), 0.9 ); ?>><?php esc_html_e( '0.9 (Very Strict)', 'happyaccess' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Higher values are stricter but may block some legitimate users.', 'happyaccess' ); ?></p>
					</td>
				</tr>
			</table>
			
			<h2 class="title"><?php esc_html_e( 'Uninstall Options', 'happyaccess' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Delete Data on Uninstall', 'happyaccess' ); ?></th>
					<td>
						<span class="dashicons dashicons-editor-help" style="color:#666; cursor:help; margin-right:5px;" title="<?php esc_attr_e( 'When enabled, all plugin data (tokens, logs, settings) will be permanently deleted when you uninstall (delete) the plugin. This does NOT affect deactivation.', 'happyaccess' ); ?>"></span>
						<label>
							<input type="checkbox" name="happyaccess_delete_on_uninstall" id="happyaccess_delete_on_uninstall" value="1" <?php checked( get_option( 'happyaccess_delete_on_uninstall', false ) ); ?> />
							<?php esc_html_e( 'Remove all plugin data when plugin is deleted', 'happyaccess' ); ?>
						</label>
						<p class="description" style="color:#d63638;">
							<strong><?php esc_html_e( 'Warning:', 'happyaccess' ); ?></strong>
							<?php esc_html_e( 'This will permanently delete all tokens, audit logs, and settings. This cannot be undone.', 'happyaccess' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Handle settings registration.
	 *
	 * @since 1.0.0
	 */
	public function handle_settings() {
		register_setting( 'happyaccess_settings', 'happyaccess_max_attempts', array(
			'type' => 'integer',
			'sanitize_callback' => 'absint',
			'default' => 5,
		) );
		register_setting( 'happyaccess_settings', 'happyaccess_lockout_duration', array(
			'type' => 'integer',
			'sanitize_callback' => 'absint',
			'default' => 900,
		) );
		register_setting( 'happyaccess_settings', 'happyaccess_token_expiry', array(
			'type' => 'integer',
			'sanitize_callback' => 'absint',
			'default' => 604800,
		) );
		register_setting( 'happyaccess_settings', 'happyaccess_cleanup_days', array(
			'type' => 'integer',
			'sanitize_callback' => 'absint',
			'default' => 30,
		) );
		register_setting( 'happyaccess_settings', 'happyaccess_enable_logging', array(
			'type' => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default' => true,
		) );
		register_setting( 'happyaccess_settings', 'happyaccess_delete_on_uninstall', array(
			'type' => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default' => false,
		) );
		register_setting( 'happyaccess_settings', 'happyaccess_magic_link_expiry', array(
			'type' => 'integer',
			'sanitize_callback' => 'absint',
			'default' => 300,
		) );
		register_setting( 'happyaccess_settings', 'happyaccess_share_link_expiry', array(
			'type' => 'integer',
			'sanitize_callback' => 'absint',
			'default' => 300,
		) );
		register_setting( 'happyaccess_settings', 'happyaccess_recaptcha_enabled', array(
			'type' => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default' => false,
		) );
		register_setting( 'happyaccess_settings', 'happyaccess_recaptcha_site_key', array(
			'type' => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default' => '',
		) );
		register_setting( 'happyaccess_settings', 'happyaccess_recaptcha_secret_key', array(
			'type' => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default' => '',
		) );
		register_setting( 'happyaccess_settings', 'happyaccess_recaptcha_threshold', array(
			'type' => 'number',
			'sanitize_callback' => array( $this, 'sanitize_threshold' ),
			'default' => 0.5,
		) );
	}

	/**
	 * Sanitize reCAPTCHA threshold value.
	 *
	 * @since 1.0.3
	 *
	 * @param mixed $value Input value.
	 * @return float Sanitized threshold (0.0 - 1.0).
	 */
	public function sanitize_threshold( $value ) {
		$value = (float) $value;
		return max( 0.0, min( 1.0, $value ) );
	}

	/**
	 * Handle AJAX token generation.
	 *
	 * @since 1.0.0
	 * @since 1.0.2 Added single_use parameter support.
	 */
	public function ajax_generate_token() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'happyaccess_ajax' ) ) {
			wp_die( esc_html__( 'Security check failed', 'happyaccess' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'happyaccess' ) );
		}

		// Get and validate input.
		$duration = isset( $_POST['duration'] ) ? absint( $_POST['duration'] ) : 86400;
		$role = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : 'administrator';
		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$email_admin = isset( $_POST['email_admin'] ) && $_POST['email_admin'] === '1';
		$single_use = isset( $_POST['single_use'] ) && $_POST['single_use'] === '1';
		
		// Get and validate IP restrictions.
		$ip_restrictions = '';
		if ( isset( $_POST['ip_restrictions'] ) && ! empty( $_POST['ip_restrictions'] ) ) {
			$raw_ips = sanitize_text_field( wp_unslash( $_POST['ip_restrictions'] ) );
			$ips = array_map( 'trim', explode( ',', $raw_ips ) );
			$valid_ips = array();
			foreach ( $ips as $ip ) {
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					$valid_ips[] = $ip;
				}
			}
			$ip_restrictions = ! empty( $valid_ips ) ? implode( ',', $valid_ips ) : '';
		}
		
		// Generate token.
		$metadata = array();
		if ( ! empty( $note ) ) {
			$metadata['note'] = $note;
		}
		
		$result = HappyAccess_Token_Manager::generate_token( $duration, $role, $metadata, $ip_restrictions, $single_use );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		
		// Format expiry time.
		$expires_timestamp = strtotime( $result['expires_at'] );
		$expires_display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires_timestamp );
		
		// Send email if requested.
		if ( $email_admin ) {
			$this->send_otp_email( $result['otp'], $expires_display, $role, $note, $single_use );
		}
		
		// Return success with OTP and details.
		$response = array(
			'otp'        => $result['otp'],
			'expires'    => $expires_display,
			'role'       => $role,
			'note'       => $note,
			'token_id'   => $result['id'],
		);
		
		if ( $single_use ) {
			$response['single_use'] = true;
		}
		
		// Generate magic link if requested.
		$generate_magic_link = isset( $_POST['generate_magic_link'] ) && $_POST['generate_magic_link'] === '1';
		if ( $generate_magic_link ) {
			$magic_link_expiry = isset( $_POST['magic_link_expiry'] ) ? absint( $_POST['magic_link_expiry'] ) : 300;
			$magic_result = HappyAccess_Magic_Link::generate( $result['id'], $magic_link_expiry );
			
			if ( ! is_wp_error( $magic_result ) ) {
				$response['magic_link'] = array(
					'url'     => $magic_result['url'],
					'expires' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $magic_result['expires_at'] ) ),
				);
			}
		}
		
		wp_send_json_success( $response );
	}

	/**
	 * Send OTP email to admin.
	 *
	 * @since 1.0.0
	 * @since 1.0.2 Added $single_use parameter.
	 *
	 * @param string $otp        The OTP code.
	 * @param string $expires    Formatted expiry date/time.
	 * @param string $role       User role.
	 * @param string $note       Optional note.
	 * @param bool   $single_use Whether this is a single-use token.
	 */
	private function send_otp_email( $otp, $expires, $role, $note = '', $single_use = false ) {
		$to = get_option( 'admin_email' );
		$site_name = get_bloginfo( 'name' );
		$site_url = get_site_url();
		$login_url = wp_login_url();
		
		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Support Access Code Ready', 'happyaccess' ),
			$site_name
		);
		
		// Build HTML message.
		$html_message = '<!DOCTYPE html>
<html>
<head>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; line-height: 1.6; color: #333; }
.container { max-width: 600px; margin: 0 auto; padding: 20px; }
.header { background: #007cba; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
.content { background: #f7f7f7; padding: 20px; border: 1px solid #ddd; border-top: none; }
.code-box { background: white; border: 2px dashed #007cba; padding: 20px; margin: 20px 0; text-align: center; border-radius: 5px; }
.code { font-size: 32px; font-weight: bold; color: #007cba; letter-spacing: 8px; font-family: monospace; }
.details { background: white; padding: 15px; margin: 20px 0; border-left: 4px solid #007cba; }
.details-row { margin: 10px 0; }
.label { font-weight: bold; color: #666; }
.steps { background: white; padding: 20px; margin: 20px 0; border-radius: 5px; }
.step { margin: 10px 0; padding-left: 20px; }
.warning { background: #fef8e7; border-left: 4px solid #f0ad4e; padding: 15px; margin: 20px 0; }
.security { background: #fff; border: 1px solid #ddd; padding: 15px; margin: 20px 0; border-radius: 5px; }
.footer { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="container">
<div class="header">
<h2 style="margin: 0; font-size: 24px;">' . esc_html__( '🔐 Temporary Support Access Created', 'happyaccess' ) . '</h2>
</div>
<div class="content">';
		
		$html_message .= '<p>' . sprintf(
			/* translators: %s: site name */
			esc_html__( 'HappyAccess has generated a secure access code for your support team to access %s.', 'happyaccess' ),
			'<strong>' . esc_html( $site_name ) . '</strong>'
		) . '</p>';
		
		$html_message .= '<div class="code-box">
<div style="color: #666; font-size: 14px; margin-bottom: 10px;">' . esc_html__( 'ACCESS CODE', 'happyaccess' ) . '</div>
<div class="code">' . esc_html( $otp ) . '</div>
</div>';
		
		$html_message .= '<div class="details">
<div class="details-row"><span class="label">' . esc_html__( 'Valid Until:', 'happyaccess' ) . '</span> ' . esc_html( $expires ) . '</div>
<div class="details-row"><span class="label">' . esc_html__( 'Access Level:', 'happyaccess' ) . '</span> ' . esc_html( ucfirst( $role ) ) . '</div>';
		
		if ( $single_use ) {
			$html_message .= '<div class="details-row"><span class="label" style="color: #d63232;">' . esc_html__( 'Usage:', 'happyaccess' ) . '</span> <strong style="color: #d63232;">' . esc_html__( 'ONE-TIME USE ONLY', 'happyaccess' ) . '</strong></div>';
		}
		
		if ( ! empty( $note ) ) {
			$html_message .= '<div class="details-row"><span class="label">' . esc_html__( 'Reference:', 'happyaccess' ) . '</span> ' . esc_html( $note ) . '</div>';
		}
		
		$html_message .= '</div>';
		
		$html_message .= '<div class="steps">
<h3 style="margin-top: 0; color: #007cba;">' . esc_html__( '📋 How to Share This Code', 'happyaccess' ) . '</h3>
<div class="step">1. Share the <strong>6-digit code above</strong> with your authorized support staff.</div>
<div class="step">2. Direct them to: <a href="' . esc_url( $login_url ) . '" style="color: #007cba;">' . esc_html( $login_url ) . '</a></div>
<div class="step">3. They enter the code in the <strong>"Temporary Support Access"</strong> field.</div>
<div class="step">4. No username or password required - just the code!</div>
</div>';
		
		$html_message .= '<div class="warning">
<strong>⚠️ ' . esc_html__( 'Security Reminder', 'happyaccess' ) . '</strong><br>
' . sprintf(
			/* translators: %s: role name */
			esc_html__( 'This code grants %s access. Only share with trusted support personnel.', 'happyaccess' ),
			'<strong>' . esc_html( $role ) . '</strong>'
		);
		
		if ( $single_use ) {
			$html_message .= '<br><br><strong style="color: #d63232;">⚡ ' . esc_html__( 'ONE-TIME USE:', 'happyaccess' ) . '</strong> ' . esc_html__( 'This code will automatically expire after the first login. The support engineer cannot log out and log back in - they will have only one session.', 'happyaccess' );
		}
		
		$html_message .= '
</div>';
		
		$html_message .= '<div class="security">
<strong>' . esc_html__( '🛡️ Your Security Controls!', 'happyaccess' ) . '</strong>
<ul style="margin: 10px 0; padding-left: 20px;">
<li>' . esc_html__( 'Access expires automatically at the time shown above.', 'happyaccess' ) . '</li>
<li>' . esc_html__( 'All actions are logged for audit purposes.', 'happyaccess' ) . '</li>
<li>' . sprintf(
			/* translators: %s: admin path */
			esc_html__( 'Revoke instantly from %s', 'happyaccess' ),
			'<a href="' . esc_url( admin_url( 'users.php?page=happyaccess&tab=active' ) ) . '" style="color: #007cba;">Users → HappyAccess → Active Tokens</a>'
		) . '</li>
<li>' . esc_html__( 'Emergency Lock available in admin bar for instant revocation.', 'happyaccess' ) . '</li>
</ul>
</div>';
		
		$html_message .= '</div>
<div class="footer">
<p>' . sprintf(
			/* translators: %s: site name */
			esc_html__( 'This email was sent from your WordPress site %s using HappyAccess.', 'happyaccess' ),
			esc_html( $site_name )
		) . '<br>
' . esc_html__( 'If you did not generate this code, revoke it immediately and secure your account.', 'happyaccess' ) . '</p>
</div>
</div>
</body>
</html>';
		
		// Build plain text message as fallback.
		$plain_message = sprintf(
			/* translators: 1: site name */
			__( 'HappyAccess - Support Access Code for %1$s
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

ACCESS CODE: %2$s

DETAILS:
• Valid Until: %3$s
• Access Level: %4$s', 'happyaccess' ),
			$site_name,
			$otp,
			$expires,
			ucfirst( $role )
		);
		
		if ( ! empty( $note ) ) {
			$plain_message .= sprintf( 
				/* translators: %s: reference note */
				__( '
• Reference: %s', 'happyaccess' ), 
				$note 
			);
		}
		
		$plain_message .= sprintf(
			/* translators: %s: login URL */
			__( '

HOW TO USE THIS CODE:
1. Share the 6-digit code with your support team.
2. Direct them to: %s
3. Enter code in "Temporary Support Access" field
4. Click "Log In" (no username/password needed).

SECURITY INFORMATION:
✓ Access expires automatically at the time shown.
✓ All actions are logged for audit.
✓ Revoke anytime from Users → HappyAccess → Active Tokens.
✓ Emergency Lock available in admin bar.

⚠️ Only share this code with trusted support personnel.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
If you did not generate this code, revoke it immediately.', 'happyaccess' ),
			$login_url
		);
		
		// Set content type for HTML email.
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		
		// Send the email with HTML format.
		wp_mail( $to, $subject, $html_message, $headers );
}

/**
 * Handle AJAX token revocation.
 *
 * @since 1.0.0
 */
public function ajax_revoke_token() {
	// Verify nonce.
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'happyaccess_ajax' ) ) {
		wp_send_json_error( array( 'message' => __( 'Security check failed', 'happyaccess' ) ) );
	}
	
	// Check permissions.
	if ( ! current_user_can( 'list_users' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'happyaccess' ) ) );
	}
	
	// Get token ID.
	$token_id = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;
	
	if ( ! $token_id ) {
		wp_send_json_error( array( 'message' => __( 'Invalid token ID', 'happyaccess' ) ) );
	}
	
	// Revoke the token.
	$revoked = HappyAccess_Token_Manager::revoke_token( $token_id );
	
	if ( $revoked ) {
		wp_send_json_success( array( 'message' => __( 'Token revoked successfully', 'happyaccess' ) ) );
	} else {
		wp_send_json_error( array( 'message' => __( 'Failed to revoke token', 'happyaccess' ) ) );
	}
}

	/**
	 * Handle AJAX logout all temp sessions.
	 *
	 * @since 1.0.1
	 */
	public function ajax_logout_sessions() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'happyaccess_ajax' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'happyaccess' ) ) );
		}
		
		// Check permissions.
		if ( ! current_user_can( 'list_users' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'happyaccess' ) ) );
		}
		
		// Get all active tokens with users.
		$token_manager = new HappyAccess_Token_Manager();
		$active_tokens = $token_manager->get_active_tokens();
		
		$logged_out_count = 0;
		
		foreach ( $active_tokens as $token ) {
			if ( ! empty( $token['user_id'] ) ) {
				$user = get_user_by( 'ID', $token['user_id'] );
				if ( $user && get_user_meta( $token['user_id'], 'happyaccess_temp_user', true ) ) {
					// Destroy all sessions for this user.
					$sessions = WP_Session_Tokens::get_instance( $token['user_id'] );
					$sessions->destroy_all();
					$logged_out_count++;
				}
			}
		}
		
		// Log the action.
		HappyAccess_Logger::log( 'sessions_logged_out', array(
			'count'       => $logged_out_count,
			'initiated_by' => wp_get_current_user()->user_login,
		) );
		
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of sessions logged out */
				__( 'Successfully logged out %d temporary user session(s). Tokens remain active for future use.', 'happyaccess' ),
				$logged_out_count
			),
		) );
	}

	/**
	 * Handle AJAX magic link generation.
	 *
	 * @since 1.0.3
	 */
	public function ajax_generate_magic_link() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'happyaccess_ajax' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'happyaccess' ) ) );
		}
		
		// Check permissions.
		if ( ! current_user_can( 'list_users' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'happyaccess' ) ) );
		}
		
		// Get token ID and expiration.
		$token_id   = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;
		$expiration = isset( $_POST['expiration'] ) ? absint( $_POST['expiration'] ) : 300;
		
		if ( ! $token_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid token ID', 'happyaccess' ) ) );
		}
		
		// Generate magic link.
		$result = HappyAccess_Magic_Link::generate( $token_id, $expiration );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		
		// Format expiration for display.
		$expires_display = wp_date( 
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 
			strtotime( $result['expires_at'] ) 
		);
		
		wp_send_json_success( array(
			'url'     => $result['url'],
			'expires' => $expires_display,
			'message' => sprintf(
				/* translators: %s: expiration time */
				__( 'Magic link generated! Valid until %s', 'happyaccess' ),
				$expires_display
			),
		) );
	}

	/**
	 * Handle AJAX OTP share link generation.
	 *
	 * @since 1.0.3
	 */
	public function ajax_generate_share_link() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'happyaccess_ajax' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'happyaccess' ) ) );
		}
		
		// Check permissions.
		if ( ! current_user_can( 'list_users' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'happyaccess' ) ) );
		}
		
		// Get parameters.
		$token_id    = isset( $_POST['token_id'] ) ? absint( $_POST['token_id'] ) : 0;
		$otp_code    = isset( $_POST['otp_code'] ) ? sanitize_text_field( wp_unslash( $_POST['otp_code'] ) ) : '';
		$expiration  = isset( $_POST['expiration'] ) ? absint( $_POST['expiration'] ) : 300;
		$single_view = isset( $_POST['single_view'] ) && $_POST['single_view'] === '1';
		
		if ( ! $token_id || empty( $otp_code ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid token ID or OTP code', 'happyaccess' ) ) );
		}
		
		// Generate share link.
		$result = HappyAccess_OTP_Share::generate( $token_id, $otp_code, $expiration, $single_view );
		
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		
		// Format expiration for display.
		$expires_display = wp_date( 
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 
			strtotime( $result['expires_at'] ) 
		);
		
		wp_send_json_success( array(
			'url'         => $result['url'],
			'expires'     => $expires_display,
			'single_view' => $result['single_view'],
			'message'     => sprintf(
				/* translators: %s: expiration time */
				__( 'Share link generated! Valid until %s', 'happyaccess' ),
				$expires_display
			),
		) );
	}

	/**
	 * Handle AJAX email magic link request.
	 *
	 * @since 1.0.3
	 */
	public function ajax_email_magic_link() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'happyaccess_ajax' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'happyaccess' ) ) );
		}
		
		// Check permissions.
		if ( ! current_user_can( 'list_users' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'happyaccess' ) ) );
		}
		
		// Get parameters.
		$magic_link_url = isset( $_POST['magic_link_url'] ) ? esc_url_raw( wp_unslash( $_POST['magic_link_url'] ) ) : '';
		$expires        = isset( $_POST['expires'] ) ? sanitize_text_field( wp_unslash( $_POST['expires'] ) ) : '';
		$recipient      = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : get_option( 'admin_email' );
		
		if ( empty( $magic_link_url ) || ! filter_var( $recipient, FILTER_VALIDATE_EMAIL ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid magic link or email address', 'happyaccess' ) ) );
		}
		
		// Send the email.
		$sent = $this->send_magic_link_email( $magic_link_url, $expires, $recipient );
		
		if ( $sent ) {
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %s: recipient email */
					__( 'Magic link sent to %s', 'happyaccess' ),
					$recipient
				),
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to send email. Please check your email settings.', 'happyaccess' ) ) );
		}
	}

	/**
	 * Send magic link email.
	 *
	 * @since 1.0.3
	 *
	 * @param string $magic_link_url The magic link URL.
	 * @param string $expires        Expiration display string.
	 * @param string $recipient      Recipient email address.
	 * @return bool True if sent, false otherwise.
	 */
	private function send_magic_link_email( $magic_link_url, $expires, $recipient ) {
		$site_name = get_bloginfo( 'name' );
		
		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] One-Click Access Link', 'happyaccess' ),
			$site_name
		);
		
		// Build HTML message.
		$html_message = '<!DOCTYPE html>
<html>
<head>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; }
.container { max-width: 600px; margin: 0 auto; padding: 20px; }
.header { background: #1d2327; color: white; padding: 24px; border-radius: 8px 8px 0 0; text-align: center; }
.content { background: #f9f9f9; padding: 24px; border: 1px solid #ddd; border-top: none; }
.btn { display: inline-block; background: #2271b1; color: white !important; text-decoration: none; padding: 16px 32px; border-radius: 6px; font-size: 16px; font-weight: 600; margin: 16px 0; }
.btn:hover { background: #135e96; }
.warning { background: #fcf0f1; border-left: 4px solid #d63638; padding: 12px 16px; margin: 16px 0; }
.footer { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="container">
<div class="header">
<h2 style="margin: 0;">' . esc_html__( 'One-Click Access Link', 'happyaccess' ) . '</h2>
<p style="margin: 8px 0 0; opacity: 0.8;">' . esc_html( $site_name ) . '</p>
</div>
<div class="content">
<p>' . esc_html__( 'You have been granted temporary access. Click the button below to log in instantly - no code required!', 'happyaccess' ) . '</p>

<p style="text-align: center;">
<a href="' . esc_url( $magic_link_url ) . '" class="btn">' . esc_html__( 'Click to Access Site', 'happyaccess' ) . '</a>
</p>

<p style="font-size: 13px; color: #666;">' . esc_html__( 'Or copy this link:', 'happyaccess' ) . '<br>
<code style="background: #e0e0e0; padding: 4px 8px; border-radius: 4px; font-size: 12px; word-break: break-all;">' . esc_html( $magic_link_url ) . '</code></p>

<div class="warning">
<strong>' . esc_html__( 'Important Security Information:', 'happyaccess' ) . '</strong>
<ul style="margin: 8px 0 0; padding-left: 20px;">
<li>' .
				/* translators: %s: link expiration time */
				sprintf( esc_html__( 'This link expires: %s', 'happyaccess' ), '<strong>' . esc_html( $expires ) . '</strong>' ) . '</li>
<li>' . esc_html__( 'This link can only be used once.', 'happyaccess' ) . '</li>
<li>' . esc_html__( 'Do not share this link with anyone else.', 'happyaccess' ) . '</li>
</ul>
</div>

</div>
<div class="footer">
<p>' . esc_html__( 'This email was sent by HappyAccess. If you did not request this access, please ignore this email.', 'happyaccess' ) . '</p>
</div>
</div>
</body>
</html>';
		
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		
		$sent = wp_mail( $recipient, $subject, $html_message, $headers );
		
		if ( $sent ) {
			HappyAccess_Logger::log( 'magic_link_emailed', array(
				'recipient' => $recipient,
				'expires'   => $expires,
			) );
		}
		
		return $sent;
	}

	/**
	 * Handle AJAX clear logs request.
	 *
	 * @since 1.0.2
	 */
	public function ajax_clear_logs() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'happyaccess_ajax' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'happyaccess' ) ) );
		}
		
		// Check permissions - only administrators can clear logs.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only administrators can clear audit logs', 'happyaccess' ) ) );
		}
		
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'happyaccess_logs' );
		
		// Get count before clearing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$count = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
			"SELECT COUNT(*) FROM `$table`"
		);
		
		// Clear all logs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
			"TRUNCATE TABLE `$table`"
		);
		
		// Log this action (will be the only entry after clear).
		HappyAccess_Logger::log( 'logs_cleared', array(
			'cleared_count' => $count,
			'cleared_by'    => wp_get_current_user()->user_login,
		) );
		
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of logs cleared */
				__( 'Successfully cleared %d audit log entries.', 'happyaccess' ),
				$count
			),
		) );
	}

	/**
	 * Add Emergency Lock button to admin bar.
	 *
	 * @since 1.0.0
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 */
	public function add_emergency_lock_button( $wp_admin_bar ) {
		// Only show for users who can manage HappyAccess.
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}
		
		// Don't show to temporary users - they shouldn't have this control.
		if ( HappyAccess_Temp_User::is_temp_user( get_current_user_id() ) ) {
			return;
		}
		
		// Check if there are any active tokens.
		$token_manager = new HappyAccess_Token_Manager();
		$active_tokens = $token_manager->get_active_tokens();
		
		if ( empty( $active_tokens ) ) {
			return;
		}
		
		// Add the Emergency Lock button.
		$wp_admin_bar->add_node( array(
			'id'    => 'happyaccess-emergency-lock',
			'title' => '<span style="color: #dc3232;">🚨 ' . __( 'Emergency Lock', 'happyaccess' ) . '</span>',
			'href'  => '#',
			'meta'  => array(
				'title' => __( 'Immediately revoke all HappyAccess tokens', 'happyaccess' ),
				'onclick' => 'happyaccessEmergencyLock(); return false;',
			),
		) );
		
		// Add inline script for the emergency lock.
		?>
		<script type="text/javascript">
		function happyaccessEmergencyLock() {
			var message = <?php echo wp_json_encode( __( "EMERGENCY LOCK: This will immediately:\n\n• Revoke ALL active access tokens\n• Delete ALL temporary users\n• Block all temporary access\n\nAre you sure you want to proceed?", 'happyaccess' ) ); ?>;
			if ( ! confirm( message ) ) {
				return;
			}
			
			jQuery.post(
				ajaxurl,
				{
					action: 'happyaccess_emergency_lock',
					nonce: '<?php echo esc_js( wp_create_nonce( 'happyaccess_emergency_lock' ) ); ?>'
				},
				function( response ) {
					if ( response.success ) {
						alert( '<?php echo esc_js( __( 'Emergency Lock activated! All temporary access has been revoked.', 'happyaccess' ) ); ?>' );
						location.reload();
					} else {
						alert( '<?php echo esc_js( __( 'Error activating Emergency Lock. Please try again.', 'happyaccess' ) ); ?>' );
					}
				}
			);
		}
		</script>
		<?php
	}
	
	/**
	 * Handle Emergency Lock AJAX request.
	 *
	 * @since 1.0.0
	 */
	public function ajax_emergency_lock() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'happyaccess_emergency_lock' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'happyaccess' ) ) );
		}
		
		// Check permissions.
		if ( ! current_user_can( 'list_users' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'happyaccess' ) ) );
		}
		
		// Get all active tokens.
		$token_manager = new HappyAccess_Token_Manager();
		$active_tokens = $token_manager->get_active_tokens();
		
		$revoked_count = 0;
		$deleted_users = 0;
		
		// Revoke all active tokens and delete users.
		foreach ( $active_tokens as $token ) {
			// Delete temporary user if exists.
			if ( ! empty( $token['user_id'] ) ) {
				$user = get_user_by( 'ID', $token['user_id'] );
				if ( $user && get_user_meta( $token['user_id'], 'happyaccess_temp_user', true ) ) {
					wp_delete_user( $token['user_id'] );
					$deleted_users++;
				}
			}
			
			// Revoke the token.
			$token_manager->revoke_token( $token['id'] );
			$revoked_count++;
		}
		
		// Log the emergency lock action.
		HappyAccess_Logger::log( 'emergency_lock', array(
			'revoked_tokens' => $revoked_count,
			'deleted_users' => $deleted_users,
			'initiated_by' => wp_get_current_user()->user_login,
		) );
		
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: number of tokens revoked, 2: number of users deleted */
				__( 'Emergency Lock activated! Revoked %1$d tokens and deleted %2$d temporary users.', 'happyaccess' ),
				$revoked_count,
				$deleted_users
			),
		) );
	}
	
	/**
	 * Display admin notices.
	 *
	 * @since 1.0.0
	 */
	public function admin_notices() {
		// Show activation notice.
		if ( get_transient( 'happyaccess_activation_notice' ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<strong><?php esc_html_e( 'HappyAccess activated successfully!', 'happyaccess' ); ?></strong>
					<?php 
					printf(
						/* translators: %s: link to generate page */
						esc_html__( 'You can now %s for support engineers.', 'happyaccess' ),
						'<a href="' . esc_url( admin_url( 'users.php?page=happyaccess' ) ) . '">' . 
						esc_html__( 'generate temporary access codes', 'happyaccess' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
			delete_transient( 'happyaccess_activation_notice' );
		}
	}
}
