<?php
/**
 * Access level tests for grants.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
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

	public function test_user_management_caps_need_a_super_admin_on_a_network() {
		$admin = get_current_user_id();
		foreach ( array( 'create_users', 'edit_users' ) as $cap ) {
			$args = array(
				'label'        => 'Acme',
				'level'        => 'custom',
				'caps'         => array( $cap ),
				'confirm_full' => true,
			);
			if ( is_multisite() ) {
				try {
					Grants::create( $args );
					$this->fail( 'A site admin on a network gave ' . $cap );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertSame( "You can't give a permission you don't have: " . $cap, $e->getMessage() );
				}
				grant_super_admin( $admin );
			}
			$made = Grants::create( $args );
			$this->assertContains( $cap, Grants::get( $made['id'] )['caps'], $cap );
			if ( is_multisite() ) {
				revoke_super_admin( $admin );
			}
		}
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

	public function test_a_level_that_is_not_text_throws_the_level_message_without_a_warning() {
		foreach ( array( array( 'full' ), 7, true ) as $level ) {
			try {
				Grants::create(
					array(
						'label' => 'Acme',
						'level' => $level,
					)
				);
				$this->fail( 'A non-text level was accepted.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'The access level is not valid.', $e->getMessage() );
			}
		}
	}

	public function test_a_mixed_case_level_throws() {
		foreach ( array( 'Full', 'CUSTOM', 'Protected' ) as $level ) {
			try {
				Grants::create(
					array(
						'label'        => 'Acme',
						'level'        => $level,
						'caps'         => array( 'edit_posts' ),
						'confirm_full' => true,
					)
				);
				$this->fail( $level . ' was accepted.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'The access level is not valid.', $e->getMessage(), $level );
			}
		}
	}

	public function test_confirm_full_must_be_a_real_true() {
		foreach ( array( '1', 1, 'true', 'yes' ) as $confirm ) {
			try {
				Grants::create(
					array(
						'label'        => 'Acme',
						'level'        => 'full',
						'confirm_full' => $confirm,
					)
				);
				$this->fail( var_export( $confirm, true ) . ' confirmed a full pass.' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Confirm that you trust this person with full access.', $e->getMessage() );
			}
		}
	}

	public function test_grant_created_meta_names_the_level_and_counts_custom_caps() {
		$custom = Grants::create(
			array(
				'label'    => 'Custom',
				'level'    => 'custom',
				'caps'     => array( 'edit_posts', 'upload_files' ),
				'duration' => DAY_IN_SECONDS,
				'one_time' => true,
			)
		);
		$full   = Grants::create(
			array(
				'label'        => 'Full',
				'level'        => 'full',
				'confirm_full' => true,
			)
		);

		$custom_meta = AuditLog::query( array( 'event' => 'grant_created', 'token_id' => $custom['id'] ) )['items'][0]['meta'];
		$full_meta   = AuditLog::query( array( 'event' => 'grant_created', 'token_id' => $full['id'] ) )['items'][0]['meta'];

		$this->assertSame(
			array(
				'role'       => 'administrator',
				'duration'   => DAY_IN_SECONDS,
				'one_time'   => true,
				'level'      => 'custom',
				'caps_count' => 3,
			),
			$custom_meta
		);
		$this->assertSame( 'full', $full_meta['level'] );
		$this->assertArrayNotHasKey( 'caps_count', $full_meta );
	}

	public function test_no_caps_are_stored_for_protected_and_full() {
		global $wpdb;
		$protected = Grants::create(
			array(
				'label' => 'Protected',
				'caps'  => array( 'edit_posts' ),
			)
		);
		$full      = Grants::create(
			array(
				'label'        => 'Full',
				'level'        => 'full',
				'caps'         => array( 'edit_posts' ),
				'confirm_full' => true,
			)
		);
		foreach ( array( $protected['id'], $full['id'] ) as $id ) {
			$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT restrictions FROM ' . Installer::table( 'tokens' ) . ' WHERE id = %d', $id ) );
			$this->assertArrayNotHasKey( 'caps', json_decode( $raw, true ), (string) $id );
			$this->assertSame( array(), Grants::get( $id )['caps'] );
		}
	}

	public function test_a_role_that_is_not_text_throws_the_role_message_without_a_warning() {
		foreach ( array( 'protected', 'full' ) as $level ) {
			foreach ( array( array( 'administrator' ), 7 ) as $role ) {
				try {
					Grants::create(
						array(
							'label'        => 'Acme',
							'level'        => $level,
							'role'         => $role,
							'confirm_full' => true,
						)
					);
					$this->fail( 'A non-text role was accepted on ' . $level . '.' );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertSame( 'The role does not exist.', $e->getMessage(), $level );
				}
			}
		}
	}
}
