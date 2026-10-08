<?php
/**
 * Tests for the check for another plugin's two-step login.
 *
 * The plugin classes are stubbed under tests/Support/Stubs. A class can't be
 * unloaded, so every test that loads a stub runs in its own process.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\OtherTwoFactor;

class OtherTwoFactorTest extends WP_UnitTestCase {

	public function test_without_another_plugin_nobody_has_2fa() {
		$user = self::factory()->user->create_and_get();

		$this->assertFalse( class_exists( 'Two_Factor_Core', false ) );
		$this->assertFalse( OtherTwoFactor::plugin_active() );
		$this->assertSame( array(), OtherTwoFactor::active_plugins() );
		$this->assertFalse( OtherTwoFactor::user_has_2fa( $user ) );
	}

	public function test_the_filter_can_say_a_user_has_2fa() {
		$user = self::factory()->user->create_and_get();
		$seen = array();
		add_filter(
			'happyaccess_user_has_other_2fa',
			function ( $has, $who ) use ( &$seen ) {
				$seen[] = array( $has, $who instanceof WP_User ? $who->ID : 0 );
				return true;
			},
			10,
			2
		);

		$this->assertTrue( OtherTwoFactor::user_has_2fa( $user ) );
		$this->assertSame( array( array( false, $user->ID ) ), $seen );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_two_factor_users_have_2fa() {
		require dirname( __DIR__ ) . '/Support/Stubs/two-factor-core.php';
		$with    = self::factory()->user->create_and_get();
		$without = self::factory()->user->create_and_get();
		Two_Factor_Core::$users = array( $with->ID );

		$this->assertTrue( OtherTwoFactor::plugin_active() );
		$this->assertSame( array( 'Two Factor' ), OtherTwoFactor::active_plugins() );
		$this->assertTrue( OtherTwoFactor::user_has_2fa( $with ) );
		$this->assertFalse( OtherTwoFactor::user_has_2fa( $without ) );

		add_filter( 'happyaccess_user_has_other_2fa', '__return_false' );
		$this->assertFalse( OtherTwoFactor::user_has_2fa( $with ), 'The filter has the last word.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wordfence_login_security_users_have_2fa() {
		require dirname( __DIR__ ) . '/Support/Stubs/wordfence-ls-users.php';
		$with    = self::factory()->user->create_and_get();
		$without = self::factory()->user->create_and_get();
		\WordfenceLS\Controller_Users::$users = array( $with->ID );

		$this->assertTrue( OtherTwoFactor::plugin_active() );
		$this->assertSame( array( 'Wordfence' ), OtherTwoFactor::active_plugins() );
		$this->assertTrue( OtherTwoFactor::user_has_2fa( $with ) );
		$this->assertFalse( OtherTwoFactor::user_has_2fa( $without ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_class_without_the_method_is_ignored() {
		require dirname( __DIR__ ) . '/Support/Stubs/two-factor-core-without-method.php';
		$user = self::factory()->user->create_and_get();

		$this->assertFalse( OtherTwoFactor::user_has_2fa( $user ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_2fa_users_have_2fa() {
		require dirname( __DIR__ ) . '/Support/Stubs/wp-2fa.php';
		$with    = self::factory()->user->create_and_get();
		$without = self::factory()->user->create_and_get();
		\WP2FA\Admin\Helpers\User_Helper::$users = array( $with->ID );

		$this->assertTrue( OtherTwoFactor::plugin_active() );
		$this->assertSame( array( 'WP 2FA' ), OtherTwoFactor::active_plugins() );
		$this->assertTrue( OtherTwoFactor::user_has_2fa( $with ) );
		$this->assertFalse( OtherTwoFactor::user_has_2fa( $without ) );
	}

	/**
	 * Kadence checks a user id only, and its own Two_Factor_Core is not the
	 * Two Factor plugin.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kadence_security_users_have_2fa() {
		require dirname( __DIR__ ) . '/Support/Stubs/kadence-two-factor.php';
		require dirname( __DIR__ ) . '/Support/Stubs/kadence-modules.php';
		ITSEC_Modules::$active = array( 'two-factor' => true );
		$with    = self::factory()->user->create_and_get();
		$without = self::factory()->user->create_and_get();
		ITSEC_Two_Factor::$users = array( $with->ID );
		wp_set_current_user( $with->ID );

		$this->assertTrue( OtherTwoFactor::plugin_active() );
		$this->assertSame( array( 'Kadence Security' ), OtherTwoFactor::active_plugins() );
		$this->assertTrue( OtherTwoFactor::user_has_2fa( $with ) );
		$this->assertFalse( OtherTwoFactor::user_has_2fa( $without ), 'The user asked about, not the one logged in.' );
	}

	/**
	 * Kadence Security loads its two-factor classes only while the module is
	 * on, but its class map can load them at any time. So the module has to
	 * be on too.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kadence_security_counts_only_while_its_two_factor_module_is_on() {
		require dirname( __DIR__ ) . '/Support/Stubs/kadence-two-factor.php';
		require dirname( __DIR__ ) . '/Support/Stubs/kadence-modules.php';
		$user                    = self::factory()->user->create_and_get();
		ITSEC_Two_Factor::$users = array( $user->ID );

		ITSEC_Modules::$active = array( 'two-factor' => false );
		$this->assertSame( array(), OtherTwoFactor::active_plugins(), 'Module off.' );
		$this->assertFalse( OtherTwoFactor::user_has_2fa( $user ), 'Module off.' );

		ITSEC_Modules::$active = array( 'two-factor' => true );
		$this->assertSame( array( 'Kadence Security' ), OtherTwoFactor::active_plugins(), 'Module on.' );
		$this->assertTrue( OtherTwoFactor::user_has_2fa( $user ), 'Module on.' );
	}

	/**
	 * Without the module list there is no way to tell the module is on.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kadence_security_without_its_module_list_is_ignored() {
		require dirname( __DIR__ ) . '/Support/Stubs/kadence-two-factor.php';
		$user                    = self::factory()->user->create_and_get();
		ITSEC_Two_Factor::$users = array( $user->ID );

		$this->assertSame( array(), OtherTwoFactor::active_plugins() );
		$this->assertFalse( OtherTwoFactor::user_has_2fa( $user ) );
	}

	/**
	 * A plugin's class map can load a class whose plugin part is off, so a
	 * class only an autoloader can reach doesn't count.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_classes_only_an_autoloader_can_reach_are_not_detected() {
		$user  = self::factory()->user->create_and_get();
		$asked = $this->autoload(
			array(
				'Two_Factor_Core'                 => 'two-factor-core.php',
				'WordfenceLS\Controller_Users'   => 'wordfence-ls-users.php',
				'WP2FA\WP2FA'                    => 'wp-2fa.php',
				'WP2FA\Admin\Helpers\User_Helper' => 'wp-2fa.php',
			),
			$user->ID
		);

		$this->assertSame( array(), OtherTwoFactor::active_plugins() );
		$this->assertFalse( OtherTwoFactor::user_has_2fa( $user ) );
		$this->assertSame( array(), $asked->classes, 'No class is autoloaded.' );
	}

	/**
	 * Kadence's class map has ITSEC_Two_Factor and its Two_Factor_Core.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kadence_classes_only_an_autoloader_can_reach_are_not_detected() {
		$user  = self::factory()->user->create_and_get();
		$asked = $this->autoload(
			array(
				'ITSEC_Two_Factor' => 'kadence-two-factor.php',
				'Two_Factor_Core'  => 'kadence-two-factor.php',
				'ITSEC_Modules'    => 'kadence-modules.php',
			),
			$user->ID
		);

		$this->assertSame( array(), OtherTwoFactor::active_plugins() );
		$this->assertFalse( OtherTwoFactor::user_has_2fa( $user ) );
		$this->assertSame( array(), $asked->classes, 'No class is autoloaded.' );
	}

	/**
	 * Registers an autoloader that loads the stubs, marks the user as having
	 * two-step login in each, and turns Kadence's module on.
	 *
	 * @param array $map     Class name to stub file.
	 * @param int   $user_id User the stubs say has two-step login.
	 * @return object Its `classes` lists the classes it was asked for.
	 */
	private function autoload( array $map, $user_id ) {
		$asked          = new stdClass();
		$asked->classes = array();
		spl_autoload_register(
			static function ( $class_name ) use ( $map, $user_id, $asked ) {
				if ( ! isset( $map[ $class_name ] ) ) {
					return;
				}
				$asked->classes[] = $class_name;
				require_once dirname( __DIR__ ) . '/Support/Stubs/' . $map[ $class_name ];
				foreach ( array( 'Two_Factor_Core', 'ITSEC_Two_Factor', 'WordfenceLS\Controller_Users', 'WP2FA\Admin\Helpers\User_Helper' ) as $stub ) {
					if ( class_exists( $stub, false ) && property_exists( $stub, 'users' ) ) {
						$stub::$users = array( (int) $user_id );
					}
				}
				if ( class_exists( 'ITSEC_Modules', false ) ) {
					ITSEC_Modules::$active = array( 'two-factor' => true );
				}
			}
		);
		return $asked;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_active_plugin_is_named_once() {
		require dirname( __DIR__ ) . '/Support/Stubs/two-factor-core.php';
		require dirname( __DIR__ ) . '/Support/Stubs/wordfence-ls-users.php';
		require dirname( __DIR__ ) . '/Support/Stubs/wp-2fa.php';

		$this->assertSame( array( 'Two Factor', 'Wordfence', 'WP 2FA' ), OtherTwoFactor::active_plugins() );
	}
}
