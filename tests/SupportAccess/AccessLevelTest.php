<?php
/**
 * Access level tests for grants.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Clock;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Secrets;
use HappyAccess\Features\SupportAccess\Catalog;
use HappyAccess\Features\SupportAccess\Grants;

class AccessLevelTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		Secrets::reset_cache();
		Clock::freeze( 1790000000 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		Clock::freeze( null );
		parent::tear_down();
	}

	public function test_default_level_is_protected() {
		$grant = Grants::get( Grants::create( array( 'label' => 'Acme' ) )['id'] );
		$this->assertSame( 'protected', $grant['level'] );
		$this->assertFalse( $grant['allow_installs'] );
		$this->assertSame( array(), $grant['caps'] );
	}

	public function test_allow_installs_stays_protected_level() {
		$grant = Grants::get(
			Grants::create(
				array(
					'label'          => 'Acme',
					'allow_installs' => true,
				)
			)['id']
		);
		$this->assertSame( 'protected', $grant['level'] );
		$this->assertTrue( $grant['allow_installs'] );
		$this->assertSame( 'protected_allow_installs', $grant['protection'] );
	}

	public function test_full_needs_confirmation() {
		try {
			Grants::create(
				array(
					'label' => 'Acme',
					'level' => 'full',
				)
			);
			$this->fail( 'Expected an exception.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'Confirm that you trust this person with full access.', $e->getMessage() );
		}

		$grant = Grants::get(
			Grants::create(
				array(
					'label'          => 'Acme',
					'level'          => 'full',
					'confirm_full'   => true,
					'role'           => 'editor',
					'allow_installs' => true,
				)
			)['id']
		);
		$this->assertSame( 'full', $grant['level'] );
		$this->assertSame( 'administrator', $grant['role'] );
		$this->assertFalse( $grant['allow_installs'] );
		$this->assertSame( array(), $grant['caps'] );
	}

	public function test_custom_cleans_and_sorts_caps() {
		$made  = Grants::create(
			array(
				'label'          => 'Acme',
				'level'          => 'custom',
				'role'           => 'editor',
				'allow_installs' => true,
				'caps'           => array( 'edit_posts', 'Edit_Posts', 'upload_files' ),
			)
		);
		$grant = Grants::get( $made['id'] );
		$this->assertSame( 'custom', $grant['level'] );
		$this->assertSame( array( 'edit_posts', 'read', 'upload_files' ), $grant['caps'] );
		$this->assertSame( 'administrator', $grant['role'] );
		$this->assertFalse( $grant['allow_installs'] );
		$this->assertSame( array(), $grant['restrictions']['menus'] );
	}

	public function test_custom_accepts_read_in_the_input() {
		$grant = Grants::get(
			Grants::create(
				array(
					'label' => 'Acme',
					'level' => 'custom',
					'caps'  => array( 'read', 'edit_posts' ),
				)
			)['id']
		);
		$this->assertSame( array( 'edit_posts', 'read' ), $grant['caps'] );
	}

	public function test_custom_without_caps_throws() {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Pick at least one permission.' );
		Grants::create(
			array(
				'label' => 'Acme',
				'level' => 'custom',
				'caps'  => array(),
			)
		);
	}

	public function test_custom_with_only_read_throws() {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Pick at least one permission.' );
		Grants::create(
			array(
				'label' => 'Acme',
				'level' => 'custom',
				'caps'  => array( 'read' ),
			)
		);
	}

	public function test_custom_rejects_caps_outside_the_catalog() {
		foreach ( array( 'manage_network', 'level_10' ) as $cap ) {
			try {
				Grants::create(
					array(
						'label' => 'Acme',
						'level' => 'custom',
						'caps'  => array( 'edit_posts', $cap ),
					)
				);
				$this->fail( 'Expected an exception for ' . $cap );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( "This permission can't be given: " . $cap, $e->getMessage() );
			}
		}
	}

	public function test_custom_rejects_caps_the_creator_lacks() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "You can't give a permission you don't have: manage_options" );
		Grants::create(
			array(
				'label' => 'Acme',
				'level' => 'custom',
				'caps'  => array( 'manage_options' ),
			)
		);
	}

	public function test_custom_with_a_trust_cap_needs_the_trust_tick() {
		foreach ( array( null, false, '1', 1 ) as $confirm ) {
			$args = array(
				'label' => 'Acme',
				'level' => 'custom',
				'caps'  => array( 'edit_posts', 'manage_options' ),
			);
			if ( null !== $confirm ) {
				$args['confirm_full'] = $confirm;
			}
			try {
				Grants::create( $args );
				$this->fail( 'Expected an exception for ' . wp_json_encode( $confirm ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Confirm that you trust this person with full access.', $e->getMessage() );
			}
		}
		$this->assertFalse( Grants::has_current() );
	}

	public function test_custom_with_a_trust_cap_and_the_tick_is_created() {
		$made = Grants::create(
			array(
				'label'        => 'Acme',
				'level'        => 'custom',
				'caps'         => array( 'edit_posts', 'manage_options' ),
				'confirm_full' => true,
			)
		);
		$this->assertSame( array( 'edit_posts', 'manage_options', 'read' ), Grants::get( $made['id'] )['caps'] );
	}

	public function test_custom_without_trust_caps_needs_no_tick() {
		$made = Grants::create(
			array(
				'label' => 'Acme',
				'level' => 'custom',
				'caps'  => array( 'edit_posts', 'list_users' ),
			)
		);
		$this->assertSame( 'custom', Grants::get( $made['id'] )['level'] );
	}

	public function test_every_preset_saves_for_an_administrator() {
		$presets = Catalog::presets();
		$this->assertArrayHasKey( 'administrator', $presets );
		$this->assertArrayHasKey( 'editor', $presets );
		foreach ( $presets as $role => $caps ) {
			$made = Grants::create(
				array(
					'label'        => 'Preset ' . $role,
					'level'        => 'custom',
					'caps'         => $caps,
					'confirm_full' => Catalog::needs_trust( $caps ),
				)
			);
			$this->assertSame( 'custom', Grants::get( $made['id'] )['level'], $role );
		}
	}

	public function test_unknown_stored_protection_fails_closed() {
		global $wpdb;
		$made = Grants::create( array( 'label' => 'Acme' ) );
		$wpdb->update( Installer::table( 'tokens' ), array( 'protection' => 'weird' ), array( 'id' => $made['id'] ) );
		$this->assertSame( 'protected', Grants::get( $made['id'] )['level'] );
	}

	public function test_unknown_level_throws() {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The access level is not valid.' );
		Grants::create(
			array(
				'label' => 'Acme',
				'level' => 'bogus',
			)
		);
	}
}
