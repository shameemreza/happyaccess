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
		Secrets::reset_cache();

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

	public function test_o1_tampered_payload_returns_null() {
		$payload  = Secrets::encrypt_with( 'o1', 'secret' );
		$tampered = substr_replace( $payload, 'A' === $payload[10] ? 'B' : 'A', 10, 1 );
		$this->assertNull( Secrets::decrypt( $tampered ) );
	}

	public function test_unknown_engine_throws() {
		$this->expectException( \InvalidArgumentException::class );
		Secrets::encrypt_with( 'x9', 'secret' );
	}

	public function test_corrupt_stored_key_is_replaced_with_autoload_off() {
		global $wpdb;
		update_option( Secrets::OPTION, 'not-base64!!' );
		Secrets::reset_cache();

		$key = Secrets::key();

		$this->assertSame( 32, strlen( $key ) );
		$this->assertSame( base64_encode( $key ), get_option( Secrets::OPTION ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Comparing the stored key.
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Secrets::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_existing_valid_key_is_not_rotated() {
		delete_option( Secrets::OPTION );
		Secrets::reset_cache();
		$first  = Secrets::key();
		$stored = get_option( Secrets::OPTION );

		Secrets::reset_cache();

		$this->assertSame( $first, Secrets::key() );
		$this->assertSame( $stored, get_option( Secrets::OPTION ) );
	}
}
