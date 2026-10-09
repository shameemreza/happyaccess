<?php
/**
 * Records what a temporary support user changes.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Internal;
use HappyAccess\Login\Session;

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
	 * Longest title or name stored in a summary.
	 */
	const MAX_NAME = 150;

	/**
	 * Option names changed during this request, grouped by grant. Each group
	 * holds the user id, the grant id and the names in the order they changed.
	 *
	 * @var array
	 */
	private static $options = array();

	/**
	 * Role events waiting for the shutdown flush, keyed by event, user and
	 * role. A role change fires several hooks for one real change, so they
	 * are merged here before anything is written.
	 *
	 * @var array
	 */
	private static $role_events = array();

	/**
	 * Posts that got a created or updated row in this request, with the hook
	 * that wrote it: 'post' for transition_post_status, 'product' for
	 * WooCommerce's product save. The product edit form fires both for one
	 * change, so the second one is skipped.
	 *
	 * @var array<int,string>
	 */
	private static $saved_posts = array();

	/**
	 * How many quietly() calls are open right now.
	 *
	 * @var int
	 */
	private static $quiet = 0;

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
		add_action( 'woocommerce_update_product', array( __CLASS__, 'product_saved' ), 10, 1 );
		add_action( 'wp_trash_post', array( __CLASS__, 'post_trashed' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'post_deleted' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_status_changed' ), 10, 3 );
		add_action( 'profile_update', array( __CLASS__, 'user_updated' ), 10, 1 );
		add_action( 'user_register', array( __CLASS__, 'user_created' ), 10, 2 );
		add_action( 'set_user_role', array( __CLASS__, 'user_role_changed' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'user_role_added' ), 10, 2 );
		add_action( 'wp_privacy_personal_data_erased', array( __CLASS__, 'privacy_erased' ), 10, 1 );
		add_action( 'woocommerce_new_webhook', array( __CLASS__, 'wc_webhook_created' ), 10, 1 );

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
		if ( ! self::is_tracking() ) {
			return;
		}

		self::record( 'plugin_activated', 'Activated plugin: ' . self::plugin_name( $plugin ), array( 'plugin' => $plugin ) );
	}

	/**
	 * Logs a plugin deactivation.
	 *
	 * @param string $plugin Plugin file relative to the plugins folder.
	 * @return void
	 */
	public static function plugin_deactivated( $plugin ) {
		if ( ! self::is_tracking() ) {
			return;
		}

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
		if ( ! self::is_tracking() ) {
			return;
		}
		if ( ! $deleted ) {
			return;
		}
		self::record( 'plugin_deleted', 'Deleted plugin: ' . self::clip( $plugin ), array( 'plugin' => $plugin ) );
	}

	/**
	 * Logs a plugin or theme install or update.
	 *
	 * @param object $upgrader   Upgrader that ran.
	 * @param array  $hook_extra Type, action and the items touched.
	 * @return void
	 */
	public static function upgrader_ran( $upgrader, $hook_extra ) {
		if ( ! self::is_tracking() ) {
			return;
		}
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) ) {
			return;
		}
		if ( 'core' === $hook_extra['type'] ) {
			self::record( 'upgrader_ran', 'Updated WordPress core', array( 'type' => 'core' ) );
			return;
		}
		if ( 'translation' === $hook_extra['type'] ) {
			self::record( 'upgrader_ran', 'Updated translations', array( 'type' => 'translation' ) );
			return;
		}
		if ( ! in_array( $hook_extra['type'], array( 'plugin', 'theme' ), true ) ) {
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
					$items[] = self::clip( (string) $theme->get( 'Name' ) );
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
		if ( ! self::is_tracking() ) {
			return;
		}

		self::record( 'theme_switched', 'Switched theme to ' . self::clip( $new_name ) );
	}

	/**
	 * Logs a theme deletion.
	 *
	 * @param string $stylesheet Theme folder name.
	 * @param bool   $deleted    Whether the delete worked.
	 * @return void
	 */
	public static function theme_deleted( $stylesheet, $deleted = true ) {
		if ( ! self::is_tracking() ) {
			return;
		}
		if ( ! $deleted ) {
			return;
		}
		self::record( 'theme_deleted', 'Deleted theme: ' . self::clip( $stylesheet ), array( 'theme' => $stylesheet ) );
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
		if ( ! self::is_tracking() ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || ! self::is_tracked_post( $post ) ) {
			return;
		}
		if ( in_array( $new_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return;
		}

		$fresh = in_array( $old_status, array( 'new', 'auto-draft' ), true );
		if ( ! $fresh && isset( self::$saved_posts[ $post->ID ] ) && 'product' === self::$saved_posts[ $post->ID ] ) {
			return;
		}
		self::$saved_posts[ $post->ID ] = 'post';
		self::record_post( $fresh ? 'post_created' : 'post_updated', $fresh ? 'Created' : 'Updated', $post );
	}

	/**
	 * Logs a product saved through WooCommerce's CRUD, as the REST API and
	 * the product block editor do. When only prices, stock or other meta
	 * change, WooCommerce skips wp_update_post(), so transition_post_status
	 * never fires. Skipped when this request already logged the post.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public static function product_saved( $product_id ) {
		if ( ! self::is_tracking() ) {
			return;
		}
		$post = get_post( (int) $product_id );
		if ( ! $post instanceof \WP_Post || ! self::is_tracked_post( $post ) || isset( self::$saved_posts[ $post->ID ] ) ) {
			return;
		}
		self::$saved_posts[ $post->ID ] = 'product';
		self::record_post( 'post_updated', 'Updated', $post );
	}

	/**
	 * Logs a post moved to the trash.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function post_trashed( $post_id ) {
		if ( ! self::is_tracking() ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_tracked_post( $post ) ) {
			return;
		}
		self::record_post( 'post_trashed', 'Trashed', $post );
	}

	/**
	 * Logs a permanent delete, also for a post that was already in the trash.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function post_deleted( $post_id ) {
		if ( ! self::is_tracking() ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_tracked_post( $post ) ) {
			return;
		}
		self::record_post( 'post_deleted', 'Deleted', $post );
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
		if ( ! self::is_tracking() ) {
			return;
		}

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
	 * Logs a new user. Roles come from the user data when it carries a role,
	 * and from the stored user otherwise, because the roles may not be set
	 * yet when the hook fires.
	 *
	 * @param int   $user_id  Id of the new user.
	 * @param array $userdata Data passed to wp_insert_user().
	 * @return void
	 */
	public static function user_created( $user_id, $userdata = array() ) {
		if ( ! self::is_tracking() ) {
			return;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}
		self::drop_role_events( (int) $user_id );
		if ( is_array( $userdata ) && array_key_exists( 'role', $userdata ) ) {
			$roles = '' === (string) $userdata['role'] ? array() : array( (string) $userdata['role'] );
		} else {
			$roles = (array) $user->roles;
		}
		self::record(
			'user_created',
			sprintf(
				/* translators: 1: user login, 2: comma separated role slugs. */
				__( 'Created user: %1$s (%2$s)', 'happyaccess' ),
				$user->user_login,
				self::role_list( $roles )
			),
			array(
				'user_id' => (int) $user_id,
				'roles'   => array_values( $roles ),
			)
		);
	}

	/**
	 * Logs a role being set on a user.
	 *
	 * @param int      $user_id   Id of the user.
	 * @param string   $role      New role.
	 * @param string[] $old_roles Roles before the change.
	 * @return void
	 */
	public static function user_role_changed( $user_id, $role, $old_roles = array() ) {
		if ( ! self::is_tracking() ) {
			return;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}
		$old = array_values( array_filter( (array) $old_roles, 'strlen' ) );
		$new = '' === (string) $role ? array() : array( (string) $role );

		// The add hook for this role already fired. This entry replaces it.
		unset( self::$role_events[ self::role_key( 'user_role_added', (int) $user_id, (string) $role ) ] );
		self::buffer_role_event(
			'user_role_changed',
			(int) $user_id,
			(string) $role,
			sprintf(
				/* translators: 1: user login, 2: old role slugs, 3: new role slug. */
				__( 'Changed role for %1$s: %2$s to %3$s', 'happyaccess' ),
				$user->user_login,
				self::role_list( $old ),
				self::role_list( $new )
			),
			array(
				'user_id'   => (int) $user_id,
				'old_roles' => $old,
				'new_roles' => $new,
			)
		);
	}

	/**
	 * Logs a role being added to a user.
	 *
	 * @param int    $user_id Id of the user.
	 * @param string $role    Role added.
	 * @return void
	 */
	public static function user_role_added( $user_id, $role ) {
		if ( ! self::is_tracking() ) {
			return;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}
		self::buffer_role_event(
			'user_role_added',
			(int) $user_id,
			(string) $role,
			sprintf(
				/* translators: 1: user login, 2: role slug. */
				__( 'Added role to %1$s: %2$s', 'happyaccess' ),
				$user->user_login,
				self::role_list( array( (string) $role ) )
			),
			array(
				'user_id' => (int) $user_id,
				'role'    => (string) $role,
			)
		);
	}

	/**
	 * Logs a personal data erasure run. Temp users cannot reach this, so an
	 * entry here means a guard failed.
	 *
	 * @param int $request_id Privacy request id.
	 * @return void
	 */
	public static function privacy_erased( $request_id ) {
		if ( ! self::is_tracking() ) {
			return;
		}
		self::record(
			'privacy_erased',
			sprintf(
				/* translators: %d: privacy request id. */
				__( 'Ran a personal data erasure (#%d)', 'happyaccess' ),
				(int) $request_id
			),
			array( 'request_id' => (int) $request_id )
		);
	}

	/**
	 * Logs a WooCommerce webhook being created.
	 *
	 * @param int $webhook_id Webhook id.
	 * @return void
	 */
	public static function wc_webhook_created( $webhook_id ) {
		if ( ! self::is_tracking() ) {
			return;
		}
		self::record(
			'wc_webhook_created',
			sprintf(
				/* translators: %d: webhook id. */
				__( 'Created WooCommerce webhook #%d', 'happyaccess' ),
				(int) $webhook_id
			),
			array( 'webhook_id' => (int) $webhook_id )
		);
	}

	/**
	 * Remembers the name of an option that changed, with the temp user who
	 * changed it. The value is never seen.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function collect_option( $option ) {
		if ( ! self::is_tracking() || self::is_front_end_page_view() ) {
			return;
		}
		if ( ! is_string( $option ) || self::is_skipped_option( $option ) ) {
			return;
		}
		$user_id  = get_current_user_id();
		$grant_id = Capabilities::grant_id( $user_id );
		if ( ! isset( self::$options[ $grant_id ] ) ) {
			self::$options[ $grant_id ] = array(
				'user_id' => $user_id,
				'names'   => array(),
			);
		}
		if ( ! in_array( $option, self::$options[ $grant_id ]['names'], true ) ) {
			self::$options[ $grant_id ]['names'][] = $option;
		}
	}

	/**
	 * Writes the collected role events, then the option names as one entry per
	 * grant, and empties both lists. Uses the user and grant stored when each option changed.
	 *
	 * @return void
	 */
	public static function flush() {
		$groups            = self::$options;
		$roles             = self::$role_events;
		self::$options     = array();
		self::$role_events = array();
		self::$saved_posts = array();

		foreach ( $roles as $entry ) {
			$event = $entry['event'];
			AuditLog::add(
				$event,
				array(
					'feature'  => 'support',
					'token_id' => (int) $entry['grant_id'],
					'user_id'  => (int) $entry['actor'],
					'summary'  => $entry['summary'],
					'meta'     => $entry['meta'],
				)
			);
		}

		foreach ( $groups as $grant_id => $group ) {
			$count = count( $group['names'] );
			AuditLog::add(
				'settings_saved',
				array(
					'feature'  => 'support',
					'token_id' => (int) $grant_id,
					'user_id'  => (int) $group['user_id'],
					/* translators: %d: number of options saved. */
					'summary'  => sprintf( _n( 'Saved settings: %d option', 'Saved settings: %d options', $count, 'happyaccess' ), $count ),
					'meta'     => array( 'options' => $group['names'] ),
				)
			);
		}
	}

	/**
	 * Empties the collected option names without writing them.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$options     = array();
		self::$role_events = array();
		self::$saved_posts = array();
		self::$quiet       = 0;
	}

	/**
	 * Runs a callable without logging anything it changes, and returns what
	 * it returns. For HappyAccess's own cleanup, which can run inside a temp
	 * user's request. The option guards stay on. The counter is restored
	 * even when the callable throws.
	 *
	 * @param callable $callback Work to run.
	 * @return mixed
	 */
	public static function quietly( callable $callback ) {
		++self::$quiet;
		try {
			return $callback();
		} finally {
			--self::$quiet;
		}
	}

	/**
	 * Whether the current change is a temp user's own, and so worth logging.
	 * Other watchers, such as AdminWatch, use the same rule.
	 *
	 * @return bool
	 */
	public static function tracking() {
		return self::is_tracking();
	}

	/**
	 * Whether the current user is a temp user, so their changes are logged.
	 * HappyAccess's own writes, run under Internal or quietly(), are never theirs.
	 *
	 * @return bool
	 */
	private static function is_tracking() {
		return 0 === self::$quiet && ! Internal::active() && Capabilities::is_temp_user( get_current_user_id() );
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
		$title = '' === trim( $post->post_title ) ? '(no title)' : self::clip( $post->post_title );

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
	 * is not a revision or an auto-draft.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function is_tracked_post( \WP_Post $post ) {
		if ( ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return false;
		}
		if ( 'auto-draft' === $post->post_status ) {
			return false;
		}
		return ! wp_is_post_revision( $post );
	}

	/**
	 * Whether this is a plain front-end page view. Core writes options there
	 * on its own, such as a block theme's theme_mods on the first visit, so
	 * those writes are not the temp user's. Form posts, the Customizer and
	 * wp-admin saves are not page views.
	 *
	 * @return bool
	 */
	private static function is_front_end_page_view() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || Session::is_rest_request() ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		return 'GET' === $method || 'HEAD' === $method;
	}

	/**
	 * Whether an option name is noise.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private static function is_skipped_option( $option ) {
		global $wpdb;
		if ( in_array( $option, self::SKIPPED_OPTIONS, true ) ) {
			return true;
		}
		// The role table has its own roles_changed event.
		if ( $wpdb->prefix . 'user_roles' === $option ) {
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
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) && false !== strpos( $plugin, '/' ) && 0 === validate_file( $plugin ) ) {
			// A plugin installed in this request isn't in the cached full list yet, but a read of its own folder is fresh.
			$plugins = array();
			foreach ( get_plugins( '/' . dirname( $plugin ) ) as $file => $data ) {
				$plugins[ dirname( $plugin ) . '/' . $file ] = $data;
			}
		}
		if ( ! empty( $plugins[ $plugin ]['Name'] ) ) {
			return self::clip( $plugins[ $plugin ]['Name'] );
		}
		return self::clip( $plugin );
	}

	/**
	 * Display name of a theme, falling back to its folder name.
	 *
	 * @param string $slug Theme folder name.
	 * @return string
	 */
	private static function theme_name( $slug ) {
		$theme = wp_get_theme( $slug );
		return self::clip( $theme->exists() ? (string) $theme->get( 'Name' ) : $slug );
	}

	/**
	 * Buffer key for one role event.
	 *
	 * @param string $event   Event key.
	 * @param int    $user_id Id of the user whose role changed.
	 * @param string $role    Role slug.
	 * @return string
	 */
	private static function role_key( $event, $user_id, $role ) {
		return $event . ':' . $user_id . ':' . $role;
	}

	/**
	 * Holds a role event until the flush, with the temp user who caused it.
	 *
	 * @param string $event   Event key.
	 * @param int    $target  Id of the user whose role changed.
	 * @param string $role    Role slug.
	 * @param string $summary Readable summary.
	 * @param array  $meta    Names and ids only.
	 * @return void
	 */
	private static function buffer_role_event( $event, $target, $role, $summary, array $meta ) {
		$key                       = self::role_key( $event, $target, $role );
		$actor                     = get_current_user_id();
		self::$role_events[ $key ] = array(
			'target'   => $target,
			'actor'    => $actor,
			'grant_id' => Capabilities::grant_id( $actor ),
			'event'    => $event,
			'summary'  => $summary,
			'meta'     => $meta,
		);
	}

	/**
	 * Forgets the buffered role events of a user. A new user's roles are
	 * already in the created entry.
	 *
	 * @param int $user_id Id of the user.
	 * @return void
	 */
	private static function drop_role_events( $user_id ) {
		foreach ( self::$role_events as $key => $entry ) {
			if ( $user_id === $entry['target'] ) {
				unset( self::$role_events[ $key ] );
			}
		}
	}

	/**
	 * Role slugs joined with commas, or "none" for an empty list.
	 *
	 * @param string[] $roles Role slugs.
	 * @return string
	 */
	private static function role_list( array $roles ) {
		$roles = array_filter( array_map( 'strval', $roles ), 'strlen' );
		return $roles ? self::clip( implode( ', ', $roles ) ) : __( 'none', 'happyaccess' );
	}

	/**
	 * Cuts a title or name to MAX_NAME characters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clip( $text ) {
		$text = (string) $text;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_NAME ) : substr( $text, 0, self::MAX_NAME );
	}
}
