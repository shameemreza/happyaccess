<?php
/**
 * The HappyAccess admin page under Users.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Admin;

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
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
	}

	/**
	 * Adds the page under Users, for people who can manage HappyAccess.
	 *
	 * @return void
	 */
	public static function add_menu() {
		$hook = add_users_page(
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
	 * settings, grants, catalog and activity responses never hold one.
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

		return array(
			'/happyaccess/v1/settings',
			'/happyaccess/v1/grants',
			'/happyaccess/v1/catalog',
			$activity,
		);
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
			'siteName'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'homeUrl'     => home_url( '/' ),
			'loginUrl'    => wp_login_url(),
			'codeUrl'     => Router::url( 'code' ),
			'adminUrl'    => admin_url( 'users.php?page=' . self::SLUG ),
			'currentUser' => array(
				'id'   => (int) $user->ID,
				'name' => $user->display_name,
			),
			'timezone'    => wp_timezone_string(),
			'features'    => (array) Settings::get( 'features' ),
			'needsSetup'  => SettingsController::needs_setup(),
			'menus'       => MenuGuard::menu_snapshot(),
			'maxDays'     => self::MAX_DAYS,
			'isMultisite' => is_multisite(),
			'roles'       => self::roles(),
		);
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
	 * Path of the asset file the build writes.
	 *
	 * @return string
	 */
	private static function asset_file() {
		return HAPPYACCESS_PLUGIN_DIR . 'build/index.asset.php';
	}
}
