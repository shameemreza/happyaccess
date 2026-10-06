<?php
/**
 * Records what a temporary support user changes.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one readable log entry for each change a temp user makes: plugins,
 * themes, content, orders, users and settings. Titles, names and ids only.
 * Option values and post content are never stored.
 */
final class ActivityTracker {

	/**
	 * Post types that are tracked.
	 */
	const POST_TYPES = array( 'post', 'page', 'product' );

	/**
	 * Option names that churn on their own or are covered by another event.
	 */
	const SKIPPED_OPTIONS = array( 'rewrite_rules', 'active_plugins', 'recently_activated', 'uninstall_plugins' );

	/**
	 * Option name prefixes that are never reported.
	 */
	const SKIPPED_PREFIXES = array( '_transient', '_site_transient', 'cron', 'happyaccess_' );

	/**
	 * Option names changed during this request, in the order they changed.
	 *
	 * @var string[]
	 */
	private static $options = array();

	/**
	 * Hooks every tracked event, the option collectors and the shutdown flush.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'activated_plugin', array( __CLASS__, 'plugin_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'plugin_deactivated' ), 10, 1 );
		add_action( 'deleted_plugin', array( __CLASS__, 'plugin_deleted' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'upgrader_ran' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'theme_switched' ), 10, 1 );
		add_action( 'deleted_theme', array( __CLASS__, 'theme_deleted' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'post_transitioned' ), 10, 3 );
		add_action( 'wp_trash_post', array( __CLASS__, 'post_trashed' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_status_changed' ), 10, 4 );
		add_action( 'profile_update', array( __CLASS__, 'user_updated' ), 10, 1 );

		// One argument only, so option values never reach this class.
		add_action( 'updated_option', array( __CLASS__, 'collect_option' ), 10, 1 );
		add_action( 'added_option', array( __CLASS__, 'collect_option' ), 10, 1 );
		add_action( 'deleted_option', array( __CLASS__, 'collect_option' ), 10, 1 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Logs a plugin activation.
	 *
	 * @param string $plugin Plugin file relative to the plugins folder.
	 * @return void
	 */
	public static function plugin_activated( $plugin ) {
		self::record( 'plugin_activated', 'Activated plugin: ' . self::plugin_name( $plugin ), array( 'plugin' => $plugin ) );
	}

	/**
	 * Logs a plugin deactivation.
	 *
	 * @param string $plugin Plugin file relative to the plugins folder.
	 * @return void
	 */
	public static function plugin_deactivated( $plugin ) {
		self::record( 'plugin_deactivated', 'Deactivated plugin: ' . self::plugin_name( $plugin ), array( 'plugin' => $plugin ) );
	}

	/**
	 * Logs a plugin deletion. The files are gone, so only the file is named.
	 *
	 * @param string $plugin  Plugin file relative to the plugins folder.
	 * @param bool   $deleted Whether the delete worked.
	 * @return void
	 */
	public static function plugin_deleted( $plugin, $deleted = true ) {
		if ( ! $deleted ) {
			return;
		}
		self::record( 'plugin_deleted', 'Deleted plugin: ' . $plugin, array( 'plugin' => $plugin ) );
	}

