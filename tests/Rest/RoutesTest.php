<?php
/**
 * Every happyaccess/v1 route shares one permission check.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Features;
use HappyAccess\Features\Passwordless\Feature;
use HappyAccess\Features\Passwordless\RestController;
use HappyAccess\Rest\Routes;

require_once __DIR__ . '/RestTestCase.php';

class RoutesTest extends RestTestCase {

	public function test_every_route_uses_can_manage() {
		$handlers = 0;
		foreach ( $this->server->get_routes( Routes::NS ) as $route => $endpoints ) {
			// Core's namespace index lists the routes and is open to everyone.
			if ( '/' . Routes::NS === $route ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				$this->assertSame( array( Routes::class, 'can_manage' ), $endpoint['permission_callback'], $route );
				++$handlers;
			}
		}
		$this->assertGreaterThanOrEqual( 15, $handlers );
	}

	/**
	 * Turns the passwordless feature on and builds a fresh server, so its
	 * public routes are part of the check.
	 *
	 * @return void
	 */
	private function server_with_passwordless() {
		Features::set( 'passwordless', true );
		Feature::register();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		Routes::register();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->server = rest_get_server();
	}

	public function test_with_passwordless_on_only_its_two_public_routes_skip_can_manage() {
		$this->server_with_passwordless();

		$public  = array(
			'/' . Routes::NS . RestController::BASE . 'request' => true,
			'/' . Routes::NS . RestController::BASE . 'verify'  => true,
		);
		$seen    = array();
		$handled = 0;
		foreach ( $this->server->get_routes( Routes::NS ) as $route => $endpoints ) {
			if ( '/' . Routes::NS === $route ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				if ( isset( $public[ $route ] ) ) {
					$this->assertSame( array( RestController::class, 'has_header' ), $endpoint['permission_callback'], $route );
					$seen[ $route ] = true;
				} else {
					$this->assertSame( array( Routes::class, 'can_manage' ), $endpoint['permission_callback'], $route );
				}
				++$handled;
			}
		}
		$this->assertSame( $public, $seen, 'Both public routes exist while the feature is on.' );
		$this->assertGreaterThanOrEqual( 17, $handled );
	}
}
