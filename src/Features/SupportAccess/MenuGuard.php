<?php
/**
 * Menu, URL and admin bar restrictions for temporary support accounts.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Hides the admin menu pages a grant blocks, refuses their URLs, and can
 * hide the admin bar. Does nothing for anyone who is not a temp user.
 */
final class MenuGuard {

	/**
	 * Restrictions for a user who has none.
	 */
	const DEFAULTS = array(
		'ips'            => array(),
		'menus'          => array(),
		'hide_admin_bar' => false,
	);

	/**
	 * Admin files that list posts of one type, "post" when the slug names none.
	 */
	const POST_TYPE_FILES = array( 'edit.php', 'post-new.php', 'edit-tags.php' );

	/**
	 * Per request cache of grant restrictions, keyed by blog, user and grant id.
	 *
	 * @var array
	 */
	private static $restrictions = array();

	/**
	 * Hooks the menu filter, the screen check and the admin bar filter.
	 * admin_menu is hooked here, not on admin_init, which runs after it.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'filter_menu' ), 9999 );
		add_action( 'current_screen', array( __CLASS__, 'block_screen' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'filter_admin_bar' ) );
	}

	/**
	 * Clears the per request restrictions cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$restrictions = array();
	}

	/**
	 * Restrictions of a grant's temp user, or the defaults for anyone else.
	 *
	 * @param int $user_id User id.
	 * @return array ips, menus and hide_admin_bar.
	 */
	public static function restrictions_for( $user_id ) {
		$user_id  = (int) $user_id;
		$grant_id = Capabilities::grant_id( $user_id );
		if ( $grant_id < 1 ) {
			return self::DEFAULTS;
		}

		$key = get_current_blog_id() . ':' . $user_id . ':' . $grant_id;
		if ( ! isset( self::$restrictions[ $key ] ) ) {
			$grant                      = Grants::get( $grant_id );
			self::$restrictions[ $key ] = is_array( $grant ) ? $grant['restrictions'] : self::DEFAULTS;
		}
		return self::$restrictions[ $key ];
	}

