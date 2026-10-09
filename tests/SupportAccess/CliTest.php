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

	public function test_notify_off_is_accepted_as_text_or_as_the_false_wp_cli_makes_of_it() {
		$this->assertSame( 'off', Cli::grant_args( array( 'label' => 'x', 'notify' => 'off' ) )['notify'] );
		$this->assertSame( 'off', Cli::grant_args( array( 'label' => 'x', 'notify' => false ) )['notify'], '--no-notify, or a YAML "off", reaches the command as false.' );
		$this->assertSame( 'first', Cli::grant_args( array( 'label' => 'x', 'notify' => 'first' ) )['notify'] );
		$this->assertSame( 'every', Cli::grant_args( array( 'label' => 'x', 'notify' => 'every' ) )['notify'] );
	}

	public function test_notify_true_still_throws() {
		$this->expectException( InvalidArgumentException::class );
		Cli::grant_args( array( 'label' => 'x', 'notify' => true ) );
	}

	/**
	 * WP-CLI reads the options list as YAML and checks the value against it
	 * before the command runs. A bare off is the boolean false in YAML, so
	 * --notify=off never matched. Each listed value must stay a string.
	 */
	public function test_the_notify_options_wp_cli_checks_are_all_strings() {
		$doc   = ( new ReflectionMethod( Cli::class, 'grant' ) )->getDocComment();
		$lines = array_map(
			static function ( $line ) {
				return trim( preg_replace( '/^\s*\*\s?/', '', $line ) );
			},
			explode( "\n", (string) $doc )
		);
		$start = array_search( '[--notify=<mode>]', $lines, true );
		$this->assertNotFalse( $start );

		$values = array();
		$fences = 0;
		for ( $i = $start + 1; $i < count( $lines ) && $fences < 2; $i++ ) {
			if ( '---' === $lines[ $i ] ) {
				++$fences;
			} elseif ( 0 === strpos( $lines[ $i ], '- ' ) ) {
				$values[] = trim( substr( $lines[ $i ], 2 ) );
			}
		}

		foreach ( $values as $value ) {
			$this->assertDoesNotMatchRegularExpression( '/^(off|on|yes|no|y|n|true|false|null|~)$/i', $value, 'A bare YAML boolean or null: ' . $value );
		}
		$this->assertSame( array( 'first', 'every', 'off' ), array_map( static function ( $value ) {
			return trim( $value, '\'"' );
		}, $values ) );
	}

	/**
	 * WP-CLI is stubbed here, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_grant_without_user_names_the_first_administrator_in_its_warning() {
		require dirname( __DIR__ ) . '/Support/Stubs/wp-cli.php';
		$first = get_userdata( (int) get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID' ) )[0] );
		wp_set_current_user( 0 );

		( new Cli() )->grant( array(), array( 'label' => 'Acme' ) );

		$warnings = array_values(
			array_filter(
				WP_CLI::$calls,
				static function ( $call ) {
					return 'warning' === $call[0];
				}
			)
		);
		$this->assertSame(
			array( array( 'warning', 'No --user given. Login alerts and any posts they write will go to ' . $first->user_login . '.' ) ),
			$warnings
		);
	}

	/**
	 * WP-CLI is stubbed here, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_extend_says_when_the_pass_now_ends() {
		require dirname( __DIR__ ) . '/Support/Stubs/wp-cli.php';
		$made = Grants::create(
			array(
				'label'    => 'Acme',
				'duration' => DAY_IN_SECONDS,
			)
		);

		( new Cli() )->extend( array( (string) $made['id'] ), array( 'by' => '2d' ) );

		$grant = Grants::get( $made['id'] );
		$this->assertSame( $made['expires_at'] + 2 * DAY_IN_SECONDS, $grant['expires_at'] );
		$this->assertSame(
			array( array( 'success', 'Grant ' . $made['id'] . ' now ends ' . wp_date( 'Y-m-d H:i', $grant['expires_at'] ) . '.' ) ),
			WP_CLI::$calls
		);
	}

	public function test_list_rows() {
		Grants::create( array( 'label' => 'Acme' ) );
		$rows = Cli::list_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'id', 'label', 'role', 'status', 'expires', 'logins' ), array_keys( $rows[0] ) );
	}
}
