<?php
/**
 * The list of capabilities a custom support pass may receive.
 *
 * @package HappyAccess
 */

namespace HappyAccess\Features\SupportAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Groups, labels and presets for the permission editor.
 */
final class Catalog {

	/**
	 * Fixed groups of core and WooCommerce capabilities, in display order.
	 */
	const GROUPS = array(
		'content'    => array(
			'edit_posts',
			'edit_others_posts',
			'edit_published_posts',
			'edit_private_posts',
			'publish_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_published_posts',
			'delete_private_posts',
			'read_private_posts',
			'edit_pages',
			'edit_others_pages',
			'edit_published_pages',
			'edit_private_pages',
			'publish_pages',
			'delete_pages',
			'delete_others_pages',
			'delete_published_pages',
			'delete_private_pages',
			'read_private_pages',
			'upload_files',
			'moderate_comments',
			'manage_categories',
			'manage_links',
		),
		'store'      => array(
			'manage_woocommerce',
			'view_woocommerce_reports',
			'edit_shop_orders',
			'read_shop_orders',
			'delete_shop_orders',
			'edit_products',
			'publish_products',
			'delete_products',
			'edit_shop_coupons',
			'manage_product_terms',
		),
		'appearance' => array(
			'switch_themes',
			'edit_theme_options',
		),
		'plugins'    => array(
			'activate_plugins',
			'install_plugins',
			'upload_plugins',
			'update_plugins',
			'delete_plugins',
			'install_themes',
			'upload_themes',
			'update_themes',
			'delete_themes',
			'update_core',
			'install_languages',
			'update_languages',
		),
		'users'      => array(
			'list_users',
			'create_users',
			'edit_users',
			'promote_users',
			'delete_users',
			'remove_users',
		),
		'settings'   => array(
			'manage_options',
			'import',
			'export',
		),
		'tools'      => array(
			'edit_dashboard',
			'customize',
			'unfiltered_html',
			'unfiltered_upload',
		),
	);

	/**
	 * Capabilities every pass has, so they are never offered.
	 */
	const ALWAYS = array( 'read' );

	/**
	 * Pattern for unlisted capabilities that belong to the store group.
	 */
	const STORE_PATTERN = '/^(manage_woocommerce|view_woocommerce_reports|create_customers)$|_(product|products|product_terms|shop_order|shop_orders|shop_order_terms|shop_coupon|shop_coupons|shop_coupon_terms|shop_webhook|shop_webhooks)$/';

	/**
	 * Pattern for unlisted capabilities that belong to the content group.
	 */
	const CONTENT_PATTERN = '/_(post|posts|page|pages)$/';

	/**
	 * Capabilities a support pass can never receive.
	 */
	const NEVER = array(
		'manage_network',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'manage_sites',
		'create_sites',
		'delete_sites',
		'upgrade_network',
		'setup_network',
		'erase_others_personal_data',
		'export_others_personal_data',
		'edit_plugins',
		'edit_themes',
		'edit_files',
		'do_not_allow',
		'happyaccess_manage',
		'exist',
		'level_0',
		'level_1',
		'level_2',
		'level_3',
		'level_4',
		'level_5',
		'level_6',
		'level_7',
		'level_8',
		'level_9',
		'level_10',
	);

	/**
	 * Every capability held by any role, minus role names.
	 *
	 * @return array<string> Sorted, unique capability names.
	 */
	private static function role_cap_names() {
		$roles = wp_roles()->roles;
		$names = array();
		foreach ( $roles as $role ) {
			if ( empty( $role['capabilities'] ) || ! is_array( $role['capabilities'] ) ) {
				continue;
			}
			foreach ( $role['capabilities'] as $cap => $has ) {
				if ( $has ) {
					$names[] = (string) $cap;
				}
			}
		}
		$names = array_diff( array_unique( $names ), array_map( 'strval', array_keys( $roles ) ) );
		sort( $names );
		return array_values( $names );
	}

