<?php
/**
 * ClientIp tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\ClientIp;
use HappyAccess\Core\Settings;

class ClientIpTest extends WP_UnitTestCase {

	private $saved_server;

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		$this->saved_server = $_SERVER;
	}

	public function tear_down() {
		$_SERVER = $this->saved_server;
		parent::tear_down();
	}

	public function test_remote_addr_is_used_by_default() {
		$server = array(
			'REMOTE_ADDR'           => '203.0.113.9',
			'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
		);
		$this->assertSame( '203.0.113.9', ClientIp::from_server( $server ) );
	}

	public function test_trusted_header_when_configured() {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '8.8.8.8, 10.0.0.1',
		);
		$this->assertSame( '8.8.8.8', ClientIp::from_server( $server ) );

		$server['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
		$this->assertSame( '10.0.0.1', ClientIp::from_server( $server ) );
	}

	public function test_spoofed_leftmost_entry_is_ignored() {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 8.8.8.8, 10.0.0.2',
		);
		$this->assertSame( '8.8.8.8', ClientIp::from_server( $server ) );
	}

	public function test_all_private_list_returns_rightmost() {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$server = array(
			'REMOTE_ADDR'          => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '10.0.0.5, 10.0.0.6',
		);
		$this->assertSame( '10.0.0.6', ClientIp::from_server( $server ) );
	}

	public function test_ipv4_mapped_ipv6_is_normalized() {
		$this->assertSame( '203.0.113.9', ClientIp::from_server( array( 'REMOTE_ADDR' => '::ffff:203.0.113.9' ) ) );
		$this->assertSame(
			'203.0.113.9',
			ClientIp::bucket( ClientIp::from_server( array( 'REMOTE_ADDR' => '::ffff:203.0.113.9' ) ) )
		);
	}

	public function test_get_uses_configured_header() {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_CF_CONNECTING_IP' ) ) );
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.8.8';
		$this->assertSame( '8.8.8.8', ClientIp::get() );
	}

	public function test_port_is_stripped_from_hops() {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$server = array( 'REMOTE_ADDR' => '10.0.0.1' );

		$server['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 1.1.1.1:8080';
		$this->assertSame( '1.1.1.1', ClientIp::from_server( $server ) );

		$server['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, [2606:4700:4700::1111]:80';
		$this->assertSame( '2606:4700:4700::1111', ClientIp::from_server( $server ) );
	}

	public function test_unparseable_hop_stops_the_walk() {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4, garbage',
		);
		$this->assertSame( '10.0.0.1', ClientIp::from_server( $server ) );
	}

	public function test_loopback_hop_is_skipped() {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '8.8.8.8, 127.0.0.1',
		);
		$this->assertSame( '8.8.8.8', ClientIp::from_server( $server ) );
	}

	public function test_mapped_private_hop_is_skipped() {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '::ffff:10.0.0.1, 8.8.8.8',
		);
		$this->assertSame( '8.8.8.8', ClientIp::from_server( $server ) );
		$server['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, ::ffff:10.0.0.1';
		$this->assertSame( '8.8.8.8', ClientIp::from_server( $server ) );
	}

	public function test_bucket_and_anonymize_normalize_mapped_addresses() {
		$this->assertSame( '203.0.113.9', ClientIp::bucket( '::ffff:203.0.113.9' ) );
		$this->assertSame( '203.0.113.0', ClientIp::anonymize( '::ffff:203.0.113.9' ) );
	}

	public function test_missing_remote_addr_falls_back() {
		$this->assertSame( '0.0.0.0', ClientIp::from_server( array() ) );
	}

	public function test_filter_overrides_and_invalid_filter_is_ignored() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		add_filter( 'happyaccess_client_ip', function () {
			return '192.0.2.44';
		} );
		$this->assertSame( '192.0.2.44', ClientIp::get() );

		remove_all_filters( 'happyaccess_client_ip' );
		add_filter( 'happyaccess_client_ip', function () {
			return 'garbage';
		} );
		$this->assertSame( '203.0.113.9', ClientIp::get() );
	}

	public function test_ipv6_bucket_groups_a_64() {
		$this->assertSame( ClientIp::bucket( '2001:db8:1:2::a' ), ClientIp::bucket( '2001:db8:1:2:ffff::1' ) );
		$this->assertNotSame( ClientIp::bucket( '2001:db8:1:2::a' ), ClientIp::bucket( '2001:db8:1:3::a' ) );
		$this->assertSame( '203.0.113.9', ClientIp::bucket( '203.0.113.9' ) );
	}

	public function test_anonymize() {
		$this->assertSame( '203.0.113.0', ClientIp::anonymize( '203.0.113.9' ) );
		$this->assertSame( '2001:db8:1::', ClientIp::anonymize( '2001:db8:1:2:3:4:5:6' ) );
	}

	public function test_canonical_form() {
		$this->assertSame( '2001:db8::1', ClientIp::canonical( '2001:DB8:0:0::1' ) );
		$this->assertSame( '203.0.113.9', ClientIp::canonical( '::ffff:203.0.113.9' ) );
		$this->assertSame( '', ClientIp::canonical( 'x' ) );
	}

	public function provide_cgnat_addresses() {
		return array(
			'start'         => array( '100.64.0.0' ),
			'typical'       => array( '100.64.0.1' ),
			'middle'        => array( '100.100.100.100' ),
			'end'           => array( '100.127.255.254' ),
			'last'          => array( '100.127.255.255' ),
			'mapped in v6'  => array( '::ffff:100.64.0.1' ),
		);
	}

	/**
	 * Carrier-grade NAT addresses are shared between customers, so they are skipped like private ones.
	 *
	 * @dataProvider provide_cgnat_addresses
	 */
	public function test_cgnat_hop_is_skipped( $cgnat ) {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '8.8.8.8, ' . $cgnat,
		);
		$this->assertSame( '8.8.8.8', ClientIp::from_server( $server ) );
	}

	public function provide_public_neighbours() {
		return array(
			'just below the range' => array( '100.63.255.255' ),
			'just above the range' => array( '100.128.0.0' ),
			'other 100 address'    => array( '100.1.2.3' ),
			'ipv6 public'          => array( '2606:4700:4700::1111' ),
		);
	}

	/**
	 * @dataProvider provide_public_neighbours
	 */
	public function test_addresses_next_to_cgnat_are_still_public( $public ) {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '1.1.1.1, ' . $public . ', 100.64.0.9',
		);
		$this->assertSame( $public, ClientIp::from_server( $server ) );
	}

	public function test_a_list_with_only_cgnat_and_private_hops_returns_the_rightmost() {
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '10.0.0.5, 100.64.0.7',
		);
		$this->assertSame( '100.64.0.7', ClientIp::from_server( $server ) );
	}

	public function test_documentation_ranges_are_not_public_where_php_knows_them() {
		if ( ! defined( 'FILTER_FLAG_GLOBAL_RANGE' ) ) {
			$this->markTestSkipped( 'FILTER_FLAG_GLOBAL_RANGE needs PHP 8.2 or later.' );
		}
		Settings::update( array( 'security' => array( 'proxy_header' => 'HTTP_X_FORWARDED_FOR' ) ) );
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.1',
			'HTTP_X_FORWARDED_FOR' => '8.8.8.8, 192.0.2.55',
		);
		$this->assertSame( '8.8.8.8', ClientIp::from_server( $server ) );
	}
}
