<?php
/**
 * Catalog tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Features\SupportAccess\Catalog;

class CatalogTest extends WP_UnitTestCase {

	public function tear_down() {
		foreach ( array( 'shop_manager', 'seo', 'netty', 'merch' ) as $role ) {
			remove_role( $role );
		}
		parent::tear_down();
	}

	private function group( $id ) {
		foreach ( Catalog::groups() as $group ) {
			if ( $id === $group['id'] ) {
				return $group;
			}
		}
		return null;
	}

	private function group_caps( $id ) {
		$group = $this->group( $id );
		return null === $group ? array() : wp_list_pluck( $group['caps'], 'cap' );
	}

	public function test_plain_site_has_no_store_group() {
		// Other tests leave store roles behind, so clear any role that holds a store cap.
		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$role = wp_roles()->get_role( $role_name );
			if ( array_intersect( Catalog::GROUPS['store'], array_keys( array_filter( $role->capabilities ) ) ) ) {
				remove_role( $role_name );
			}
		}
		$this->assertNull( $this->group( 'store' ) );
		$this->assertContains( 'edit_posts', $this->group_caps( 'content' ) );
	}

	public function test_store_group_appears_when_a_role_has_store_caps() {
		add_role(
			'shop_manager',
			'Shop manager',
			array(
				'read'               => true,
				'manage_woocommerce' => true,
				'edit_shop_orders'   => true,
			)
		);
		$caps = $this->group_caps( 'store' );
		$this->assertContains( 'manage_woocommerce', $caps );
		$this->assertContains( 'edit_shop_orders', $caps );
		$this->assertNotContains( 'edit_products', $caps );
	}

	public function test_custom_caps_land_in_other_with_their_raw_name() {
		add_role( 'seo', 'SEO', array( 'wpseo_manage_options' => true ) );
		$other = $this->group( 'other' );
		$this->assertNotNull( $other );
		$groups = Catalog::groups();
		$last   = end( $groups );
		$this->assertSame( 'other', $last['id'] );
		$found = wp_list_filter( $other['caps'], array( 'cap' => 'wpseo_manage_options' ) );
		$this->assertCount( 1, $found );
		$this->assertSame( 'wpseo_manage_options', reset( $found )['label'] );
	}

	public function test_other_is_sorted_and_skips_role_names_and_fixed_caps() {
		add_role(
			'seo',
			'SEO',
			array(
				'zeta_cap'  => true,
				'alpha_cap' => true,
				'seo'       => true,
				'edit_posts' => true,
			)
		);
		$caps = $this->group_caps( 'other' );
		$sorted = $caps;
		sort( $sorted );
		$this->assertSame( $sorted, $caps );
		$this->assertNotContains( 'seo', $caps );
		$this->assertNotContains( 'edit_posts', $caps );
	}

	public function test_never_caps_are_never_grantable() {
		add_role(
			'netty',
			'Netty',
			array(
				'manage_network'    => true,
				'edit_plugins'      => true,
				'happyaccess_manage' => true,
				'level_10'          => true,
				'exist'             => true,
			)
		);
		$grantable = Catalog::grantable();
		foreach ( Catalog::NEVER as $cap ) {
			$this->assertNotContains( $cap, $grantable, $cap );
		}
		$this->assertNotContains( 'manage_network', $grantable );
		$this->assertNotContains( 'edit_plugins', $grantable );
	}

	public function test_never_holds_the_guard_network_caps() {
		foreach ( array( 'manage_network', 'setup_network', 'edit_files', 'do_not_allow', 'level_0', 'level_10' ) as $cap ) {
			$this->assertContains( $cap, Catalog::NEVER, $cap );
		}
	}

	public function test_role_caps_filters_to_grantable() {
		$editor = Catalog::role_caps( 'editor' );
		$this->assertContains( 'edit_others_posts', $editor );
		$this->assertNotContains( 'manage_options', $editor );
		$this->assertSame( array(), Catalog::role_caps( 'nope' ) );
	}

	public function test_presets_keep_only_existing_roles() {
		remove_role( 'shop_manager' );
		$presets = Catalog::presets();
		$this->assertArrayHasKey( 'administrator', $presets );
		$this->assertArrayHasKey( 'editor', $presets );
		$this->assertArrayNotHasKey( 'shop_manager', $presets );
		add_role( 'shop_manager', 'Shop manager', array( 'manage_woocommerce' => true ) );
		$this->assertArrayHasKey( 'shop_manager', Catalog::presets() );
	}

	public function test_every_label_is_a_non_empty_string() {
		add_role( 'seo', 'SEO', array( 'wpseo_manage_options' => true ) );
		foreach ( Catalog::groups() as $group ) {
			$this->assertNotSame( '', $group['label'] );
			$this->assertIsString( $group['label'] );
			$this->assertNotSame( '', $group['hint'] );
			foreach ( $group['caps'] as $cap ) {
				$this->assertIsString( $cap['label'] );
				$this->assertNotSame( '', $cap['label'], $cap['cap'] );
			}
		}
	}

	public function test_core_labels_use_prototype_wording() {
		add_role( 'shop_manager', 'Shop manager', array( 'edit_shop_orders' => true ) );
		$found = wp_list_filter( $this->group( 'store' )['caps'], array( 'cap' => 'edit_shop_orders' ) );
		$this->assertSame( 'View and edit orders', reset( $found )['label'] );
	}

	public function test_read_is_never_offered() {
		$this->assertContains( 'read', array_keys( array_filter( wp_roles()->get_role( 'editor' )->capabilities ) ) );
		$this->assertNotContains( 'read', Catalog::grantable() );
		$this->assertNotContains( 'read', Catalog::NEVER );
		$this->assertContains( 'read', Catalog::ALWAYS );
	}

	public function test_core_private_and_published_caps_are_in_content() {
		$caps = $this->group_caps( 'content' );
		$this->assertContains( 'delete_private_posts', $caps );
		$this->assertContains( 'edit_published_pages', $caps );
		$this->assertContains( 'manage_links', $caps );
	}

	public function test_store_pattern_caps_route_to_store_with_a_readable_label() {
		add_role( 'merch', 'Merch', array( 'delete_others_products' => true ) );
		$found = wp_list_filter( $this->group( 'store' )['caps'], array( 'cap' => 'delete_others_products' ) );
		$this->assertCount( 1, $found );
		$this->assertSame( 'Delete others products', reset( $found )['label'] );
		$this->assertNotContains( 'delete_others_products', $this->group_caps( 'other' ) );
	}

	public function test_content_pattern_caps_route_to_content() {
		add_role( 'merch', 'Merch', array( 'edit_things_posts' => true ) );
		$found = wp_list_filter( $this->group( 'content' )['caps'], array( 'cap' => 'edit_things_posts' ) );
		$this->assertSame( 'Edit things posts', reset( $found )['label'] );
	}

	public function test_unknown_plugin_caps_stay_in_other() {
		add_role( 'seo', 'SEO', array( 'wpseo_manage_options' => true ) );
		$this->assertContains( 'wpseo_manage_options', $this->group_caps( 'other' ) );
	}

	public function test_create_customers_routes_to_store() {
		add_role( 'merch', 'Merch', array( 'create_customers' => true ) );
		$this->assertContains( 'create_customers', $this->group_caps( 'store' ) );
		$this->assertNotContains( 'create_customers', $this->group_caps( 'other' ) );
	}

	public function test_unfiltered_upload_is_a_tools_choice() {
		$found = wp_list_filter( $this->group( 'tools' )['caps'], array( 'cap' => 'unfiltered_upload' ) );
		$this->assertSame( 'Upload any file type', reset( $found )['label'] );
		$this->assertContains( 'unfiltered_upload', Catalog::grantable() );
		$this->assertNotContains( 'unfiltered_upload', Catalog::NEVER );
	}
}
