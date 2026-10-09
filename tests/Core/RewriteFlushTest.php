<?php
/**
 * The rewrite flush flag and the stored plugin version.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Plugin;

class RewriteFlushTest extends WP_UnitTestCase {

	/**
	 * Times the rewrite rules were built.
	 *
	 * @var int
	 */
	private $built = 0;

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		$this->built = 0;
	}

	public function count_build() {
		++$this->built;
	}

	private function autoload_of( $name ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	public function test_turning_two_step_on_or_off_sets_an_autoloaded_flag() {
		Features::set( 'two_step', false );
		update_option( Features::REWRITE_FLUSH_OPTION, '0', true );

		Features::set( 'two_step', true );
		$this->assertSame( '1', get_option( Features::REWRITE_FLUSH_OPTION ) );
		$this->assertArrayHasKey( Features::REWRITE_FLUSH_OPTION, wp_load_alloptions() );
		$this->assertContains( $this->autoload_of( Features::REWRITE_FLUSH_OPTION ), wp_autoload_values_to_autoload() );

		update_option( Features::REWRITE_FLUSH_OPTION, '0' );
		Settings::update( array( 'security' => array( 'max_attempts' => 6 ) ) );
		Features::set( 'two_step', true );
		$this->assertSame( '0', get_option( Features::REWRITE_FLUSH_OPTION ), 'Saving while it stays on asks for nothing.' );

		Features::set( 'two_step', false );
		$this->assertSame( '1', get_option( Features::REWRITE_FLUSH_OPTION ), 'Turning it off takes the endpoint rule away.' );
	}

	public function test_an_old_flag_that_is_not_autoloaded_becomes_autoloaded() {
		delete_option( Features::REWRITE_FLUSH_OPTION );
		add_option( Features::REWRITE_FLUSH_OPTION, '1', '', false );

		Features::request_rewrite_flush();

		$this->assertContains( $this->autoload_of( Features::REWRITE_FLUSH_OPTION ), wp_autoload_values_to_autoload() );
	}

	public function test_a_migration_sets_the_flag() {
		update_option( Features::REWRITE_FLUSH_OPTION, '0', true );
		update_option( 'happyaccess_db_version', '0.0.0' );

		Installer::migrate();

		$this->assertSame( Installer::DB_VERSION, get_option( 'happyaccess_db_version' ) );
		$this->assertSame( '1', get_option( Features::REWRITE_FLUSH_OPTION ) );
	}

	public function test_a_pending_flag_flushes_once_and_goes_back_to_zero() {
		$this->set_permalink_structure( '/%postname%/' );
		add_action( 'generate_rewrite_rules', array( $this, 'count_build' ) );
		update_option( Features::REWRITE_FLUSH_OPTION, '1', true );

		Features::maybe_flush_rewrites();
		Features::maybe_flush_rewrites();

		$this->assertSame( 1, $this->built, 'Flushed once, not on every request.' );
		$this->assertSame( '0', get_option( Features::REWRITE_FLUSH_OPTION ) );
		$this->assertArrayHasKey( Features::REWRITE_FLUSH_OPTION, wp_load_alloptions(), 'The row stays, so it is never looked up on its own.' );
	}

	public function test_only_the_request_that_changes_the_row_flushes() {
		global $wpdb;
		$this->set_permalink_structure( '/%postname%/' );
		add_action( 'generate_rewrite_rules', array( $this, 'count_build' ) );
		update_option( Features::REWRITE_FLUSH_OPTION, '1', true );
		// Another request consumed it after this one read the cached value.
		$wpdb->update( $wpdb->options, array( 'option_value' => '0' ), array( 'option_name' => Features::REWRITE_FLUSH_OPTION ) );

		Features::maybe_flush_rewrites();

		$this->assertSame( 0, $this->built );
		$this->assertSame( '0', get_option( Features::REWRITE_FLUSH_OPTION ), 'The stale cached value is dropped.' );
	}

	public function test_an_idle_flag_costs_no_query() {
		global $wpdb;
		update_option( Features::REWRITE_FLUSH_OPTION, '0', true );
		wp_load_alloptions();

		$before = $wpdb->num_queries;
		Features::maybe_flush_rewrites();
		$this->assertSame( $before, $wpdb->num_queries );
	}

	public function test_a_version_change_sets_the_flag_once() {
		update_option( Features::REWRITE_FLUSH_OPTION, '0', true );
		update_option( Installer::VERSION_OPTION, '1.0.9' );

		Installer::note_version();
		$this->assertSame( HAPPYACCESS_VERSION, get_option( Installer::VERSION_OPTION ) );
		$this->assertContains( $this->autoload_of( Installer::VERSION_OPTION ), wp_autoload_values_to_autoload() );
		$this->assertSame( '1', get_option( Features::REWRITE_FLUSH_OPTION ) );

		update_option( Features::REWRITE_FLUSH_OPTION, '0' );
		Installer::note_version();
		$this->assertSame( '0', get_option( Features::REWRITE_FLUSH_OPTION ), 'The same version asks for nothing.' );
	}

	public function test_the_version_waits_for_the_migration() {
		delete_option( Installer::VERSION_OPTION );
		update_option( 'happyaccess_db_version', '1.0.4' );

		Installer::note_version();

		$this->assertFalse( get_option( Installer::VERSION_OPTION ), 'The migration reads the 1.0.x option of the same name first.' );
	}

	public function test_the_flag_is_consumed_on_init_whatever_the_features() {
		Features::set( 'two_step', false );
		Plugin::init();

		$this->assertSame( PHP_INT_MAX, has_action( 'init', array( Features::class, 'maybe_flush_rewrites' ) ) );
	}
}