	/**
	 * Group id to translated label and hint.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function group_text() {
		return array(
			'content'    => array(
				'label' => __( 'Content', 'happyaccess' ),
				'hint'  => __( 'Posts, pages, media and comments', 'happyaccess' ),
			),
			'store'      => array(
				'label' => __( 'Store', 'happyaccess' ),
				'hint'  => __( 'WooCommerce orders, products and coupons', 'happyaccess' ),
			),
			'appearance' => array(
				'label' => __( 'Appearance', 'happyaccess' ),
				'hint'  => __( 'Themes, menus, widgets and the site editor', 'happyaccess' ),
			),
			'plugins'    => array(
				'label' => __( 'Plugins and updates', 'happyaccess' ),
				'hint'  => __( 'Turn plugins on or off, install and update', 'happyaccess' ),
			),
			'users'      => array(
				'label' => __( 'Users', 'happyaccess' ),
				'hint'  => __( 'See, add and edit accounts', 'happyaccess' ),
			),
			'settings'   => array(
				'label' => __( 'Site settings', 'happyaccess' ),
				'hint'  => __( 'Settings screens, import and export', 'happyaccess' ),
			),
			'tools'      => array(
				'label' => __( 'Tools', 'happyaccess' ),
				'hint'  => __( 'Dashboard, Customizer and unfiltered HTML', 'happyaccess' ),
			),
		);
	}

	/**
	 * Capability to translated label for every fixed-group capability.
	 *
	 * @return array<string, string>
	 */
	private static function cap_labels() {
		return array(
			'edit_posts'               => __( 'Write and edit their own posts', 'happyaccess' ),
			'edit_others_posts'        => __( 'Edit posts by others', 'happyaccess' ),
			'publish_posts'            => __( 'Publish posts', 'happyaccess' ),
			'delete_posts'             => __( 'Delete posts', 'happyaccess' ),
			'delete_others_posts'      => __( 'Delete posts by others', 'happyaccess' ),
			'edit_pages'               => __( 'Edit pages', 'happyaccess' ),
			'edit_others_pages'        => __( 'Edit pages by others', 'happyaccess' ),
			'publish_pages'            => __( 'Publish pages', 'happyaccess' ),
			'delete_pages'             => __( 'Delete pages', 'happyaccess' ),
			'upload_files'             => __( 'Upload media', 'happyaccess' ),
			'moderate_comments'        => __( 'Moderate comments', 'happyaccess' ),
			'manage_categories'        => __( 'Manage categories and tags', 'happyaccess' ),
			'edit_published_posts'     => __( 'Edit published posts', 'happyaccess' ),
			'edit_private_posts'       => __( 'Edit private posts', 'happyaccess' ),
			'delete_published_posts'   => __( 'Delete published posts', 'happyaccess' ),
			'delete_private_posts'     => __( 'Delete private posts', 'happyaccess' ),
			'read_private_posts'       => __( 'Read private posts', 'happyaccess' ),
			'edit_published_pages'     => __( 'Edit published pages', 'happyaccess' ),
			'edit_private_pages'       => __( 'Edit private pages', 'happyaccess' ),
			'delete_others_pages'      => __( 'Delete pages by others', 'happyaccess' ),
			'delete_published_pages'   => __( 'Delete published pages', 'happyaccess' ),
			'delete_private_pages'     => __( 'Delete private pages', 'happyaccess' ),
			'read_private_pages'       => __( 'Read private pages', 'happyaccess' ),
			'manage_links'             => __( 'Manage links', 'happyaccess' ),
			'manage_woocommerce'       => __( 'WooCommerce settings and status', 'happyaccess' ),
			'view_woocommerce_reports' => __( 'See reports and analytics', 'happyaccess' ),
			'edit_shop_orders'         => __( 'View and edit orders', 'happyaccess' ),
			'read_shop_orders'         => __( 'View orders', 'happyaccess' ),
			'delete_shop_orders'       => __( 'Delete orders', 'happyaccess' ),
			'edit_products'            => __( 'Edit products', 'happyaccess' ),
			'publish_products'         => __( 'Publish products', 'happyaccess' ),
			'delete_products'          => __( 'Delete products', 'happyaccess' ),
			'edit_shop_coupons'        => __( 'Edit coupons', 'happyaccess' ),
			'manage_product_terms'     => __( 'Manage product categories and tags', 'happyaccess' ),
			'switch_themes'            => __( 'Change the theme', 'happyaccess' ),
			'edit_theme_options'       => __( 'Menus, widgets and the site editor', 'happyaccess' ),
			'activate_plugins'         => __( 'Turn plugins on and off', 'happyaccess' ),
			'install_plugins'          => __( 'Install plugins', 'happyaccess' ),
			'upload_plugins'           => __( 'Upload plugin files', 'happyaccess' ),
			'update_plugins'           => __( 'Update plugins', 'happyaccess' ),
			'delete_plugins'           => __( 'Delete plugins', 'happyaccess' ),
			'install_themes'           => __( 'Install themes', 'happyaccess' ),
			'upload_themes'            => __( 'Upload theme files', 'happyaccess' ),
			'update_themes'            => __( 'Update themes', 'happyaccess' ),
			'delete_themes'            => __( 'Delete themes', 'happyaccess' ),
			'update_core'              => __( 'Update WordPress', 'happyaccess' ),
			'install_languages'        => __( 'Install languages', 'happyaccess' ),
			'update_languages'         => __( 'Update languages', 'happyaccess' ),
			'list_users'               => __( 'See the user list', 'happyaccess' ),
			'create_users'             => __( 'Add users', 'happyaccess' ),
			'edit_users'               => __( 'Edit users, including passwords and emails', 'happyaccess' ),
			'promote_users'            => __( 'Change user roles', 'happyaccess' ),
			'delete_users'             => __( 'Delete users', 'happyaccess' ),
			'remove_users'             => __( 'Remove users from the site', 'happyaccess' ),
			'manage_options'           => __( 'Change site settings', 'happyaccess' ),
			'import'                   => __( 'Import content', 'happyaccess' ),
			'export'                   => __( 'Export content', 'happyaccess' ),
			'edit_dashboard'           => __( 'Change the dashboard', 'happyaccess' ),
			'customize'                => __( 'Use the Customizer', 'happyaccess' ),
			'unfiltered_html'          => __( 'Post unfiltered HTML', 'happyaccess' ),
			'unfiltered_upload'        => __( 'Upload any file type', 'happyaccess' ),
		);
	}

