<?php
/**
 * HappyAccess Login block tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Features;
use HappyAccess\Core\Installer;
use HappyAccess\Core\Settings;
use HappyAccess\Features\Passwordless\Feature;
use HappyAccess\Login\Router;

class PasswordlessBlockTest extends WP_UnitTestCase {

	const NAME = 'happyaccess/login';

	public function set_up() {
		parent::set_up();
		Installer::install();
		update_option( 'happyaccess_db_version', Installer::DB_VERSION );
		delete_option( Settings::OPTION );
		Router::reset();
		$this->reset_assets();
		$this->unregister_block();
		$_GET = array();
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		$this->unregister_block();
		$this->reset_assets();
		$_GET = array();
		Router::reset();
		parent::tear_down();
	}

	private function reset_assets() {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	/**
	 * The block registry lives outside the hooks the test case restores.
	 */
	private function unregister_block() {
		$registry = WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( self::NAME ) ) {
			$registry->unregister( self::NAME );
		}
	}

	private function boot( $enabled = true ) {
		Features::set( 'passwordless', $enabled );
		Feature::register();
		if ( $enabled ) {
			Feature::register_block();
		}
	}

	private function render( $attrs = array() ) {
		$json = empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs );
		return do_blocks( '<!-- wp:happyaccess/login' . $json . ' /-->' );
	}

	public function test_block_json_holds_the_agreed_values() {
		$meta = json_decode( (string) file_get_contents( HAPPYACCESS_PLUGIN_DIR . 'blocks/login/block.json' ), true );

		$this->assertSame( 3, $meta['apiVersion'] );
		$this->assertSame( self::NAME, $meta['name'] );
		$this->assertSame( 'HappyAccess Login', $meta['title'] );
		$this->assertSame( 'widgets', $meta['category'] );
		$this->assertSame( 'happyaccess', $meta['textdomain'] );
		$this->assertSame( 'file:./render.php', $meta['render'] );
		$this->assertSame( 'file:../../build/blocks.js', $meta['editorScript'] );
		$this->assertSame( array( 'redirectTo', 'toggleStyle' ), array_keys( $meta['attributes'] ) );
		$this->assertSame( 'string', $meta['attributes']['redirectTo']['type'] );
		$this->assertSame( array( '', 'link', 'button' ), $meta['attributes']['toggleStyle']['enum'] );
		$this->assertSame( array( 'spacing', 'align' ), array_keys( array_intersect_key( $meta['supports'], array_flip( array( 'spacing', 'align' ) ) ) ) );
		$this->assertCount( 2, $meta['supports'], 'Spacing and alignment only.' );
	}

	public function test_the_block_is_registered_only_while_the_feature_is_on() {
		Features::set( 'passwordless', false );
		Feature::register();
		$this->assertFalse( has_action( 'init', array( Feature::class, 'register_block' ) ) );
		$this->assertFalse( WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) );

		Features::set( 'passwordless', true );
		Feature::register();
		$this->assertNotFalse( has_action( 'init', array( Feature::class, 'register_block' ) ) );
		Feature::register_block();

		$type = WP_Block_Type_Registry::get_instance()->get_registered( self::NAME );
		$this->assertInstanceOf( WP_Block_Type::class, $type );
		$this->assertSame( 'HappyAccess Login', $type->title );
		$this->assertArrayHasKey( 'redirectTo', $type->attributes );
		$this->assertArrayHasKey( 'toggleStyle', $type->attributes );
		$this->assertTrue( $type->is_dynamic() );
	}

	public function test_registering_twice_is_safe() {
		$this->boot();
		Feature::register_block();
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) );
	}

	public function test_a_post_with_the_block_renders_nothing_while_the_feature_is_off() {
		$this->boot( false );
		$this->assertSame( '', trim( $this->render() ) );
		$this->assertFalse( wp_script_is( 'happyaccess-login', 'enqueued' ) );
	}

	public function test_a_visitor_gets_the_form() {
		$this->boot();
		$html = $this->render();

		$this->assertStringContainsString( 'data-context="block"', $html );
		$this->assertStringContainsString( 'Send login code', $html );
		$this->assertStringContainsString( 'wp-block-happyaccess-login', $html, 'The wrapper carries the block classes, so spacing and alignment apply.' );
		$this->assertTrue( wp_script_is( 'happyaccess-login', 'enqueued' ) );
	}

	public function test_a_logged_in_user_gets_nothing() {
		$this->boot();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', trim( $this->render() ) );
		$this->assertFalse( wp_script_is( 'happyaccess-login', 'enqueued' ) );
	}

	public function test_an_offsite_redirect_is_dropped_and_an_onsite_one_is_kept() {
		$this->boot();

		$html = $this->render( array( 'redirectTo' => 'https://evil.example/steal' ) );
		$this->assertStringNotContainsString( 'evil.example', $html );
		$this->assertStringContainsString( 'data-redirect=""', $html );

		$html = $this->render( array( 'redirectTo' => home_url( '/welcome/' ) ) );
		$this->assertStringContainsString( 'data-redirect="' . esc_attr( home_url( '/welcome/' ) ) . '"', $html );
	}

	public function test_the_toggle_style_attribute_overrides_the_site_setting() {
		$this->boot();

		$this->assertStringContainsString( 'happyaccess-pl__toggle--link', $this->render() );
		$this->assertStringContainsString( 'happyaccess-pl__toggle--button', $this->render( array( 'toggleStyle' => 'button' ) ) );

		Settings::update( array( 'passwordless' => array( 'toggle_style' => 'button' ) ) );
		$this->assertStringContainsString( 'happyaccess-pl__toggle--button', $this->render() );
		$this->assertStringContainsString( 'happyaccess-pl__toggle--link', $this->render( array( 'toggleStyle' => 'link' ) ) );
	}

	public function test_nothing_prints_while_the_database_updates() {
		$this->boot();
		update_option( 'happyaccess_db_version', '0' );
		$this->assertSame( '', trim( $this->render() ) );
	}

	public function test_the_editor_script_is_dropped_when_its_build_is_missing() {
		$dir = trailingslashit( get_temp_dir() ) . 'happyaccess-block-' . wp_generate_password( 6, false );
		wp_mkdir_p( $dir . '/build' );
		wp_mkdir_p( $dir . '/blocks/login' );
		$meta = array(
			'name'         => self::NAME,
			'file'         => $dir . '/blocks/login/block.json',
			'editorScript' => 'file:../../build/blocks.js',
		);

		$this->assertArrayNotHasKey( 'editorScript', Feature::block_metadata( $meta ) );

		file_put_contents( $dir . '/build/blocks.asset.php', "<?php return array( 'dependencies' => array(), 'version' => '1' );" );
		$this->assertArrayHasKey( 'editorScript', Feature::block_metadata( $meta ) );

		$other = array_merge( $meta, array( 'name' => 'other/block' ) );
		unlink( $dir . '/build/blocks.asset.php' );
		$this->assertArrayHasKey( 'editorScript', Feature::block_metadata( $other ), 'Other blocks are left alone.' );
		rmdir( $dir . '/build' );
		rmdir( $dir . '/blocks/login' );
		rmdir( $dir . '/blocks' );
		rmdir( $dir );
	}

	/**
	 * The editor preview runs through the REST block renderer as a logged-in
	 * user, so the form must show there although the front end hides it.
	 * The REST constant can't be undone, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_editor_preview_shows_the_form_to_a_logged_in_editor() {
		define( 'REST_REQUEST', true );
		$this->boot();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . self::NAME );
		$request->set_query_params(
			array(
				'context'    => 'edit',
				'attributes' => array( 'toggleStyle' => 'button' ),
			)
		);
		$_GET['context'] = 'edit';

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$html = $response->get_data()['rendered'];
		$this->assertStringContainsString( 'happyaccess-pl', $html );
		$this->assertStringContainsString( 'happyaccess-pl__toggle--button', $html );
		$this->assertStringNotContainsString( 'happyaccess-pl__toggle-row" hidden', $html, 'The preview shows the toggle, since no script runs in the editor.' );
		$this->assertStringNotContainsString( 'happyaccess-pl__fallback', $html );
	}

	/**
	 * A logged-in visitor on the front end still gets nothing, even while a
	 * REST request carries context=edit without being the block renderer.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rest_request_for_another_context_stays_empty_for_a_logged_in_user() {
		define( 'REST_REQUEST', true );
		$this->boot();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_GET['context'] = 'view';

		$this->assertSame( '', trim( $this->render() ) );
	}
}