	/**
	 * Removes the blocked pages from the admin menu.
	 *
	 * @return void
	 */
	public static function filter_menu() {
		$user_id = get_current_user_id();
		if ( ! Capabilities::is_temp_user( $user_id ) ) {
			return;
		}
		foreach ( self::restrictions_for( $user_id )['menus'] as $slug ) {
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}
			$parts = explode( '::', $slug, 2 );
			if ( 2 === count( $parts ) ) {
				remove_submenu_page( $parts[0], $parts[1] );
			} else {
				remove_menu_page( $slug );
			}
		}
	}

	/**
	 * Refuses a blocked admin page when it is opened by URL.
	 *
	 * @param \WP_Screen|object|null $screen Current screen.
	 * @return void
	 */
	public static function block_screen( $screen = null ) {
		$user_id = get_current_user_id();
		if ( ! Capabilities::is_temp_user( $user_id ) ) {
			return;
		}
		$blocked = self::restrictions_for( $user_id )['menus'];
		if ( empty( $blocked ) ) {
			return;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) && is_string( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read only check of the requested screen.
		$query = array();
		foreach ( $_GET as $key => $value ) {
			// Exact keys only: "PAGE" must not overwrite or stand in for "page".
			if ( is_string( $key ) && is_string( $value ) && sanitize_key( $key ) === $key ) {
				$query[ $key ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}
		$post_type = isset( $_REQUEST['post_type'] ) && is_string( $_REQUEST['post_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['post_type'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Core reads the page arg through plugin_basename(), so "/wc-settings" opens wc-settings.
		$page = isset( $query['page'] ) ? plugin_basename( $query['page'] ) : '';
		if ( '' !== $page ) {
			$query['page'] = $page;
		}
		if ( is_object( $screen ) && isset( $screen->post_type ) && is_string( $screen->post_type ) && '' !== $screen->post_type ) {
			$post_type = $screen->post_type;
		}
		if ( '' !== $post_type ) {
			$query['post_type'] = $post_type;
		}
		if ( is_object( $screen ) && isset( $screen->taxonomy ) && is_string( $screen->taxonomy ) && '' !== $screen->taxonomy ) {
			$query['taxonomy'] = $screen->taxonomy;
		}

		$hit = self::is_blocked( $blocked, $pagenow, $page, $post_type, $query );
		if ( ! $hit && in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) && '' !== $post_type ) {
			// An edit screen belongs to its list page, so blocking the list blocks the editor.
			$list_query = $query;
			if ( 'post' === $post_type ) {
				unset( $list_query['post_type'] );
			}
			$hit = self::is_blocked( $blocked, 'edit.php', '', 'post' === $post_type ? '' : $post_type, $list_query );
		}
		if ( ! $hit && 'term.php' === $pagenow && isset( $query['taxonomy'] ) ) {
			// A term edit screen belongs to its taxonomy list page.
			$list_query = $query;
			if ( '' === $post_type || 'post' === $post_type ) {
				unset( $list_query['post_type'] );
			}
			$hit = self::is_blocked( $blocked, 'edit-tags.php', '', isset( $list_query['post_type'] ) ? $post_type : '', $list_query );
		}
		if ( ! $hit ) {
			return;
		}

		$url = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		AuditLog::add(
			'access_blocked',
			array(
				'feature'  => 'support',
				'token_id' => Capabilities::grant_id( $user_id ),
				'meta'     => array( 'url' => $url ),
			)
		);
		wp_die(
			esc_html__( "This page isn't part of your support access.", 'happyaccess' ),
			'',
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}

	/**
	 * Hides the admin bar for a restricted temp user.
	 *
	 * @param bool $show Whether to show the bar.
	 * @return bool
	 */
	public static function filter_admin_bar( $show ) {
		$user_id = get_current_user_id();
		if ( ! Capabilities::is_temp_user( $user_id ) ) {
			return $show;
		}
		return self::restrictions_for( $user_id )['hide_admin_bar'] ? false : $show;
	}

	/**
	 * Whether the requested screen is one of the blocked pages. Slugs are
	 * compared as stored, so mixed case plugin slugs still match.
	 *
	 * @param array  $blocked   Blocked slugs, "slug" or "parent::child".
	 * @param string $pagenow   Current admin file, for example "edit.php".
	 * @param string $page      The page query arg.
	 * @param string $post_type The post_type of the request or screen.
	 * @param array  $query     Request query args. page and post_type are filled in when missing.
	 * @return bool
	 */
	public static function is_blocked( array $blocked, $pagenow, $page, $post_type, array $query = array() ) {
		if ( ! isset( $query['page'] ) && '' !== $page ) {
			$query['page'] = $page;
		}
		if ( ! isset( $query['post_type'] ) && '' !== $post_type ) {
			$query['post_type'] = $post_type;
		}

		foreach ( $blocked as $entry ) {
			if ( ! is_string( $entry ) || '' === $entry ) {
				continue;
			}
			$parts = explode( '::', $entry, 2 );
			if ( 2 === count( $parts ) ) {
				if ( self::slug_matches( $parts[1], $pagenow, $page, $query ) ) {
					return true;
				}
				continue;
			}
			if ( self::slug_matches( $entry, $pagenow, $page, $query ) ) {
				return true;
			}
			foreach ( self::children_of( $entry ) as $child ) {
				if ( self::slug_matches( $child, $pagenow, $page, $query ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The admin menu as the settings screen offers it for blocking.
	 *
	 * @return array Top-level items with slug, title and children (slug "parent::child", title).
	 */
	public static function menu_snapshot() {
		$menu     = isset( $GLOBALS['menu'] ) && is_array( $GLOBALS['menu'] ) ? $GLOBALS['menu'] : array();
		$submenu  = isset( $GLOBALS['submenu'] ) && is_array( $GLOBALS['submenu'] ) ? $GLOBALS['submenu'] : array();
		$snapshot = array();

		foreach ( $menu as $item ) {
			if ( ! is_array( $item ) || ! isset( $item[2] ) || ! is_string( $item[2] ) ) {
				continue;
			}
			$slug = $item[2];
			if ( 0 === strpos( $slug, 'separator' ) || 'index.php' === $slug || 'happyaccess' === $slug ) {
				continue;
			}
			if ( isset( $item[4] ) && is_string( $item[4] ) && false !== strpos( $item[4], 'wp-menu-separator' ) ) {
				continue;
			}

			$children = array();
			if ( isset( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
				foreach ( $submenu[ $slug ] as $child ) {
					if ( ! is_array( $child ) || ! isset( $child[2] ) || ! is_string( $child[2] ) ) {
						continue;
					}
					$children[] = array(
						'slug'  => $slug . '::' . $child[2],
						'title' => self::clean_title( isset( $child[0] ) ? $child[0] : '' ),
					);
				}
			}

			$snapshot[] = array(
				'slug'     => $slug,
				'title'    => self::clean_title( isset( $item[0] ) ? $item[0] : '' ),
				'children' => $children,
			);
		}
		return $snapshot;
	}

	/**
	 * Slugs of the pages registered under a top-level menu slug.
	 *
	 * @param string $parent_slug Top-level slug.
	 * @return array
	 */
	private static function children_of( $parent_slug ) {
		$submenu = isset( $GLOBALS['submenu'] ) && is_array( $GLOBALS['submenu'] ) ? $GLOBALS['submenu'] : array();
		if ( ! isset( $submenu[ $parent_slug ] ) || ! is_array( $submenu[ $parent_slug ] ) ) {
			return array();
		}
		$children = array();
		foreach ( $submenu[ $parent_slug ] as $child ) {
			if ( is_array( $child ) && isset( $child[2] ) && is_string( $child[2] ) && '' !== $child[2] ) {
				$children[] = $child[2];
			}
		}
		return $children;
	}

	/**
	 * Whether one menu slug is the requested screen.
	 *
	 * A slug is a base plus optional args. A base before "?" is an admin file
	 * ("edit.php?post_type=product"). A base before "&" or a plain slug is a
	 * page arg ("wc-admin&path=/customers", "wc-settings"); a plain slug also
	 * matches the admin file when no page arg is present ("tools.php"). Every
	 * arg in the slug must equal the request's.
	 *
	 * @param string $slug    Menu slug.
	 * @param string $pagenow Current admin file.
	 * @param string $page    The page query arg.
	 * @param array  $query   Request query args.
	 * @return bool
	 */
	private static function slug_matches( $slug, $pagenow, $page, array $query ) {
		$pos  = strcspn( $slug, '?&' );
		$base = substr( $slug, 0, $pos );
		$sep  = $pos < strlen( $slug ) ? $slug[ $pos ] : '';
		$args = array();
		if ( '' !== $sep ) {
			wp_parse_str( substr( $slug, $pos + 1 ), $args );
		}

		$is_file = '?' === $sep;
		if ( $is_file ) {
			if ( $base !== $pagenow ) {
				return false;
			}
		} elseif ( $base !== $page && ! ( '' === $page && $base === $pagenow ) ) {
			return false;
		}

		foreach ( $args as $key => $value ) {
			if ( ! isset( $query[ $key ] ) || (string) $value !== (string) $query[ $key ] ) {
				return false;
			}
		}

		if ( 'wc-admin' === $base && ! $is_file && ! isset( $args['path'] ) && ! empty( $query['path'] ) ) {
			// A bare wc-admin slug is the WooCommerce home screen, not its path based sub pages.
			return false;
		}

		if ( in_array( $base, self::POST_TYPE_FILES, true ) && ! isset( $args['post_type'] ) ) {
			$type = isset( $query['post_type'] ) ? $query['post_type'] : '';
			return '' === $type || 'post' === $type;
		}
		return true;
	}

	/**
	 * Menu title as plain text, without update count bubbles.
	 *
	 * @param mixed $title Raw menu title.
	 * @return string
	 */
	private static function clean_title( $title ) {
		$title  = is_string( $title ) ? $title : '';
		$bubble = '#<span\b[^>]*class="[^"]*\b(?:update-plugins|plugin-count|awaiting-mod|pending-count|update-count|count-\d+)\b[^"]*"[^>]*>[^<]*</span>#';
		do {
			$title = preg_replace( $bubble, '', $title, -1, $removed );
		} while ( $removed > 0 && is_string( $title ) );
		return trim( wp_strip_all_tags( (string) $title ) );
	}
}
