<?php
/**
 * Access guard for temporary users.
 *
 * Restricts what temporary users can see and do in the admin area:
 * - Admin menu filtering (hide specific menu items)
 * - Direct URL access blocking (prevent navigating to restricted pages)
 * - Admin bar visibility toggle
 * - Main admin and plugin self-protection
 *
 * @package HappyAccess
 * @since   1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access guard class.
 *
 * @since 1.1.0
 */
class HappyAccess_Access_Guard {

	/**
	 * Initialize access guard hooks.
	 *
	 * @since 1.1.0
	 */
	public static function init() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! HappyAccess_Temp_User::is_temp_user( $user_id ) ) {
			return;
		}

		// Menu restrictions.
		add_action( 'admin_menu', array( __CLASS__, 'filter_admin_menu' ), 9999 );
		add_action( 'current_screen', array( __CLASS__, 'block_restricted_screens' ) );

		// Hide admin bar if configured.
		if ( self::should_hide_admin_bar( $user_id ) ) {
			add_filter( 'show_admin_bar', '__return_false' );
		}

		// Protect main admin: hide from user list, block edits.
		add_filter( 'user_row_actions', array( __CLASS__, 'filter_user_row_actions' ), 10, 2 );
		add_filter( 'users_list_table_query_args', array( __CLASS__, 'hide_creator_from_user_list' ) );
		add_filter( 'all_plugins', array( __CLASS__, 'hide_happyaccess_from_plugins_list' ) );
		add_action( 'current_screen', array( __CLASS__, 'block_editing_creator' ) );

		// Block temp users from bulk-deleting the creator or self-promoting.
		add_filter( 'bulk_actions-users', array( __CLASS__, 'filter_user_bulk_actions' ) );
	}

	/**
	 * Get the restricted menu slugs for the current temp user.
	 *
	 * @since 1.1.0
	 * @param int $user_id User ID.
	 * @return array Array of restricted menu slugs.
	 */
	public static function get_restricted_menus( $user_id ) {
		$token_id = get_user_meta( $user_id, 'happyaccess_token_id', true );
		if ( ! $token_id ) {
			return array();
		}

		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$token = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
				"SELECT metadata FROM `$table` WHERE id = %d",
				$token_id
			),
			ARRAY_A
		);

		if ( ! $token || empty( $token['metadata'] ) ) {
			return array();
		}

		$metadata = json_decode( $token['metadata'], true );
		if ( ! is_array( $metadata ) || empty( $metadata['restricted_menus'] ) ) {
			return array();
		}

		return array_map( 'sanitize_text_field', $metadata['restricted_menus'] );
	}

	/**
	 * Filter admin menu for temp users.
	 *
	 * Handles both top-level menu slugs and submenu slugs stored as "parent::child".
	 *
	 * @since 1.1.0
	 */
	public static function filter_admin_menu() {
		$user_id          = get_current_user_id();
		$restricted_menus = self::get_restricted_menus( $user_id );

		if ( empty( $restricted_menus ) ) {
			return;
		}

		global $menu, $submenu;

		foreach ( $restricted_menus as $slug ) {
			if ( false !== strpos( $slug, '::' ) ) {
				$parts       = explode( '::', $slug, 2 );
				$parent_slug = $parts[0];
				$sub_slug    = $parts[1];
				remove_submenu_page( $parent_slug, $sub_slug );
			} else {
				remove_menu_page( $slug );
				if ( isset( $submenu[ $slug ] ) ) {
					unset( $submenu[ $slug ] );
				}
			}
		}
	}

	/**
	 * Block temp users from directly accessing restricted admin pages.
	 *
	 * Handles both top-level slugs and submenu slugs stored as "parent::child".
	 * Supports complex submenu slugs containing query strings (e.g. edit.php?post_type=product).
	 *
	 * @since 1.1.0
	 * @param WP_Screen $screen Current screen object.
	 */
	public static function block_restricted_screens( $screen ) {
		$user_id          = get_current_user_id();
		$restricted_menus = self::get_restricted_menus( $user_id );

		if ( empty( $restricted_menus ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check.
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

		$current_script = ! empty( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';

		foreach ( $restricted_menus as $slug ) {
			$check_slug = $slug;

			if ( false !== strpos( $slug, '::' ) ) {
				$parts      = explode( '::', $slug, 2 );
				$check_slug = $parts[1];
			}

			if ( self::does_slug_match_current_url( $check_slug, $page, $current_script ) ) {
				self::deny_access();
			}
		}
	}

	/**
	 * Check if a menu/submenu slug matches the current admin URL.
	 *
	 * Handles simple page slugs, PHP file slugs, and slugs with embedded query strings
	 * like "edit.php?post_type=product".
	 *
	 * @since 1.1.0
	 * @param string $slug           Menu slug to test.
	 * @param string $page           Current ?page= parameter.
	 * @param string $current_script Current pagenow (e.g. edit.php).
	 * @return bool
	 */
	private static function does_slug_match_current_url( $slug, $page, $current_script ) {
		if ( $page && $page === $slug ) {
			return true;
		}

		if ( $current_script && $current_script === $slug ) {
			return true;
		}

		if ( false !== strpos( $slug, '?' ) ) {
			$slug_parts = wp_parse_url( $slug );
			$slug_file  = isset( $slug_parts['path'] ) ? $slug_parts['path'] : '';
			$slug_query = isset( $slug_parts['query'] ) ? $slug_parts['query'] : '';

			if ( $slug_file && $current_script === $slug_file && ! empty( $slug_query ) ) {
				parse_str( $slug_query, $required_params );
				$all_match = true;
				foreach ( $required_params as $key => $value ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL parameter comparison.
					$actual = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
					if ( $actual !== $value ) {
						$all_match = false;
						break;
					}
				}
				return $all_match;
			}
		}

		return false;
	}

	/**
	 * Deny access and redirect to dashboard with a notice.
	 *
	 * @since 1.1.0
	 */
	private static function deny_access() {
		HappyAccess_Logger::log( 'access_blocked', array(
			'user_id'       => get_current_user_id(),
			'requested_url' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
		) );

		wp_safe_redirect( admin_url( '?happyaccess_restricted=1' ) );
		exit;
	}

	/**
	 * Whether to hide admin bar for the given temp user.
	 *
	 * @since 1.1.0
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function should_hide_admin_bar( $user_id ) {
		$token_id = get_user_meta( $user_id, 'happyaccess_token_id', true );
		if ( ! $token_id ) {
			return false;
		}

		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$token = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
				"SELECT metadata FROM `$table` WHERE id = %d",
				$token_id
			),
			ARRAY_A
		);

		if ( ! $token || empty( $token['metadata'] ) ) {
			return false;
		}

		$metadata = json_decode( $token['metadata'], true );

		return ! empty( $metadata['hide_admin_bar'] );
	}

	/**
	 * Hide the token creator from the user list for temp users.
	 *
	 * @since 1.1.0
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function hide_creator_from_user_list( $args ) {
		$user_id  = get_current_user_id();
		$token_id = get_user_meta( $user_id, 'happyaccess_token_id', true );

		if ( ! $token_id ) {
			return $args;
		}

		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$created_by = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
				"SELECT created_by FROM `$table` WHERE id = %d",
				$token_id
			)
		);

		if ( $created_by ) {
			$exclude = isset( $args['exclude'] ) ? (array) $args['exclude'] : array();
			$exclude[] = absint( $created_by );
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Excluding a single user ID (the token creator) from the users list table; negligible performance impact.
			$args['exclude'] = $exclude;
		}

		return $args;
	}

	/**
	 * Block temp users from editing the creator on user-edit.php.
	 *
	 * @since 1.1.0
	 * @param WP_Screen $screen Current screen.
	 */
	public static function block_editing_creator( $screen ) {
		if ( 'user-edit' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check.
		$edited_user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		if ( ! $edited_user_id ) {
			return;
		}

		$user_id  = get_current_user_id();
		$token_id = get_user_meta( $user_id, 'happyaccess_token_id', true );
		if ( ! $token_id ) {
			return;
		}

		global $wpdb;
		$table = esc_sql( $wpdb->prefix . 'happyaccess_tokens' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$created_by = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped and safe.
				"SELECT created_by FROM `$table` WHERE id = %d",
				$token_id
			)
		);

		if ( $created_by && absint( $created_by ) === $edited_user_id ) {
			self::deny_access();
		}
	}

	/**
	 * Filter user row actions to remove dangerous options for temp users viewing real admins.
	 *
	 * @since 1.1.0
	 * @param array   $actions Row actions.
	 * @param WP_User $user    User being displayed.
	 * @return array
	 */
	public static function filter_user_row_actions( $actions, $user ) {
		if ( ! HappyAccess_Temp_User::is_temp_user( get_current_user_id() ) ) {
			return $actions;
		}

		if ( ! HappyAccess_Temp_User::is_temp_user( $user->ID ) ) {
			unset( $actions['delete'] );
			unset( $actions['remove'] );
			unset( $actions['resetpassword'] );
		}

		return $actions;
	}

	/**
	 * Remove delete from bulk actions for temp users.
	 *
	 * @since 1.1.0
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function filter_user_bulk_actions( $actions ) {
		unset( $actions['delete'] );
		unset( $actions['remove'] );

		return $actions;
	}

	/**
	 * Hide HappyAccess plugin from the plugins list for temp users.
	 *
	 * @since 1.1.0
	 * @param array $plugins All plugins.
	 * @return array
	 */
	public static function hide_happyaccess_from_plugins_list( $plugins ) {
		unset( $plugins[ HAPPYACCESS_PLUGIN_BASENAME ] );

		return $plugins;
	}

	/**
	 * Check if a temp user's access is currently deactivated.
	 *
	 * @since 1.1.0
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_deactivated( $user_id ) {
		return (bool) get_user_meta( $user_id, 'happyaccess_deactivated', true );
	}

	/**
	 * Deactivate a temp user (block login without deleting).
	 *
	 * @since 1.1.0
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function deactivate_user( $user_id ) {
		if ( ! HappyAccess_Temp_User::is_temp_user( $user_id ) ) {
			return false;
		}

		update_user_meta( $user_id, 'happyaccess_deactivated', true );

		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();

		HappyAccess_Logger::log( 'temp_user_deactivated', array(
			'user_id'        => $user_id,
			'deactivated_by' => get_current_user_id(),
		) );

		return true;
	}

	/**
	 * Reactivate a previously deactivated temp user.
	 *
	 * @since 1.1.0
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function reactivate_user( $user_id ) {
		if ( ! HappyAccess_Temp_User::is_temp_user( $user_id ) ) {
			return false;
		}

		delete_user_meta( $user_id, 'happyaccess_deactivated' );

		HappyAccess_Logger::log( 'temp_user_reactivated', array(
			'user_id'        => $user_id,
			'reactivated_by' => get_current_user_id(),
		) );

		return true;
	}

	/**
	 * Get all admin menu and submenu items for the restriction picker.
	 *
	 * Returns a nested structure: top-level menus each containing their submenu children.
	 * Must be called during or after admin_menu has fired.
	 *
	 * @since 1.1.0
	 * @return array Array of menu items, each with slug, title, and children.
	 */
	public static function get_admin_menu_items() {
		global $menu, $submenu;

		$items = array();
		if ( empty( $menu ) ) {
			return $items;
		}

		$skip_slugs = array( 'happyaccess', 'index.php' );

		foreach ( $menu as $item ) {
			if ( empty( $item[0] ) || empty( $item[2] ) ) {
				continue;
			}

			$parent_slug = $item[2];

			if ( in_array( $parent_slug, $skip_slugs, true ) ) {
				continue;
			}

			$title = wp_strip_all_tags( $item[0] );
			$title = preg_replace( '/\s*\d+$/', '', $title );

			$children = array();
			if ( ! empty( $submenu[ $parent_slug ] ) && count( $submenu[ $parent_slug ] ) > 1 ) {
				foreach ( $submenu[ $parent_slug ] as $sub ) {
					if ( empty( $sub[0] ) || empty( $sub[2] ) ) {
						continue;
					}

					$sub_slug = $sub[2];
					if ( $sub_slug === $parent_slug ) {
						continue;
					}

					$sub_title = wp_strip_all_tags( $sub[0] );
					$sub_title = preg_replace( '/\s*\d+$/', '', $sub_title );

					$children[] = array(
						'slug'   => $parent_slug . '::' . $sub_slug,
						'title'  => $sub_title,
						'raw'    => $sub_slug,
						'parent' => $parent_slug,
					);
				}
			}

			$items[] = array(
				'slug'     => $parent_slug,
				'title'    => $title,
				'children' => $children,
			);
		}

		return $items;
	}
}
