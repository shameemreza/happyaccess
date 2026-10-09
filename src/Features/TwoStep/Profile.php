<?php
/**
 * The two-step section on Profile and Edit User, and in My Account.
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
 *
 * MyAccount prints the same section for the user's own account, with
 * WooCommerce classes in place of the wp-admin table. There the panels
 * with a field are forms, which the script never submits. The elements the
 * script looks for stay the same in both layouts.
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
	 * Layouts of the section: the wp-admin profile table, or WooCommerce My Account.
	 */
	const LAYOUT_ADMIN   = 'admin';
	const LAYOUT_ACCOUNT = 'account';

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
	 * Where this user changes their two-step login: the My Account endpoint
	 * when WooCommerce keeps them out of wp-admin, else the profile section.
	 * WooCommerce is checked first, so MyAccount only loads with it.
	 *
	 * @param \WP_User $user The user.
	 * @return string
	 */
	public static function url_for( \WP_User $user ) {
		if ( class_exists( 'WooCommerce', false ) && MyAccount::uses_my_account( $user ) ) {
			return MyAccount::url();
		}
		return self::url();
	}

	/**
	 * A link to url_for(), named for where it goes.
	 *
	 * @param \WP_User $user The user.
	 * @return string
	 */
	public static function link_for( \WP_User $user ) {
		return '<a href="' . esc_url( self::url_for( $user ) ) . '">' . esc_html( self::link_label( $user ) ) . '</a>';
	}

	/**
	 * The plain text name of url_for(): your profile, or your account page.
	 *
	 * @param \WP_User $user The user.
	 * @return string
	 */
	public static function link_label( \WP_User $user ) {
		return self::url() === self::url_for( $user ) ? __( 'your profile', 'happyaccess' ) : __( 'your account page', 'happyaccess' );
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
	 * current user sees it. Empty when there is nothing for them. My Account
	 * only ever shows the user's own section.
	 *
	 * @param \WP_User $user   The user whose profile is shown.
	 * @param string   $layout LAYOUT_ADMIN or LAYOUT_ACCOUNT.
	 * @return string
	 */
	public static function section( \WP_User $user, $layout = self::LAYOUT_ADMIN ) {
		$viewer = get_current_user_id();
		if ( $viewer < 1 || Capabilities::is_temp_user( $viewer ) || Capabilities::is_temp_user( $user->ID ) ) {
			return '';
		}
		if ( $viewer === (int) $user->ID ) {
			return self::own_section( $user, self::ui( $layout ) );
		}
		if ( self::LAYOUT_ADMIN === $layout && self::can_reset_for( $user->ID ) ) {
			return self::admin_section( $user );
		}
		return '';
	}

	/**
	 * Whether the user's own section has anything to show: their role offers
	 * two-step login, or something is still set up that they can turn off.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function has_own_section( \WP_User $user ) {
		return RestController::is_offered( $user ) || UserState::is_enabled( $user->ID ) || BackupCodes::remaining( $user->ID ) > 0;
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
	 * Turns off another user's two-step login and logs it. The user gets an
	 * email only when they had a method or backup codes, not when the reset
	 * only cleared a re-check, grace counters or a lock.
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
		$used  = UserState::is_enabled( $user->ID ) || BackupCodes::remaining( $user->ID ) > 0;
		UserState::reset(
			$user->ID,
			array(
				'source'  => 'admin',
				'user_id' => (int) $admin->ID,
			)
		);
		if ( $used ) {
			Mailer::send(
				$user->user_email,
				__( 'Two-step login was turned off', 'happyaccess' ),
				'twostep-reset',
				array(
					'admin_name'  => $admin->display_name,
					'setup_label' => self::link_label( $user ),
					'setup_url'   => self::url_for( $user ),
				)
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
		echo wp_kses_post( self::pause_notice() );
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
		self::enqueue_own_assets();
	}

	/**
	 * The stylesheet, the QR library, the shared setup script and the
	 * section script with its settings, for the user's own section. The
	 * profile and My Account both load them through here.
	 *
	 * @return void
	 */
	public static function enqueue_own_assets() {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/twostep-setup.css', HAPPYACCESS_PLUGIN_FILE ), array(), SetupSteps::asset_version( 'assets/twostep-setup.css' ) );
		SetupSteps::register_qr();
		wp_register_script( SetupSteps::HANDLE, plugins_url( 'assets/twostep-setup.js', HAPPYACCESS_PLUGIN_FILE ), array( SetupSteps::QR_HANDLE ), SetupSteps::asset_version( 'assets/twostep-setup.js' ), true );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/twostep-profile.js', HAPPYACCESS_PLUGIN_FILE ), array( SetupSteps::HANDLE ), SetupSteps::asset_version( 'assets/twostep-profile.js' ), true );
		wp_add_inline_script( self::HANDLE, 'window.happyaccessTwoStepProfile = ' . wp_json_encode( self::script_config() ) . ';', 'before' );
	}

	/**
	 * Settings of the section script: the routes, a wp_rest nonce for the
	 * logged-in user and the messages.
	 *
	 * @return array
	 */
	public static function script_config() {
		return array(
			'restUrl' => esc_url_raw( rest_url( Routes::NS . RestController::BASE ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'strings' => array(
				'failed'       => __( "That didn't work. Try again.", 'happyaccess' ),
				'enterCode'    => __( 'Enter the code from the app.', 'happyaccess' ),
				'enterRecheck' => __( 'Enter your password or a code.', 'happyaccess' ),
				'scan'         => __( 'Scan the QR code, then enter the code from the app.', 'happyaccess' ),
				'emailSent'    => __( 'We sent a code to your email address.', 'happyaccess' ),
				'enterEmail'   => __( 'Enter the code from the email.', 'happyaccess' ),
				'codesShown'   => __( 'Your new backup codes are below.', 'happyaccess' ),
				'saving'       => __( 'Saving.', 'happyaccess' ),
			),
		);
	}

	/**
	 * The section on the user's own profile or account. Nothing when their
	 * role has two-step login off and nothing is set up.
	 *
	 * @param \WP_User $user The current user.
	 * @param array    $ui   Classes of the layout, from ui().
	 * @return string
	 */
	private static function own_section( \WP_User $user, array $ui ) {
		if ( ! self::has_own_section( $user ) ) {
			return '';
		}
		$account  = self::LAYOUT_ACCOUNT === $ui['layout'];
		$offered  = RestController::is_offered( $user );
		$app      = UserState::app_enabled( $user->ID );
		$email    = UserState::email_enabled( $user->ID );
		$left     = BackupCodes::remaining( $user->ID );
		$required = Enforcement::REQUIRED === Enforcement::policy( $user );

		if ( $account ) {
			// The endpoint title already names the section, so the box itself takes focus.
			$html = '<div id="' . esc_attr( self::ANCHOR ) . '" class="happyaccess-ts-profile happyaccess-ts-account" tabindex="-1" data-happyaccess-twostep>';
		} else {
			$html  = '<div class="happyaccess-ts-profile" data-happyaccess-twostep>';
			$html .= '<h2 id="' . esc_attr( self::ANCHOR ) . '" tabindex="-1">' . esc_html__( 'Two-step login', 'happyaccess' ) . '</h2>';
		}
		$html .= '<p>' . esc_html__( 'After your password, you also enter a code from your authenticator app or your email.', 'happyaccess' ) . '</p>';
		if ( $required ) {
			$html .= '<p><strong>' . esc_html__( 'Your role needs two-step login.', 'happyaccess' ) . '</strong></p>';
		}
		if ( UserState::secret_unreadable( $user->ID ) ) {
			$html .= '<div class="' . esc_attr( $ui['warning'] ) . '"><p>' . esc_html__( "Set up your authenticator app again. This site changed its security keys, so codes from the app can't be checked. Until then, log in with an email code or a backup code.", 'happyaccess' ) . '</p></div>';
		}
		$html .= '<div id="happyaccess-ts-profile-error" class="' . esc_attr( $ui['error'] ) . '" role="alert" hidden><p></p></div>';

		$html .= $account ? '<div class="happyaccess-ts-methods" data-happyaccess-methods>' : '<table class="form-table" role="presentation" data-happyaccess-methods><tbody>';

		$action = '';
		if ( $app && ! RestController::is_last_required( $user, 'app' ) ) {
			$action = self::button( 'app-disable', __( 'Turn off', 'happyaccess' ), $ui['button'] );
		} elseif ( ! $app && $offered ) {
			$action = self::button( 'app-begin', __( 'Set up', 'happyaccess' ), $ui['button'] );
		}
		$body = self::row( self::status( $app ), $action );
		if ( ! $app && $offered ) {
			$body .= self::app_panel( $ui );
		}
		$body .= self::help( $ui, __( 'Codes come from an app on your phone.', 'happyaccess' ) );
		$html .= self::method( $ui, __( 'Authenticator app', 'happyaccess' ), $body );

		$action = '';
		if ( $email && ! RestController::is_last_required( $user, 'email' ) ) {
			$action = self::button( 'email-disable', __( 'Turn off', 'happyaccess' ), $ui['button'] );
		} elseif ( ! $email && $offered ) {
			$action = self::button( 'email-begin', __( 'Turn on', 'happyaccess' ), $ui['button'] );
		}
		$body = self::row( self::status( $email ), $action );
		if ( ! $email && $offered ) {
			$body .= self::email_panel( $ui );
		}
		$body .= self::help( $ui, __( 'Codes go to the email address on your account.', 'happyaccess' ) );
		$html .= self::method( $ui, __( 'Email codes', 'happyaccess' ), $body );

		if ( $app || $email ) {
			$body  = self::row( self::state( self::codes_left( $left ) ), self::button( 'backup-regenerate', __( 'Make new codes', 'happyaccess' ), $ui['button'] ) );
			$body .= self::help( $ui, __( 'Making new codes stops the old ones from working.', 'happyaccess' ) );
		} else {
			$body = self::help( $ui, __( 'Turn on the app or email codes first.', 'happyaccess' ) );
		}
		$html .= self::method( $ui, __( 'Backup codes', 'happyaccess' ), $body );

		$html .= $account ? '</div>' : '</tbody></table>';
		$html .= self::codes_panel( $ui );
		$html .= self::recheck_panel( $ui );
		$html .= '<p class="screen-reader-text" role="status" data-happyaccess-live></p>';
		return $html . '</div>';
	}

	/**
	 * The classes of each layout. My Account follows the theme through
	 * WooCommerce's own classes, including the block theme button class.
	 *
	 * @param string $layout LAYOUT_ADMIN or LAYOUT_ACCOUNT.
	 * @return array<string,string>
	 */
	private static function ui( $layout ) {
		if ( self::LAYOUT_ACCOUNT !== $layout ) {
			return array(
				'layout'  => self::LAYOUT_ADMIN,
				'button'  => 'button',
				'primary' => 'button button-primary',
				'link'    => 'button-link',
				'input'   => 'regular-text',
				'help'    => 'description',
				'error'   => 'notice notice-error inline',
				'warning' => 'notice notice-warning inline',
				'field'   => '',
				'panel'   => 'div',
				'actions' => '',
			);
		}
		$theme  = function_exists( 'wc_wp_theme_get_element_class_name' ) ? (string) wc_wp_theme_get_element_class_name( 'button' ) : '';
		$button = trim( 'woocommerce-Button button ' . $theme );
		return array(
			'layout'  => self::LAYOUT_ACCOUNT,
			'button'  => $button,
			'primary' => $button . ' alt',
			'link'    => 'happyaccess-ts-link',
			'input'   => 'woocommerce-Input woocommerce-Input--text input-text',
			'help'    => 'happyaccess-ts-help',
			'error'   => 'woocommerce-error',
			'warning' => 'woocommerce-info',
			'field'   => 'woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide',
			'panel'   => 'form',
			'actions' => 'happyaccess-ts-actions',
		);
	}

	/**
	 * One method: a table row on the profile, a block with a heading in My Account.
	 *
	 * @param array  $ui    Classes of the layout.
	 * @param string $label Plain text name of the method.
	 * @param string $body  Markup of the value.
	 * @return string
	 */
	private static function method( array $ui, $label, $body ) {
		if ( self::LAYOUT_ACCOUNT === $ui['layout'] ) {
			return '<div class="happyaccess-ts-method"><h3 class="happyaccess-ts-label">' . esc_html( $label ) . '</h3>' . $body . '</div>';
		}
		return '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $body . '</td></tr>';
	}

	/**
	 * The help text under a method's status line.
	 *
	 * @param array  $ui   Classes of the layout.
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function help( array $ui, $text ) {
		return '<p class="' . esc_attr( $ui['help'] ) . '">' . esc_html( $text ) . '</p>';
	}

	/**
	 * The opening of a paragraph with a label and its field.
	 *
	 * @param array $ui Classes of the layout.
	 * @return string
	 */
	private static function field_open( array $ui ) {
		return '' === $ui['field'] ? '<p>' : '<p class="' . esc_attr( $ui['field'] ) . '">';
	}

	/**
	 * What goes between a label and its field: a line break on the profile.
	 * WooCommerce's form rows put the field on its own line.
	 *
	 * @param array $ui Classes of the layout.
	 * @return string
	 */
	private static function field_break( array $ui ) {
		return self::LAYOUT_ACCOUNT === $ui['layout'] ? '' : '<br />';
	}

	/**
	 * The opening of a panel with a field. In My Account it is a form,
	 * because WooCommerce styles a form row's label and field only inside
	 * one; the script stops it from submitting. On the profile it is a div,
	 * since the section already sits inside the profile form.
	 *
	 * @param array  $ui   Classes of the layout.
	 * @param string $name Panel name.
	 * @return string
	 */
	private static function panel_open( array $ui, $name ) {
		return '<' . $ui['panel'] . ' class="happyaccess-ts-panel" data-happyaccess-panel="' . esc_attr( $name ) . '" hidden>';
	}

	/**
	 * The closing of a panel opened by panel_open().
	 *
	 * @param array $ui Classes of the layout.
	 * @return string
	 */
	private static function panel_close( array $ui ) {
		return '</' . $ui['panel'] . '>';
	}

	/**
	 * The opening of a panel's button row. My Account spaces the buttons
	 * through its class; the profile keeps core's spacing.
	 *
	 * @param array $ui Classes of the layout.
	 * @return string
	 */
	private static function actions_open( array $ui ) {
		return '' === $ui['actions'] ? '<p>' : '<p class="' . esc_attr( $ui['actions'] ) . '">';
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
			$html                .= '<p class="description">' . esc_html__( 'Use this when they lost their phone or their backup codes. They get an email, and they can set it up again from their account.', 'happyaccess' ) . '</p>';
			$html                .= '</td></tr>';
		}
		return $html . '</tbody></table></div>';
	}

	/**
	 * The app setup panel, filled in by the script after app/begin.
	 *
	 * @param array $ui Classes of the layout.
	 * @return string
	 */
	private static function app_panel( array $ui ) {
		$html  = self::panel_open( $ui, 'app' );
		$html .= '<p>' . esc_html__( 'Scan this QR code with your authenticator app.', 'happyaccess' ) . '</p>';
		$html .= SetupSteps::qr_and_key( '', '' );
		$html .= self::field_open( $ui ) . '<label for="happyaccess-ts-profile-code">' . esc_html__( 'Code from the app', 'happyaccess' ) . '</label>' . self::field_break( $ui );
		$html .= SetupSteps::code_input( 'happyaccess-ts-profile-code', '', false, $ui['input'] . ' happyaccess-ts-code' ) . '</p>';
		$html .= self::actions_open( $ui ) . self::button( 'app-confirm', __( 'Turn on two-step login', 'happyaccess' ), $ui['primary'], true );
		$html .= ' ' . self::button( 'cancel', __( 'Cancel', 'happyaccess' ), $ui['link'] ) . '</p>';
		return $html . self::panel_close( $ui );
	}

	/**
	 * The email codes panel, shown once a code is on its way. Email codes
	 * turn on only when that code comes back, so an address that gets no
	 * mail is never turned on.
	 *
	 * @param array $ui Classes of the layout.
	 * @return string
	 */
	private static function email_panel( array $ui ) {
		$html  = self::panel_open( $ui, 'email' );
		$html .= '<p>' . esc_html__( 'We sent a code to the email address on your account. Enter it to turn on email codes.', 'happyaccess' ) . '</p>';
		$html .= self::field_open( $ui ) . '<label for="happyaccess-ts-profile-email-code">' . esc_html__( 'Code from the email', 'happyaccess' ) . '</label>' . self::field_break( $ui );
		$html .= SetupSteps::code_input( 'happyaccess-ts-profile-email-code', '', false, $ui['input'] . ' happyaccess-ts-code' ) . '</p>';
		$html .= self::actions_open( $ui ) . self::button( 'email-confirm', __( 'Turn on email codes', 'happyaccess' ), $ui['primary'], true );
		$html .= ' ' . self::button( 'email-begin', __( 'Send a new code', 'happyaccess' ), $ui['link'] );
		$html .= ' ' . self::button( 'cancel', __( 'Cancel', 'happyaccess' ), $ui['link'] ) . '</p>';
		return $html . self::panel_close( $ui );
	}

	/**
	 * The backup codes panel, filled in by the script after a change that
	 * made new codes. Done reloads the page.
	 *
	 * @param array $ui Classes of the layout.
	 * @return string
	 */
	private static function codes_panel( array $ui ) {
		$html  = '<div class="happyaccess-ts-panel" data-happyaccess-panel="codes" hidden>';
		$html .= SetupSteps::codes_block( array(), false, 'h3', $ui['button'] );
		$html .= '<p><button type="button" id="happyaccess-ts-continue" class="' . esc_attr( $ui['primary'] ) . '" data-happyaccess-action="done" data-happyaccess-primary>' . esc_html__( 'Done', 'happyaccess' ) . '</button></p>';
		return $html . '</div>';
	}

	/**
	 * The re-check panel, shown when a change needs it.
	 *
	 * @param array $ui Classes of the layout.
	 * @return string
	 */
	private static function recheck_panel( array $ui ) {
		$html  = self::panel_open( $ui, 'recheck' );
		$html .= '<p>' . esc_html__( "Confirm it's you to change two-step login.", 'happyaccess' ) . '</p>';
		$html .= self::field_open( $ui ) . '<label for="happyaccess-ts-recheck" data-label-password="' . esc_attr__( 'Current password', 'happyaccess' ) . '" data-label-code="' . esc_attr__( 'Code from your app, or a backup code', 'happyaccess' ) . '">' . esc_html__( 'Current password', 'happyaccess' ) . '</label>' . self::field_break( $ui );
		$html .= '<input type="password" id="happyaccess-ts-recheck" class="' . esc_attr( $ui['input'] ) . '" value="" autocomplete="current-password" spellcheck="false" /></p>';
		$html .= self::actions_open( $ui ) . self::button( 'recheck', __( 'Confirm', 'happyaccess' ), $ui['primary'], true );
		$html .= ' <button type="button" class="' . esc_attr( $ui['link'] ) . '" data-happyaccess-action="recheck-mode" data-label-password="' . esc_attr__( 'Use a code instead', 'happyaccess' ) . '" data-label-code="' . esc_attr__( 'Use your password instead', 'happyaccess' ) . '">' . esc_html__( 'Use a code instead', 'happyaccess' ) . '</button>';
		$html .= ' ' . self::button( 'cancel', __( 'Cancel', 'happyaccess' ), $ui['link'] ) . '</p>';
		return $html . self::panel_close( $ui );
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
	 * A button the script handles. Enter in a panel's field runs its primary button.
	 *
	 * @param string $action    Action name.
	 * @param string $label     Label.
	 * @param string $css_class Classes.
	 * @param bool   $primary   Whether it is the panel's primary button.
	 * @return string
	 */
	private static function button( $action, $label, $css_class = 'button', $primary = false ) {
		return '<button type="button" class="' . esc_attr( $css_class ) . '" data-happyaccess-action="' . esc_attr( $action ) . '"' . ( $primary ? ' data-happyaccess-primary' : '' ) . '>' . esc_html( $label ) . '</button>';
	}
}
