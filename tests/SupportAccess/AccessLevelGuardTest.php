<?php
/**
 * Guard rules per access level.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\AuditLog;
use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\AccountGuard;
use HappyAccess\Features\SupportAccess\CapabilityGuard;
use HappyAccess\Features\SupportAccess\Catalog;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;

class AccessLevelGuardTest extends WP_UnitTestCase {

	private $owner;
	private $other_admin;
	private $die_code = 0;

	public function set_up() {
		parent::set_up();
		Installer::install();
		Capabilities::register();
		CapabilityGuard::register();
		AccountGuard::register();
		add_filter( 'wp_die_handler', array( $this, 'capture_die_handler' ), 20 );
		$this->owner       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner );
		Grants::flush_cache();
	}

	/**
	 * Creates a grant and its temp user, then logs in as that user.
	 *
	 * @param array $args Grant args.
	 * @return int Temp user id.
	 */
	private function become( array $args = array() ) {
		$made = Grants::create( array_merge( array( 'label' => 'Acme' ), $args ) );
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		wp_set_current_user( $temp );
		return $temp;
	}

	private function full() {
		return $this->become(
			array(
				'level'        => 'full',
				'confirm_full' => true,
			)
		);
	}

	private function custom( array $caps ) {
		return $this->become(
			array(
				'level'        => 'custom',
				'caps'         => $caps,
				'confirm_full' => Catalog::needs_trust( $caps ),
			)
		);
	}

	public function test_full_drops_protected_rules_but_keeps_self_protection() {
		$temp = $this->full();
		$this->assertSame( 'full', CapabilityGuard::level_for( $temp ) );
		$this->assertTrue( current_user_can( 'install_plugins' ) );
		$this->assertTrue( current_user_can( 'create_users' ) );
		$this->assertTrue( current_user_can( 'edit_user', $this->other_admin ) );
		$this->assertFalse( current_user_can( 'edit_user', $this->owner ) );
		$this->assertFalse( current_user_can( 'delete_user', $this->owner ) );
		$this->assertFalse( current_user_can( 'promote_user', $this->owner ) );
		$this->assertFalse( current_user_can( 'deactivate_plugin', 'happyaccess/happyaccess.php' ) );
		$this->assertSame( array( 'do_not_allow' ), CapabilityGuard::map( array( 'delete_plugins' ), 'delete_plugin', $temp, array( HAPPYACCESS_PLUGIN_BASENAME ) ) );
		$this->assertTrue( current_user_can( 'deactivate_plugin', 'hello.php' ) );
		$this->assertFalse( current_user_can( 'create_app_password', $temp ) );
		$this->assertFalse( current_user_can( Capabilities::MANAGE ) );
		foreach ( array( 'erase_others_personal_data', 'export_others_personal_data', 'manage_network', 'setup_network' ) as $cap ) {
			$this->assertFalse( current_user_can( $cap ), $cap );
		}
	}

	public function test_full_writes_site_options_but_not_happyaccess_options() {
		update_option( 'happyaccess_settings', array( 'marker' => 'kept' ) );
		$before = get_option( 'happyaccess_settings' );
		$this->assertNotSame( array(), $before );

		$this->full();
		update_option( 'admin_email', 'x@example.com' );
		update_option( 'happyaccess_settings', array() );

		$this->assertSame( 'x@example.com', get_option( 'admin_email' ) );
		$this->assertSame( $before, get_option( 'happyaccess_settings' ) );
	}

	public function test_full_cannot_delete_a_happyaccess_option() {
		update_option( 'happyaccess_settings', array( 'marker' => 'kept' ) );
		$this->full();
		$this->expectException( WPDieException::class );
		delete_option( 'happyaccess_settings' );
	}

	public function test_full_options_page_keeps_core_options_and_drops_happyaccess_ones() {
		$this->full();
		$allowed = CapabilityGuard::filter_allowed_options(
			array(
				'options'           => array( 'blogname' ),
				'general'           => array( 'blogname', 'admin_email', 'happyaccess_secret' ),
				'happyaccess_group' => array( 'blogname' ),
			)
		);
		$this->assertSame(
			array(
				'options' => array( 'blogname' ),
				'general' => array( 'blogname', 'admin_email' ),
			),
			$allowed
		);
	}

	public function test_full_edits_other_emails_but_not_the_creator() {
		$this->full();
		$creator_email = get_userdata( $this->owner )->user_email;

		wp_update_user(
			array(
				'ID'         => $this->other_admin,
				'user_email' => 'new@example.com',
			)
		);
		wp_update_user(
			array(
				'ID'         => $this->owner,
				'user_email' => 'stolen@example.com',
			)
		);

		$this->assertSame( 'new@example.com', get_userdata( $this->other_admin )->user_email );
		$this->assertSame( $creator_email, get_userdata( $this->owner )->user_email );
	}

	public function test_full_resets_only_block_the_creator() {
		$this->full();
		$this->assertTrue( AccountGuard::block_reset( true, $this->other_admin ) );
		$this->assertFalse( AccountGuard::block_reset( true, $this->owner ) );
	}

	public function test_full_leaves_woocommerce_checks_to_woocommerce() {
		$this->full();
		$errors = AccountGuard::block_wc_account( new WP_Error() );
		$this->assertFalse( $errors->has_errors() );
	}

	public function test_full_hides_the_plugin_and_the_owner() {
		$this->full();
		$plugins = CapabilityGuard::hide_plugin( array( HAPPYACCESS_PLUGIN_BASENAME => array() ) );
		$this->assertArrayNotHasKey( HAPPYACCESS_PLUGIN_BASENAME, $plugins );
		$args = CapabilityGuard::hide_owner( array() );
		$this->assertSame( array( $this->owner ), $args['exclude'] );
	}

	public function test_custom_with_list_users() {
		$this->custom( array( 'edit_posts', 'list_users' ) );
		$this->assertTrue( current_user_can( 'list_users' ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertFalse( current_user_can( 'edit_user', $this->owner ) );
	}

	public function test_custom_with_edit_users_edits_a_subscriber_email_but_not_the_creator() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->custom( array( 'edit_users' ) );
		$creator_email = get_userdata( $this->owner )->user_email;

		$this->assertTrue( current_user_can( 'edit_user', $subscriber ) );
		$this->assertFalse( current_user_can( 'edit_user', $this->owner ) );

		wp_update_user(
			array(
				'ID'         => $subscriber,
				'user_email' => 'customer@example.com',
			)
		);
		wp_update_user(
			array(
				'ID'         => $this->owner,
				'user_email' => 'stolen@example.com',
			)
		);

		$this->assertSame( 'customer@example.com', get_userdata( $subscriber )->user_email );
		$this->assertSame( $creator_email, get_userdata( $this->owner )->user_email );
	}

	/**
	 * The administrator Grants::owner_id() falls back to: the lowest id.
	 *
	 * @return int
	 */
	private function fallback_owner() {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);
		return (int) $admins[0];
	}

	public function test_no_creator_locks_the_fallback_owner() {
		$fallback = $this->fallback_owner();
		$this->become(
			array(
				'level'        => 'full',
				'confirm_full' => true,
				'created_by'   => 0,
			)
		);
		$this->assertFalse( current_user_can( 'edit_user', $fallback ) );
		$this->assertFalse( current_user_can( 'delete_user', $fallback ) );
		$this->assertFalse( AccountGuard::block_reset( true, $fallback ) );
		$this->assertTrue( current_user_can( 'edit_user', $this->other_admin ) );
	}

	public function test_deleted_creator_locks_the_fallback_owner() {
		$creator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $creator );
		$this->full();
		self::delete_user( $creator );
		Grants::flush_cache();
		$fallback = $this->fallback_owner();
		$this->assertFalse( current_user_can( 'edit_user', $fallback ) );
		$this->assertTrue( current_user_can( 'edit_user', $this->other_admin ) );
		$this->assertTrue( AccountGuard::block_reset( true, $creator ) );
		$this->assertFalse( AccountGuard::block_reset( true, $fallback ) );
	}

	public function test_custom_cannot_edit_the_fallback_owners_email() {
		$fallback = $this->fallback_owner();
		$email    = get_userdata( $fallback )->user_email;
		$this->become(
			array(
				'level'        => 'custom',
				'caps'         => array( 'list_users', 'edit_users' ),
				'confirm_full' => true,
				'created_by'   => 0,
			)
		);
		wp_update_user(
			array(
				'ID'         => $fallback,
				'user_email' => 'stolen@example.com',
			)
		);
		clean_user_cache( $fallback );
		$this->assertSame( $email, get_userdata( $fallback )->user_email );
	}

	public function test_protected_keeps_the_old_rules() {
		$temp = $this->become();
		$this->assertSame( 'protected', CapabilityGuard::level_for( $temp ) );
		$this->assertFalse( current_user_can( 'install_plugins' ) );
		$this->assertFalse( current_user_can( 'edit_user', $this->other_admin ) );

		$email = get_option( 'admin_email' );
		update_option( 'admin_email', 'x@example.com' );
		$this->assertSame( $email, get_option( 'admin_email' ) );
	}

	public function test_legacy_allow_installs_keeps_the_protected_rules() {
		$temp = $this->become( array( 'allow_installs' => true ) );
		$this->assertSame( 'protected', CapabilityGuard::level_for( $temp ) );
		$this->assertTrue( current_user_can( 'install_plugins' ) );
		$this->assertFalse( current_user_can( 'create_users' ) );
	}

	public function test_full_cannot_change_its_own_login_details() {
		$temp   = $this->full();
		$before = get_userdata( $temp );
		wp_update_user(
			array(
				'ID'         => $temp,
				'user_pass'  => 'a-new-password',
				'user_email' => 'self@example.com',
			)
		);
		clean_user_cache( $temp );
		$after = get_userdata( $temp );
		$this->assertSame( $before->user_pass, $after->user_pass );
		$this->assertSame( $before->user_email, $after->user_email );
		$this->assertFalse( apply_filters( 'allow_password_reset', true, $temp ) );
	}

	public function test_custom_cannot_change_its_own_login_details() {
		$temp   = $this->custom( array( 'edit_posts' ) );
		$before = get_userdata( $temp );
		wp_update_user(
			array(
				'ID'         => $temp,
				'user_pass'  => 'a-new-password',
				'user_email' => 'self@example.com',
			)
		);
		clean_user_cache( $temp );
		$after = get_userdata( $temp );
		$this->assertSame( $before->user_pass, $after->user_pass );
		$this->assertSame( $before->user_email, $after->user_email );
		$this->assertFalse( AccountGuard::skip_change_email( true, array( 'ID' => $temp ) ) );
	}

	public function test_full_role_edits_are_written_and_logged_once() {
		global $wpdb;
		$temp = $this->full();
		$key  = $wpdb->prefix . 'user_roles';

		get_role( 'editor' )->add_cap( 'happyaccess_test_one' );
		get_role( 'editor' )->add_cap( 'happyaccess_test_two' );
		$stored = get_option( $key )['editor']['capabilities'];
		get_role( 'editor' )->remove_cap( 'happyaccess_test_one' );
		get_role( 'editor' )->remove_cap( 'happyaccess_test_two' );

		$this->assertTrue( ! empty( $stored['happyaccess_test_one'] ) && ! empty( $stored['happyaccess_test_two'] ) );
		$rows = AuditLog::query(
			array(
				'event'    => 'roles_changed',
				'token_id' => Capabilities::grant_id( $temp ),
			)
		);
		$this->assertSame( 1, $rows['total'] );
		$this->assertSame( 'Changed role permissions', $rows['items'][0]['summary'] );
	}

	public function test_full_cannot_empty_the_plugin_list() {
		update_option( 'active_plugins', array( HAPPYACCESS_PLUGIN_BASENAME, 'hello.php' ) );
		$this->full();
		update_option( 'active_plugins', '' );
		$this->assertContains( HAPPYACCESS_PLUGIN_BASENAME, (array) get_option( 'active_plugins' ) );

		update_option( 'active_plugins', array( 'hello.php' ) );
		$this->assertSame( array( 'hello.php', HAPPYACCESS_PLUGIN_BASENAME ), get_option( 'active_plugins' ) );
	}

	public function test_full_cannot_empty_the_network_plugin_list() {
		update_site_option( 'active_sitewide_plugins', array( HAPPYACCESS_PLUGIN_BASENAME => 1 ) );
		$this->full();
		update_site_option( 'active_sitewide_plugins', '' );
		$this->assertSame( array( HAPPYACCESS_PLUGIN_BASENAME => 1 ), get_site_option( 'active_sitewide_plugins' ) );

		update_site_option( 'active_sitewide_plugins', array( 'hello.php' => 2 ) );
		$this->assertSame(
			array(
				'hello.php'                 => 2,
				HAPPYACCESS_PLUGIN_BASENAME => 1,
			),
			get_site_option( 'active_sitewide_plugins' )
		);
	}

	public function test_full_cannot_reach_options_through_look_alike_names() {
		$secret = get_option( 'happyaccess_secret' );
		$this->assertNotEmpty( $secret );
		update_option( 'happyaccess_settings', array( 'marker' => 'kept' ) );
		$settings = get_option( 'happyaccess_settings' );
		update_option( 'active_plugins', array( HAPPYACCESS_PLUGIN_BASENAME ) );
		$this->full();

		update_option( 'HAPPYACCESS_SECRET', 'x' );
		update_option( "h\u{00e1}ppyaccess_secret", 'y' );
		update_option( 'HappyAccess_Settings', array() );
		update_option( 'Active_Plugins', array() );
		wp_cache_delete( 'happyaccess_secret', 'options' );
		wp_cache_delete( 'happyaccess_settings', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$this->assertSame( $secret, get_option( 'happyaccess_secret' ) );
		$this->assertSame( $settings, get_option( 'happyaccess_settings' ) );
		$this->assertContains( HAPPYACCESS_PLUGIN_BASENAME, (array) get_option( 'active_plugins' ) );
		$old = array( HAPPYACCESS_PLUGIN_BASENAME => 1 );
		$this->assertSame( $old, CapabilityGuard::keep_old_site_option( array(), $old, 'Active_Sitewide_Plugins', 1 ) );
	}

	public function test_full_cannot_reach_options_through_names_the_table_folds() {
		$secret = get_option( 'happyaccess_secret' );
		update_option( 'active_plugins', array( HAPPYACCESS_PLUGIN_BASENAME ) );
		$this->full();

		$names = array(
			"\u{FF41}\u{FF43}\u{FF54}\u{FF49}\u{FF56}\u{FF45}_plugins",
			"happyacce\u{00DF}_secret",
			'happyaccess_secret ',
			"happy\u{200B}access_secret",
		);
		foreach ( $names as $name ) {
			update_option( $name, array() );
		}
		wp_cache_delete( 'happyaccess_secret', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$this->assertSame( $secret, get_option( 'happyaccess_secret' ) );
		$this->assertSame( array( HAPPYACCESS_PLUGIN_BASENAME ), get_option( 'active_plugins' ) );
		foreach ( $names as $name ) {
			$this->assertSame( 'old', CapabilityGuard::keep_old_option( 'new', $name, 'old' ), $name );
		}
	}

	public function test_full_cannot_delete_a_full_width_look_alike() {
		$this->full();
		try {
			CapabilityGuard::block_option_delete( "\u{FF41}\u{FF43}\u{FF54}\u{FF49}\u{FF56}\u{FF45}_plugins" );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $this->die_code );
		}
	}

	public function capture_die_handler() {
		return array( $this, 'record_die' );
	}

	public function record_die( $message, $title = '', $args = array() ) {
		$this->die_code = isset( $args['response'] ) ? (int) $args['response'] : 0;
		throw new WPDieException( is_string( $message ) ? esc_html( $message ) : '' );
	}

	public function test_full_writes_other_mixed_case_options() {
		add_filter( 'get_available_languages', array( $this, 'add_german' ) );
		$this->full();
		update_option( 'WPLANG', 'de_DE' );
		update_option( 'My_Plugin_Option', 1 );
		$this->assertSame( 'de_DE', get_option( 'WPLANG' ) );
		$this->assertEquals( 1, get_option( 'My_Plugin_Option' ) );
	}

	public function test_protected_writes_other_mixed_case_options() {
		$this->become();
		update_option( 'My_Plugin_Option', 1 );
		$this->assertEquals( 1, get_option( 'My_Plugin_Option' ) );
		update_option( 'Admin_Email', 'x@example.com' );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertNotSame( 'x@example.com', get_option( 'admin_email' ) );
	}

	public function add_german( $languages ) {
		$languages[] = 'de_DE';
		return $languages;
	}

	public function test_full_cannot_delete_an_option_through_another_letter_case() {
		$this->full();
		$this->expectException( WPDieException::class );
		CapabilityGuard::block_option_delete( 'HAPPYACCESS_SECRET' );
	}

	public function test_full_cannot_open_the_file_editor() {
		$this->full();
		foreach ( array( 'edit_plugins', 'edit_themes', 'edit_files' ) as $cap ) {
			$this->assertFalse( current_user_can( $cap ), $cap );
		}
	}

	public function test_every_network_cap_in_the_catalog_is_self_blocked() {
		$network = array();
		foreach ( Catalog::NEVER as $cap ) {
			if ( false !== strpos( $cap, 'network' ) || false !== strpos( $cap, '_sites' ) ) {
				$network[] = $cap;
			}
		}
		$this->assertCount( 10, $network );
		$this->assertSame( array(), array_values( array_diff( $network, CapabilityGuard::SELF_BLOCKED ) ) );
		$this->assertSame( array(), array_values( array_diff( CapabilityGuard::APP_PASSWORD_CAPS, CapabilityGuard::SELF_BLOCKED ) ) );
	}

	public function test_full_options_page_save_cannot_write_happyaccess_options() {
		$secret = get_option( 'happyaccess_secret' );
		$this->full();
		$allowed = apply_filters(
			'allowed_options',
			array(
				'options' => array( 'blogname', 'happyaccess_secret', 'happyaccess_settings' ),
				'general' => array( 'blogname' ),
			)
		);
		foreach ( $allowed as $group => $options ) {
			$this->assertStringStartsNotWith( 'happyaccess_', $group );
			foreach ( $options as $option ) {
				$this->assertStringStartsNotWith( 'happyaccess_', $option );
			}
		}

		// options.php with option_page=options saves whatever page_options lists.
		foreach ( explode( ',', 'blogname,happyaccess_secret,HAPPYACCESS_SECRET' ) as $option ) {
			update_option( $option, 'posted' );
		}
		wp_cache_delete( 'happyaccess_secret', 'options' );
		$this->assertSame( 'posted', get_option( 'blogname' ) );
		$this->assertSame( $secret, get_option( 'happyaccess_secret' ) );
	}

	public function test_custom_cannot_point_default_role_at_a_role_with_more_than_read() {
		add_role( 'happyaccess_reader', 'Reader', array( 'read' => true ) );
		$this->custom( array( 'manage_options' ) );

		update_option( 'default_role', 'administrator' );
		update_option( 'default_role', 'editor' );
		update_option( 'Default_Role', 'administrator' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'default_role', 'options' );
		$this->assertSame( 'subscriber', get_option( 'default_role' ) );

		update_option( 'default_role', 'happyaccess_reader' );
		$this->assertSame( 'happyaccess_reader', get_option( 'default_role' ) );
		update_option( 'default_role', 'subscriber' );
		$this->assertSame( 'subscriber', get_option( 'default_role' ) );
		remove_role( 'happyaccess_reader' );
	}

	public function test_custom_cannot_add_default_role_with_an_admin_role() {
		delete_option( 'default_role' );
		$this->custom( array( 'manage_options' ) );
		try {
			add_option( 'default_role', 'administrator' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $this->die_code );
		}
		$this->assertFalse( get_option( 'default_role' ) );
		add_option( 'default_role', 'subscriber' );
		$this->assertSame( 'subscriber', get_option( 'default_role' ) );
	}

	public function test_custom_cannot_write_the_role_table_outside_plugin_work() {
		global $wpdb;
		$key = $wpdb->prefix . 'user_roles';
		$this->custom( array( 'manage_options' ) );

		$table                                               = get_option( $key );
		$table['subscriber']['capabilities']['manage_options'] = true;
		update_option( $key, $table );
		update_option( strtoupper( $key ), $table );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( $key, 'options' );

		$this->assertArrayNotHasKey( 'manage_options', get_option( $key )['subscriber']['capabilities'] );
		$this->assertArrayNotHasKey( $key, CapabilityGuard::filter_allowed_options( array( 'general' => array( $key ) ) )['general'] );
	}

	public function test_custom_role_table_writes_during_plugin_work_are_kept_and_logged() {
		global $wpdb;
		$key  = $wpdb->prefix . 'user_roles';
		$temp = $this->custom( array( 'activate_plugins' ) );

		CapabilityGuard::plugin_work_started();
		get_role( 'editor' )->add_cap( 'happyaccess_test_cap' );
		CapabilityGuard::plugin_work_finished();
		$stored = get_option( $key )['editor']['capabilities'];
		get_role( 'editor' )->remove_cap( 'happyaccess_test_cap' );

		$this->assertTrue( ! empty( $stored['happyaccess_test_cap'] ) );
		$rows = AuditLog::query(
			array(
				'event'    => 'roles_changed',
				'token_id' => Capabilities::grant_id( $temp ),
			)
		);
		$this->assertSame( 1, $rows['total'] );
	}

	public function test_custom_options_page_has_no_catch_all_group() {
		$this->custom( array( 'manage_options' ) );
		$allowed = CapabilityGuard::filter_allowed_options(
			array(
				'options' => array( 'default_role', 'users_can_register' ),
				'general' => array( 'blogname' ),
			)
		);
		$this->assertSame( array( 'general' => array( 'blogname' ) ), $allowed );
	}

	public function test_custom_without_activate_plugins_keeps_the_plugin_list() {
		update_option( 'active_plugins', array( HAPPYACCESS_PLUGIN_BASENAME ) );
		$this->custom( array( 'manage_options' ) );
		$this->assertFalse( current_user_can( 'activate_plugins' ) );

		update_option( 'active_plugins', array( HAPPYACCESS_PLUGIN_BASENAME, 'hello.php' ) );
		$this->assertSame( array( HAPPYACCESS_PLUGIN_BASENAME ), get_option( 'active_plugins' ) );
		update_option( 'active_plugins', 'not a list' );
		$this->assertSame( array( HAPPYACCESS_PLUGIN_BASENAME ), get_option( 'active_plugins' ) );
	}

	public function test_custom_with_activate_plugins_changes_the_list_but_keeps_happyaccess() {
		update_option( 'active_plugins', array( HAPPYACCESS_PLUGIN_BASENAME ) );
		$this->custom( array( 'activate_plugins' ) );

		update_option( 'active_plugins', array( 'hello.php' ) );
		$this->assertSame( array( 'hello.php', HAPPYACCESS_PLUGIN_BASENAME ), get_option( 'active_plugins' ) );
	}

	public function test_full_may_still_write_default_role_and_the_role_table() {
		global $wpdb;
		$key = $wpdb->prefix . 'user_roles';
		$this->full();

		update_option( 'default_role', 'editor' );
		$this->assertSame( 'editor', get_option( 'default_role' ) );
		get_role( 'subscriber' )->add_cap( 'happyaccess_test_cap' );
		$stored = get_option( $key )['subscriber']['capabilities'];
		get_role( 'subscriber' )->remove_cap( 'happyaccess_test_cap' );
		$this->assertTrue( ! empty( $stored['happyaccess_test_cap'] ) );
	}

	public function test_unreadable_grant_fails_closed_to_protected() {
		$temp = $this->full();
		update_user_meta( $temp, 'happyaccess_token_id', 999999 );
		Grants::flush_cache();
		$this->assertSame( 'protected', CapabilityGuard::level_for( $temp ) );
		$this->assertFalse( current_user_can( 'create_users' ) );
	}
}
