<?php
/**
 * The two-step section on Profile and Edit User.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Mailer;
use HappyAccess\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * On your own profile: the status of each method and the controls to
 * change them, which run through RestController. On someone else's, for a
 * user who can edit users: the status and one button that turns their
 * two-step login off.
 *
 * The section prints inside the profile form, so it holds no form of its
 * own and no field with a name: its controls are buttons the script
 * handles. The admin reset button submits a form printed in the footer,
 * outside the profile form, through its form attribute.
 */
final class Profile {

	/**
	 * Id of the section heading, for links to it.
	 */
	const ANCHOR = 'happyaccess-twostep';

	/**
	 * admin-post action and nonce prefix of the admin reset. The nonce adds
	 * the user id, so one form can't reset another user.
	 */
	const ACTION = 'happyaccess_twostep_reset';
	const NONCE  = 'happyaccess_twostep_reset';

	/**
	 * Query arg on Edit User after a reset.
	 */
	const DONE_ARG = 'happyaccess_ts_reset';

	const HANDLE = 'happyaccess-twostep-profile';

	/**
	 * User whose reset form the footer prints, 0 for none.
	 *
	 * @var int
	 */
	private static $reset_form_for = 0;

	/**
	 * Adds the section, the reset handler and the notices. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'show_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_reset' ) );
		add_action( 'admin_footer', array( __CLASS__, 'print_reset_form' ) );
		add_action( 'admin_notices', array( __CLASS__, 'print_pause_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'print_pause_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'print_reset_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'print_reset_notice' ) );
	}

	/**
	 * The URL of the section on the user's own profile.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'profile.php#' . self::ANCHOR );
	}

	/**
	 * Prints the section for the profile being shown.
	 *
	 * @param \WP_User $profileuser The user whose profile is shown.
	 * @return void
	 */
	public static function render( $profileuser ) {
		if ( ! $profileuser instanceof \WP_User ) {
			return;
		}
		echo self::section( $profileuser ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in section().
	}

	/**
	 * The section markup for the user whose profile is shown, as the
	 * current user sees it. Empty when there is nothing for them.
	 *
	 * @param \WP_User $user The user whose profile is shown.
	 * @return string
	 */
	public static function section( \WP_User $user ) {
		$viewer = get_current_user_id();
		if ( $viewer < 1 || Capabilities::is_temp_user( $viewer ) || Capabilities::is_temp_user( $user->ID ) ) {
			return '';
		}
		if ( $viewer === (int) $user->ID ) {
			return self::own_section( $user );
		}
		if ( self::can_reset_for( $user->ID ) ) {
			return self::admin_section( $user );
		}
		return '';
	}

	/**
	 * Whether the current user may turn off this user's two-step login:
	 * someone who can edit users and this user, on another account, and not
	 * a Support Access temp user.
	 *
	 * @param int $user_id User whose two-step login would go.
	 * @return bool
	 */
	public static function can_reset_for( $user_id ) {
		$viewer = get_current_user_id();
		return $viewer > 0
			&& (int) $user_id !== $viewer
			&& ! Capabilities::is_temp_user( $viewer )
			&& current_user_can( 'edit_users' )
			&& current_user_can( 'edit_user', (int) $user_id );
	}

	/**
	 * Turns off another user's two-step login, logs it and emails them.
	 *
	 * @param int    $user_id User whose two-step login goes.
	 * @param string $nonce   Nonce from the reset form.
	 * @return true|\WP_Error
	 */
	public static function reset_user( $user_id, $nonce ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'happyaccess_no_user', __( 'That user does not exist.', 'happyaccess' ) );
		}
		if ( ! is_string( $nonce ) || false === wp_verify_nonce( $nonce, self::NONCE . '_' . $user->ID ) ) {
			return new \WP_Error( 'expired_page', __( 'This page expired. Try again.', 'happyaccess' ) );
		}
		if ( ! self::can_reset_for( $user->ID ) ) {
			return new \WP_Error( 'happyaccess_forbidden', __( "You can't turn off two-step login for this user.", 'happyaccess' ) );
		}

