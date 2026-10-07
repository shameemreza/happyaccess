<?php
/**
 * Every happyaccess/v1 route shares one permission check.
 *
 * @package HappyAccess
 */

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
}
