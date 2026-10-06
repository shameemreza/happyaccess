<?php
/**
 * CLI helper tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\Cli;
use HappyAccess\Features\SupportAccess\Grants;

class CliTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_grant_args_mapping() {
		$args = Cli::grant_args( array( 'label' => 'Acme', 'expires' => '3d', 'one-time' => true, 'allow-installs' => true, 'role' => 'editor' ) );
		$this->assertSame( 'Acme', $args['label'] );
		$this->assertSame( 3 * DAY_IN_SECONDS, $args['duration'] );
		$this->assertTrue( $args['one_time'] );
		$this->assertTrue( $args['allow_installs'] );
		$this->assertSame( 'editor', $args['role'] );
		$this->assertSame( 12 * HOUR_IN_SECONDS, Cli::grant_args( array( 'label' => 'x', 'expires' => '12h' ) )['duration'] );
	}

	public function test_bad_unit_throws() {
		$this->expectException( InvalidArgumentException::class );
		Cli::grant_args( array( 'label' => 'x', 'expires' => '3w' ) );
	}

	public function test_boolean_flags_are_strict() {
		$this->assertFalse( Cli::grant_args( array( 'label' => 'x', 'allow-installs' => 'false' ) )['allow_installs'] );
		$this->assertFalse( Cli::grant_args( array( 'label' => 'x', 'allow-installs' => '0' ) )['allow_installs'] );
		$this->assertFalse( Cli::grant_args( array( 'label' => 'x', 'one-time' => 'no' ) )['one_time'] );
		$this->assertFalse( Cli::grant_args( array( 'label' => 'x' ) )['allow_installs'] );
		$this->assertTrue( Cli::grant_args( array( 'label' => 'x', 'allow-installs' => true ) )['allow_installs'] );
		$this->assertTrue( Cli::grant_args( array( 'label' => 'x', 'allow-installs' => 'true' ) )['allow_installs'] );
		$this->assertTrue( Cli::grant_args( array( 'label' => 'x', 'one-time' => '' ) )['one_time'] );
	}

	public function test_unknown_boolean_value_throws() {
		$this->expectException( InvalidArgumentException::class );
		Cli::grant_args( array( 'label' => 'x', 'allow-installs' => 'maybe' ) );
	}

	public function test_zero_duration_throws() {
		$this->expectException( InvalidArgumentException::class );
		Cli::grant_args( array( 'label' => 'x', 'expires' => '0d' ) );
	}

	public function test_fractional_duration_throws() {
		$this->expectException( InvalidArgumentException::class );
		Cli::grant_args( array( 'label' => 'x', 'expires' => '3.5d' ) );
	}

	public function test_bad_notify_throws() {
		$this->expectException( InvalidArgumentException::class );
		Cli::grant_args( array( 'label' => 'x', 'notify' => 'sometimes' ) );
	}

	public function test_redirect_and_notify_mapping() {
		$args = Cli::grant_args( array( 'label' => 'x', 'redirect' => '/wp-admin/plugins.php', 'notify' => 'every', 'email' => 'a@example.com' ) );
		$this->assertSame( '/wp-admin/plugins.php', $args['redirect_to'] );
		$this->assertSame( 'every', $args['notify'] );
		$this->assertSame( 'a@example.com', $args['email'] );
		$this->assertArrayNotHasKey( 'duration', $args );
	}

	public function test_list_rows() {
		Grants::create( array( 'label' => 'Acme' ) );
		$rows = Cli::list_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'id', 'label', 'role', 'status', 'expires', 'logins' ), array_keys( $rows[0] ) );
	}
}
