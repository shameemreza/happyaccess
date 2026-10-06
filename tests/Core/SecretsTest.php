<?php
/**
 * Secrets tests.
 *
 * @package HappyAccess
 */

use HappyAccess\Core\Secrets;

class SecretsTest extends WP_UnitTestCase {

	public function test_key_is_created_once_with_autoload_off() {
		global $wpdb;
		delete_option( Secrets::OPTION );

		$first  = Secrets::key();
		$second = Secrets::key();

		$this->assertSame( 32, strlen( $first ) );
		$this->assertSame( $first, $second );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Secrets::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_hmac_is_stable_hex() {
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', Secrets::hmac( 'abc' ) );
		$this->assertSame( Secrets::hmac( 'abc' ), Secrets::hmac( 'abc' ) );
		$this->assertNotSame( Secrets::hmac( 'abc' ), Secrets::hmac( 'abd' ) );
	}

	public function test_encrypt_round_trip_both_engines() {
		foreach ( array( 's1', 'o1' ) as $engine ) {
			$payload = Secrets::encrypt_with( $engine, 'JBSWY3DPEHPK3PXP' );
			$this->assertStringStartsWith( $engine . ':', $payload );
			$this->assertSame( 'JBSWY3DPEHPK3PXP', Secrets::decrypt( $payload ) );
		}
		$this->assertSame( 'x', Secrets::decrypt( Secrets::encrypt( 'x' ) ) );
	}

	public function test_tampered_or_garbage_payload_returns_null() {
		$payload  = Secrets::encrypt_with( 's1', 'secret' );
		$tampered = substr_replace( $payload, 'A' === $payload[10] ? 'B' : 'A', 10, 1 );
		$this->assertNull( Secrets::decrypt( $tampered ) );
		$this->assertNull( Secrets::decrypt( 'zz:abc' ) );
		$this->assertNull( Secrets::decrypt( '' ) );
	}
}