	/**
	 * Position of a group in a built list.
	 *
	 * @param array  $groups Built groups.
	 * @param string $id     Group id.
	 * @return int|null
	 */
	private static function group_index( array $groups, $id ) {
		foreach ( $groups as $index => $group ) {
			if ( $id === $group['id'] ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Groups of capabilities a custom pass may receive, ready for the editor.
	 *
	 * @return array<int, array{id:string, label:string, hint:string, caps:array<int, array{cap:string, label:string}>}>
	 */
	public static function groups() {
		$held   = self::role_cap_names();
		$text   = self::group_text();
		$labels = self::cap_labels();
		$groups = array();
		$fixed  = array();
		$routed = array(
			'content' => array(),
			'store'   => array(),
		);

		foreach ( self::GROUPS as $id => $caps ) {
			$fixed = array_merge( $fixed, $caps );
			$items = array();
			foreach ( $caps as $cap ) {
				// Store caps exist only when a role has them; the rest are core primitives.
				if ( 'store' === $id && ! in_array( $cap, $held, true ) ) {
					continue;
				}
				$items[] = array(
					'cap'   => $cap,
					'label' => $labels[ $cap ],
				);
			}
			if ( empty( $items ) ) {
				continue;
			}
			$groups[] = array(
				'id'    => $id,
				'label' => $text[ $id ]['label'],
				'hint'  => $text[ $id ]['hint'],
				'caps'  => $items,
			);
		}

		$other = array();
		foreach ( array_diff( $held, $fixed, self::NEVER, self::ALWAYS ) as $cap ) {
			$item = array(
				'cap'   => $cap,
				'label' => $cap,
			);
			if ( preg_match( self::STORE_PATTERN, $cap ) ) {
				$routed['store'][] = $item;
			} elseif ( preg_match( self::CONTENT_PATTERN, $cap ) ) {
				$routed['content'][] = $item;
			} else {
				$other[] = $item;
			}
		}
		foreach ( $routed as $id => $items ) {
			if ( empty( $items ) ) {
				continue;
			}
			foreach ( $items as $key => $item ) {
				$items[ $key ]['label'] = ucfirst( str_replace( '_', ' ', $item['cap'] ) );
			}
			$index = self::group_index( $groups, $id );
			if ( null === $index ) {
				// Content always exists, so a missing store group goes right after it.
				array_splice(
					$groups,
					1,
					0,
					array(
						array(
							'id'    => $id,
							'label' => $text[ $id ]['label'],
							'hint'  => $text[ $id ]['hint'],
							'caps'  => array(),
						),
					)
				);
				$index = 1;
			}
			$groups[ $index ]['caps'] = array_merge( $groups[ $index ]['caps'], $items );
		}
		if ( ! empty( $other ) ) {
			$groups[] = array(
				'id'    => 'other',
				'label' => __( 'Other plugins on this site', 'happyaccess' ),
				'hint'  => __( 'Found in your site\'s roles', 'happyaccess' ),
				'caps'  => $other,
			);
		}

		return $groups;
	}

	/**
	 * Every capability a custom pass may receive.
	 *
	 * @return array<string>
	 */
	public static function grantable() {
		$caps = array();
		foreach ( self::groups() as $group ) {
			foreach ( $group['caps'] as $item ) {
				$caps[] = $item['cap'];
			}
		}
		return $caps;
	}

	/**
	 * A role's capabilities that a custom pass may receive.
	 *
	 * @param string $role Role slug.
	 * @return array<string>
	 */
	public static function role_caps( $role ) {
		$object = wp_roles()->get_role( (string) $role );
		if ( ! $object ) {
			return array();
		}
		$has = array_keys( array_filter( $object->capabilities ) );
		return array_values( array_intersect( self::grantable(), array_map( 'strval', $has ) ) );
	}

	/**
	 * Starting points for the permission editor, one per role that exists.
	 *
	 * @return array<string, array<string>>
	 */
	public static function presets() {
		$presets = array();
		foreach ( array( 'administrator', 'shop_manager', 'editor' ) as $role ) {
			if ( wp_roles()->get_role( $role ) ) {
				$presets[ $role ] = self::role_caps( $role );
			}
		}
		return $presets;
	}
}
