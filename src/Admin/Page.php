<?php
/**
 * The HappyAccess admin page under Users.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Admin;

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Features;
use HappyAccess\Core\OtherTwoFactor;
use HappyAccess\Core\Settings;
use HappyAccess\Features\SupportAccess\MenuGuard;
use HappyAccess\Login\Router;
use HappyAccess\Rest\SettingsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu, loads the React app on our screen only and prints
 * the boot data the app reads on load.
 */
final class Page {

	const SLUG = 'happyaccess';

	const HANDLE = 'happyaccess-admin';

	const MAX_DAYS = 30;

	/**
	 * Rows per page the Activity tab asks for. Keep in step with PER_PAGE in
	 * admin-app/src/activity/activityFormat.js, or the preloaded page misses.
	 */
	const ACTIVITY_PER_PAGE = 25;

	/**
	 * Days the Activity tab shows by default, today included.
	 */
	const ACTIVITY_DEFAULT_DAYS = 7;

	/**
	 * Hook suffix returned by add_users_page().
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Hooks the menu and the asset loading.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'lock_notice' ) );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );
	}

	/**
	 * After the admin bar Emergency lock, says how many passes it ended. The
	 * count comes from the happyaccess_locked query arg, which core then drops
	 * from the address bar, so a reload doesn't show the notice again.
	 *
	 * @return void
	 */
	public static function lock_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only count for a notice; the lock itself checked its nonce.
		$raw = isset( $_GET['happyaccess_locked'] ) ? sanitize_text_field( wp_unslash( $_GET['happyaccess_locked'] ) ) : '';
		if ( '' === $raw || ! ctype_digit( $raw ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$count = (int) $raw;
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of support passes ended. */
					_n( 'Emergency lock ended %d support pass.', 'Emergency lock ended %d support passes.', $count, 'happyaccess' ),
					$count
				)
			)
		);
	}

	/**
	 * Adds happyaccess_locked to the query args core removes after load.
	 *
	 * @param array $args Removable query args.
	 * @return array
	 */
	public static function removable_query_args( $args ) {
		$args[] = 'happyaccess_locked';
		return $args;
	}

	/**
	 * Adds the page under Users, for people who can manage HappyAccess.
	 *
	 * Core shows the Users menu to anyone with list_users, but add_users_page()
	 * picks its parent by edit_users. A site admin on a network lacks edit_users,
	 * so the page would land under a Profile menu they never see.
	 *
	 * @return void
	 */
	public static function add_menu() {
		$hook = add_submenu_page(
			current_user_can( 'list_users' ) ? 'users.php' : 'profile.php',
			__( 'HappyAccess', 'happyaccess' ),
			__( 'HappyAccess', 'happyaccess' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( __CLASS__, 'render' )
		);

		self::$hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * The screen hook suffix, or an empty string when the menu was not added.
	 *
	 * @return string
	 */
	public static function hook_suffix() {
		return self::$hook_suffix;
	}

	/**
	 * Loads the bundle and stylesheet on our screen and nowhere else.
	 *
	 * @param string $hook Hook suffix of the current admin screen.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( '' === self::$hook_suffix || self::$hook_suffix !== $hook ) {
			return;
		}

		$asset_file = self::asset_file();
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;
		if ( ! is_array( $asset ) ) {
			return;
		}

		$dependencies = isset( $asset['dependencies'] ) ? (array) $asset['dependencies'] : array();
		$dependencies = array_values( array_unique( array_merge( $dependencies, array( 'wp-date' ) ) ) );
		$version      = isset( $asset['version'] ) ? $asset['version'] : HAPPYACCESS_VERSION;

		wp_register_script( self::HANDLE, HAPPYACCESS_PLUGIN_URL . 'build/index.js', $dependencies, $version, true );
		wp_register_style( self::HANDLE, HAPPYACCESS_PLUGIN_URL . 'build/style-index.css', array( 'wp-components' ), $version );

		wp_set_script_translations( self::HANDLE, 'happyaccess', HAPPYACCESS_PLUGIN_DIR . 'languages' );
		wp_add_inline_script(
			self::HANDLE,
			'window.happyaccessBoot = ' . wp_json_encode( self::boot_data() ) . ';',
			'before'
		);

		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );

		self::print_preload();
	}

	/**
	 * Answers the app's first GET requests from the page, so the first view
	 * needs no round trip. Nothing here carries a code, key or hash: the
	 * settings, grants, catalog, activity and coverage responses never hold one.
	 *
	 * @return void
	 */
	private static function print_preload() {
		$paths = self::preload_paths();
		if ( array() === $paths ) {
			return;
		}

		$preload = array_reduce( $paths, 'rest_preload_api_request', array() );
		if ( empty( $preload ) ) {
			return;
		}

		wp_add_inline_script(
			'wp-api-fetch',
			sprintf(
				'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );',
				wp_json_encode( $preload )
			),
			'after'
		);
	}

	/**
	 * REST paths the app requests first. Each must match what api.js sends,
	 * query string included. The preloading middleware sorts the query, and
	 * apiFetch adds _locale later, so neither belongs here.
	 *
	 * Nothing is preloaded before setup, since the setup screen reads none of it.
	 *
	 * @return string[]
	 */
	public static function preload_paths() {
		if ( SettingsController::needs_setup() ) {
			return array();
		}

		$today = ( new \DateTimeImmutable( '@' . Clock::now() ) )->setTimezone( wp_timezone() );
		$since = $today->modify( '-' . ( self::ACTIVITY_DEFAULT_DAYS - 1 ) . ' days' );

		$activity = add_query_arg(
			array(
				'since'    => $since->format( 'Y-m-d' ),
				'until'    => $today->format( 'Y-m-d' ),
				'page'     => 1,
				'per_page' => self::ACTIVITY_PER_PAGE,
			),
			'/happyaccess/v1/activity'
		);

		$paths = array(
			'/happyaccess/v1/settings',
			'/happyaccess/v1/grants',
			'/happyaccess/v1/catalog',
			$activity,
		);
		// The route exists only while two-step login is on. Counting can be
		// slow on a big store, so it runs here only when the page opens on
		// the Login and security tab; that tab loads it when opened later.
		if ( Features::is_enabled( 'two_step' ) && 'login' === self::start_tab() ) {
			$paths[] = '/happyaccess/v1/twostep/coverage';
		}
		return $paths;
	}

	/**
	 * The tab named in the page URL, or an empty string. The app may still
	 * open a remembered tab, which only the browser knows.
	 *
	 * @return string
	 */
	private static function start_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; picks what to preload.
		return isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	}

	/**
	 * Prints the mount point. A missing build gets a notice for developers.
	 *
	 * @return void
	 */
	public static function render() {
		echo '<div class="wrap"><hr class="wp-header-end"><div id="happyaccess-root" class="happyaccess-app"></div>';
		if ( ! is_readable( self::asset_file() ) ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'The HappyAccess admin app is not built yet. Run npm ci and npm run build in the plugin folder.', 'happyaccess' );
			echo '</p></div>';
		}
		echo '</div>';
	}

	/**
	 * Data the app needs on load. It carries no codes, keys, hashes, other
	 * people's email addresses or the reCAPTCHA secret.
	 *
	 * @return array
	 */
	public static function boot_data() {
		$user = wp_get_current_user();

		return array(
			'siteName'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'homeUrl'        => home_url( '/' ),
			'loginUrl'       => wp_login_url(),
			'codeUrl'        => Router::url( 'code' ),
			'adminUrl'       => admin_url( 'users.php?page=' . self::SLUG ),
			'currentUser'    => array(
				'id'   => (int) $user->ID,
				'name' => $user->display_name,
			),
			'timezone'       => wp_timezone_string(),
			'features'       => self::features(),
			'needsSetup'     => SettingsController::needs_setup(),
			'menus'          => MenuGuard::menu_snapshot(),
			'maxDays'        => self::MAX_DAYS,
			'isMultisite'    => is_multisite(),
			'roles'          => self::roles(),
			'loginRoles'     => self::login_roles(),
			'loginReady'     => true,
			'woocommerce'    => class_exists( 'WooCommerce' ),
			'otherTwoFactor' => OtherTwoFactor::plugin_active(),
			'otherTwoStep'   => OtherTwoFactor::active_plugins(),
			'twoStepNetwork' => self::two_step_network(),
		);
	}

	/**
	 * The feature switches that apply on this site. On a subsite of a
	 * network-active install the main site's two-step switch applies, so the
	 * Login and security tab shows what people really get.
	 *
	 * @return array<string,bool>
	 */
	private static function features() {
		$features = array();
		foreach ( Features::ALL as $feature ) {
			$features[ $feature ] = Features::is_enabled( $feature );
		}
		return $features;
	}

	/**
	 * How this network shares two-step login settings, for the notice on
	 * the Login and security tab: main when HappyAccess is network active and
	 * this isn't the main site, so the main site's settings apply; subdir on
	 * a subdirectory network without network activation, where a login on
	 * one site works on the others but each site has its own rules; else
	 * empty.
	 *
	 * @return string
	 */
	private static function two_step_network() {
		if ( ! is_multisite() ) {
			return '';
		}
		if ( Settings::network_active() ) {
			return is_main_site() ? '' : 'main';
		}
		return is_subdomain_install() ? '' : 'subdir';
	}

	/**
	 * Roles a protected pass can use instead of administrator, as slug and name.
	 *
	 * @return array
	 */
	private static function roles() {
		$roles = array();
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$roles[] = array(
				'slug' => (string) $slug,
				'name' => translate_user_role( $name ),
			);
		}
		return $roles;
	}

	/**
	 * Every role, for the Login tab: slug, name and whether the role can
	 * manage options. The login policy applies to every role, so this list is
	 * not limited to the roles the current user may edit.
	 *
	 * @return array
	 */
	private static function login_roles() {
		$roles = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps    = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			$roles[] = array(
				'slug'    => (string) $slug,
				'name'    => translate_user_role( $role['name'] ),
				'isAdmin' => ! empty( $caps['manage_options'] ),
			);
		}
		return $roles;
	}

	/**
	 * Path of the asset file the build writes.
	 *
	 * @return string
	 */
	private static function asset_file() {
		return HAPPYACCESS_PLUGIN_DIR . 'build/index.asset.php';
	}
}
