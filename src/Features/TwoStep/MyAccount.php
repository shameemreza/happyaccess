<?php
/**
 * The two-step section in WooCommerce My Account.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\TwoStep;

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\OtherTwoFactor;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce keeps customers out of wp-admin, so they can't reach the
 * profile section. This adds a My Account endpoint with the same section,
 * printed by Profile with WooCommerce classes, using the same REST routes.
 *
 * Feature registers it only while WooCommerce is active and two-step login
 * is on. Features::maybe_flush_rewrites() flushes the endpoint's rewrite
 * rules once, on the first init after the feature was turned on or off.
 */
final class MyAccount {

	/**
	 * The endpoint slug and WooCommerce query var.
	 */
	const ENDPOINT = 'two-step-login';

	/**
	 * Adds the endpoint, the menu item, the content and the assets. Safe to call twice.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_hidden' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Registers the rewrite endpoint.
	 *
	 * @return void
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Adds the endpoint to WooCommerce's query vars, so WooCommerce knows it
	 * as an account endpoint: its URL, its title and the active menu item.
	 *
	 * @param array $vars Query var key to endpoint slug.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars                   = is_array( $vars ) ? $vars : array();
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Adds "Two-step login" after "Account details", or before "Log out"
	 * when there is no "Account details", for a user who may see it.
	 *
	 * @param array $items Endpoint to label.
	 * @return array
	 */
	public static function menu_items( $items ) {
		$items = is_array( $items ) ? $items : array();
		if ( ! self::is_visible( wp_get_current_user() ) ) {
			return $items;
		}
		$label = self::title();
		$out   = array();
		$added = false;
		foreach ( $items as $key => $value ) {
			if ( 'customer-logout' === $key && ! $added ) {
				$out[ self::ENDPOINT ] = $label;
				$added                 = true;
			}
			$out[ $key ] = $value;
			if ( 'edit-account' === $key ) {
				$out[ self::ENDPOINT ] = $label;
				$added                 = true;
			}
		}
		if ( ! $added ) {
			$out[ self::ENDPOINT ] = $label;
		}
		return $out;
	}

	/**
	 * The endpoint title.
	 *
	 * @return string
	 */
	public static function title() {
		return __( 'Two-step login', 'happyaccess' );
	}

	/**
	 * Whether the user gets the menu item and the endpoint: logged in, not a
	 * Support Access temp user, no two-step login from another plugin, and a
	 * section to show (the role offers it, or something is set up to turn off).
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function is_visible( \WP_User $user ) {
		if ( ! $user->exists() || Capabilities::is_temp_user( $user->ID ) || OtherTwoFactor::user_has_2fa( $user ) ) {
			return false;
		}
		return Profile::has_own_section( $user );
	}

	/**
	 * Prints the section on the endpoint.
	 *
	 * @return void
	 */
	public static function render() {
		$user = wp_get_current_user();
		if ( ! self::is_visible( $user ) ) {
			return;
		}
		echo Profile::section( $user, Profile::LAYOUT_ACCOUNT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in Profile::section().
	}

	/**
	 * Where a logged-in user who may not see the endpoint goes instead: the
	 * account dashboard. Empty when they stay.
	 *
	 * @return string
	 */
	public static function redirect_target() {
		if ( ! is_user_logged_in() || ! self::on_endpoint() || self::is_visible( wp_get_current_user() ) ) {
			return '';
		}
		return (string) wc_get_page_permalink( 'myaccount' );
	}

	/**
	 * Sends a user who may not see the endpoint to the account dashboard.
	 *
	 * @return void
	 */
	public static function redirect_hidden() {
		$target = self::redirect_target();
		if ( '' === $target ) {
			return;
		}
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Loads the section's stylesheet and scripts on the endpoint only.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::on_endpoint() || ! self::is_visible( wp_get_current_user() ) ) {
			return;
		}
		Profile::enqueue_own_assets();
	}

	/**
	 * The endpoint URL.
	 *
	 * @return string
	 */
	public static function url() {
		return (string) wc_get_account_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Whether WooCommerce keeps this user out of wp-admin and the shop has a
	 * My Account page to send them to. Without that page there is no
	 * endpoint to link, so the profile is used. The rule is the same as
	 * WC_Admin::prevent_admin_access(): with the admin bar hidden for
	 * shoppers (woocommerce_disable_admin_bar), a user without edit_posts,
	 * manage_woocommerce or view_admin_dashboard is kept out, and the
	 * woocommerce_prevent_admin_access filter has the last word. That filter
	 * gets no user, so a callback that reads the current user answers for
	 * them, not for this user.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function uses_my_account( \WP_User $user ) {
		if ( ! function_exists( 'wc_get_account_endpoint_url' ) || ! function_exists( 'wc_get_page_id' ) || wc_get_page_id( 'myaccount' ) < 1 ) {
			return false;
		}
		$prevent = false;
		if ( apply_filters( 'woocommerce_disable_admin_bar', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
			$prevent = true;
			foreach ( array( 'edit_posts', 'manage_woocommerce', 'view_admin_dashboard' ) as $cap ) {
				if ( user_can( $user, $cap ) ) {
					$prevent = false;
					break;
				}
			}
		}
		return (bool) apply_filters( 'woocommerce_prevent_admin_access', $prevent ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
	}

	/**
	 * Whether the request is the endpoint of the My Account page.
	 *
	 * @return bool
	 */
	private static function on_endpoint() {
		return function_exists( 'is_account_page' ) && function_exists( 'is_wc_endpoint_url' ) && is_account_page() && is_wc_endpoint_url( self::ENDPOINT );
	}
}
