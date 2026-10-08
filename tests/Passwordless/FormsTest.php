<?php
/**
 * Passwordless inline form and shortcode tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\Passwordless\Feature;
use HappyAccess\Features\Passwordless\Forms;
use HappyAccess\Login\Router;

class PasswordlessFormsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
		$this->reset_assets();
		$_GET = array();
		// Shortcodes live outside the hooks the test case restores, so another test's Feature::register() can leave this one behind.
		remove_shortcode( 'happyaccess_login' );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		$this->reset_assets();
		$_GET = array();
		remove_shortcode( 'happyaccess_login' );
		Router::reset();
		parent::tear_down();
	}

	private function reset_assets() {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	private function boot( $enabled = true ) {
		Features::set( 'passwordless', $enabled );
		Feature::register();
	}

	private function woo_form_end() {
		ob_start();
		do_action( 'woocommerce_login_form_end' );
		return ob_get_clean();
	}

	private function enqueued() {
		return wp_script_is( 'happyaccess-login', 'enqueued' ) || wp_style_is( 'happyaccess-login', 'enqueued' );
	}

	public function test_my_account_prints_the_form_only_when_its_setting_is_on() {
		$this->boot();

		$html = $this->woo_form_end();
		$this->assertStringContainsString( 'happyaccess-pl', $html );
		$this->assertStringContainsString( 'data-context="woo_account"', $html );
		$this->assertStringContainsString( 'Email me a login code instead', $html );

		Settings::update( array( 'passwordless' => array( 'show_on' => array( 'woo_account' => false ) ) ) );
		$this->assertSame( '', $this->woo_form_end() );
	}

	public function test_the_assets_load_only_when_a_form_printed() {
		$this->boot();
		Settings::update( array( 'passwordless' => array( 'show_on' => array( 'woo_account' => false ) ) ) );
		$this->woo_form_end();
		$this->assertFalse( $this->enqueued() );

		Settings::update( array( 'passwordless' => array( 'show_on' => array( 'woo_account' => true ) ) ) );
		$this->woo_form_end();
		$this->assertTrue( wp_script_is( 'happyaccess-login', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'happyaccess-login', 'enqueued' ) );

		$script = wp_scripts()->registered['happyaccess-login'];
		$this->assertSame( array(), $script->deps, 'No jQuery or other dependency.' );
		$inline = implode( "\n", (array) wp_scripts()->get_data( 'happyaccess-login', 'before' ) );
		$this->assertStringContainsString( wp_json_encode( rest_url( 'happyaccess/v1/passwordless/' ) ), $inline );
	}

	public function test_two_forms_add_the_settings_once_and_get_their_own_ids() {
		$this->boot();
		$first  = Forms::render( array( 'context' => 'shortcode' ) );
		$second = Forms::render( array( 'context' => 'shortcode' ) );

		$this->assertSame( 1, preg_match( '/aria-controls="([^"]+)"/', $first, $one ) );
		$this->assertSame( 1, preg_match( '/aria-controls="([^"]+)"/', $second, $two ) );
		$this->assertNotSame( $one[1], $two[1] );
		// Core keeps a false first entry in the inline list, so count the strings.
		$this->assertCount( 1, array_filter( (array) wp_scripts()->get_data( 'happyaccess-login', 'before' ), 'is_string' ) );
	}

	public function test_the_form_markup_is_accessible_and_holds_no_form_element() {
		$this->boot();
		$html = Forms::render(
			array(
				'context'     => 'woo_account',
				'redirect_to' => home_url( '/my-account/' ),
			)
		);

		$this->assertStringNotContainsString( '<form', $html, 'It prints inside the WooCommerce form, so it cannot hold its own.' );
		$this->assertSame( 1, preg_match( '/<button type="button"[^>]*aria-expanded="false"[^>]*aria-controls="([^"]+)"/', $html, $toggle ) );
		$this->assertStringContainsString( 'id="' . $toggle[1] . '"', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertSame( 2, preg_match_all( '/<input type="text"[^>]*id="([^"]+)"/', $html, $fields ) );
		foreach ( $fields[1] as $id ) {
			$this->assertStringContainsString( '<label for="' . $id . '"', $html );
		}
		$this->assertStringContainsString( 'autocomplete="one-time-code"', $html );
		$this->assertStringContainsString( 'Use a different email', $html );
		$this->assertStringContainsString( 'data-redirect="' . esc_attr( home_url( '/my-account/' ) ) . '"', $html );
		$this->assertStringContainsString( 'class="button', $html, 'The submit buttons keep the theme button look.' );
	}

	public function test_urls_in_the_markup_go_through_esc_url() {
		$this->boot();
		$url  = home_url( '/pay/?pay_for_order=true&key=wc_order_abc' );
		$html = Forms::render(
			array(
				'context'     => 'shortcode',
				'redirect_to' => $url,
			)
		);

		$this->assertStringContainsString( 'data-redirect="' . esc_url( $url ) . '"', $html );
		$this->assertStringContainsString( '&#038;key=wc_order_abc', $html );
		$this->assertStringContainsString( 'href="#happyaccess-pl-', $html );
	}

	public function test_the_toggle_starts_hidden_and_a_plain_link_stands_in_until_the_script_runs() {
		$this->boot();
		$html = Forms::render( array( 'context' => 'shortcode' ) );

		$this->assertSame( 1, preg_match( '/<p class="happyaccess-pl__toggle-row"[^>]*\bhidden\b[^>]*>\s*<button type="button"/', $html ), 'The toggle row is hidden until the script unhides it.' );
		$this->assertSame( 1, preg_match( '/<p class="happyaccess-pl__fallback"(?![^>]*\bhidden\b)[^>]*>\s*<a href="([^"]+)"/', $html, $link ), 'The plain link is visible by default.' );
		$this->assertStringContainsString( 'step=request', html_entity_decode( $link[1] ) );
		$this->assertStringContainsString( 'wp-login.php', $link[1] );
	}

	public function test_the_toggle_is_a_link_by_default() {
		$this->boot();
		$html = Forms::render( array( 'context' => 'shortcode' ) );

		$this->assertStringContainsString( 'happyaccess-pl__toggle--link', $html );
		$this->assertStringNotContainsString( 'happyaccess-pl__toggle--button', $html );
		$this->assertSame( 1, preg_match( '/<button type="button" class="([^"]*happyaccess-pl__toggle[^"]*)"/', $html, $toggle ) );
		$this->assertStringNotContainsString( 'button ', $toggle[1] . ' ', 'The link style carries no theme button class.' );
		$this->assertStringNotContainsString( 'wp-element-button', $toggle[1] );
	}

	public function test_the_site_setting_picks_the_button_style() {
		$this->boot();
		Settings::update( array( 'passwordless' => array( 'toggle_style' => 'button' ) ) );
		$html = Forms::render( array( 'context' => 'shortcode' ) );

		$this->assertSame( 1, preg_match( '/<button type="button" class="([^"]*happyaccess-pl__toggle[^"]*)"/', $html, $toggle ) );
		$this->assertStringContainsString( 'happyaccess-pl__toggle--button', $toggle[1] );
		$this->assertContains( 'button', explode( ' ', $toggle[1] ) );
		$this->assertStringNotContainsString( 'happyaccess-pl__toggle--link', $html );
	}

	public function test_the_style_argument_overrides_the_setting_and_a_bad_one_falls_back() {
		$this->boot();

		$html = Forms::render(
			array(
				'context' => 'shortcode',
				'style'   => 'button',
			)
		);
		$this->assertStringContainsString( 'happyaccess-pl__toggle--button', $html );

		Settings::update( array( 'passwordless' => array( 'toggle_style' => 'button' ) ) );
		$html = Forms::render(
			array(
				'context' => 'shortcode',
				'style'   => 'link',
			)
		);
		$this->assertStringContainsString( 'happyaccess-pl__toggle--link', $html );

		foreach ( array( 'bogus', 'BUTTON', array( 'link' ), 5 ) as $bad ) {
			$html = Forms::render(
				array(
					'context' => 'shortcode',
					'style'   => $bad,
				)
			);
			$this->assertStringContainsString( 'happyaccess-pl__toggle--button', $html, 'An invalid style uses the site setting (button).' );
		}

		$html = Forms::render(
			array(
				'context' => 'shortcode',
				'style'   => '',
			)
		);
		$this->assertStringContainsString( 'happyaccess-pl__toggle--button', $html, 'An empty style means the site setting.' );
	}

	public function test_the_shortcode_style_attribute_overrides_the_setting() {
		$this->boot();

		$this->assertStringContainsString( 'happyaccess-pl__toggle--link', do_shortcode( '[happyaccess_login]' ) );
		$this->assertStringContainsString( 'happyaccess-pl__toggle--button', do_shortcode( '[happyaccess_login style="button"]' ) );

		Settings::update( array( 'passwordless' => array( 'toggle_style' => 'button' ) ) );
		$this->assertStringContainsString( 'happyaccess-pl__toggle--button', do_shortcode( '[happyaccess_login]' ) );
		$this->assertStringContainsString( 'happyaccess-pl__toggle--link', do_shortcode( '[happyaccess_login style="link"]' ) );
		$this->assertStringContainsString( 'happyaccess-pl__toggle--button', do_shortcode( '[happyaccess_login style="fancy"]' ), 'An invalid attribute falls back to the setting.' );
	}

	public function test_the_woo_forms_use_the_site_style() {
		$this->boot();
		$this->assertStringContainsString( 'happyaccess-pl__toggle--link', $this->woo_form_end() );

		Settings::update( array( 'passwordless' => array( 'toggle_style' => 'button' ) ) );
		$this->assertStringContainsString( 'happyaccess-pl__toggle--button', $this->woo_form_end() );
	}

	public function test_the_asset_version_adds_the_file_time_only_while_debugging() {
		$this->boot();
		Forms::render( array( 'context' => 'shortcode' ) );

		$ver = wp_scripts()->registered['happyaccess-login']->ver;
		$this->assertStringStartsWith( HAPPYACCESS_VERSION, $ver );
		if ( WP_DEBUG ) {
			$this->assertSame( HAPPYACCESS_VERSION . '.' . filemtime( HAPPYACCESS_PLUGIN_DIR . 'assets/login.js' ), $ver );
		} else {
			$this->assertSame( HAPPYACCESS_VERSION, $ver );
		}
	}

	public function test_an_offsite_redirect_is_dropped() {
		$this->boot();
		$html = Forms::render(
			array(
				'context'     => 'shortcode',
				'redirect_to' => 'https://evil.example.com/',
			)
		);
		$this->assertStringContainsString( 'data-redirect=""', $html );
	}

	public function test_my_account_keeps_a_valid_redirect_from_the_address() {
		$this->boot();
		$_GET['redirect_to'] = home_url( '/checkout/' );
		$this->assertStringContainsString( 'data-redirect="' . esc_attr( home_url( '/checkout/' ) ) . '"', $this->woo_form_end() );

		$_GET['redirect_to'] = 'https://evil.example.com/';
		$this->assertStringNotContainsString( 'evil.example.com', $this->woo_form_end() );
	}

	public function test_a_logged_in_visitor_gets_no_woo_form() {
		$this->boot();
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( '', $this->woo_form_end() );
		$this->assertFalse( $this->enqueued() );
	}

	public function test_nothing_prints_while_the_database_updates() {
		$this->boot();
		update_option( 'happyaccess_db_version', '0.0.0' );
		$this->assertSame( '', $this->woo_form_end() );
		$this->assertSame( '', do_shortcode( '[happyaccess_login]' ) );
		$this->assertFalse( $this->enqueued() );
	}

	public function test_the_shortcode_prints_the_form_for_a_visitor() {
		$this->boot();
		$html = do_shortcode( '[happyaccess_login redirect_to="' . home_url( '/welcome/' ) . '"]' );

		$this->assertStringContainsString( 'data-context="shortcode"', $html );
		$this->assertStringContainsString( 'data-redirect="' . esc_attr( home_url( '/welcome/' ) ) . '"', $html );
		$this->assertTrue( wp_script_is( 'happyaccess-login', 'enqueued' ) );
	}

	public function test_the_shortcode_prints_nothing_for_a_logged_in_user() {
		$this->boot();
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( '', do_shortcode( '[happyaccess_login]' ) );
		$this->assertFalse( $this->enqueued() );
	}

	public function test_with_the_feature_off_there_is_no_hook_and_no_shortcode() {
		$this->boot( false );

		$this->assertFalse( has_action( 'woocommerce_login_form_end' ) );
		$this->assertFalse( shortcode_exists( 'happyaccess_login' ) );
		$this->assertSame( '', $this->woo_form_end() );
		$this->assertFalse( $this->enqueued() );
	}

	/**
	 * Runs in its own process because it defines WooCommerce functions that
	 * must not leak into other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_checkout_form_follows_its_own_setting_and_returns_to_checkout() {
		if ( ! function_exists( 'is_checkout' ) ) {
			eval( 'function is_checkout() { return true; } function wc_get_checkout_url() { return home_url( "/checkout/" ); }' );
		}
		$this->boot();
		Settings::update( array( 'passwordless' => array( 'show_on' => array( 'woo_account' => false ) ) ) );

		$html = $this->woo_form_end();
		$this->assertStringContainsString( 'data-context="woo_checkout"', $html );
		$this->assertStringContainsString( 'data-redirect="' . esc_attr( home_url( '/checkout/' ) ) . '"', $html );

		Settings::update(
			array(
				'passwordless' => array(
					'show_on' => array(
						'woo_account'  => true,
						'woo_checkout' => false,
					),
				),
			)
		);
		$this->assertSame( '', $this->woo_form_end() );
	}

	public function provide_order_endpoints() {
		return array(
			'order pay'      => array( 'order-pay', '/checkout/order-pay/55/?pay_for_order=true&key=wc_order_abc' ),
			'order received' => array( 'order-received', '/checkout/order-received/55/?key=wc_order_abc' ),
		);
	}

	/**
	 * Runs in its own process because it defines WooCommerce functions that
	 * must not leak into other tests.
	 *
	 * @dataProvider provide_order_endpoints
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_order_pay_and_order_received_forms_return_to_the_same_page( $endpoint, $uri ) {
		if ( ! function_exists( 'is_checkout' ) ) {
			// Both endpoints are part of the checkout page, so is_checkout() is true there.
			eval(
				'function is_checkout() { return true; }
				function wc_get_checkout_url() { return home_url( "/checkout/" ); }
				function is_wc_endpoint_url( $endpoint = false ) { return $endpoint === $GLOBALS["happyaccess_test_endpoint"]; }'
			);
		}
		$GLOBALS['happyaccess_test_endpoint'] = $endpoint;
		$_SERVER['REQUEST_URI']               = $uri;
		$this->boot();

		$html = $this->woo_form_end();
		$this->assertStringContainsString( 'data-context="woo_checkout"', $html );
		$this->assertStringContainsString( 'data-redirect="' . esc_url( home_url( $uri ) ) . '"', $html );
		$this->assertStringNotContainsString( 'data-redirect="' . esc_url( home_url( '/checkout/' ) ) . '"', $html, 'Not the generic checkout.' );
	}
}
