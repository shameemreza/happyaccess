<?php
/**
 * Shared setup for REST route tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Capabilities;
use HappyAccess\Core\Clock;
use HappyAccess\Core\Installer;
use HappyAccess\Features\SupportAccess\Grants;
use HappyAccess\Features\SupportAccess\TempUsers;
use HappyAccess\Rest\Routes;

abstract class RestTestCase extends WP_UnitTestCase {

	/**
	 * Administrator who owns the passes made in a test.
	 *
	 * @var int
	 */
	protected $owner;

	/**
	 * REST server the routes are registered on.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Settings globals as they were before rest_api_init ran. Core's
	 * rest_api_init registers settings into them, which would leak into
	 * later tests that read the allowed_options filter.
	 *
	 * @var array
	 */
	private $settings_globals = array();

	public function set_up() {
		parent::set_up();
		Installer::install();
		Clock::freeze( 1790000000 );
		Capabilities::register();

		$this->owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner );
		Grants::flush_cache();

		foreach ( array( 'new_allowed_options', 'wp_registered_settings' ) as $name ) {
			$this->settings_globals[ $name ] = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;
		}

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		Routes::register();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->server = rest_get_server();
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		foreach ( $this->settings_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
		Clock::freeze( null );
		parent::tear_down();
	}

	/**
	 * Runs one request against the happyaccess/v1 namespace.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path after the namespace, starting with a slash.
	 * @param array  $params Body params.
	 * @return WP_REST_Response
	 */
	protected function request( string $method, string $path, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . Routes::NS . $path );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	/**
	 * Creates a pass and its temp user, then switches to that user.
	 *
	 * @param array $grant_args Grant args.
	 * @return int Temp user id.
	 */
	protected function as_temp_user( array $grant_args = array() ): int {
		$made = Grants::create( array_merge( array( 'label' => 'Agent' ), $grant_args ) );
		$temp = TempUsers::get_or_create( Grants::get( $made['id'] ) );
		wp_set_current_user( $temp );
		Grants::flush_cache();
		return (int) $temp;
	}
}
