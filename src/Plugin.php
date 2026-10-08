<?php
/**
 * Boots the 1.1.0 code.
 *
 * @package HappyAccess
 */

namespace HappyAccess;

use HappyAccess\Admin\Page;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Cron;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Privacy;
use HappyAccess\Core\Uninstaller;
use HappyAccess\Features\Passwordless\Feature as PasswordlessFeature;
use HappyAccess\Features\SupportAccess\Feature;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Login\Router;
use HappyAccess\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every class. happyaccess.php calls boot() on every load.
 */
final class Plugin {

	/**
	 * Sites handled per page on a network-wide deactivation.
	 */
	const DEACTIVATE_BATCH = 50;

	/**
	 * Hooks the setup to plugins_loaded. Safe to call twice.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'plugins_loaded', array( self::class, 'init' ), 5 );
	}

	/**
	 * Registers each class, in order.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_initialize_site', array( Installer::class, 'on_new_site' ), 11 );
		add_action( Installer::NETWORK_HOOK, array( Installer::class, 'network_upgrade' ) );
		add_filter( 'wpmu_drop_tables', array( Uninstaller::class, 'drop_tables' ), 10, 2 );
		add_filter( 'plugin_action_links_' . HAPPYACCESS_PLUGIN_BASENAME, array( self::class, 'action_links' ) );
		Installer::maybe_upgrade();
		Capabilities::register();
		Cron::register();
		Privacy::register();
		// Hooked whether or not Support Access is on: with no steps added, the dispatcher sends visitors to the normal login.
		Router::register();
		Feature::register();
		PasswordlessFeature::register();
		Routes::register();
		Page::register();
	}

	/**
	 * Activation entry point.
	 *
	 * @param bool $network_wide Whether the plugin is network activated.
	 * @return void
	 */
	public static function activate( $network_wide ) {
		Installer::activate( $network_wide );
	}

	/**
	 * Adds a Settings link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return $links;
		}
		$settings = '<a href="' . esc_url( admin_url( 'users.php?page=happyaccess' ) ) . '">' . esc_html__( 'Settings', 'happyaccess' ) . '</a>';
		return array_merge( array( 'settings' => $settings ), (array) $links );
	}

	/**
	 * Deactivation entry point. Ends every pass and stops the scheduled
	 * events. Settings, logs and the tables stay for the next activation.
	 * A support person's own session can't switch the plugin off for the site.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated network wide.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( Capabilities::is_temp_user( get_current_user_id() ) ) {
			return;
		}

		if ( is_multisite() && $network_wide ) {
			$offset = 0;
			$more   = true;
			while ( $more ) {
				$site_ids = get_sites(
					array(
						'fields'  => 'ids',
						'number'  => self::DEACTIVATE_BATCH,
						'offset'  => $offset,
						'orderby' => 'id',
						'order'   => 'ASC',
					)
				);
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( (int) $site_id );
					try {
						self::end_passes_and_events();
					} finally {
						restore_current_blog();
					}
				}
				$offset += self::DEACTIVATE_BATCH;
				$more    = count( $site_ids ) === self::DEACTIVATE_BATCH;
			}
			return;
		}

		self::end_passes_and_events();
	}

	/**
	 * Revokes every pass of the current site and clears its scheduled events.
	 *
	 * @return void
	 */
	private static function end_passes_and_events() {
		if ( Installer::table_exists( 'tokens' ) ) {
			Grants::revoke_all( 'plugin_deactivated' );
		}
		Cron::unschedule();
		wp_clear_scheduled_hook( Installer::NETWORK_HOOK );
	}
}
