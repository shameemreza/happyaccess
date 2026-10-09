<?php
/**
 * The two-step section in WooCommerce My Account.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Clock;
use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\TwoStep\BackupCodes;
use HappyAccess\Features\TwoStep\Challenge;
use HappyAccess\Features\TwoStep\Feature;
use HappyAccess\Features\TwoStep\MyAccount;
use HappyAccess\Features\TwoStep\Profile;
use HappyAccess\Features\TwoStep\Totp;
use HappyAccess\Features\TwoStep\UserState;
use HappyAccess\Login\Router;
use HappyAccess\Plugin;

class TwoStepMyAccountTest extends WP_UnitTestCase {

	const PASSWORD = 'correct horse battery';

	/**
	 * Settings globals as they were before rest_api_init ran.
	 *
	 * @var array
	 */
	private $settings_globals = array();

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
		Router::reset();
		Challenge::reset();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		foreach ( array( 'new_allowed_options', 'wp_registered_settings' ) as $name ) {
			$this->settings_globals[ $name ] = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;
		}
		if ( null === get_role( 'customer' ) ) {
			add_role( 'customer', 'Customer', array( 'read' => true ) );
		}
		Features::set( 'two_step', true );
		$this->set_policy( array( 'customer' => 'optional' ) );
		Feature::register();
		$this->built = 0;
		add_filter( 'pre_wp_mail', '__return_true' );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		// The parent always runs, so a failed reset can't leave the test transaction open.
		try {
			$GLOBALS['wp_rest_server']                     = null;
			$GLOBALS['wp_rest_application_password_uuid'] = null;
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
			foreach ( $this->settings_globals as $name => $value ) {
				$GLOBALS[ $name ] = $value;
			}
			foreach ( array( Profile::HANDLE, 'happyaccess-twostep-setup', 'happyaccess-qrcode' ) as $handle ) {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
			Clock::freeze( null );
			Router::reset();
			Challenge::reset();
		} finally {
			parent::tear_down();
		}
	}

	/**
	 * Saves the role policy, replacing the old one.
	 *
	 * @param array $policy Role slug to choice.
	 * @return void
	 */
	private function set_policy( array $policy ) {
		Settings::update( array( 'two_step' => array( 'role_policy' => array_fill_keys( array_keys( wp_roles()->roles ), 'off' ) ) ) );
		Settings::update( array( 'two_step' => array( 'role_policy' => $policy ) ) );
	}

	private function customer() {
		return self::factory()->user->create_and_get(
			array(
				'role'      => 'customer',
				'user_pass' => self::PASSWORD,
			)
		);
	}

	private function admin() {
		$admin = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin->ID );
		}
		return $admin;
	}

	/**
	 * The menu WooCommerce builds, without the payment methods item.
	 *
	 * @return array
	 */
	private function core_menu() {
		return array(
			'dashboard'       => 'Dashboard',
			'orders'          => 'Orders',
			'edit-address'    => 'Addresses',
			'edit-account'    => 'Account details',
			'customer-logout' => 'Log out',
		);
	}

	/**
	 * Starts a login session for the user and sends its cookie, as a browser would.
	 *
	 * @param int $user_id User id.
	 * @return string The session token.
	 */
	private function start_session( $user_id ) {
		$expires = time() + HOUR_IN_SECONDS;
		$token   = WP_Session_Tokens::get_instance( $user_id )->create( $expires );

		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expires, 'logged_in', $token );
		return $token;
	}

	private function call( $path, array $params = array() ) {
		$request = new WP_REST_Request( 'POST', '/happyaccess/v1/twostep/' . $path );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	/**
	 * Loads WooCommerce and a My Account page. Only in a separate process.
	 *
	 * @return int The page id.
	 */
	private function load_woocommerce() {
		$woo = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( ! file_exists( $woo ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed next to HappyAccess.' );
		}
		require_once $woo;
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'my-account',
				'post_content' => '[woocommerce_my_account]',
			)
		);
		update_option( 'woocommerce_myaccount_page_id', $page );
		Feature::register();
		return $page;
	}

	/**
	 * Makes a page the main query, as a visit to it would. go_to() would
	 * also run WooCommerce's product filters, which need its tables.
	 *
	 * @param int  $page_id  Page id.
	 * @param bool $endpoint Whether the URL ends in the endpoint.
	 * @return void
	 */
	private function visit( $page_id, $endpoint ) {
		$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $page_id ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['wp']->query_vars = $endpoint ? array( 'page_id' => $page_id, 'two-step-login' => '' ) : array( 'page_id' => $page_id );
	}

	public function count_build() {
		++$this->built;
	}

	public function test_the_menu_item_goes_after_account_details_for_an_optional_customer() {
		wp_set_current_user( $this->customer()->ID );

		$items = MyAccount::menu_items( $this->core_menu() );

		$this->assertSame( array( 'dashboard', 'orders', 'edit-address', 'edit-account', 'two-step-login', 'customer-logout' ), array_keys( $items ) );
		$this->assertSame( 'Two-step login', $items['two-step-login'] );
	}

	public function test_without_account_details_the_item_goes_before_log_out() {
		wp_set_current_user( $this->customer()->ID );
		$menu = $this->core_menu();
		unset( $menu['edit-account'] );

		$this->assertSame( array( 'dashboard', 'orders', 'edit-address', 'two-step-login', 'customer-logout' ), array_keys( MyAccount::menu_items( $menu ) ) );
	}

	public function test_the_menu_item_and_section_hide_for_an_off_role_a_temp_user_and_another_plugins_two_step() {
		$customer = $this->customer();
		wp_set_current_user( $customer->ID );

		$this->set_policy( array( 'customer' => 'off' ) );
		$this->assertSame( $this->core_menu(), MyAccount::menu_items( $this->core_menu() ), 'A role with two-step off.' );
		ob_start();
		MyAccount::render();
		$this->assertSame( '', ob_get_clean() );

		UserState::enable_email( $customer->ID );
		$this->assertArrayHasKey( 'two-step-login', MyAccount::menu_items( $this->core_menu() ), 'A method left on can still be turned off.' );
		UserState::disable_email( $customer->ID );

		$this->set_policy( array( 'customer' => 'optional' ) );
		$temp = $this->customer();
		update_user_meta( $temp->ID, 'happyaccess_temp_user', 1 );
		wp_set_current_user( $temp->ID );
		$this->assertSame( $this->core_menu(), MyAccount::menu_items( $this->core_menu() ), 'A Support Access temp user.' );

		wp_set_current_user( $customer->ID );
		add_filter( 'happyaccess_user_has_other_2fa', '__return_true' );
		$this->assertSame( $this->core_menu(), MyAccount::menu_items( $this->core_menu() ), 'Another plugin handles their two-step login.' );
		ob_start();
		MyAccount::render();
		$this->assertSame( '', ob_get_clean() );
		remove_filter( 'happyaccess_user_has_other_2fa', '__return_true' );

		wp_set_current_user( 0 );
		$this->assertSame( $this->core_menu(), MyAccount::menu_items( $this->core_menu() ), 'Logged out.' );
	}

	public function test_the_endpoint_renders_the_shared_section_with_woocommerce_classes() {
		$customer = $this->customer();
		wp_set_current_user( $customer->ID );

		ob_start();
		MyAccount::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-happyaccess-twostep', $html );
		$this->assertStringContainsString( 'id="happyaccess-twostep"', $html, 'Cancel moves focus back to the section.' );
		$this->assertStringContainsString( 'data-happyaccess-action="app-begin"', $html );
		$this->assertStringContainsString( 'data-happyaccess-action="email-begin"', $html );
		$this->assertStringContainsString( 'data-happyaccess-action="email-confirm"', $html );
		$this->assertStringContainsString( 'Turn on the app or email codes first.', $html );
		$this->assertStringContainsString( 'class="happyaccess-ts-qr"', $html );
		$this->assertStringContainsString( 'id="happyaccess-ts-codes"', $html );
		$this->assertStringContainsString( 'data-happyaccess-panel="recheck"', $html );
		$this->assertStringContainsString( 'data-happyaccess-methods', $html );
		$this->assertStringContainsString( 'woocommerce-Button button', $html );
		$this->assertStringContainsString( 'woocommerce-form-row', $html );
		$this->assertStringContainsString( 'woocommerce-Input', $html );
		$this->assertSame( 1, preg_match( '/<button type="button" class="([^"]+)" data-happyaccess-copy>/', $html, $copy ) );
		$this->assertStringContainsString( 'woocommerce-Button button', $copy[1], 'The codes tools use the theme buttons too.' );
		$this->assertStringNotContainsString( 'form-table', $html, 'No wp-admin table on the front end.' );
		$this->assertStringNotContainsString( 'button-primary', $html );
		$this->assertStringNotContainsString( '<h2', $html, 'The page title already names the section.' );

		UserState::enable_app( $customer->ID, Totp::new_secret() );
		BackupCodes::generate( $customer->ID );
		ob_start();
		MyAccount::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'data-happyaccess-action="app-disable"', $html );
		$this->assertStringContainsString( 'data-happyaccess-action="backup-regenerate"', $html );
		$this->assertStringContainsString( '<span class="happyaccess-ts-state">10 codes left</span>', $html );
		$this->assertSame( 3, substr_count( $html, 'class="happyaccess-ts-row"' ), 'Status and button share one line per method.' );
	}

	/**
	 * WooCommerce styles a .form-row label and field only inside a form, so
	 * on a block theme the label sat next to a thin field. Each panel with a
	 * field is a form in My Account, and its buttons sit 12px apart.
	 */
	public function test_the_account_setup_fields_sit_in_woocommerce_form_rows_with_spaced_buttons() {
		$customer = $this->customer();
		wp_set_current_user( $customer->ID );

		ob_start();
		MyAccount::render();
		$html = ob_get_clean();

		$fields = array(
			'app'     => 'happyaccess-ts-profile-code',
			'email'   => 'happyaccess-ts-profile-email-code',
			'recheck' => 'happyaccess-ts-recheck',
		);
		foreach ( $fields as $panel => $field ) {
			$this->assertStringContainsString( '<form class="happyaccess-ts-panel" data-happyaccess-panel="' . $panel . '" hidden>', $html, $panel );
			$this->assertMatchesRegularExpression( '#<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="' . $field . '"[^>]*>[^<]+</label><input [^>]*id="' . $field . '" class="woocommerce-Input woocommerce-Input--text input-text#', $html, $panel );
		}
		$this->assertSame( substr_count( $html, '<form' ), substr_count( $html, '</form>' ) );
		$this->assertSame( 3, substr_count( $html, '<p class="happyaccess-ts-actions">' ) );
		$this->assertMatchesRegularExpression( '#<p class="happyaccess-ts-actions"><button [^>]*data-happyaccess-action="app-confirm"[^>]*>Turn on two-step login</button> <button [^>]*data-happyaccess-action="cancel"#', $html );

		$css = (string) file_get_contents( HAPPYACCESS_PLUGIN_DIR . 'assets/twostep-setup.css' );
		$this->assertMatchesRegularExpression( '/\.happyaccess-ts-account \.happyaccess-ts-actions \{[^}]*display: flex;[^}]*gap: 12px;/', $css );
		$this->assertMatchesRegularExpression( '/\.happyaccess-ts-account \.happyaccess-ts-panel \.form-row label \{[^}]*display: block;/', $css );
	}

	public function test_a_required_customer_sees_no_turn_off_for_the_last_method() {
		$this->set_policy( array( 'customer' => 'required' ) );
		$customer = $this->customer();
		wp_set_current_user( $customer->ID );
		UserState::enable_app( $customer->ID, Totp::new_secret() );

		ob_start();
		MyAccount::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Your role needs two-step login.', $html );
		$this->assertStringNotContainsString( 'data-happyaccess-action="app-disable"', $html );
	}

	public function test_a_customer_sets_up_the_app_and_makes_backup_codes_through_the_routes() {
		Clock::freeze( 1790000000 );
		// An earlier test may leave an application password marked as used.
		$GLOBALS['wp_rest_application_password_uuid'] = null;
		$GLOBALS['wp_rest_server']                     = null;
		rest_get_server();
		$customer = $this->customer();
		wp_set_current_user( $customer->ID );
		$this->start_session( $customer->ID );

		$config = Profile::script_config();
		$this->assertSame( rest_url( 'happyaccess/v1/twostep/' ), $config['restUrl'] );
		$this->assertSame( 1, wp_verify_nonce( $config['nonce'], 'wp_rest' ), 'The script sends a wp_rest nonce for this user.' );

		$this->assertSame( 'happyaccess_recheck_required', $this->call( 'app/begin' )->as_error()->get_error_code() );
		$this->assertSame( 200, $this->call( 'recheck', array( 'password' => self::PASSWORD ) )->get_status() );

		$begin = $this->call( 'app/begin' );
		$this->assertSame( 200, $begin->get_status() );
		$secret  = $begin->get_data()['secret'];
		$confirm = $this->call( 'app/confirm', array( 'code' => Totp::code( $secret, Totp::step_for( Clock::now() ) ) ) );
		$this->assertSame( 200, $confirm->get_status() );
		$this->assertCount( 10, $confirm->get_data()['codes'] );
		$this->assertTrue( UserState::app_enabled( $customer->ID ) );

		$again = $this->call( 'backup/regenerate' );
		$this->assertSame( 200, $again->get_status() );
		$this->assertCount( 10, $again->get_data()['codes'] );
		$this->assertNotSame( $confirm->get_data()['codes'], $again->get_data()['codes'] );

		// Another browser of the same customer has its own session and no re-check.
		$this->start_session( $customer->ID );
		$this->assertSame( 'happyaccess_recheck_required', $this->call( 'backup/regenerate' )->as_error()->get_error_code() );
	}

	public function test_the_rewrite_rules_flush_once_with_the_endpoint() {
		$this->set_permalink_structure( '/%postname%/' );
		MyAccount::add_endpoint();
		add_action( 'generate_rewrite_rules', array( $this, 'count_build' ) );
		update_option( Features::REWRITE_FLUSH_OPTION, '1', true );

		Features::maybe_flush_rewrites();
		Features::maybe_flush_rewrites();

		$this->assertSame( 1, $this->built, 'Flushed once, not on every request.' );
		$this->assertSame( '0', get_option( Features::REWRITE_FLUSH_OPTION ) );
		$this->assertStringContainsString( 'two-step-login', implode( ' ', array_keys( (array) get_option( 'rewrite_rules' ) ) ) );
	}

	public function test_without_woocommerce_feature_register_adds_no_my_account_hook() {
		$this->assertFalse( class_exists( 'WooCommerce', false ) );
		$this->assertFalse( has_filter( 'woocommerce_account_menu_items', array( MyAccount::class, 'menu_items' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_get_query_vars', array( MyAccount::class, 'query_vars' ) ) );
		$this->assertFalse( has_action( 'woocommerce_account_two-step-login_endpoint', array( MyAccount::class, 'render' ) ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( MyAccount::class, 'enqueue' ) ) );
		$this->assertFalse( has_action( 'init', array( MyAccount::class, 'maybe_flush' ) ) );
	}

	public function test_without_woocommerce_the_links_stay_on_the_profile() {
		$customer = $this->customer();
		$this->assertSame( Profile::url(), Profile::url_for( $customer ) );
	}

	/**
	 * Boots the plugin, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_woocommerce_boot_loads_no_my_account_class() {
		Plugin::boot();
		do_action( 'plugins_loaded' );
		$this->assertFalse( class_exists( MyAccount::class, false ) );
	}

	/**
	 * Loads WooCommerce, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_woocommerce_and_two_step_off_nothing_is_added() {
		$woo = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( ! file_exists( $woo ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed next to HappyAccess.' );
		}
		require_once $woo;
		Features::set( 'two_step', false );
		Plugin::boot();
		do_action( 'plugins_loaded' );

		$this->assertFalse( class_exists( MyAccount::class, false ) );
		$this->assertArrayNotHasKey( 'two-step-login', WC()->query->get_query_vars() );
	}

	/**
	 * Loads WooCommerce, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_woocommerce_the_endpoint_title_query_var_menu_and_links_work() {
		$this->load_woocommerce();
		$customer = $this->customer();
		$admin    = $this->admin();
		$endpoint = wc_get_account_endpoint_url( 'two-step-login' );

		$this->assertSame( 'two-step-login', WC()->query->get_query_vars()['two-step-login'] );
		$this->assertSame( 'Two-step login', WC()->query->get_endpoint_title( 'two-step-login' ) );
		wp_set_current_user( $customer->ID );
		$menu = apply_filters( 'woocommerce_account_menu_items', $this->core_menu(), array() );
		$this->assertSame( 'Two-step login', $menu['two-step-login'] );

		$this->assertTrue( MyAccount::uses_my_account( $customer ) );
		$this->assertFalse( MyAccount::uses_my_account( $admin ) );
		$this->assertSame( $endpoint, Profile::url_for( $customer ) );
		$this->assertSame( Profile::url(), Profile::url_for( $admin ) );

		// WooCommerce's own switches decide, as they do for wp-admin.
		add_filter( 'woocommerce_prevent_admin_access', '__return_false' );
		$this->assertFalse( MyAccount::uses_my_account( $customer ) );
		remove_filter( 'woocommerce_prevent_admin_access', '__return_false' );
		add_filter( 'woocommerce_disable_admin_bar', '__return_false' );
		$this->assertFalse( MyAccount::uses_my_account( $customer ) );
		remove_filter( 'woocommerce_disable_admin_bar', '__return_false' );
		add_filter( 'woocommerce_prevent_admin_access', '__return_true' );
		$this->assertTrue( MyAccount::uses_my_account( $admin ) );
	}

	/**
	 * Loads WooCommerce, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_a_my_account_page_the_links_stay_on_the_profile() {
		$this->load_woocommerce();
		$customer = $this->customer();
		update_option( 'woocommerce_myaccount_page_id', 0 );

		$this->assertFalse( MyAccount::uses_my_account( $customer ) );
		$this->assertSame( Profile::url(), Profile::url_for( $customer ) );
	}

	/**
	 * Loads WooCommerce, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_assets_load_only_on_the_endpoint() {
		$page     = $this->load_woocommerce();
		$customer = $this->customer();
		wp_set_current_user( $customer->ID );
		$other = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->visit( $other, true );
		MyAccount::enqueue();
		$this->assertFalse( wp_script_is( Profile::HANDLE, 'enqueued' ), 'Not on another page.' );

		$this->visit( $page, false );
		$this->assertTrue( is_account_page() );
		MyAccount::enqueue();
		$this->assertFalse( wp_script_is( Profile::HANDLE, 'enqueued' ), 'Not on the account dashboard.' );
		$this->assertFalse( wp_style_is( Profile::HANDLE, 'enqueued' ) );

		$this->visit( $page, true );
		MyAccount::enqueue();
		$this->assertTrue( wp_script_is( Profile::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Profile::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'happyaccess-qrcode', 'registered' ) );
		$inline = implode( "\n", (array) wp_scripts()->get_data( Profile::HANDLE, 'before' ) );
		$this->assertStringContainsString( 'happyaccessTwoStepProfile', $inline );
		$this->assertStringContainsString( '"nonce":"' . wp_create_nonce( 'wp_rest' ) . '"', $inline );

		wp_dequeue_script( Profile::HANDLE );
		wp_dequeue_style( Profile::HANDLE );
		$this->set_policy( array( 'customer' => 'off' ) );
		MyAccount::enqueue();
		$this->assertFalse( wp_script_is( Profile::HANDLE, 'enqueued' ), 'Not for a user the section is hidden from.' );
	}

	/**
	 * Loads WooCommerce, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_hidden_user_on_the_endpoint_goes_to_the_dashboard() {
		$page     = $this->load_woocommerce();
		$customer = $this->customer();
		wp_set_current_user( $customer->ID );
		$this->visit( $page, true );

		$this->assertSame( '', MyAccount::redirect_target() );
		$this->set_policy( array( 'customer' => 'off' ) );
		$this->assertSame( wc_get_page_permalink( 'myaccount' ), MyAccount::redirect_target() );
	}

	/**
	 * Loads WooCommerce, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_low_backup_codes_notice_in_my_account_links_to_the_endpoint() {
		$this->load_woocommerce();
		// Only the notice: WooCommerce's own callbacks need its templates.
		remove_all_actions( 'woocommerce_account_content' );
		Challenge::register();
		$customer = $this->customer();
		wp_set_current_user( $customer->ID );
		set_transient( Challenge::NOTICE_TRANSIENT . $customer->ID, 2, DAY_IN_SECONDS );

		ob_start();
		do_action( 'woocommerce_account_content' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'woocommerce-info', $html );
		$this->assertStringContainsString( 'You have 2 backup codes left.', $html );
		$this->assertStringContainsString( 'href="' . esc_url( wc_get_account_endpoint_url( 'two-step-login' ) ) . '"', $html );
		$this->assertStringContainsString( Profile::link_for( $customer ), $html, 'Escaping on output leaves the link as built.' );
		$this->assertStringNotContainsString( 'profile.php', $html );
	}
}
