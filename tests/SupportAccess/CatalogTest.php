<?php
/**
 * Catalog tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Features\SupportAccess\Catalog;
use HappyAccess\Features\SupportAccess\Grants;

class CatalogTest extends WP_UnitTestCase {

	public function tear_down() {
		foreach ( array( 'shop_manager', 'seo', 'netty', 'merch', 'wc_product' ) as $role ) {
			remove_role( $role );
		}
		unregister_post_type( 'ha_widget' );
		parent::tear_down();
	}

	/**
	 * Registers a post type that maps single-item meta caps, as WooCommerce
	 * does for products, and a role that holds them.
	 *
	 * @return void
	 */
	private function add_widget_role() {
		register_post_type(
			'ha_widget',
			array(
				'capability_type' => 'ha_widget',
				'map_meta_cap'    => true,
			)
		);
		add_role(
			'merch',
			'Merch',
			array(
				'read'             => true,
				'edit_ha_widget'   => true,
				'read_ha_widget'   => true,
				'delete_ha_widget' => true,
				'edit_ha_widgets'  => true,
			)
		);
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

	public function test_trust_list_matches_the_spec() {
		$expected = array( 'manage_options', 'activate_plugins', 'install_plugins', 'upload_plugins', 'update_plugins', 'install_themes', 'upload_themes', 'update_themes', 'update_core', 'create_users', 'edit_users', 'promote_users', 'delete_users', 'unfiltered_html', 'import' );
		sort( $expected );
		$trust = Catalog::TRUST;
		sort( $trust );
		$this->assertSame( $expected, $trust );
	}

	public function test_every_trust_cap_is_in_a_fixed_group() {
		$fixed = array();
		foreach ( Catalog::GROUPS as $caps ) {
			$fixed = array_merge( $fixed, $caps );
		}
		$this->assertSame( array(), array_values( array_diff( Catalog::TRUST, $fixed ) ) );
	}

	public function test_every_cap_item_says_whether_it_runs_on_trust() {
		// Roles added by earlier tests stay in memory after their rollback, so read the stored roles again.
		wp_roles()->for_site();
		add_role(
			'seo',
			'SEO',
			array(
				'read'       => true,
				'manage_seo' => true,
			)
		);
		$seen = array();
		foreach ( Catalog::groups() as $group ) {
			foreach ( $group['caps'] as $item ) {
				$this->assertArrayHasKey( 'trust', $item, $item['cap'] );
				$this->assertSame( in_array( $item['cap'], Catalog::TRUST, true ), $item['trust'], $item['cap'] );
				$seen[] = $item['cap'];
			}
		}
		$this->assertContains( 'manage_seo', $seen );
		$this->assertSame( array(), array_values( array_diff( Catalog::TRUST, $seen ) ) );
	}

	public function test_needs_trust_for_any_trust_cap() {
		$this->assertFalse( Catalog::needs_trust( array( 'read', 'edit_posts' ) ) );
		$this->assertTrue( Catalog::needs_trust( array( 'edit_posts', 'promote_users' ) ) );
	}

	public function test_admin_is_offered_only_what_they_can_use() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( current_user_can( 'manage_links' ) );

		$this->assertNotContains( 'manage_links', Catalog::grantable() );
		$this->assertNotContains( 'manage_links', Catalog::presets()['administrator'] );
		$this->assertContains( 'edit_posts', Catalog::grantable() );
	}

	public function test_editor_is_offered_only_their_own_caps() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertNotContains( 'manage_options', Catalog::grantable() );
		$this->assertContains( 'edit_others_posts', Catalog::grantable() );
	}

	public function test_nothing_is_filtered_with_no_user() {
		wp_set_current_user( 0 );
		$this->assertContains( 'manage_links', Catalog::grantable() );
		$this->assertContains( 'manage_options', Catalog::grantable() );
	}

	public function test_the_unfiltered_list_ignores_the_current_user() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertContains( 'manage_options', Catalog::grantable( false ) );
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
				'zeta_cap'   => true,
				'alpha_cap'  => true,
				'seo'        => true,
				'edit_posts' => true,
			)
		);
		$caps   = $this->group_caps( 'other' );
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
				'manage_network'     => true,
				'edit_plugins'       => true,
				'happyaccess_manage' => true,
				'level_10'           => true,
				'exist'              => true,
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

	public function test_unfiltered_upload_is_never_offered() {
		add_role( 'merch', 'Merch', array( 'unfiltered_upload' => true ) );
		$this->assertContains( 'unfiltered_upload', Catalog::NEVER );
		$this->assertNotContains( 'unfiltered_upload', Catalog::grantable() );
	}

	public function test_role_slugs_are_not_treated_as_caps() {
		add_role(
			'wc_product',
			'WC product',
			array(
				'wc_product' => true,
				'wc_things'  => true,
			)
		);
		$this->assertNotContains( 'wc_product', Catalog::grantable() );
		$this->assertContains( 'wc_things', $this->group_caps( 'other' ) );
	}

	public function test_meta_caps_of_a_post_type_are_never_asked_about_or_offered() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->add_widget_role();
		$notices = array();
		$listen  = static function ( $function_name ) use ( &$notices ) {
			$notices[] = $function_name;
		};
		add_action( 'doing_it_wrong_run', $listen );

		$offered    = Catalog::grantable();
		$unfiltered = Catalog::grantable( false );

		remove_action( 'doing_it_wrong_run', $listen );
		$this->assertSame( array(), $notices );
		foreach ( array( 'edit_ha_widget', 'read_ha_widget', 'delete_ha_widget' ) as $cap ) {
			$this->assertNotContains( $cap, $offered );
			$this->assertNotContains( $cap, $unfiltered );
		}
		// The plain capabilities of the same role are still offered.
		$this->assertContains( 'edit_ha_widgets', $unfiltered );
	}

	public function test_meta_caps_list_covers_core_post_types_and_taxonomies() {
		$this->add_widget_role();
		$meta = Catalog::meta_caps();

		foreach ( array( 'edit_post', 'read_post', 'delete_post', 'edit_user', 'promote_user', 'edit_term', 'edit_ha_widget', 'read_ha_widget', 'delete_ha_widget' ) as $cap ) {
			$this->assertContains( $cap, $meta );
		}
		$this->assertNotContains( 'edit_posts', $meta );
		$this->assertNotContains( 'edit_ha_widgets', $meta );
	}

	public function test_meta_caps_list_follows_post_types_registered_later() {
		$this->assertNotContains( 'edit_ha_widget', Catalog::meta_caps() );
		$this->add_widget_role();
		$this->assertContains( 'edit_ha_widget', Catalog::meta_caps() );
	}

	public function test_a_pass_cannot_be_given_a_meta_cap() {
		Installer::install();
		Secrets::reset_cache();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->add_widget_role();

		try {
			Grants::create(
				array(
					'label' => 'Acme',
					'level' => 'custom',
					'caps'  => array( 'edit_posts', 'edit_ha_widget' ),
				)
			);
			$this->fail( 'A meta capability was accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( "This permission can't be given: edit_ha_widget", $e->getMessage() );
		}
	}
}
