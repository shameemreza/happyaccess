<?php
/**
 * Internal bypass flag tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Internal;
use HappyAccess\Core\Settings;
use HappyAccess\Features\SupportAccess\CapabilityGuard;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class InternalTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::register();
		CapabilityGuard::register();
		Internal::reset();
	}

	private function become_temp_user() {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $owner );
		Grants::flush_cache();
		$made = Grants::create( array( 'label' => 'Acme' ) );
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		wp_set_current_user( $temp );
		return array(
			'user'  => $temp,
			'grant' => (int) $made['id'],
		);
	}

	public function test_run_returns_the_result_and_is_active_only_inside() {
		$this->assertFalse( Internal::active() );
		$result = Internal::run(
			function () {
				return Internal::active() ? 'inside' : 'outside';
			}
		);
		$this->assertSame( 'inside', $result );
		$this->assertFalse( Internal::active() );
	}

	public function test_run_nests() {
		Internal::run(
			function () {
				Internal::run(
					function () {
						$this->assertTrue( Internal::active() );
					}
				);
				$this->assertTrue( Internal::active() );
			}
		);
		$this->assertFalse( Internal::active() );
	}

	public function test_run_restores_the_counter_when_the_callable_throws() {
		try {
			Internal::run(
				function () {
					throw new RuntimeException( 'boom' );
				}
			);
			$this->fail( 'The exception should pass through.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertFalse( Internal::active() );
	}

	public function test_internal_settings_update_works_as_a_temp_user_but_a_direct_write_does_not() {
		$this->become_temp_user();
		Internal::run(
			function () {
				Settings::update( array( 'security' => array( 'max_attempts' => 7 ) ) );
			}
		);
		$this->assertSame( 7, Settings::get( 'security.max_attempts' ) );

		$stored                             = get_option( 'happyaccess_settings' );
		$direct                             = $stored;
		$direct['security']['max_attempts'] = 3;
		update_option( 'happyaccess_settings', $direct );
		$this->assertSame( 7, Settings::get( 'security.max_attempts' ) );
	}

	public function test_internal_run_also_lets_a_temp_user_delete_a_protected_option() {
		$this->become_temp_user();
		update_option( 'happyaccess_scratch', 'x' );
		Internal::run(
			function () {
				delete_option( 'happyaccess_scratch' );
			}
		);
		$this->assertFalse( get_option( 'happyaccess_scratch' ) );
	}
}
