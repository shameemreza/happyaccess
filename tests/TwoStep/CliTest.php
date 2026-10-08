<?php
/**
 * The wp happyaccess twostep reset command.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\BackupCodes;
use HappyAccess\Features\TwoStep\Cli;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;

class TwoStepCliTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			wp_set_current_user( 0 );
		} finally {
			parent::tear_down();
		}
	}

	/**
	 * A user with the app, email and backup codes on.
	 *
	 * @return WP_User
	 */
	private function two_step_user() {
		$user = self::factory()->user->create_and_get(
			array(
				'user_login' => 'sam' . wp_rand( 1000, 9999 ),
				'user_email' => 'sam' . wp_rand( 1000, 9999 ) . '@example.org',
			)
		);
		UserState::enable_app( $user->ID, Totp::new_secret() );
		UserState::enable_email( $user->ID );
		BackupCodes::generate( $user->ID );
		return $user;
	}

	public function provide_ways_to_name_the_user() {
		return array(
			'id'    => array( 'ID' ),
			'login' => array( 'user_login' ),
			'email' => array( 'user_email' ),
		);
	}

	/**
	 * @dataProvider provide_ways_to_name_the_user
	 */
	public function test_reset_works_by_id_login_and_email( $field ) {
		$user = $this->two_step_user();

		$result = Cli::reset_user( (string) $user->{$field} );
		$this->assertSame( 'Two-step login is off for ' . $user->user_login . '.', $result );
		$this->assertFalse( UserState::is_enabled( $user->ID ) );
		$this->assertSame( 0, BackupCodes::remaining( $user->ID ) );

		$rows = AuditLog::query( array( 'event' => 'twostep_reset' ) )['items'];
		$this->assertCount( 1, $rows );
		$this->assertSame( $user->ID, (int) $rows[0]['user_id'] );
		$this->assertSame( 'cli', $rows[0]['meta']['source'] );
	}

	public function test_an_unknown_user_is_an_error() {
		$this->assertWPError( Cli::reset_user( 'nobody-here' ) );
		$this->assertWPError( Cli::reset_user( '' ) );
	}

	public function test_a_user_without_two_step_still_gets_the_message() {
		$user = self::factory()->user->create_and_get();
		$this->assertSame( 'Two-step login is off for ' . $user->user_login . '.', Cli::reset_user( $user->user_login ) );
	}

	/**
	 * WP-CLI is stubbed here, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_register_adds_the_command_only_under_wp_cli() {
		require dirname( __DIR__ ) . '/Support/Stubs/wp-cli.php';

		Cli::register();
		$this->assertSame( array(), WP_CLI::$commands, 'Not without the WP_CLI constant.' );

		define( 'WP_CLI', true );
		Cli::register();
		$this->assertSame( array( 'happyaccess twostep' => Cli::class ), WP_CLI::$commands );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_reset_command_prints_success_and_resets() {
		require dirname( __DIR__ ) . '/Support/Stubs/wp-cli.php';
		$user = $this->two_step_user();

		( new Cli() )->reset( array( $user->user_login ), array() );

		$this->assertSame( array( array( 'success', 'Two-step login is off for ' . $user->user_login . '.' ) ), WP_CLI::$calls );
		$this->assertFalse( UserState::is_enabled( $user->ID ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_reset_command_needs_exactly_one_known_user() {
		require dirname( __DIR__ ) . '/Support/Stubs/wp-cli.php';
		$user = $this->two_step_user();
		$cli  = new Cli();

		$cli->reset( array( 'nobody-here' ), array() );
		$cli->reset( array(), array() );
		$cli->reset( array( $user->user_login, 'another' ), array() );

		$error = array( 'error', 'No user matches that id, login or email address.' );
		$this->assertSame( array( $error, $error, $error ), WP_CLI::$calls );
		$this->assertTrue( UserState::is_enabled( $user->ID ), 'Nothing was reset.' );
	}
}