		$admin = wp_get_current_user();
		$had   = UserState::reset(
			$user->ID,
			array(
				'source'  => 'admin',
				'user_id' => (int) $admin->ID,
			)
		);
		if ( $had ) {
			Mailer::send(
				$user->user_email,
				__( 'Two-step login was turned off', 'happyaccess' ),
				'twostep-reset',
				array( 'admin_name' => $admin->display_name )
			);
		}
		return true;
	}

	/**
	 * admin-post handler of the reset form.
	 *
	 * @return void
	 */
	public static function handle_reset() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- reset_user() verifies the nonce.
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$nonce   = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = self::reset_user( $user_id, $nonce );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
		}
		wp_safe_redirect( add_query_arg( self::DONE_ARG, '1', get_edit_user_link( $user_id ) ) . '#' . self::ANCHOR );
		exit;
	}

	/**
	 * Prints the reset form, outside the profile form, when the section has
	 * a reset button.
	 *
	 * @return void
	 */
	public static function print_reset_form() {
		if ( self::$reset_form_for < 1 ) {
			return;
		}
		$user_id              = self::$reset_form_for;
		self::$reset_form_for = 0;

		echo '<form id="happyaccess-ts-reset" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden>';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '" />';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::NONCE . '_' . $user_id ) ) . '" />';
		echo '</form>';
	}

	/**
	 * The notice while HAPPYACCESS_DISABLE_TWOSTEP is on, for users who can
	 * manage options. Empty otherwise.
	 *
	 * @return string
	 */
	public static function pause_notice() {
		if ( ! defined( 'HAPPYACCESS_DISABLE_TWOSTEP' ) || true !== (bool) constant( 'HAPPYACCESS_DISABLE_TWOSTEP' ) ) {
			return '';
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return '<div class="notice notice-warning"><p>' . esc_html__( "Two-step login is paused by HAPPYACCESS_DISABLE_TWOSTEP in wp-config.php. Remove it when you're back in.", 'happyaccess' ) . '</p></div>';
	}

	/**
	 * Prints the pause notice.
	 *
	 * @return void
	 */
	public static function print_pause_notice() {
		echo self::pause_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in pause_notice().
	}

	/**
	 * Says the reset worked, on Edit User after the redirect.
	 *
	 * @return void
	 */
	public static function print_reset_notice() {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the value only shows a message.
		if ( 'user-edit.php' !== $pagenow || empty( $_GET[ self::DONE_ARG ] ) ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Two-step login is off for this user.', 'happyaccess' ) . '</p></div>';
	}

	/**
	 * Loads the stylesheet when the section shows. On the user's own
	 * profile it also loads the QR library, the shared setup script and the
	 * profile script. Edit User with your own id shows your own profile, so
	 * it loads the same as profile.php.
	 *
	 * @param string $hook_suffix Admin page.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( 'user-edit.php' === $hook_suffix ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; picks the user whose profile is shown.
			$shown = get_userdata( isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0 );
			if ( ! $shown instanceof \WP_User ) {
				return;
			}
			if ( get_current_user_id() !== (int) $shown->ID ) {
				if ( '' !== self::section( $shown ) ) {
					self::$reset_form_for = 0;
					wp_enqueue_style( self::HANDLE, plugins_url( 'assets/twostep-setup.css', HAPPYACCESS_PLUGIN_FILE ), array(), SetupSteps::asset_version( 'assets/twostep-setup.css' ) );
				}
				return;
			}
		} elseif ( 'profile.php' !== $hook_suffix ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() || '' === self::section( $user ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/twostep-setup.css', HAPPYACCESS_PLUGIN_FILE ), array(), SetupSteps::asset_version( 'assets/twostep-setup.css' ) );
		SetupSteps::register_qr();
		wp_register_script( SetupSteps::HANDLE, plugins_url( 'assets/twostep-setup.js', HAPPYACCESS_PLUGIN_FILE ), array( SetupSteps::QR_HANDLE ), SetupSteps::asset_version( 'assets/twostep-setup.js' ), true );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/twostep-profile.js', HAPPYACCESS_PLUGIN_FILE ), array( SetupSteps::HANDLE ), SetupSteps::asset_version( 'assets/twostep-profile.js' ), true );
		wp_localize_script(
			self::HANDLE,
			'happyaccessTwoStepProfile',
			array(
				'restUrl' => esc_url_raw( rest_url( Routes::NS . RestController::BASE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => array(
					'failed'       => __( "That didn't work. Try again.", 'happyaccess' ),
					'enterCode'    => __( 'Enter the code from the app.', 'happyaccess' ),
					'enterRecheck' => __( 'Enter your password or a code.', 'happyaccess' ),
					'scan'         => __( 'Scan the QR code, then enter the code from the app.', 'happyaccess' ),
					'codesShown'   => __( 'Your new backup codes are below.', 'happyaccess' ),
					'saving'       => __( 'Saving.', 'happyaccess' ),
				),
			)
		);
	}

	/**
	 * The section on the user's own profile. Nothing when their role has
	 * two-step login off and nothing is set up.
	 *
	 * @param \WP_User $user The current user.
	 * @return string
	 */
	private static function own_section( \WP_User $user ) {
		$offered  = RestController::is_offered( $user );
		$app      = UserState::app_enabled( $user->ID );
		$email    = UserState::email_enabled( $user->ID );
		$left     = BackupCodes::remaining( $user->ID );
		$required = Enforcement::REQUIRED === Enforcement::policy( $user );
		if ( ! $offered && ! $app && ! $email && $left < 1 ) {
			return '';
		}

		$html  = '<div class="happyaccess-ts-profile" data-happyaccess-twostep>';
		$html .= '<h2 id="' . esc_attr( self::ANCHOR ) . '" tabindex="-1">' . esc_html__( 'Two-step login', 'happyaccess' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'After your password, you also enter a code from your authenticator app or your email.', 'happyaccess' ) . '</p>';
		if ( $required ) {
			$html .= '<p><strong>' . esc_html__( 'Your role needs two-step login.', 'happyaccess' ) . '</strong></p>';
		}
		$html .= '<div id="happyaccess-ts-profile-error" class="notice notice-error inline" role="alert" hidden><p></p></div>';

		$html .= '<table class="form-table" role="presentation"><tbody>';

		$html  .= '<tr><th scope="row">' . esc_html__( 'Authenticator app', 'happyaccess' ) . '</th><td>';
		$action = '';
		if ( $app && ! RestController::is_last_required( $user, 'app' ) ) {
			$action = self::button( 'app-disable', __( 'Turn off', 'happyaccess' ) );
		} elseif ( ! $app && $offered ) {
			$action = self::button( 'app-begin', __( 'Set up', 'happyaccess' ) );
		}
		$html .= self::row( self::status( $app ), $action );
		if ( ! $app && $offered ) {
			$html .= self::app_panel();
		}
		$html .= '<p class="description">' . esc_html__( 'Codes come from an app on your phone.', 'happyaccess' ) . '</p>';
		$html .= '</td></tr>';

		$html  .= '<tr><th scope="row">' . esc_html__( 'Email codes', 'happyaccess' ) . '</th><td>';
		$action = '';
		if ( $email && ! RestController::is_last_required( $user, 'email' ) ) {
			$action = self::button( 'email-disable', __( 'Turn off', 'happyaccess' ) );
		} elseif ( ! $email && $offered ) {
			$action = self::button( 'email-enable', __( 'Turn on', 'happyaccess' ) );
		}
		$html .= self::row( self::status( $email ), $action );
		$html .= '<p class="description">' . esc_html__( 'Codes go to the email address on your account.', 'happyaccess' ) . '</p>';
		$html .= '</td></tr>';

		$html .= '<tr><th scope="row">' . esc_html__( 'Backup codes', 'happyaccess' ) . '</th><td>';
		if ( $app || $email ) {
			$html .= self::row( self::state( self::codes_left( $left ) ), self::button( 'backup-regenerate', __( 'Make new codes', 'happyaccess' ) ) );
			$html .= '<p class="description">' . esc_html__( 'Making new codes stops the old ones from working.', 'happyaccess' ) . '</p>';
		} else {
			$html .= '<p class="description">' . esc_html__( 'Turn on the app or email codes first.', 'happyaccess' ) . '</p>';
		}
		$html .= '</td></tr>';

		$html .= '</tbody></table>';
		$html .= self::codes_panel();
		$html .= self::recheck_panel();
		$html .= '<p class="screen-reader-text" role="status" data-happyaccess-live></p>';
		return $html . '</div>';
	}

	/**
	 * The section on another user's profile: the status and the reset.
	 *
	 * @param \WP_User $user The user whose profile is shown.
	 * @return string
	 */
	private static function admin_section( \WP_User $user ) {
		$app   = UserState::app_enabled( $user->ID );
		$email = UserState::email_enabled( $user->ID );
		$left  = BackupCodes::remaining( $user->ID );

		$html  = '<div class="happyaccess-ts-profile">';
		$html .= '<h2 id="' . esc_attr( self::ANCHOR ) . '">' . esc_html__( 'Two-step login', 'happyaccess' ) . '</h2>';
		$html .= '<table class="form-table" role="presentation"><tbody>';
		$html .= '<tr><th scope="row">' . esc_html__( 'Authenticator app', 'happyaccess' ) . '</th><td>' . self::row( self::status( $app ), '' ) . '</td></tr>';
		$html .= '<tr><th scope="row">' . esc_html__( 'Email codes', 'happyaccess' ) . '</th><td>' . self::row( self::status( $email ), '' ) . '</td></tr>';
		$html .= '<tr><th scope="row">' . esc_html__( 'Backup codes', 'happyaccess' ) . '</th><td>' . self::row( self::state( self::codes_left( $left ) ), '' ) . '</td></tr>';
		if ( $app || $email ) {
			self::$reset_form_for = (int) $user->ID;
			$html                .= '<tr><th scope="row">' . esc_html__( 'Recovery', 'happyaccess' ) . '</th><td>';
			$html                .= '<button type="submit" form="happyaccess-ts-reset" class="button">' . esc_html__( 'Turn off two-step login for this user', 'happyaccess' ) . '</button>';
			$html                .= '<p class="description">' . esc_html__( 'Use this when they lost their phone or their backup codes. They get an email, and they can set it up again from their profile.', 'happyaccess' ) . '</p>';
			$html                .= '</td></tr>';
		}
		return $html . '</tbody></table></div>';
	}

	/**
	 * The app setup panel, filled in by the script after app/begin.
	 *
	 * @return string
	 */
	private static function app_panel() {
		$html  = '<div class="happyaccess-ts-panel" data-happyaccess-panel="app" hidden>';
		$html .= '<p>' . esc_html__( 'Scan this QR code with your authenticator app.', 'happyaccess' ) . '</p>';
		$html .= SetupSteps::qr_and_key( '', '' );
		$html .= '<p><label for="happyaccess-ts-profile-code">' . esc_html__( 'Code from the app', 'happyaccess' ) . '</label><br />';
		$html .= SetupSteps::code_input( 'happyaccess-ts-profile-code', '', false, 'regular-text happyaccess-ts-code' ) . '</p>';
		$html .= '<p>' . self::button( 'app-confirm', __( 'Turn on two-step login', 'happyaccess' ), 'button button-primary' );
		$html .= ' ' . self::button( 'cancel', __( 'Cancel', 'happyaccess' ), 'button-link' ) . '</p>';
		return $html . '</div>';
	}

	/**
	 * The backup codes panel, filled in by the script after a change that
	 * made new codes. Done reloads the profile.
	 *
	 * @return string
	 */
	private static function codes_panel() {
		$html  = '<div class="happyaccess-ts-panel" data-happyaccess-panel="codes" hidden>';
		$html .= SetupSteps::codes_block( array(), false, 'h3' );
		$html .= '<p><button type="button" id="happyaccess-ts-continue" class="button button-primary" data-happyaccess-action="done">' . esc_html__( 'Done', 'happyaccess' ) . '</button></p>';
		return $html . '</div>';
	}

	/**
	 * The re-check panel, shown when a change needs it.
	 *
	 * @return string
	 */
	private static function recheck_panel() {
		$html  = '<div class="happyaccess-ts-panel" data-happyaccess-panel="recheck" hidden>';
		$html .= '<p>' . esc_html__( "Confirm it's you to change two-step login.", 'happyaccess' ) . '</p>';
		$html .= '<p><label for="happyaccess-ts-recheck" data-label-password="' . esc_attr__( 'Current password', 'happyaccess' ) . '" data-label-code="' . esc_attr__( 'Code from your app, or a backup code', 'happyaccess' ) . '">' . esc_html__( 'Current password', 'happyaccess' ) . '</label><br />';
		$html .= '<input type="password" id="happyaccess-ts-recheck" class="regular-text" value="" autocomplete="current-password" spellcheck="false" /></p>';
		$html .= '<p>' . self::button( 'recheck', __( 'Confirm', 'happyaccess' ), 'button button-primary' );
		$html .= ' <button type="button" class="button-link" data-happyaccess-action="recheck-mode" data-label-password="' . esc_attr__( 'Use a code instead', 'happyaccess' ) . '" data-label-code="' . esc_attr__( 'Use your password instead', 'happyaccess' ) . '">' . esc_html__( 'Use a code instead', 'happyaccess' ) . '</button>';
		$html .= ' ' . self::button( 'cancel', __( 'Cancel', 'happyaccess' ), 'button-link' ) . '</p>';
		return $html . '</div>';
	}

	/**
	 * One line of a value cell: the status and its button, centered on
	 * each other. They wrap on a narrow screen.
	 *
	 * @param string $status Status markup.
	 * @param string $action Button markup, or empty.
	 * @return string
	 */
	private static function row( $status, $action ) {
		return '<div class="happyaccess-ts-row">' . $status . $action . '</div>';
	}

	/**
	 * On or Off.
	 *
	 * @param bool $on Whether the method is on.
	 * @return string
	 */
	private static function status( $on ) {
		return self::state( $on ? __( 'On', 'happyaccess' ) : __( 'Off', 'happyaccess' ) );
	}

	/**
	 * A status in the one look all three rows share.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function state( $text ) {
		return '<span class="happyaccess-ts-state">' . esc_html( $text ) . '</span>';
	}

	/**
	 * The backup codes left, as plain text.
	 *
	 * @param int $left Codes left.
	 * @return string
	 */
	private static function codes_left( $left ) {
		return sprintf(
			/* translators: %d: backup codes left. */
			_n( '%d code left', '%d codes left', (int) $left, 'happyaccess' ),
			(int) $left
		);
	}

	/**
	 * A button the script handles.
	 *
	 * @param string $action    Action name.
	 * @param string $label     Label.
	 * @param string $css_class Classes.
	 * @return string
	 */
	private static function button( $action, $label, $css_class = 'button' ) {
		return '<button type="button" class="' . esc_attr( $css_class ) . '" data-happyaccess-action="' . esc_attr( $action ) . '">' . esc_html( $label ) . '</button>';
	}
}
