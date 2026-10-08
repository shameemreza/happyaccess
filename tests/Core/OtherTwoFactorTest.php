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