	/**
	 * Logs a plugin or theme install or update.
	 *
	 * @param object $upgrader   Upgrader that ran.
	 * @param array  $hook_extra Type, action and the items touched.
	 * @return void
	 */
	public static function upgrader_ran( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) || ! in_array( $hook_extra['type'], array( 'plugin', 'theme' ), true ) ) {
			return;
		}
		if ( ! self::is_tracking() ) {
			return;
		}

		$type  = $hook_extra['type'];
		$items = array();
		if ( 'plugin' === $type ) {
			foreach ( self::listed( $hook_extra, 'plugins', 'plugin' ) as $file ) {
				$items[] = self::plugin_name( $file );
			}
			if ( ! $items && is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ) {
				$file = $upgrader->plugin_info();
				if ( is_string( $file ) && '' !== $file ) {
					$items[] = self::plugin_name( $file );
				}
			}
		} else {
			foreach ( self::listed( $hook_extra, 'themes', 'theme' ) as $slug ) {
				$items[] = self::theme_name( $slug );
			}
			if ( ! $items && is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ) {
				$theme = $upgrader->theme_info();
				if ( $theme instanceof \WP_Theme ) {
					$items[] = (string) $theme->get( 'Name' );
				}
			}
		}
		if ( ! $items ) {
			$items[] = '(unknown)';
		}

		self::record(
			'upgrader_ran',
			sprintf( 'Installed or updated %s: %s', $type, implode( ', ', $items ) ),
			array(
				'type'   => $type,
				'action' => isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '',
				'names'  => $items,
			)
		);
	}

	/**
	 * Logs a theme switch.
	 *
	 * @param string $new_name Name of the new theme.
	 * @return void
	 */
	public static function theme_switched( $new_name ) {
		self::record( 'theme_switched', 'Switched theme to ' . $new_name );
	}

	/**
	 * Logs a theme deletion.
	 *
	 * @param string $stylesheet Theme folder name.
	 * @param bool   $deleted    Whether the delete worked.
	 * @return void
	 */
	public static function theme_deleted( $stylesheet, $deleted = true ) {
		if ( ! $deleted ) {
			return;
		}
		self::record( 'theme_deleted', 'Deleted theme: ' . $stylesheet, array( 'theme' => $stylesheet ) );
	}

	/**
	 * Logs a post being created or updated. Trashing is logged by post_trashed().
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function post_transitioned( $new_status, $old_status, $post ) {
		if ( ! $post instanceof \WP_Post || ! self::is_tracked_post( $post ) ) {
			return;
		}
		if ( in_array( $new_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return;
		}

		$fresh = in_array( $old_status, array( 'new', 'auto-draft' ), true );
		self::record_post( $fresh ? 'post_created' : 'post_updated', $fresh ? 'Created' : 'Updated', $post );
	}

	/**
	 * Logs a post moved to the trash.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function post_trashed( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_tracked_post( $post ) ) {
			return;
		}
		self::record_post( 'post_trashed', 'Trashed', $post );
	}

	/**
	 * Logs an order status change. Works with any order storage, and needs
	 * no WooCommerce class.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 * @return void
	 */
	public static function order_status_changed( $order_id, $from, $to ) {
		self::record(
			'order_status_changed',
			sprintf( 'Order #%d: %s to %s', (int) $order_id, $from, $to ),
			array(
				'order_id' => (int) $order_id,
				'from'     => (string) $from,
				'to'       => (string) $to,
			)
		);
	}

	/**
	 * Logs a user profile change.
	 *
	 * @param int $user_id Id of the user that changed.
	 * @return void
	 */
	public static function user_updated( $user_id ) {
		if ( ! self::is_tracking() ) {
			return;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}
		self::record( 'user_updated', 'Updated user: ' . $user->user_login, array( 'user_id' => (int) $user_id ) );
	}

	/**
	 * Remembers the name of an option that changed. The value is never seen.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function collect_option( $option ) {
		if ( ! is_string( $option ) || self::is_skipped_option( $option ) || ! self::is_tracking() ) {
			return;
		}
		if ( ! in_array( $option, self::$options, true ) ) {
			self::$options[] = $option;
		}
	}

	/**
	 * Writes the collected option names as one entry, then empties the list.
	 *
	 * @return void
	 */
	public static function flush() {
		$options       = self::$options;
		self::$options = array();

		if ( ! $options || ! self::is_tracking() ) {
			return;
		}
		self::record(
			'settings_saved',
			sprintf( 'Saved settings: %d options', count( $options ) ),
			array( 'options' => $options )
		);
	}

	/**
	 * Whether the current user is a temp user, so their changes are logged.
	 *
	 * @return bool
	 */
	private static function is_tracking() {
		return Capabilities::is_temp_user( get_current_user_id() );
	}

	/**
	 * Adds one entry for the current temp user. Does nothing for anyone else.
	 *
	 * @param string $event   Event key.
	 * @param string $summary Readable summary.
	 * @param array  $meta    Names and ids only.
	 * @return void
	 */
	private static function record( $event, $summary, array $meta = array() ) {
		$user_id = get_current_user_id();
		if ( ! Capabilities::is_temp_user( $user_id ) ) {
			return;
		}
		AuditLog::add(
			$event,
			array(
				'feature'  => 'support',
				'token_id' => Capabilities::grant_id( $user_id ),
				'user_id'  => $user_id,
				'summary'  => $summary,
				'meta'     => $meta,
			)
		);
	}

	/**
	 * Logs a post entry as "{verb} {type label}: {title} (#ID)".
	 *
	 * @param string   $event Event key.
	 * @param string   $verb  Created, Updated or Trashed.
	 * @param \WP_Post $post  Post.
	 * @return void
	 */
	private static function record_post( $event, $verb, \WP_Post $post ) {
		$type  = get_post_type_object( $post->post_type );
		$label = ( $type && isset( $type->labels->singular_name ) ) ? $type->labels->singular_name : $post->post_type;
		$title = '' === trim( $post->post_title ) ? '(no title)' : $post->post_title;

		self::record(
			$event,
			sprintf( '%s %s: %s (#%d)', $verb, $label, $title, $post->ID ),
			array(
				'post_id'   => (int) $post->ID,
				'post_type' => $post->post_type,
			)
		);
	}

	/**
	 * Whether a post is one this tracker reports: a post, page or product that
	 * is not a revision and is not saved by autosave.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function is_tracked_post( \WP_Post $post ) {
		if ( ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		return ! wp_is_post_revision( $post );
	}

	/**
	 * Whether an option name is noise.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private static function is_skipped_option( $option ) {
		if ( in_array( $option, self::SKIPPED_OPTIONS, true ) ) {
			return true;
		}
		foreach ( self::SKIPPED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $option, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads the items an upgrader touched from its hook data.
	 *
	 * @param array  $hook_extra Hook data.
	 * @param string $many_key   Key holding a list, for bulk updates.
	 * @param string $one_key    Key holding one item.
	 * @return string[]
	 */
	private static function listed( array $hook_extra, $many_key, $one_key ) {
		if ( ! empty( $hook_extra[ $many_key ] ) && is_array( $hook_extra[ $many_key ] ) ) {
			return array_map( 'strval', $hook_extra[ $many_key ] );
		}
		if ( ! empty( $hook_extra[ $one_key ] ) && is_string( $hook_extra[ $one_key ] ) ) {
			return array( $hook_extra[ $one_key ] );
		}
		return array();
	}

	/**
	 * Display name of a plugin, falling back to its file.
	 *
	 * @param string $plugin Plugin file relative to the plugins folder.
	 * @return string
	 */
	private static function plugin_name( $plugin ) {
		$path = WP_PLUGIN_DIR . '/' . $plugin;
		if ( is_readable( $path ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$data = get_plugin_data( $path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}
		return $plugin;
	}

	/**
	 * Display name of a theme, falling back to its folder name.
	 *
	 * @param string $slug Theme folder name.
	 * @return string
	 */
	private static function theme_name( $slug ) {
		$theme = wp_get_theme( $slug );
		return $theme->exists() ? (string) $theme->get( 'Name' ) : $slug;
	}
}
